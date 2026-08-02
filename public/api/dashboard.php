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
    require_once dirname(__DIR__, 2) . '/src/MarketMode.php';
    $mode = MarketMode::fromRequest($_GET['market'] ?? null);
    $client = new PolymarketClient($config['polymarket']);

    $markets = $client->searchActiveUpDownMarkets(
        (string) $mode['asset'],
        (int) $mode['interval_minutes']
    );
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
            $bestBid = null;
            $bestAsk = null;
            $bidSize = null;
            $askSize = null;

            try {
                $book = $client->getOrderBook($tokenId);
                $bids = array_values(array_filter((array) ($book['bids'] ?? []), 'is_array'));
                $asks = array_values(array_filter((array) ($book['asks'] ?? []), 'is_array'));

                if ($bids !== []) {
                    usort($bids, static fn (array $a, array $b): int =>
                        ((float) ($b['price'] ?? 0)) <=> ((float) ($a['price'] ?? 0))
                    );
                    $bestBid = isset($bids[0]['price']) ? (float) $bids[0]['price'] : null;
                    $bidSize = isset($bids[0]['size']) ? (float) $bids[0]['size'] : null;
                }

                if ($asks !== []) {
                    usort($asks, static fn (array $a, array $b): int =>
                        ((float) ($a['price'] ?? 0)) <=> ((float) ($b['price'] ?? 0))
                    );
                    $bestAsk = isset($asks[0]['price']) ? (float) $asks[0]['price'] : null;
                    $askSize = isset($asks[0]['size']) ? (float) $asks[0]['size'] : null;
                }

                if ($bestBid !== null && $bestAsk !== null) {
                    $midpoint = ($bestBid + $bestAsk) / 2;
                    $spread = max(0, $bestAsk - $bestBid);
                }
            } catch (Throwable) {
                $bestBid = null;
                $bestAsk = null;
            }

            if ($midpoint === null) {
                try {
                    $midpointResponse = $client->getMidpoint($tokenId);
                    $midpoint = $midpointResponse['mid_price'] ?? $midpointResponse['mid'] ?? null;
                } catch (Throwable) {
                    $midpoint = null;
                }
            }

            if ($spread === null) {
                try {
                    $spreadResponse = $client->getSpread($tokenId);
                    $spread = $spreadResponse['spread'] ?? null;
                } catch (Throwable) {
                    $spread = null;
                }
            }

            $tokens[] = [
                'token_id' => $tokenId,
                'outcome' => (string) ($outcomes[$index] ?? ('Outcome ' . ($index + 1))),
                'midpoint' => $midpoint,
                'spread' => $spread,
                'best_bid' => $bestBid,
                'best_ask' => $bestAsk,
                'bid_size' => $bidSize,
                'ask_size' => $askSize,
            ];
        }
    }

    $intervalStart = null;
    $marketVolume = null;
    if (is_array($market)) {
        if (preg_match('/-(\d{10})$/', (string) ($market['slug'] ?? ''), $matches) === 1) {
            $intervalStart = gmdate(DATE_ATOM, (int) $matches[1]);
        } else {
            $intervalStart = $market['eventStartTime']
                ?? $market['events'][0]['startTime']
                ?? null;
        }

        foreach (['volume', 'volumeNum', 'volumeClob'] as $volumeField) {
            if (isset($market[$volumeField]) && is_numeric($market[$volumeField])) {
                $marketVolume = (float) $market[$volumeField];
                break;
            }
        }

        $events = $market['events'] ?? [];
        if (is_string($events)) {
            $decoded = json_decode($events, true);
            $events = is_array($decoded) ? $decoded : [];
        }

        $eventId = isset($events[0]['id']) && is_numeric($events[0]['id'])
            ? (int) $events[0]['id']
            : 0;

        if ($eventId > 0) {
            try {
                $liveVolume = $client->getLiveVolume($eventId);
                $liveEvent = isset($liveVolume[0]) && is_array($liveVolume[0])
                    ? $liveVolume[0]
                    : $liveVolume;
                $conditionId = strtolower((string) ($market['conditionId'] ?? ''));

                foreach ((array) ($liveEvent['markets'] ?? []) as $liveMarket) {
                    if (
                        is_array($liveMarket)
                        && strtolower((string) ($liveMarket['market'] ?? '')) === $conditionId
                        && isset($liveMarket['value'])
                        && is_numeric($liveMarket['value'])
                    ) {
                        $marketVolume = (float) $liveMarket['value'];
                        break;
                    }
                }

                if ($marketVolume === null && isset($liveEvent['total']) && is_numeric($liveEvent['total'])) {
                    $marketVolume = (float) $liveEvent['total'];
                }
            } catch (Throwable) {
                // Keep the Gamma fallback when the optional live-volume feed is unavailable.
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'generated_at' => date(DATE_ATOM),
        'mode' => 'paper',
        'market_mode' => $mode,
        'geoblock' => $geo,
        'market' => $market ? [
            'id' => $market['id'] ?? null,
            'question' => $market['question'] ?? null,
            'slug' => $market['slug'] ?? null,
            'condition_id' => $market['conditionId'] ?? null,
            'start_date' => $market['startDate'] ?? null,
            'interval_start' => $intervalStart,
            'end_date' => $market['endDate'] ?? null,
            'liquidity' => $market['liquidity'] ?? null,
            'volume' => $marketVolume,
            'fee_rate' => $market['feeSchedule']['rate'] ?? 0.07,
            'tokens' => $tokens,
        ] : null,
        'risk' => MarketMode::paperSettings(
            $config,
            $mode,
            dirname(__DIR__, 2)
        ),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}
