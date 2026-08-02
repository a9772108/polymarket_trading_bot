<?php

declare(strict_types=1);

final class PolymarketClient
{
    public function __construct(private readonly array $config)
    {
    }

    public function searchActiveBtcFiveMinuteMarkets(int $limit = 50): array
    {
        return $this->searchActiveUpDownMarkets('btc', 5, $limit);
    }

    public function searchActiveUpDownMarkets(
        string $asset,
        int $intervalMinutes,
        int $limit = 50
    ): array {
        $asset = strtolower(trim($asset));
        if (!in_array($asset, ['btc', 'eth'], true) || !in_array($intervalMinutes, [5, 15], true)) {
            throw new InvalidArgumentException('Unsupported paper market mode.');
        }

        $intervalSeconds = $intervalMinutes * 60;
        $now = time();
        $slot = intdiv($now, $intervalSeconds) * $intervalSeconds;

        // Crypto Up/Down markets use a predictable slug containing the interval start timestamp.
        // Check the current slot first, then nearby slots to tolerate clock/API publication delays.
        foreach ([0, -$intervalSeconds, $intervalSeconds, -2 * $intervalSeconds, 2 * $intervalSeconds] as $offset) {
            $slug = sprintf(
                '%s-updown-%dm-%d',
                $asset,
                $intervalMinutes,
                $slot + $offset
            );
            $market = $this->getJsonOrNull(
                $this->config['gamma_base_url'] . '/markets/slug/' . rawurlencode($slug)
            );

            if (
                is_array($market)
                && $this->isTradableCurrentUpDownMarket(
                    $market,
                    $now,
                    $asset,
                    $intervalMinutes
                )
            ) {
                return [$market];
            }
        }

        // Fallback for any future slug-format change. Reject stale records even when Gamma
        // incorrectly reports them as active.
        $query = http_build_query([
            'active' => 'true',
            'closed' => 'false',
            'limit' => $limit,
            'order' => 'endDate',
            'ascending' => 'true',
        ]);

        $markets = $this->getJson($this->config['gamma_base_url'] . '/markets?' . $query);

        $filtered = array_values(array_filter($markets, function (array $market) use (
            $now,
            $asset,
            $intervalMinutes
        ): bool {
            return $this->isTradableCurrentUpDownMarket(
                $market,
                $now,
                $asset,
                $intervalMinutes
            );
        }));

        usort($filtered, static function (array $a, array $b): int {
            return strtotime((string) ($a['endDate'] ?? '')) <=> strtotime((string) ($b['endDate'] ?? ''));
        });

        return $filtered;
    }

    public function getMarketBySlug(string $slug): ?array
    {
        return $this->getJsonOrNull(
            $this->config['gamma_base_url'] . '/markets/slug/' . rawurlencode($slug)
        );
    }

    public function getOrderBook(string $tokenId): array
    {
        return $this->getJson(
            $this->config['clob_base_url'] . '/book?' . http_build_query(['token_id' => $tokenId])
        );
    }

    public function getMidpoint(string $tokenId): array
    {
        return $this->getJsonOrNull(
            $this->config['clob_base_url'] . '/midpoint?' . http_build_query(['token_id' => $tokenId])
        ) ?? [];
    }

    public function getSpread(string $tokenId): array
    {
        return $this->getJsonOrNull(
            $this->config['clob_base_url'] . '/spread?' . http_build_query(['token_id' => $tokenId])
        ) ?? [];
    }

    public function getLiveVolume(int $eventId): array
    {
        if ($eventId < 1) {
            return [];
        }

        $dataBaseUrl = (string) ($this->config['data_base_url'] ?? 'https://data-api.polymarket.com');

        return $this->getJson(
            rtrim($dataBaseUrl, '/')
            . '/live-volume?'
            . http_build_query(['id' => $eventId])
        );
    }

    public function checkGeoblock(): array
    {
        return $this->getJson($this->config['geoblock_url']);
    }

    private function isTradableCurrentUpDownMarket(
        array $market,
        int $now,
        string $asset,
        int $intervalMinutes
    ): bool {
        $question = strtolower((string) ($market['question'] ?? ''));
        $slug = strtolower((string) ($market['slug'] ?? ''));
        $text = $question . ' ' . $slug;
        $endTimestamp = strtotime((string) ($market['endDate'] ?? ''));
        $startTimestamp = strtotime((string) ($market['startDate'] ?? ''));

        $assetName = $asset === 'btc' ? 'bitcoin' : 'ethereum';
        $looksLikeRequestedMarket = str_contains($text, $asset) || str_contains($text, $assetName);
        $looksLikeRequestedMarket = $looksLikeRequestedMarket
            && (str_contains($text, 'up or down') || str_contains($text, 'up-down') || str_contains($text, 'updown'))
            && (
                str_contains($text, $intervalMinutes . 'm')
                || str_contains($text, $intervalMinutes . ' min')
                || str_contains($text, $intervalMinutes . '-minute')
            );

        if (!$looksLikeRequestedMarket || $endTimestamp === false) {
            return false;
        }

        if (($market['closed'] ?? false) === true || ($market['active'] ?? true) === false) {
            return false;
        }

        if (array_key_exists('acceptingOrders', $market) && ($market['acceptingOrders'] ?? false) !== true) {
            return false;
        }

        // Must not be expired and should be the current or immediately upcoming interval.
        $maximumFutureEnd = max(900, $intervalMinutes * 120);
        if ($endTimestamp <= $now || $endTimestamp > $now + $maximumFutureEnd) {
            return false;
        }

        if ($startTimestamp !== false && $startTimestamp > $now + ($intervalMinutes * 60)) {
            return false;
        }

        return true;
    }

    private function getJsonOrNull(string $url): ?array
    {
        try {
            return $this->getJson($url);
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'HTTP 404')) {
                return null;
            }

            throw $exception;
        }
    }

    private function getJson(string $url): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'PolymarketPaperTrader/0.2',
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false || $error !== '') {
            throw new RuntimeException('Polymarket request failed: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Polymarket returned HTTP %d for %s.', $status, $url));
        }

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException('Unexpected API response.');
        }

        return $decoded;
    }
}
