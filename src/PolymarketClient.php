<?php

declare(strict_types=1);

final class PolymarketClient
{
    public function __construct(private readonly array $config)
    {
    }

    public function searchActiveBtcFiveMinuteMarkets(int $limit = 50): array
    {
        $now = time();
        $slot = intdiv($now, 300) * 300;

        // BTC 5-minute markets use a predictable slug containing the interval start timestamp.
        // Check the current slot first, then nearby slots to tolerate clock/API publication delays.
        foreach ([0, -300, 300, -600, 600] as $offset) {
            $slug = 'btc-updown-5m-' . ($slot + $offset);
            $market = $this->getJsonOrNull(
                $this->config['gamma_base_url'] . '/markets/slug/' . rawurlencode($slug)
            );

            if (is_array($market) && $this->isTradableCurrentFiveMinuteMarket($market, $now)) {
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

        $filtered = array_values(array_filter($markets, function (array $market) use ($now): bool {
            return $this->isTradableCurrentFiveMinuteMarket($market, $now);
        }));

        usort($filtered, static function (array $a, array $b): int {
            return strtotime((string) ($a['endDate'] ?? '')) <=> strtotime((string) ($b['endDate'] ?? ''));
        });

        return $filtered;
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

    public function checkGeoblock(): array
    {
        return $this->getJson($this->config['geoblock_url']);
    }

    private function isTradableCurrentFiveMinuteMarket(array $market, int $now): bool
    {
        $question = strtolower((string) ($market['question'] ?? ''));
        $slug = strtolower((string) ($market['slug'] ?? ''));
        $text = $question . ' ' . $slug;
        $endTimestamp = strtotime((string) ($market['endDate'] ?? ''));
        $startTimestamp = strtotime((string) ($market['startDate'] ?? ''));

        $looksLikeBtcFiveMinute = str_contains($text, 'btc') || str_contains($text, 'bitcoin');
        $looksLikeBtcFiveMinute = $looksLikeBtcFiveMinute
            && (str_contains($text, 'up or down') || str_contains($text, 'up-down') || str_contains($text, 'updown'))
            && (str_contains($text, '5m') || str_contains($text, '5 min') || str_contains($text, '5-minute'));

        if (!$looksLikeBtcFiveMinute || $endTimestamp === false) {
            return false;
        }

        if (($market['closed'] ?? false) === true || ($market['active'] ?? true) === false) {
            return false;
        }

        if (array_key_exists('acceptingOrders', $market) && ($market['acceptingOrders'] ?? false) !== true) {
            return false;
        }

        // Must not be expired and should be the current or immediately upcoming interval.
        if ($endTimestamp <= $now || $endTimestamp > $now + 900) {
            return false;
        }

        if ($startTimestamp !== false && $startTimestamp > $now + 600) {
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
