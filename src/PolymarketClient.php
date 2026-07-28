<?php

declare(strict_types=1);

final class PolymarketClient
{
    public function __construct(private readonly array $config)
    {
    }

    public function searchActiveBtcFiveMinuteMarkets(int $limit = 50): array
    {
        $query = http_build_query([
            'active' => 'true',
            'closed' => 'false',
            'limit' => $limit,
            'order' => 'endDate',
            'ascending' => 'true',
        ]);

        $markets = $this->getJson($this->config['gamma_base_url'] . '/markets?' . $query);

        return array_values(array_filter($markets, static function (array $market): bool {
            $question = strtolower((string) ($market['question'] ?? ''));
            $slug = strtolower((string) ($market['slug'] ?? ''));
            $text = $question . ' ' . $slug;

            return str_contains($text, 'btc')
                && (str_contains($text, 'up or down') || str_contains($text, 'up-down'))
                && (str_contains($text, '5m') || str_contains($text, '5 min') || str_contains($text, '5-minute'));
        }));
    }

    public function getOrderBook(string $tokenId): array
    {
        return $this->getJson(
            $this->config['clob_base_url'] . '/book?' . http_build_query(['token_id' => $tokenId])
        );
    }

    public function getMidpoint(string $tokenId): array
    {
        return $this->getJson(
            $this->config['clob_base_url'] . '/midpoint?' . http_build_query(['token_id' => $tokenId])
        );
    }

    public function getSpread(string $tokenId): array
    {
        return $this->getJson(
            $this->config['clob_base_url'] . '/spread?' . http_build_query(['token_id' => $tokenId])
        );
    }

    public function checkGeoblock(): array
    {
        return $this->getJson($this->config['geoblock_url']);
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
            CURLOPT_USERAGENT => 'PolymarketPaperTrader/0.1',
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false || $error !== '') {
            throw new RuntimeException('Polymarket request failed: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Polymarket returned HTTP %d.', $status));
        }

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException('Unexpected API response.');
        }

        return $decoded;
    }
}
