<?php

declare(strict_types=1);

final class HistoricalBacktest
{
    private PDO $database;
    private array $api;
    private array $risk;

    public function __construct(array $api, array $risk, string $projectRoot)
    {
        $this->api = array_replace([
            'gamma_base_url' => 'https://gamma-api.polymarket.com',
            'data_base_url' => 'https://data-api.polymarket.com',
            'binance_base_url' => 'https://data-api.binance.vision',
        ], $api);
        $this->risk = array_replace([
            'starting_balance' => 1000.00,
            'max_position_usd' => 5.00,
            'daily_loss_limit_usd' => 10.00,
            'minimum_edge' => 0.05,
            'assumed_slippage' => 0.01,
        ], $risk);

        $path = (string) ($risk['backtest_database']
            ?? ($projectRoot . '/storage/backtests.sqlite'));
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the backtest storage directory.');
        }

        $this->database = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->database->exec('PRAGMA journal_mode = WAL');
        $this->database->exec('PRAGMA busy_timeout = 10000');
        $this->initializeSchema();
    }

    public function run(array $parameters): array
    {
        $marketCount = $this->allowedInteger($parameters['market_count'] ?? 24, [12, 24, 48, 96], 24);
        $entrySeconds = $this->allowedInteger($parameters['entry_seconds'] ?? 60, [15, 30, 60], 60);
        $minimumEdge = $this->boundedNumber(
            $parameters['minimum_edge'] ?? $this->risk['minimum_edge'],
            0,
            0.25,
            (float) $this->risk['minimum_edge']
        );
        $slippage = $this->boundedNumber(
            $parameters['slippage'] ?? $this->risk['assumed_slippage'],
            0,
            0.05,
            (float) $this->risk['assumed_slippage']
        );
        $assumedSpread = $this->boundedNumber(
            $parameters['assumed_spread'] ?? 0.01,
            0,
            0.05,
            0.01
        );
        $positionSize = min(
            (float) $this->risk['max_position_usd'],
            $this->boundedNumber(
                $parameters['position_size'] ?? $this->risk['max_position_usd'],
                0.50,
                (float) $this->risk['max_position_usd'],
                (float) $this->risk['max_position_usd']
            )
        );

        $events = $this->fetchClosedEvents($marketCount);
        if ($events === []) {
            throw new RuntimeException('No completed BTC five-minute markets were returned.');
        }

        usort($events, static fn (array $a, array $b): int =>
            self::eventStart($a) <=> self::eventStart($b)
        );

        $firstStart = self::eventStart($events[0]);
        $lastEnd = self::eventStart($events[count($events) - 1]) + 300;
        $this->ensureBinanceTicks($firstStart - 90, $lastEnd);
        $ticks = $this->loadTicks($firstStart - 90, $lastEnd);
        $tradesByCondition = $this->marketTrades($events);

        $startingBalance = (float) $this->risk['starting_balance'];
        $balance = $startingBalance;
        $peakBalance = $balance;
        $maximumDrawdown = 0.0;
        $wins = 0;
        $losses = 0;
        $tradeCount = 0;
        $totalStaked = 0.0;
        $grossProfit = 0.0;
        $grossLoss = 0.0;
        $modelBrierSum = 0.0;
        $marketBrierSum = 0.0;
        $analyzed = 0;
        $skippedNoData = 0;
        $dailyPnl = [];
        $rows = [];
        $equity = [['label' => 'Start', 'balance' => $balance]];

        foreach ($events as $event) {
            $market = is_array($event['markets'][0] ?? null) ? $event['markets'][0] : [];
            $conditionId = (string) ($market['conditionId'] ?? '');
            $start = self::eventStart($event);
            $end = $start + 300;
            $entryTimestamp = $end - $entrySeconds;
            $outcome = $this->resolvedOutcome($market);
            $priceToBeat = $this->numberOrNull($event['eventMetadata']['priceToBeat'] ?? null);
            $startProxy = $this->tickAtOrBefore($ticks, $start);
            $entryProxy = $this->tickAtOrBefore($ticks, $entryTimestamp);
            $volatility = $this->tickVolatility($ticks, $entryTimestamp, 60);
            $marketTrade = $this->lastTradeAtOrBefore(
                (array) ($tradesByCondition[$conditionId] ?? []),
                $entryTimestamp,
                90
            );

            if (
                $conditionId === ''
                || $outcome === null
                || $priceToBeat === null
                || $startProxy === null
                || $entryProxy === null
                || $volatility === null
                || $marketTrade === null
            ) {
                $skippedNoData++;
                continue;
            }

            $distance = $entryProxy - $startProxy;
            $expectedMove = max(0.000001, $volatility * sqrt(max(1, $entrySeconds)));
            $probabilityUp = $this->clamp(
                $this->normalCdf($distance / $expectedMove),
                0.01,
                0.99
            );
            $marketMidUp = $this->impliedUpPrice($marketTrade);
            if ($marketMidUp === null) {
                $skippedNoData++;
                continue;
            }

            $upAsk = $this->clamp($marketMidUp + ($assumedSpread / 2), 0.01, 0.99);
            $downAsk = $this->clamp((1 - $marketMidUp) + ($assumedSpread / 2), 0.01, 0.99);
            $upFee = 0.07 * $upAsk * (1 - $upAsk);
            $downFee = 0.07 * $downAsk * (1 - $downAsk);
            $upEdge = $probabilityUp - $upAsk - $upFee - $slippage;
            $downProbability = 1 - $probabilityUp;
            $downEdge = $downProbability - $downAsk - $downFee - $slippage;
            $side = $upEdge >= $downEdge ? 'Up' : 'Down';
            $edge = max($upEdge, $downEdge);
            $entryAsk = $side === 'Up' ? $upAsk : $downAsk;
            $feePerShare = $side === 'Up' ? $upFee : $downFee;
            $actualUp = strcasecmp($outcome, 'Up') === 0 ? 1.0 : 0.0;
            $modelBrierSum += ($probabilityUp - $actualUp) ** 2;
            $marketBrierSum += ($marketMidUp - $actualUp) ** 2;
            $analyzed++;

            $decision = 'SKIP';
            $pnl = 0.0;
            $day = gmdate('Y-m-d', $end);
            $dailyPnl[$day] ??= 0.0;
            $costPerShare = $entryAsk + $feePerShare + $slippage;

            if (
                $edge >= $minimumEdge
                && $dailyPnl[$day] > -(float) $this->risk['daily_loss_limit_usd']
                && $costPerShare > 0
                && $costPerShare < 1
            ) {
                $decision = 'BET ' . strtoupper($side);
                $shares = $positionSize / $costPerShare;
                $won = strcasecmp($side, $outcome) === 0;
                $pnl = $won ? $shares - $positionSize : -$positionSize;
                $balance += $pnl;
                $dailyPnl[$day] += $pnl;
                $tradeCount++;
                $totalStaked += $positionSize;

                if ($won) {
                    $wins++;
                    $grossProfit += $pnl;
                } else {
                    $losses++;
                    $grossLoss += abs($pnl);
                }

                $peakBalance = max($peakBalance, $balance);
                $maximumDrawdown = max($maximumDrawdown, $peakBalance - $balance);
                $equity[] = [
                    'label' => gmdate('H:i', $end),
                    'balance' => $balance,
                ];
            }

            $rows[] = [
                'slug' => $event['slug'] ?? '',
                'ended_at' => gmdate(DATE_ATOM, $end),
                'actual_outcome' => $outcome,
                'decision' => $decision,
                'model_probability_up' => $probabilityUp,
                'market_midpoint_up' => $marketMidUp,
                'entry_ask' => $entryAsk,
                'net_edge' => $edge,
                'pnl' => $pnl,
                'price_to_beat' => $priceToBeat,
                'binance_start' => $startProxy,
                'binance_entry' => $entryProxy,
                'trade_timestamp' => (int) $marketTrade['timestamp'],
            ];
        }

        if ($analyzed === 0) {
            throw new RuntimeException('Historical markets were found, but entry-price data was insufficient for analysis.');
        }

        $result = [
            'ok' => true,
            'generated_at' => date(DATE_ATOM),
            'parameters' => [
                'market_count' => $marketCount,
                'entry_seconds' => $entrySeconds,
                'minimum_edge' => $minimumEdge,
                'slippage' => $slippage,
                'assumed_spread' => $assumedSpread,
                'position_size' => $positionSize,
            ],
            'summary' => [
                'requested_markets' => $marketCount,
                'analyzed_markets' => $analyzed,
                'skipped_no_data' => max(0, $marketCount - $analyzed),
                'trades' => $tradeCount,
                'wins' => $wins,
                'losses' => $losses,
                'win_rate' => $tradeCount > 0 ? $wins / $tradeCount : null,
                'starting_balance' => $startingBalance,
                'ending_balance' => $balance,
                'net_pnl' => $balance - $startingBalance,
                'return_on_staked' => $totalStaked > 0 ? ($balance - $startingBalance) / $totalStaked : null,
                'total_staked' => $totalStaked,
                'maximum_drawdown' => $maximumDrawdown,
                'profit_factor' => $grossLoss > 0 ? $grossProfit / $grossLoss : ($grossProfit > 0 ? null : 0),
                'model_brier' => $modelBrierSum / $analyzed,
                'market_brier' => $marketBrierSum / $analyzed,
            ],
            'equity_curve' => $equity,
            'markets' => array_reverse(array_slice(array_reverse($rows), 0, 100)),
            'assumptions' => [
                'The resolved outcome and Chainlink opening target come from Polymarket closed-event metadata.',
                'Binance BTC/USDT one-second changes approximate the unavailable historical Chainlink intrainterval path.',
                'The entry midpoint is the latest public trade at or before the decision time.',
                'The executable ask is approximated as midpoint plus half of the assumed spread.',
                'Crypto taker fees and configured slippage are deducted from every simulated entry.',
                'This replay does not reconstruct historical order-book depth, partial fills, latency, or queue position.',
            ],
        ];

        $result['run_id'] = $this->saveRun($result);
        return $result;
    }

    public function latest(): ?array
    {
        $row = $this->database->query(
            'SELECT id, result_json FROM backtest_runs ORDER BY id DESC LIMIT 1'
        )->fetch();
        if (!$row) {
            return null;
        }
        $result = json_decode((string) $row['result_json'], true);
        if (!is_array($result)) {
            return null;
        }
        $result['run_id'] ??= (int) $row['id'];
        return $result;
    }

    private function initializeSchema(): void
    {
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS backtest_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at INTEGER NOT NULL,
                parameters_json TEXT NOT NULL,
                result_json TEXT NOT NULL
            )'
        );
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS historical_market_cache (
                slug TEXT PRIMARY KEY,
                condition_id TEXT NOT NULL,
                event_json TEXT NOT NULL,
                trades_json TEXT NOT NULL,
                cached_at INTEGER NOT NULL
            )'
        );
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS historical_btc_ticks (
                timestamp INTEGER PRIMARY KEY,
                price REAL NOT NULL
            )'
        );
    }

    private function fetchClosedEvents(int $limit): array
    {
        $query = http_build_query([
            'series_id' => 10684,
            'closed' => 'true',
            'limit' => $limit,
            'order' => 'endDate',
            'ascending' => 'false',
        ]);
        $events = $this->getJson($this->api['gamma_base_url'] . '/events?' . $query);

        return array_values(array_filter($events, static function (mixed $event): bool {
            return is_array($event)
                && str_starts_with((string) ($event['slug'] ?? ''), 'btc-updown-5m-')
                && is_array($event['markets'][0] ?? null)
                && isset($event['eventMetadata']['priceToBeat']);
        }));
    }

    private function marketTrades(array $events): array
    {
        $result = [];
        $missing = [];

        foreach ($events as $event) {
            $market = (array) ($event['markets'][0] ?? []);
            $conditionId = (string) ($market['conditionId'] ?? '');
            $slug = (string) ($event['slug'] ?? '');
            if ($conditionId === '' || $slug === '') {
                continue;
            }

            $statement = $this->database->prepare(
                'SELECT trades_json FROM historical_market_cache WHERE slug = :slug'
            );
            $statement->execute([':slug' => $slug]);
            $cached = $statement->fetchColumn();
            if ($cached !== false) {
                $decoded = json_decode((string) $cached, true);
                $result[$conditionId] = is_array($decoded) ? $decoded : [];
            } else {
                $missing[$conditionId] = $event;
            }
        }

        if ($missing !== []) {
            $urls = [];
            foreach ($missing as $conditionId => $event) {
                $urls[$conditionId] = $this->api['data_base_url'] . '/trades?' . http_build_query([
                    'market' => $conditionId,
                    'limit' => 1000,
                    'takerOnly' => 'false',
                    'filterType' => 'CASH',
                    'filterAmount' => 5,
                ]);
            }

            $responses = $this->getJsonMany($urls);
            $statement = $this->database->prepare(
                'INSERT OR REPLACE INTO historical_market_cache (
                    slug, condition_id, event_json, trades_json, cached_at
                 ) VALUES (
                    :slug, :condition_id, :event_json, :trades_json, :cached_at
                 )'
            );

            foreach ($missing as $conditionId => $event) {
                $trades = is_array($responses[$conditionId] ?? null)
                    ? $responses[$conditionId]
                    : [];
                $result[$conditionId] = $trades;
                $statement->execute([
                    ':slug' => $event['slug'],
                    ':condition_id' => $conditionId,
                    ':event_json' => json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    ':trades_json' => json_encode($trades, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    ':cached_at' => time(),
                ]);
            }
        }

        return $result;
    }

    private function ensureBinanceTicks(int $start, int $end): void
    {
        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM historical_btc_ticks WHERE timestamp BETWEEN :start AND :end'
        );
        $statement->execute([':start' => $start, ':end' => $end]);
        $expected = max(1, $end - $start);
        if ((int) $statement->fetchColumn() >= (int) ($expected * 0.9)) {
            return;
        }

        $cursor = $start * 1000;
        $endMilliseconds = $end * 1000;
        $insert = $this->database->prepare(
            'INSERT OR REPLACE INTO historical_btc_ticks (timestamp, price)
             VALUES (:timestamp, :price)'
        );

        while ($cursor <= $endMilliseconds) {
            $query = http_build_query([
                'symbol' => 'BTCUSDT',
                'interval' => '1s',
                'startTime' => $cursor,
                'endTime' => $endMilliseconds,
                'limit' => 1000,
            ]);
            $rows = $this->getJson($this->api['binance_base_url'] . '/api/v3/klines?' . $query);
            if ($rows === []) {
                break;
            }

            $this->database->beginTransaction();
            try {
                foreach ($rows as $row) {
                    if (!is_array($row) || !isset($row[0], $row[4])) {
                        continue;
                    }
                    $insert->execute([
                        ':timestamp' => (int) floor(((int) $row[0]) / 1000),
                        ':price' => (float) $row[4],
                    ]);
                }
                $this->database->commit();
            } catch (Throwable $exception) {
                $this->database->rollBack();
                throw $exception;
            }

            $last = $rows[count($rows) - 1];
            $next = ((int) $last[0]) + 1000;
            if ($next <= $cursor) {
                break;
            }
            $cursor = $next;
        }
    }

    private function loadTicks(int $start, int $end): array
    {
        $statement = $this->database->prepare(
            'SELECT timestamp, price FROM historical_btc_ticks
             WHERE timestamp BETWEEN :start AND :end
             ORDER BY timestamp ASC'
        );
        $statement->execute([':start' => $start, ':end' => $end]);
        $ticks = [];
        foreach ($statement->fetchAll() as $row) {
            $ticks[(int) $row['timestamp']] = (float) $row['price'];
        }
        return $ticks;
    }

    private function tickAtOrBefore(array $ticks, int $timestamp): ?float
    {
        for ($offset = 0; $offset <= 5; $offset++) {
            if (isset($ticks[$timestamp - $offset])) {
                return (float) $ticks[$timestamp - $offset];
            }
        }
        return null;
    }

    private function tickVolatility(array $ticks, int $timestamp, int $lookback): ?float
    {
        $sumSquares = 0.0;
        $count = 0;
        $previous = null;

        for ($second = $timestamp - $lookback; $second <= $timestamp; $second++) {
            if (!isset($ticks[$second])) {
                continue;
            }
            $price = (float) $ticks[$second];
            if ($previous !== null) {
                $change = $price - $previous;
                $sumSquares += $change * $change;
                $count++;
            }
            $previous = $price;
        }

        return $count >= 30 ? sqrt($sumSquares / $count) : null;
    }

    private function lastTradeAtOrBefore(array $trades, int $timestamp, int $maximumAge): ?array
    {
        $best = null;
        $bestTimestamp = 0;

        foreach ($trades as $trade) {
            if (!is_array($trade)) {
                continue;
            }
            $tradeTimestamp = (int) ($trade['timestamp'] ?? 0);
            if (
                $tradeTimestamp <= $timestamp
                && $tradeTimestamp >= $timestamp - $maximumAge
                && $tradeTimestamp >= $bestTimestamp
            ) {
                $best = $trade;
                $bestTimestamp = $tradeTimestamp;
            }
        }

        return $best;
    }

    private function impliedUpPrice(array $trade): ?float
    {
        $price = $this->numberOrNull($trade['price'] ?? null);
        if ($price === null) {
            return null;
        }
        return strcasecmp((string) ($trade['outcome'] ?? ''), 'Up') === 0
            ? $price
            : 1 - $price;
    }

    private function resolvedOutcome(array $market): ?string
    {
        $outcomes = $market['outcomes'] ?? [];
        $prices = $market['outcomePrices'] ?? [];
        if (is_string($outcomes)) {
            $outcomes = json_decode($outcomes, true) ?: [];
        }
        if (is_string($prices)) {
            $prices = json_decode($prices, true) ?: [];
        }
        foreach ((array) $prices as $index => $price) {
            if ((float) $price >= 0.99 && isset($outcomes[$index])) {
                return (string) $outcomes[$index];
            }
        }
        return null;
    }

    private function saveRun(array $result): int
    {
        $statement = $this->database->prepare(
            'INSERT INTO backtest_runs (created_at, parameters_json, result_json)
             VALUES (:created_at, :parameters, :result)'
        );
        $statement->execute([
            ':created_at' => time(),
            ':parameters' => json_encode($result['parameters'], JSON_THROW_ON_ERROR),
            ':result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
        return (int) $this->database->lastInsertId();
    }

    private function getJson(string $url): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize a historical-data request.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'PolymarketBacktestLab/1.0',
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false || $error !== '') {
            throw new RuntimeException('Historical-data request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Historical-data source returned HTTP %d.', $status));
        }
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    private function getJsonMany(array $urls): array
    {
        $results = [];
        foreach (array_chunk($urls, 8, true) as $chunk) {
            $multi = curl_multi_init();
            $handles = [];

            foreach ($chunk as $key => $url) {
                $handle = curl_init($url);
                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_CONNECTTIMEOUT => 8,
                    CURLOPT_TIMEOUT => 35,
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                    CURLOPT_USERAGENT => 'PolymarketBacktestLab/1.0',
                ]);
                curl_multi_add_handle($multi, $handle);
                $handles[(string) $key] = $handle;
            }

            do {
                $status = curl_multi_exec($multi, $running);
                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running > 0 && $status === CURLM_OK);

            foreach ($handles as $key => $handle) {
                $body = curl_multi_getcontent($handle);
                $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $decoded = $httpStatus >= 200 && $httpStatus < 300
                    ? json_decode($body, true)
                    : [];
                $results[$key] = is_array($decoded) ? $decoded : [];
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }
        return $results;
    }

    private static function eventStart(array $event): int
    {
        if (preg_match('/-(\d{10})$/', (string) ($event['slug'] ?? ''), $matches) === 1) {
            return (int) $matches[1];
        }
        return strtotime((string) ($event['startTime'] ?? '')) ?: 0;
    }

    private function normalCdf(float $value): float
    {
        $absolute = abs($value);
        $t = 1 / (1 + 0.2316419 * $absolute);
        $density = 0.3989422804014327 * exp(-0.5 * $absolute * $absolute);
        $probability = 1 - $density * (
            0.319381530 * $t
            - 0.356563782 * ($t ** 2)
            + 1.781477937 * ($t ** 3)
            - 1.821255978 * ($t ** 4)
            + 1.330274429 * ($t ** 5)
        );
        return $value >= 0 ? $probability : 1 - $probability;
    }

    private function allowedInteger(mixed $value, array $allowed, int $fallback): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return $integer !== false && in_array($integer, $allowed, true) ? $integer : $fallback;
    }

    private function boundedNumber(mixed $value, float $minimum, float $maximum, float $fallback): float
    {
        if (!is_numeric($value)) {
            return $fallback;
        }
        return $this->clamp((float) $value, $minimum, $maximum);
    }

    private function numberOrNull(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private function clamp(float $value, float $minimum, float $maximum): float
    {
        return max($minimum, min($maximum, $value));
    }
}
