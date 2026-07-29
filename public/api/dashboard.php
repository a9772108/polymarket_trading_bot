<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $configFile = dirname(__DIR__, 2) . '/config/config.php';
    $fallbackFile = dirname(__DIR__, 2) . '/config/config.example.php';
    $config = require file_exists($configFile) ? $configFile : $fallbackFile;

    date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

    require_once dirname(__DIR__, 2) . '/src/PolymarketClient.php';
    $client = new PolymarketClient($config['polymarket']);

    $markets = $client->searchActiveBtcFiveMinuteMarkets();
    $market = $markets[0] ?? null;

    $geo = [
        'blocked' => true,
        'country' => 'unknown',
        'region' => 'unknown',
        'available' => false,
        'error' => null,
    ];

    try {
        $geoResponse = $client->checkGeoblock();
        $geo = [
            'blocked' => (bool) ($geoResponse['blocked'] ?? true),
            'country' => (string) ($geoResponse['country'] ?? 'unknown'),
            'region' => (string) ($geoResponse['region'] ?? 'unknown'),
            'available' => true,
            'error' => null,
        ];
    } catch (Throwable $geoException) {
        $geo['error'] = $geoException->getMessage();
    }

    $tokens = [];
    if (is_array($market)) {
        $rawTokens = $market['clobTokenIds'] ?? [];
        if (is_string($rawTokens)) {
            $decoded = json_decode($rawTokens, true);
            $rawTokens = is_array($decoded) ? $decoded : [];
        }

        $outcomes = $market['outcomes'] ?? [];
        if (is_string($outcomes)) {
            $decoded = json_decode($outcomes, true);
            $outcomes = is_array($decoded) ? $decoded : [];
        }

        foreach ((array) $rawTokens as $index => $tokenId) {
            $tokenId = (string) $tokenId;
            if ($tokenId === '') {
                continue;
            }

            $midpoint = null;
            $spread = null;

            try {
                $midpointResponse = $client->getMidpoint($tokenId);
                $midpoint = $midpointResponse['mid_price'] ?? $midpointResponse['mid'] ?? null;
            } catch (Throwable) {
                $midpoint = null;
            }

            try {
                $spreadResponse = $client->getSpread($tokenId);
                $spread = $spreadResponse['spread'] ?? null;
            } catch (Throwable) {
                $spread = null;
            }

            $tokens[] = [
                'token_id' => $tokenId,
                'outcome' => (string) ($outcomes[$index] ?? ('Outcome ' . ($index + 1))),
                'midpoint' => $midpoint,
                'spread' => $spread,
            ];
        }
    }

    echo json_encode([
        'ok' => true,
        'generated_at' => date(DATE_ATOM),
        'mode' => 'paper',
        'geoblock' => $geo,
        'market' => $market ? [
            'id' => $market['id'] ?? null,
            'question' => $market['question'] ?? null,
            'slug' => $market['slug'] ?? null,
            'condition_id' => $market['conditionId'] ?? null,
            'start_date' => $market['startDate'] ?? null,
            'end_date' => $market['endDate'] ?? null,
            'liquidity' => $market['liquidity'] ?? null,
            'volume' => $market['volume'] ?? null,
            'tokens' => $tokens,
        ] : null,
        'risk' => $config['paper_trading'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}
