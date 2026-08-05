<?php

declare(strict_types=1);

final class WalletPaperFollower
{
    private PDO $database;
    private string $dataBaseUrl;
    private string $clobBaseUrl;
    private string $gammaBaseUrl;
    private DateTimeZone $timezone;
    private float $maximumStake;

    public function __construct(array $config, string $databasePath)
    {
        $this->dataBaseUrl = rtrim((string) ($config['polymarket']['data_base_url'] ?? 'https://data-api.polymarket.com'), '/');
        $this->clobBaseUrl = rtrim((string) ($config['polymarket']['clob_base_url'] ?? 'https://clob.polymarket.com'), '/');
        $this->gammaBaseUrl = rtrim((string) ($config['polymarket']['gamma_base_url'] ?? 'https://gamma-api.polymarket.com'), '/');
        $this->timezone = new DateTimeZone((string) ($config['app']['timezone'] ?? 'UTC'));
        $this->maximumStake = max(1.0, (float) ($config['paper_trading']['max_position_usd'] ?? 5.0));
        $this->database = new PDO('sqlite:' . $databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->database->exec('PRAGMA journal_mode = WAL');
        $this->initializeSchema();
    }

    public function start(string $wallet, float $stake): array
    {
        $wallet = strtolower(trim($wallet));
        if (preg_match('/^0x[a-f0-9]{40}$/', $wallet) !== 1) {
            throw new InvalidArgumentException('Enter a valid 0x public wallet address.');
        }
        $stake = max(1.0, min($this->maximumStake, $stake));
        $now = time();
        $date = (new DateTimeImmutable('@' . $now))->setTimezone($this->timezone)->format('Y-m-d');

        $this->database->beginTransaction();
        try {
            $this->database->prepare(
                "UPDATE wallet_follow_sessions SET status = 'STOPPED', stopped_at = :now
                 WHERE status = 'ACTIVE'"
            )->execute([':now' => $now]);
            $statement = $this->database->prepare(
                'INSERT INTO wallet_follow_sessions
                 (wallet, session_date, timezone, stake, status, started_at, last_polled_at)
                 VALUES (:wallet, :session_date, :timezone, :stake, :status, :started_at, NULL)'
            );
            $statement->execute([
                ':wallet' => $wallet,
                ':session_date' => $date,
                ':timezone' => $this->timezone->getName(),
                ':stake' => $stake,
                ':status' => 'ACTIVE',
                ':started_at' => $now,
            ]);
            $sessionId = (int) $this->database->lastInsertId();
            $this->database->commit();
        } catch (Throwable $exception) {
            $this->database->rollBack();
            throw $exception;
        }

        return $this->status($sessionId);
    }

    public function stop(int $sessionId): array
    {
        $this->settleExpiredPositions($sessionId, time());
        $this->liquidateOpenPositions($sessionId, 'MANUAL_STOP', time());
        $this->database->prepare(
            "UPDATE wallet_follow_sessions SET status = 'STOPPED', stopped_at = :now
             WHERE id = :id AND status = 'ACTIVE'"
        )->execute([':now' => time(), ':id' => $sessionId]);
        return $this->status($sessionId);
    }

    public function poll(int $sessionId): array
    {
        $session = $this->session($sessionId);
        if ($session === []) {
            throw new RuntimeException('Paper-follow session not found.');
        }
        $now = time();
        $today = (new DateTimeImmutable('@' . $now))->setTimezone($this->timezone)->format('Y-m-d');
        if ($session['status'] !== 'ACTIVE' || $session['session_date'] !== $today) {
            if ($session['status'] === 'ACTIVE') {
                $this->settleExpiredPositions($sessionId, $now);
                $this->liquidateOpenPositions($sessionId, 'DAY_END', $now);
                $this->database->prepare(
                    "UPDATE wallet_follow_sessions SET status = 'COMPLETED', stopped_at = :now WHERE id = :id"
                )->execute([':now' => $now, ':id' => $sessionId]);
            }
            return $this->status($sessionId);
        }

        $start = max((int) $session['started_at'], (int) ($session['last_polled_at'] ?? 0) - 120);
        $activity = [];
        for ($offset = 0; $offset < 2000; $offset += 500) {
            $page = $this->getJson($this->dataBaseUrl . '/activity?' . http_build_query([
                'user' => $session['wallet'],
                'type' => 'TRADE',
                'start' => $start,
                'end' => $now,
                'limit' => 500,
                'offset' => $offset,
                'sortBy' => 'TIMESTAMP',
                'sortDirection' => 'ASC',
            ]));
            $activity = array_merge($activity, $page);
            if (count($page) < 500) {
                break;
            }
        }

        foreach ($activity as $trade) {
            if (!is_array($trade) || preg_match('/^btc-updown-5m-(\d+)$/', (string) ($trade['slug'] ?? ''), $matches) !== 1) {
                continue;
            }
            $hash = strtolower((string) ($trade['transactionHash'] ?? ''));
            $asset = (string) ($trade['asset'] ?? '');
            $conditionId = (string) ($trade['conditionId'] ?? '');
            if ($hash === '' || $asset === '' || $conditionId === '' || (int) ($trade['timestamp'] ?? 0) < (int) $session['started_at']) {
                continue;
            }
            if (!$this->markSeen($sessionId, $hash, $asset, (int) ($trade['timestamp'] ?? 0))) {
                continue;
            }

            $side = strtoupper((string) ($trade['side'] ?? ''));
            if ($side === 'BUY') {
                $settlesAt = (int) $matches[1] + 300;
                if ($now >= $settlesAt || $this->positionExists($sessionId, $conditionId, $asset)) {
                    continue;
                }
                $sourcePrice = $this->number($trade['price'] ?? null);
                $executionPrice = $this->executablePrice($asset, 'BUY', $sourcePrice);
                if ($executionPrice <= 0 || $executionPrice >= 1) {
                    continue;
                }
                $this->openPosition($session, $trade, $executionPrice, $now);
            } elseif ($side === 'SELL') {
                $position = $this->openPositionRecord($sessionId, $conditionId, $asset);
                if ($position !== []) {
                    $price = $this->executablePrice($asset, 'SELL', $this->number($trade['price'] ?? null));
                    $this->closePosition((int) $position['id'], $price, 'SOURCE_SOLD', $now);
                }
            }
        }

        $this->settleExpiredPositions($sessionId, $now);
        $this->database->prepare(
            'UPDATE wallet_follow_sessions SET last_polled_at = :now, last_error = NULL WHERE id = :id'
        )->execute([':now' => $now, ':id' => $sessionId]);
        return $this->status($sessionId);
    }

    public function latestStatus(): array
    {
        $id = $this->database->query('SELECT id FROM wallet_follow_sessions ORDER BY id DESC LIMIT 1')->fetchColumn();
        if ($id === false) {
            return $this->emptyStatus();
        }
        $session = $this->session((int) $id);
        $today = (new DateTimeImmutable('now', $this->timezone))->format('Y-m-d');
        if (($session['status'] ?? '') === 'ACTIVE' && ($session['session_date'] ?? '') !== $today) {
            return $this->poll((int) $id);
        }
        return $this->status((int) $id);
    }

    public function history(): array
    {
        $statement = $this->database->query(
            "SELECT s.wallet, s.session_date, s.timezone,
                    MIN(s.started_at) AS started_at,
                    MAX(COALESCE(s.stopped_at, s.last_polled_at, s.started_at)) AS ended_at,
                    COUNT(DISTINCT s.id) AS session_count,
                    CASE WHEN MAX(CASE WHEN s.status = 'ACTIVE' THEN 1 ELSE 0 END) = 1
                         THEN 'ACTIVE' ELSE 'COMPLETED' END AS status,
                    COUNT(p.id) AS positions,
                    SUM(CASE WHEN p.status = 'OPEN' THEN 1 ELSE 0 END) AS open_positions,
                    SUM(CASE WHEN p.status = 'CLOSED' THEN 1 ELSE 0 END) AS closed_positions,
                    SUM(CASE WHEN p.status = 'CLOSED' AND p.pnl > 0 THEN 1 ELSE 0 END) AS wins,
                    SUM(CASE WHEN p.status = 'CLOSED' AND p.pnl < 0 THEN 1 ELSE 0 END) AS losses,
                    COALESCE(SUM(CASE WHEN p.status = 'CLOSED' THEN p.pnl ELSE 0 END), 0) AS realized_pnl
             FROM wallet_follow_sessions s
             LEFT JOIN wallet_follow_positions p ON p.session_id = s.id
             GROUP BY s.wallet, s.session_date, s.timezone
             ORDER BY s.session_date DESC, started_at DESC
             LIMIT 180"
        );
        $days = array_map(function (array $row): array {
            $closed = (int) $row['closed_positions'];
            $wins = (int) $row['wins'];
            return [
                'wallet' => $row['wallet'],
                'date' => $row['session_date'],
                'timezone' => $row['timezone'],
                'started_at' => date(DATE_ATOM, (int) $row['started_at']),
                'ended_at' => date(DATE_ATOM, (int) $row['ended_at']),
                'session_count' => (int) $row['session_count'],
                'status' => $row['status'],
                'positions' => (int) $row['positions'],
                'open' => (int) $row['open_positions'],
                'closed' => $closed,
                'wins' => $wins,
                'losses' => (int) $row['losses'],
                'win_rate' => $closed > 0 ? round(($wins / $closed) * 100, 1) : null,
                'realized_pnl' => round((float) $row['realized_pnl'], 4),
            ];
        }, $statement->fetchAll());
        return ['ok' => true, 'days' => $days, 'paper_only' => true];
    }

    public function dayStatus(string $wallet, string $date): array
    {
        $wallet = strtolower(trim($wallet));
        if (preg_match('/^0x[a-f0-9]{40}$/', $wallet) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException('Invalid wallet history selection.');
        }
        $statement = $this->database->prepare(
            'SELECT * FROM wallet_follow_sessions
             WHERE wallet = :wallet AND session_date = :session_date
             ORDER BY started_at ASC, id ASC'
        );
        $statement->execute([':wallet' => $wallet, ':session_date' => $date]);
        $sessions = $statement->fetchAll();
        if ($sessions === []) {
            throw new RuntimeException('Saved wallet day not found.');
        }
        $sessionIds = array_map(static fn (array $row): int => (int) $row['id'], $sessions);
        $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
        $positionStatement = $this->database->prepare(
            "SELECT * FROM wallet_follow_positions
             WHERE session_id IN ($placeholders)
             ORDER BY opened_at DESC, id DESC"
        );
        $positionStatement->execute($sessionIds);
        $positions = $positionStatement->fetchAll();
        $closed = array_values(array_filter($positions, static fn (array $row): bool => $row['status'] === 'CLOSED'));
        $wins = count(array_filter($closed, static fn (array $row): bool => (float) ($row['pnl'] ?? 0) > 0));
        $losses = count(array_filter($closed, static fn (array $row): bool => (float) ($row['pnl'] ?? 0) < 0));
        $active = array_values(array_filter($sessions, static fn (array $row): bool => $row['status'] === 'ACTIVE'));
        $selected = $active !== [] ? end($active) : end($sessions);
        $startedAt = min(array_map(static fn (array $row): int => (int) $row['started_at'], $sessions));
        $endedAt = max(array_map(static fn (array $row): int => (int) ($row['stopped_at'] ?? $row['last_polled_at'] ?? $row['started_at']), $sessions));
        $realizedPnl = array_sum(array_map(static fn (array $row): float => (float) ($row['pnl'] ?? 0), $closed));

        return [
            'ok' => true,
            'session' => [
                'id' => (int) $selected['id'],
                'wallet' => $wallet,
                'date' => $date,
                'timezone' => $selected['timezone'],
                'stake' => (float) $selected['stake'],
                'maximum_stake' => $this->maximumStake,
                'status' => $active !== [] ? 'ACTIVE' : 'COMPLETED',
                'started_at' => date(DATE_ATOM, $startedAt),
                'stopped_at' => date(DATE_ATOM, $endedAt),
                'last_polled_at' => $selected['last_polled_at'] ? date(DATE_ATOM, (int) $selected['last_polled_at']) : null,
                'session_count' => count($sessions),
            ],
            'summary' => [
                'positions' => count($positions),
                'open' => count($positions) - count($closed),
                'closed' => count($closed),
                'wins' => $wins,
                'losses' => $losses,
                'win_rate' => count($closed) > 0 ? round(($wins / count($closed)) * 100, 1) : null,
                'realized_pnl' => round($realizedPnl, 4),
            ],
            'positions' => array_map(fn (array $row): array => $this->formatPosition($row), $positions),
            'paper_only' => true,
            'history_view' => true,
        ];
    }

    public function status(int $sessionId): array
    {
        $session = $this->session($sessionId);
        if ($session === []) {
            return $this->emptyStatus();
        }
        $statement = $this->database->prepare(
            'SELECT * FROM wallet_follow_positions WHERE session_id = :session_id ORDER BY opened_at DESC, id DESC'
        );
        $statement->execute([':session_id' => $sessionId]);
        $positions = $statement->fetchAll();
        $closed = array_values(array_filter($positions, static fn (array $row): bool => $row['status'] === 'CLOSED'));
        $realizedPnl = array_sum(array_map(static fn (array $row): float => (float) ($row['pnl'] ?? 0), $closed));
        $wins = count(array_filter($closed, static fn (array $row): bool => (float) ($row['pnl'] ?? 0) > 0));
        $losses = count(array_filter($closed, static fn (array $row): bool => (float) ($row['pnl'] ?? 0) < 0));

        return [
            'ok' => true,
            'session' => [
                'id' => (int) $session['id'],
                'wallet' => $session['wallet'],
                'date' => $session['session_date'],
                'timezone' => $session['timezone'],
                'stake' => (float) $session['stake'],
                'maximum_stake' => $this->maximumStake,
                'status' => $session['status'],
                'started_at' => date(DATE_ATOM, (int) $session['started_at']),
                'stopped_at' => $session['stopped_at'] ? date(DATE_ATOM, (int) $session['stopped_at']) : null,
                'last_polled_at' => $session['last_polled_at'] ? date(DATE_ATOM, (int) $session['last_polled_at']) : null,
            ],
            'summary' => [
                'positions' => count($positions),
                'open' => count($positions) - count($closed),
                'closed' => count($closed),
                'wins' => $wins,
                'losses' => $losses,
                'win_rate' => count($closed) > 0 ? round(($wins / count($closed)) * 100, 1) : null,
                'realized_pnl' => round($realizedPnl, 4),
            ],
            'positions' => array_map(fn (array $row): array => $this->formatPosition($row), $positions),
            'paper_only' => true,
            'note' => 'One paper position per BTC 5-minute market/outcome. Entry uses the current public ask when available; the reported source fill is only a reference.',
        ];
    }

    private function initializeSchema(): void
    {
        $this->database->exec(
            "CREATE TABLE IF NOT EXISTS wallet_follow_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                wallet TEXT NOT NULL,
                session_date TEXT NOT NULL,
                timezone TEXT NOT NULL,
                stake REAL NOT NULL,
                status TEXT NOT NULL,
                started_at INTEGER NOT NULL,
                stopped_at INTEGER,
                last_polled_at INTEGER,
                last_error TEXT
            );
            CREATE TABLE IF NOT EXISTS wallet_follow_seen (
                session_id INTEGER NOT NULL,
                transaction_hash TEXT NOT NULL,
                asset TEXT NOT NULL,
                source_timestamp INTEGER NOT NULL,
                PRIMARY KEY (session_id, transaction_hash, asset)
            );
            CREATE TABLE IF NOT EXISTS wallet_follow_positions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id INTEGER NOT NULL,
                condition_id TEXT NOT NULL,
                asset TEXT NOT NULL,
                slug TEXT NOT NULL,
                title TEXT NOT NULL,
                outcome TEXT NOT NULL,
                source_transaction_hash TEXT NOT NULL,
                source_timestamp INTEGER NOT NULL,
                source_price REAL NOT NULL,
                execution_price REAL NOT NULL,
                stake REAL NOT NULL,
                shares REAL NOT NULL,
                status TEXT NOT NULL,
                opened_at INTEGER NOT NULL,
                closed_at INTEGER,
                close_price REAL,
                close_reason TEXT,
                pnl REAL,
                UNIQUE (session_id, condition_id, asset)
            );"
        );
    }

    private function markSeen(int $sessionId, string $hash, string $asset, int $timestamp): bool
    {
        $statement = $this->database->prepare(
            'INSERT OR IGNORE INTO wallet_follow_seen (session_id, transaction_hash, asset, source_timestamp)
             VALUES (:session_id, :hash, :asset, :timestamp)'
        );
        $statement->execute([':session_id' => $sessionId, ':hash' => $hash, ':asset' => $asset, ':timestamp' => $timestamp]);
        return $statement->rowCount() > 0;
    }

    private function positionExists(int $sessionId, string $conditionId, string $asset): bool
    {
        $statement = $this->database->prepare(
            'SELECT 1 FROM wallet_follow_positions WHERE session_id = :session_id AND condition_id = :condition_id AND asset = :asset'
        );
        $statement->execute([':session_id' => $sessionId, ':condition_id' => $conditionId, ':asset' => $asset]);
        return $statement->fetchColumn() !== false;
    }

    private function openPosition(array $session, array $trade, float $price, int $now): void
    {
        $stake = (float) $session['stake'];
        $statement = $this->database->prepare(
            "INSERT OR IGNORE INTO wallet_follow_positions
             (session_id, condition_id, asset, slug, title, outcome, source_transaction_hash,
              source_timestamp, source_price, execution_price, stake, shares, status, opened_at)
             VALUES (:session_id, :condition_id, :asset, :slug, :title, :outcome, :hash,
                     :source_timestamp, :source_price, :execution_price, :stake, :shares, 'OPEN', :opened_at)"
        );
        $statement->execute([
            ':session_id' => (int) $session['id'],
            ':condition_id' => (string) $trade['conditionId'],
            ':asset' => (string) $trade['asset'],
            ':slug' => (string) $trade['slug'],
            ':title' => (string) ($trade['title'] ?? ''),
            ':outcome' => (string) ($trade['outcome'] ?? ''),
            ':hash' => strtolower((string) $trade['transactionHash']),
            ':source_timestamp' => (int) $trade['timestamp'],
            ':source_price' => $this->number($trade['price'] ?? null),
            ':execution_price' => $price,
            ':stake' => $stake,
            ':shares' => $stake / $price,
            ':opened_at' => $now,
        ]);
    }

    private function openPositionRecord(int $sessionId, string $conditionId, string $asset): array
    {
        $statement = $this->database->prepare(
            "SELECT * FROM wallet_follow_positions
             WHERE session_id = :session_id AND condition_id = :condition_id AND asset = :asset AND status = 'OPEN'"
        );
        $statement->execute([':session_id' => $sessionId, ':condition_id' => $conditionId, ':asset' => $asset]);
        return $statement->fetch() ?: [];
    }

    private function settleExpiredPositions(int $sessionId, int $now): void
    {
        $statement = $this->database->prepare(
            "SELECT * FROM wallet_follow_positions WHERE session_id = :session_id AND status = 'OPEN'"
        );
        $statement->execute([':session_id' => $sessionId]);
        foreach ($statement->fetchAll() as $position) {
            if (preg_match('/^btc-updown-5m-(\d+)$/', $position['slug'], $matches) !== 1 || $now < (int) $matches[1] + 305) {
                continue;
            }
            try {
                $market = $this->getJson($this->gammaBaseUrl . '/markets/slug/' . rawurlencode($position['slug']));
                $outcomes = $this->decodeList($market['outcomes'] ?? []);
                $prices = $this->decodeList($market['outcomePrices'] ?? []);
                $index = array_search($position['outcome'], $outcomes, true);
                if (($market['closed'] ?? false) !== true || $index === false || !isset($prices[$index])) {
                    continue;
                }
                $this->closePosition((int) $position['id'], (float) $prices[$index], 'SETTLED', $now);
            } catch (Throwable) {
                continue;
            }
        }
    }

    private function closePosition(int $positionId, float $price, string $reason, int $now): void
    {
        $statement = $this->database->prepare('SELECT * FROM wallet_follow_positions WHERE id = :id');
        $statement->execute([':id' => $positionId]);
        $position = $statement->fetch();
        if (!$position || $position['status'] !== 'OPEN') {
            return;
        }
        $pnl = ((float) $position['shares'] * $price) - (float) $position['stake'];
        $this->database->prepare(
            "UPDATE wallet_follow_positions
             SET status = 'CLOSED', closed_at = :closed_at, close_price = :close_price,
                 close_reason = :close_reason, pnl = :pnl WHERE id = :id"
        )->execute([
            ':closed_at' => $now,
            ':close_price' => $price,
            ':close_reason' => $reason,
            ':pnl' => $pnl,
            ':id' => $positionId,
        ]);
    }

    private function liquidateOpenPositions(int $sessionId, string $reason, int $now): void
    {
        $statement = $this->database->prepare(
            "SELECT * FROM wallet_follow_positions WHERE session_id = :session_id AND status = 'OPEN'"
        );
        $statement->execute([':session_id' => $sessionId]);
        foreach ($statement->fetchAll() as $position) {
            $price = $this->executablePrice(
                (string) $position['asset'],
                'SELL',
                (float) $position['execution_price']
            );
            $this->closePosition((int) $position['id'], $price, $reason, $now);
        }
    }

    private function executablePrice(string $asset, string $side, float $fallback): float
    {
        try {
            $book = $this->getJson($this->clobBaseUrl . '/book?' . http_build_query(['token_id' => $asset]));
            $levels = (array) ($book[$side === 'BUY' ? 'asks' : 'bids'] ?? []);
            $prices = [];
            foreach ($levels as $level) {
                if (is_array($level) && isset($level['price']) && is_numeric($level['price'])) {
                    $prices[] = (float) $level['price'];
                }
            }
            if ($prices !== []) {
                return $side === 'BUY' ? min($prices) : max($prices);
            }
        } catch (Throwable) {
        }
        return $fallback;
    }

    private function formatPosition(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'slug' => $row['slug'],
            'outcome' => $row['outcome'],
            'source_timestamp' => date(DATE_ATOM, (int) $row['source_timestamp']),
            'observed_at' => date(DATE_ATOM, (int) $row['opened_at']),
            'source_price' => (float) $row['source_price'],
            'execution_price' => (float) $row['execution_price'],
            'stake' => (float) $row['stake'],
            'shares' => (float) $row['shares'],
            'status' => $row['status'],
            'close_price' => $row['close_price'] === null ? null : (float) $row['close_price'],
            'close_reason' => $row['close_reason'],
            'pnl' => $row['pnl'] === null ? null : (float) $row['pnl'],
            'transaction_hash' => $row['source_transaction_hash'],
        ];
    }

    private function session(int $sessionId): array
    {
        $statement = $this->database->prepare('SELECT * FROM wallet_follow_sessions WHERE id = :id');
        $statement->execute([':id' => $sessionId]);
        return $statement->fetch() ?: [];
    }

    private function emptyStatus(): array
    {
        return [
            'ok' => true,
            'session' => null,
            'summary' => ['positions' => 0, 'open' => 0, 'closed' => 0, 'wins' => 0, 'losses' => 0, 'win_rate' => null, 'realized_pnl' => 0],
            'positions' => [],
            'paper_only' => true,
            'maximum_stake' => $this->maximumStake,
        ];
    }

    private function getJson(string $url): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize public-data request.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'PolymarketPaperTrader/0.4',
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($body) || $error !== '' || $status < 200 || $status >= 300) {
            throw new RuntimeException($error !== '' ? $error : 'Public data returned HTTP ' . $status . '.');
        }
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? array_values($decoded) : [];
        }
        return [];
    }

    private function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
