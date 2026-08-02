<?php

declare(strict_types=1);

final class PaperModel
{
    private PDO $database;
    private array $settings;

    public function __construct(array $settings, string $projectRoot)
    {
        $this->settings = array_replace([
            'enabled' => true,
            'starting_balance' => 1000.00,
            'max_position_usd' => 5.00,
            'daily_loss_limit_usd' => 10.00,
            'minimum_edge' => 0.05,
            'assumed_slippage' => 0.01,
            'maximum_spread' => 0.03,
            'decision_window_seconds' => 60,
            'minimum_seconds_remaining' => 15,
        ], $settings);

        $databasePath = (string) ($settings['model_database']
            ?? ($projectRoot . '/storage/paper_trader.sqlite'));
        $directory = dirname($databasePath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the paper-model storage directory.');
        }

        $this->database = new PDO('sqlite:' . $databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->database->exec('PRAGMA journal_mode = WAL');
        $this->database->exec('PRAGMA busy_timeout = 5000');
        $this->database->exec('PRAGMA foreign_keys = ON');
        $this->initializeSchema();
        $this->backfillEarlyExitShadow();
        $this->backfillExit10Shadow();
    }

    public function observe(array $payload): array
    {
        $market = is_array($payload['market'] ?? null) ? $payload['market'] : [];
        $feeds = is_array($payload['feeds'] ?? null) ? $payload['feeds'] : [];

        $marketId = trim((string) ($market['id'] ?? ''));
        $slug = trim((string) ($market['slug'] ?? ''));
        $question = trim((string) ($market['question'] ?? ''));
        $intervalStart = strtotime((string) ($market['interval_start'] ?? ''));
        $intervalEnd = strtotime((string) ($market['end_date'] ?? ''));
        $chainlinkPrice = $this->numberOrNull($feeds['chainlink_price'] ?? null);
        $binancePrice = $this->numberOrNull($feeds['binance_price'] ?? null);
        $feedTimestamp = $this->numberOrNull($feeds['chainlink_timestamp'] ?? null);

        if (
            $marketId === ''
            || $slug === ''
            || $intervalStart === false
            || $intervalEnd === false
            || $chainlinkPrice === null
            || $chainlinkPrice <= 0
        ) {
            throw new InvalidArgumentException('A current market and a valid Chainlink price are required.');
        }

        $observedAt = $feedTimestamp === null
            ? time()
            : (int) ($feedTimestamp > 10_000_000_000 ? floor($feedTimestamp / 1000) : $feedTimestamp);

        if (abs(time() - $observedAt) > 30) {
            throw new InvalidArgumentException('The Chainlink price feed is stale.');
        }

        $tokens = $this->indexTokens((array) ($market['tokens'] ?? []));
        $up = $tokens['up'] ?? [];
        $down = $tokens['down'] ?? [];
        $upBid = $this->numberOrNull($up['best_bid'] ?? null);
        $upAsk = $this->numberOrNull($up['best_ask'] ?? null);
        $downBid = $this->numberOrNull($down['best_bid'] ?? null);
        $downAsk = $this->numberOrNull($down['best_ask'] ?? null);
        $upBidSize = $this->numberOrNull($up['bid_size'] ?? null);
        $upAskSize = $this->numberOrNull($up['ask_size'] ?? null);
        $downBidSize = $this->numberOrNull($down['bid_size'] ?? null);
        $downAskSize = $this->numberOrNull($down['ask_size'] ?? null);
        $marketVolume = $this->numberOrNull($market['volume'] ?? null);
        $upSpread = $this->spread($upBid, $upAsk, $up['spread'] ?? null);
        $downSpread = $this->spread($downBid, $downAsk, $down['spread'] ?? null);
        $feeRate = $this->numberOrNull($market['fee_rate'] ?? null) ?? 0.07;
        $orderBookImbalanceUp = $this->orderBookImbalance($upBidSize, $upAskSize);

        $this->database->beginTransaction();

        try {
            $statement = $this->database->prepare(
                'INSERT INTO markets (
                    market_id, slug, question, interval_start, interval_end, created_at, updated_at
                 ) VALUES (
                    :market_id, :slug, :question, :interval_start, :interval_end, :now, :now
                 )
                 ON CONFLICT(market_id) DO UPDATE SET
                    slug = excluded.slug,
                    question = excluded.question,
                    interval_start = excluded.interval_start,
                    interval_end = excluded.interval_end,
                    updated_at = excluded.updated_at'
            );
            $statement->execute([
                ':market_id' => $marketId,
                ':slug' => $slug,
                ':question' => $question,
                ':interval_start' => $intervalStart,
                ':interval_end' => $intervalEnd,
                ':now' => time(),
            ]);

            $marketRecord = $this->marketRecord($marketId);
            $openPrice = $this->numberOrNull($marketRecord['open_price'] ?? null);
            $openTrusted = (bool) ($marketRecord['open_price_trusted'] ?? false);

            if ($openPrice === null) {
                $secondsFromStart = $observedAt - $intervalStart;
                $openPrice = $chainlinkPrice;
                $openTrusted = $secondsFromStart >= -2 && $secondsFromStart <= 12;

                $statement = $this->database->prepare(
                    'UPDATE markets
                     SET open_price = :open_price, open_price_trusted = :trusted, updated_at = :now
                     WHERE market_id = :market_id'
                );
                $statement->execute([
                    ':open_price' => $openPrice,
                    ':trusted' => $openTrusted ? 1 : 0,
                    ':now' => time(),
                    ':market_id' => $marketId,
                ]);
            }

            $secondsRemaining = $intervalEnd - $observedAt;
            $volatility = $this->estimateVolatility($observedAt, $chainlinkPrice);
            [$volumeChange30s, $volumePerSecond] = $this->volumeMomentum(
                $marketId,
                $observedAt,
                $marketVolume
            );
            $distance = $chainlinkPrice - $openPrice;
            $probabilityUp = null;

            if ($openTrusted && $volatility !== null && $secondsRemaining > 0) {
                $expectedMove = max(0.000001, $volatility * sqrt(max(1, $secondsRemaining)));
                $probabilityUp = $this->clamp(
                    $this->normalCdf($distance / $expectedMove),
                    0.01,
                    0.99
                );
            }

            $upFee = $upAsk === null ? null : $feeRate * $upAsk * (1 - $upAsk);
            $downFee = $downAsk === null ? null : $feeRate * $downAsk * (1 - $downAsk);
            $slippage = (float) $this->settings['assumed_slippage'];
            $upNetEdge = $probabilityUp === null || $upAsk === null || $upFee === null
                ? null
                : $probabilityUp - $upAsk - $upFee - $slippage;
            $downNetEdge = $probabilityUp === null || $downAsk === null || $downFee === null
                ? null
                : (1 - $probabilityUp) - $downAsk - $downFee - $slippage;

            [$decision, $reason, $recommendedOutcome, $recommendedEdge] = $this->decision(
                $secondsRemaining,
                $openTrusted,
                $volatility,
                $upSpread,
                $downSpread,
                $upNetEdge,
                $downNetEdge
            );

            $statement = $this->database->prepare(
                'INSERT OR IGNORE INTO observations (
                    market_id, observed_at, seconds_remaining, chainlink_price, binance_price,
                    distance, volatility_60s, up_bid, up_ask, down_bid, down_ask,
                    up_bid_size, up_ask_size, down_bid_size, down_ask_size,
                    market_volume, volume_change_30s, volume_per_second, order_book_imbalance_up,
                    model_probability_up, up_net_edge, down_net_edge, decision, decision_reason
                 ) VALUES (
                    :market_id, :observed_at, :seconds_remaining, :chainlink_price, :binance_price,
                    :distance, :volatility, :up_bid, :up_ask, :down_bid, :down_ask,
                    :up_bid_size, :up_ask_size, :down_bid_size, :down_ask_size,
                    :market_volume, :volume_change_30s, :volume_per_second, :order_book_imbalance_up,
                    :probability_up, :up_net_edge, :down_net_edge, :decision, :reason
                 )'
            );
            $statement->execute([
                ':market_id' => $marketId,
                ':observed_at' => $observedAt,
                ':seconds_remaining' => $secondsRemaining,
                ':chainlink_price' => $chainlinkPrice,
                ':binance_price' => $binancePrice,
                ':distance' => $distance,
                ':volatility' => $volatility,
                ':up_bid' => $upBid,
                ':up_ask' => $upAsk,
                ':down_bid' => $downBid,
                ':down_ask' => $downAsk,
                ':up_bid_size' => $upBidSize,
                ':up_ask_size' => $upAskSize,
                ':down_bid_size' => $downBidSize,
                ':down_ask_size' => $downAskSize,
                ':market_volume' => $marketVolume,
                ':volume_change_30s' => $volumeChange30s,
                ':volume_per_second' => $volumePerSecond,
                ':order_book_imbalance_up' => $orderBookImbalanceUp,
                ':probability_up' => $probabilityUp,
                ':up_net_edge' => $upNetEdge,
                ':down_net_edge' => $downNetEdge,
                ':decision' => $decision,
                ':reason' => $reason,
            ]);

            $statement = $this->database->prepare(
                'SELECT id FROM observations WHERE market_id = :market_id AND observed_at = :observed_at'
            );
            $statement->execute([':market_id' => $marketId, ':observed_at' => $observedAt]);
            $observationId = (int) ($statement->fetchColumn() ?: 0);

            $this->trackEarlyExitOpportunity(
                $marketId,
                $observedAt,
                $secondsRemaining,
                $upBid,
                $downBid
            );
            $this->trackExit10Shadow(
                $marketId,
                $observedAt,
                $secondsRemaining,
                $upBid,
                $downBid
            );

            if ($decision === 'PAPER BET' && $recommendedOutcome !== null && $recommendedEdge !== null) {
                $entryAsk = $recommendedOutcome === 'Up' ? $upAsk : $downAsk;
                $feePerShare = $recommendedOutcome === 'Up' ? $upFee : $downFee;
                $modelProbability = $recommendedOutcome === 'Up'
                    ? $probabilityUp
                    : ($probabilityUp === null ? null : 1 - $probabilityUp);

                if ($entryAsk !== null && $feePerShare !== null && $modelProbability !== null) {
                    $this->openPaperTrade(
                        $marketId,
                        $observationId,
                        $recommendedOutcome,
                        $entryAsk,
                        $feePerShare,
                        $modelProbability,
                        $recommendedEdge,
                        $observedAt
                    );
                }
            }

            $this->database->commit();
        } catch (Throwable $exception) {
            $this->database->rollBack();
            throw $exception;
        }

        return $this->summary();
    }

    public function settleExpiredMarkets(PolymarketClient $client): void
    {
        $lastCheck = (int) ($this->meta('last_settlement_check') ?? 0);
        if (time() - $lastCheck < 20) {
            return;
        }
        $this->setMeta('last_settlement_check', (string) time());

        $statement = $this->database->prepare(
            'SELECT market_id, slug
             FROM markets
             WHERE outcome IS NULL AND interval_end < :cutoff
             ORDER BY interval_end ASC
             LIMIT 12'
        );
        $statement->execute([':cutoff' => time() - 15]);

        foreach ($statement->fetchAll() as $marketRecord) {
            try {
                $market = $client->getMarketBySlug((string) $marketRecord['slug']);
                $winner = is_array($market) ? $this->resolvedOutcome($market) : null;
                if ($winner !== null) {
                    $this->settleMarket((string) $marketRecord['market_id'], $winner);
                }
            } catch (Throwable) {
                // Resolution is eventually consistent. A later refresh will retry.
            }
        }
    }

    public function summary(): array
    {
        $latest = $this->database->query(
            'SELECT o.*, m.question, m.slug, m.open_price, m.open_price_trusted,
                    m.interval_start, m.interval_end
             FROM observations o
             JOIN markets m ON m.market_id = o.market_id
             ORDER BY o.observed_at DESC, o.id DESC
             LIMIT 1'
        )->fetch() ?: null;

        $performance = $this->performance();
        $metrics = $this->modelMetrics();
        $earlyExit = $this->earlyExitMetrics();
        $exit10Shadow = $this->exit10Metrics();

        $statement = $this->database->query(
            'SELECT t.id, t.outcome, t.entry_price, t.model_probability, t.net_edge,
                    t.stake, t.shares, t.status, t.pnl, t.opened_at, t.resolved_at,
                    t.best_exit_price, t.best_exit_pnl, t.best_exit_at,
                    t.best_exit_seconds_remaining, t.first_profitable_at,
                    t.exit_10_action, t.exit_10_bid, t.exit_10_pnl,
                    t.exit_10_at, t.exit_10_seconds_remaining,
                    m.question
             FROM paper_trades t
             JOIN markets m ON m.market_id = t.market_id
             ORDER BY t.opened_at DESC
             LIMIT 12'
        );

        return [
            'ok' => true,
            'signal' => $latest === null ? null : $this->formatSignal($latest),
            'performance' => $performance,
            'metrics' => $metrics,
            'early_exit' => $earlyExit,
            'exit_10_shadow' => $exit10Shadow,
            'recent_trades' => array_map([$this, 'formatTrade'], $statement->fetchAll()),
            'settings' => [
                'starting_balance' => (float) $this->settings['starting_balance'],
                'position_size' => (float) $this->settings['max_position_usd'],
                'daily_loss_limit' => (float) $this->settings['daily_loss_limit_usd'],
                'ó=¶‰žËkºwµçQÁ¹°€ô€‘Ý½¸(€€€€€€€€€€€€€€€€€€€€ü€ ¡™±½…Ð¤€‘ÑÉ…‘•lÍ¡…É•Ìt€´€¡™±½…Ð¤€‘ÑÉ…‘•lÍÑ…­”t¤(€€€€€€€€€€€€€€€€€€€€è€´¡™±½…Ð¤€‘ÑÉ…‘•lÍÑ…­”tì(€€€€€€€€€€€€€€€€‘ÕÁ‘…Ñ”€ô€‘Ñ¡¥Ì´ù‘…Ñ…‰…Í”´ùÁÉ•Á…É” (€€€€€€€€€€€€€€€€€€€€UAQÁ…Á•É}ÑÉ…‘•Ì(€€€€€€€€€€€€€€€€€€€€MPÍÑ…ÑÕÌ€ô€éÍÑ…ÑÕÌ°Á¹°€ô€éÁ¹°°É•Í½±Ù•‘}…Ð€ô€é¹½Ü(€€€€€€€€€€€€€€€€€€€€]!I¥€ô€é¥œ(€€€€€€€€€€€€€€€€¤ì(€€€€€€€€€€€€€€€€‘ÕÁ‘…Ñ”´ù•á•ÕÑ”¡l(€€€€€€€€€€€€€€€€€€€€œéÍÑ…ÑÕÌœ€ôø€‘Ý½¸€ü€Ý½¸œ€è€±½ÍÐœ°(€€€€€€€€€€€€€€€€€€€€œéÁ¹°œ€ôø€‘Á¹°°(€€€€€€€€€€€€€€€€€€€€œé¹½Üœ€ôø€‘¹½Ü°(€€€€€€€€€€€€€€€€€€€€œé¥œ€ôø€‘ÑÉ…‘•l¥t°(€€€€€€€€€€€€€€€t¤ì(€€€€€€€€€€€ô((€€€€€€€€€€€€‘Ñ¡¥Ì´ù‘…Ñ…‰…Í”´ù½µµ¥Ð ¤ì(€€€€€€€ô…Ñ €¡Q¡É½Ý…‰±”€‘•á•ÁÑ¥½¸¤ì(€€€€€€€€€€€€‘Ñ¡¥Ì´ù‘…Ñ…‰…Í”´ùÉ½±±	…¬ ¤ì(€€€€€€€€€€€Ñ¡É½Ü€‘•á•ÁÑ¥½¸ì(€€€€€€€ô(€€€ô((€€€ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸É•Í½±Ù•‘=ÕÑ½µ”¡…ÉÉ…ä€‘µ…É­•Ð¤è€ýÍÑÉ¥¹œ(€€€ì(€€€€€€€€‘ÁÉ¥•Ì€ô€‘µ…É­•Ñl½ÕÑ½µ•AÉ¥•Ìt€üümtì(€€€€€€€€‘½ÕÑ½µ•Ì€ô€‘µ…É­•Ñl½ÕÑ½µ•Ìt€üümtì((€€€€€€€¥˜€¡¥Í}ÍÑÉ¥¹œ ‘ÁÉ¥•Ì¤¤ì(€€€€€€€€€€€€‘ÁÉ¥•Ì€ô©Í½¹}‘•½‘” ‘ÁÉ¥•Ì°ÑÉÕ”¤€üèmtì(€€€€€€€ô(€€€€€€€¥˜€¡¥Í}ÍÑÉ¥¹œ ‘½ÕÑ½µ•Ì¤¤ì(€€€€€€€€€€€€‘½ÕÑ½µ•Ì€ô©Í½¹}‘•½‘” ‘½ÕÑ½µ•Ì°ÑÉÕ”¤€üèmtì(€€€€€€€ô((€€€€€€€™½É•… € ¡…ÉÉ…ä¤€‘ÁÉ¥•Ì…Ì€‘¥¹‘•à€ôø€‘ÁÉ¥”¤ì(€€€€€€€€€€€¥˜€ ¡™±½…Ð¤€‘ÁÉ¥”€øô€À¸ää€˜˜¥ÍÍ•Ð ‘½ÕÑ½µ•Íl‘¥¹‘•át¤¤ì(€€€€€€€€€€€€€€€É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤€‘½ÕÑ½µ•Íl‘¥¹‘•átì(€€€€€€€€€€€ô(€€€€€€€ô((€€€€€€€É•ÑÕÉ¸¹Õ±°ì(€€€ô((€€€ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸Á•É™½Éµ…¹” ¤è…ÉÉ…ä(€€€ì(€€€€€€€€‘É½Ü€ô€‘Ñ¡¥Ì´ù‘…Ñ…‰…Í”´ùÅÕ•Éä (€€€€€€€€€€€€M1P(€€€€€€€€€€€€€€€=U9P ¨¤LÑ½Ñ…°°(€€€€€€€€€€€€€€€MU4¡M]!8ÍÑ…ÑÕÌ€ô€‰½Á•¸ˆQ!8€Ä1M€À9¤L½Á•¹}½Õ¹Ð°(€€€€€€€€€€€€€€€MU4¡M]!8ÍÑ…ÑÕÌ€ô€‰Ý½¸ˆQ!8€Ä1M€À9¤LÝ¥¹Ì°(€€€€€€€€€€€€€€€MU4¡M]!8ÍÑ…ÑÕÌ€ô€‰±½ÍÐˆQ!8€Ä1M€À9¤L±½ÍÍ•Ì°(€€€€€€€€€€€€€€€=1M¡MU4¡M]!8ÍÑ…ÑÕÌ%8€ ‰Ý½¸ˆ°€‰±½ÍÐˆ¤Q!8Á¹°1M€À9¤°€À¤LÉ•…±¥é•‘}Á¹°°(€€€€€€€€€€€€€€€=1M¡MU4¡M]!8ÍÑ…ÑÕÌ€ô€‰½Á•¸ˆQ!8ÍÑ…­”1M€À9¤°€À¤L½Á•¹}•áÁ½ÍÕÉ”(€€€€€€€€€€€€I=4Á…Á•É}ÑÉ…‘•Ìœ(€€€€€€€€¤´ù™•Ñ  ¤ì((€€€€€€€€‘É•Í½±Ù•€ô€¡¥¹Ð¤€‘É½ÝlÝ¥¹Ìt€¬€¡¥¹Ð¤€‘É½Ýl±½ÍÍ•Ìtì(€€€€€€€€‘‰…±…¹”€ô€¡™±½…Ð¤€‘Ñ¡¥Ì´ùÍ•ÑÑ¥¹ÍlÍÑ…ÉÑ¥¹}‰…±…¹”t€¬€¡™±½…Ð¤€‘É½ÝlÉ•…±¥é•‘}Á¹°tì(€€€€€€€€‘Á•…¬€ô€¡™±½…Ð¤€‘Ñ¡¥Ì´ùÍ•ÑÑ¥¹ÍlÍÑ…ÉÑ¥¹}‰…±…¹”tì(€€€€€€€€‘•ÅÕ¥Ñä€ô€‘Á•…¬ì(€€€€€€€€‘µ…á¥µÕµÉ…Ý‘½Ý¸€ô€À¸Àì(€€€€€€€€‘ÑÉ…‘•Ì€ô€‘Ñ¡¥Ì´ù‘…Ñ…‰…Í”´ùÅÕ•Éä (€€€€€€€€€€€€M1PÁ¹°I=4Á…Á•É}ÑÉ…‘•Ì(€€€€€€€€€€€€]!IÍÑ…ÑÕÌ%8€ ‰Ý½¸ˆ°€‰±½ÍÐˆ¤(€€€€€€€€€€€€=IH	dÉ•Í½±Ù•‘}…ÐM°¥Mœ(€€€€€€€€¤´ù™•Ñ¡±° ¤ì((€€€€€€€™½É•… € ‘ÑÉ…‘•Ì…Ì€‘ÑÉ…‘”¤ì(€€€€€€€€€€€€‘•ÅÕ¥Ñä€¬ô€¡™±½…Ð¤€‘ÑÉ…‘•lÁ¹°tì(€€€€€€€€€€€€‘Á•…¬€ôµ…à ‘Á•…¬°€‘•ÅÕ¥Ñä¤ì(€€€€€€€€€€€€‘µ…á¥µÕµÉ…Ý‘½Ý¸€ôµ…à ‘µ…á¥µÕµÉ…Ý‘½Ý¸°€‘Á•…¬€´€‘•ÅÕ¥Ñä¤ì(€€€€€€€ô((€€€€€€€É•ÑÕÉ¸l(€€€€€€€€€€€€‰…±…¹”œ€ôø€‘‰…±…¹”°(€€€€€€€€€€€€É•…±¥é•‘}Á¹°œ€ôø€¡™±½…Ð¤€‘É½ÝlÉ•…±¥é•‘}Á¹°t°(€€€€€€€€€€€€Ñ½Ñ…±}ÑÉ…‘•Ìœ€ôø€¡¥¹Ð¤€‘É½ÝlÑ½Ñ…°t°(€€€€€€€€€€€€½Á•¹}ÑÉ…‘•Ìœ€ôø€¡¥¹Ð¤€‘É½Ýl½Á•¹}½Õ¹Ðt°(€€€€€€€€€€€€Ý¥¹Ìœ€ôø€¡¥¹Ð¤€‘É½ÝlÝ¥¹Ìt°(€€€€€€€€€€€€±½ÍÍ•Ìœ€ôø€¡¥¹Ð¤€‘É½Ýl±½ÍÍ•Ìt°(€€€€€€€€€€€€Ý¥¹}É…Ñ”œ€ôø€‘É•Í½±Ù•€ø€À€ü€¡¥¹Ð¤€‘É½ÝlÝ¥¹Ìt€¼€‘É•Í½±Ù•€è¹Õ±°°(€€€€€€€€€€€€½Á•¹}•áÁ½ÍÕÉ”œ€ôø€¡™±½…Ð¤€‘É½Ýl½Á•¹}•áÁ½ÍÕÉ”t°(€€€€€€€€€€€€µ…á¥µÕµ}‘É…Ý‘½Ý¸œ€ôø€‘µ…á¥µÕµÉ…Ý‘½Ý¸°(€€€€€€€tì(€€€ô((€€€ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸•…É±åá¥Ñ5•ÑÉ¥Ì ¤è…ÉÉ…ä(€€€ì(€€€€€€€€‘É½Ü€ô€‘Ñ¡¥Ì´ù‘…Ñ…‰…Í”´ùÅÕ•Éä (€€€€€€€€€€€€M1P(€€€€€€€€€€€€€€€MU4¡M]!8‰•ÍÑ}•á¥Ñ}Á¹°%L9=P9U10Q!8€Ä1M€À9¤LÑÉ…­•°(€€€€€€€€€€€€€€€MU4¡M]!8‰•ÍÑ}•á¥Ñ}Á¹°€ø€ÀQ!8€Ä1M€À9¤LÁÉ½™¥Ñ…‰±”°(€€€€€€€€€€€€€€€MU4¡M]!8ÍÑ…ÑÕÌ€ô€‰±½ÍÐˆ9‰•ÍÑ}•á¥Ñ}Á¹°€ø€ÀQ!8€Ä1M€À9¤LÉ•ÍÕ•‘}±½ÍÍ•Ì°(€€€€€€€€€€€€€€€5`¡‰•ÍÑ}•á¥Ñ}Á¹°¤L‰•ÍÑ}Á¹°(€€€€€€€€€€€€I=4Á…Á•É}ÑÉ…‘•Ìœ(€€€€€€€€¤´ù™•Ñ  ¤ì((€€€€€€€€‘ÑÉ…­•€ô€¡¥¹Ð¤€ ‘É½ÝlÑÉ…­•t€üü€À¤ì(€€€€€€€€‘ÁÉ½™¥Ñ…‰±”€ô€¡¥¹Ð¤€ ‘É½ÝlÁÉ½™¥Ñ…‰±”t€üü€À¤ì((€€€€€€€É•ÑÕÉ¸l(€€€€€€€€€€€€ÑÉ…­•‘}ÑÉ…‘•Ìœ€ôø€‘ÑÉ…­•°(€€€€€€€€€€€€ÁÉ½™¥Ñ…‰±•}½ÁÁ½ÉÑÕ¹¥Ñ¥•Ìœ€ôø€‘ÁÉ½™¥Ñ…‰±”°(€€€€€€€€€€€€½ÁÁ½ÉÑÕ¹¥Ñå}É…Ñ”œ€ôø€‘ÑÉ…­•€ø€À€ü€‘ÁÉ½™¥Ñ…‰±”€¼€‘ÑÉ…­•€è¹Õ±°°(€€€€€€€€€€€€±½Í¥¹}ÑÉ…‘•Í}ÁÉ½™¥Ñ…‰±•}•…É±¥•Èœ€ôø€¡¥¹Ð¤€ ‘É½ÝlÉ•ÍÕ•‘}±½ÍÍ•Ìt€üü€À¤°(€€€€€€€€€€€€‰•ÍÑ}½‰Í•ÉÙ•‘}Á¹°œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½Ýl‰•ÍÑ}Á¹°t€üü¹Õ±°¤°(€€€€€€€€€€€€µ•Ñ¡½œ€ôø€	•ÍÐ•á•ÕÑ…‰±”‰¥½‰Í•ÉÙ•…™Ñ•È•¹ÑÉä°¹•Ð½˜•ÍÑ¥µ…Ñ••á¥Ð™•”…¹Í±¥ÁÁ…”¸œ°(€€€€€€€tì(€€€ô((€€€ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸•á¥ÐÄÁ5•ÑÉ¥Ì ¤è…ÉÉ…ä(€€€ì(€€€€€€€€‘É½Ü€ô€‘Ñ¡¥Ì´ù‘…Ñ…‰…Í”´ùÅÕ•Éä (€€€€€€€€€€€€M1P(€€€€€€€€€€€€€€€=U9P ¨¤LÉ•Í½±Ù•°(€€€€€€€€€€€€€€€=1M¡MU4¡M(€€€€€€€€€€€€€€€€€€€]!8•á¥Ñ|ÄÁ}…Ñ¥½¸€ô€‰Í½±‘}±½ÍÌˆQ!8•á¥Ñ|ÄÁ}Á¹°(€€€€€€€€€€€€€€€€€€€1MÁ¹°(€€€€€€€€€€€€€€€9¤°€À¤LÉÕ±•}Á¹°°(€€€€€€€€€€€€€€€=1M¡MU4¡Á¹°¤°€À¤L¡½±‘}Á¹°°(€€€€€€€€€€€€€€€MU4¡M]!8•á¥Ñ|ÄÁ}…Ñ¥½¸€ô€‰Í½±‘}±½ÍÌˆQ!8€Ä1M€À9¤L•á¥ÑÌ°(€€€€€€€€€€€€€€€MU4¡M(€€€€€€€€€€€€€€€€€€€]!8•á¥Ñ|ÄÁ}…Ñ¥½¸€ô€‰Í½±‘}±½ÍÌˆ9ÍÑ…ÑÕÌ€ô€‰Ý½¸ˆQ!8€Ä(€€€€€€€€€€€€€€€€€€€1M€À(€€€€€€€€€€€€€€€9¤LÝ¥¹¹•ÉÍ}ÕÐ(€€€€€€€€€€€€I=4Á…Á•É}ÑÉ…‘•Ì(€€€€€€€€€€€€]!I•á¥Ñ|ÄÁ}…Ñ¥½¸%L9=P9U10(€€€€€€€€€€€€€€9ÍÑ…ÑÕÌ%8€ ‰Ý½¸ˆ°€‰±½ÍÐˆ¤œ(€€€€€€€€¤´ù™•Ñ  ¤ì((€€€€€€€€‘ÉÕ±•A¹°€ô€¡™±½…Ð¤€ ‘É½ÝlÉÕ±•}Á¹°t€üü€À¤ì(€€€€€€€€‘¡½±‘A¹°€ô€¡™±½…Ð¤€ ‘É½Ýl¡½±‘}Á¹°t€üü€À¤ì((€€€€€€€É•ÑÕÉ¸l(€€€€€€€€€€€€Ñ…É•Ñ}Í•½¹‘Ìœ€ôø€ÄÀ°(€€€€€€€€€€€€É•Í½±Ù•‘}ÑÉ…‘•Ìœ€ôø€¡¥¹Ð¤€ ‘É½ÝlÉ•Í½±Ù•t€üü€À¤°(€€€€€€€€€€€€ÉÕ±•}Á¹°œ€ôø€‘ÉÕ±•A¹°°(€€€€€€€€€€€€¡½±‘}Á¹°œ€ôø€‘¡½±‘A¹°°(€€€€€€€€€€€€‘¥™™•É•¹”œ€ôø€‘ÉÕ±•A¹°€´€‘¡½±‘A¹°°(€€€€€€€€€€€€•á¥ÑÍ}ÑÉ¥•É•œ€ôø€¡¥¹Ð¤€ ‘É½Ýl•á¥ÑÌt€üü€À¤°(€€€€€€€€€€€€•Ù•¹ÑÕ…±}Ý¥¹¹•ÉÍ}•á¥Ñ•œ€ôø€¡¥¹Ð¤€ ‘É½ÝlÝ¥¹¹•ÉÍ}ÕÐt€üü€À¤°(€€€€€€€€€€€€µ½‘”œ€ôø€Í¡…‘½Üœ°(€€€€€€€tì(€€€ô((€€€ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸µ½‘•±5•ÑÉ¥Ì ¤è…ÉÉ…ä(€€€ì(€€€€€€€€‘É½ÝÌ€ô€‘Ñ¡¥Ì´ù‘…Ñ…‰…Í”´ùÅÕ•Éä (€€€€€€€€€€€€M1P¼¹µ…É­•Ñ}¥°¼¹Í•½¹‘Í}É•µ…¥¹¥¹œ°¼¹µ½‘•±}ÁÉ½‰…‰¥±¥Ñå}ÕÀ°(€€€€€€€€€€€€€€€€€€€¼¹ÕÁ}‰¥°¼¹ÕÁ}…Í¬°¼¹½É‘•É}‰½½­}¥µ‰…±…¹•}ÕÀ°´¹½ÕÑ½µ”(€€€€€€€€€€€€I=4½‰Í•ÉÙ…Ñ¥½¹Ì¼(€€€€€€€€€€€€)=%8µ…É­•ÑÌ´=8´¹µ…É­•Ñ}¥€ô¼¹µ…É­•Ñ}¥(€€€€€€€€€€€€]!I´¹½ÕÑ½µ”%L9=P9U109¼¹µ½‘•±}ÁÉ½‰…‰¥±¥Ñå}ÕÀ%L9=P9U10(€€€€€€€€€€€€=IH	d¼¹µ…É­•Ñ}¥M°	L¡¼¹Í•½¹‘Í}É•µ…¥¹¥¹œ€´€ØÀ¤M°¼¹½‰Í•ÉÙ•‘}…ÐMœ(€€€€€€€€¤´ù™•Ñ¡±° ¤ì((€€€€€€€€‘Í•±•Ñ•€ômtì(€€€€€€€™½É•… € ‘É½ÝÌ…Ì€‘É½Ü¤ì(€€€€€€€€€€€€‘Í•±•Ñ•‘l¡ÍÑÉ¥¹œ¤€‘É½Ýlµ…É­•Ñ}¥ut€üüô€‘É½Üì(€€€€€€€ô((€€€€€€€€‘µ½‘•±	É¥•È€ô€À¸Àì(€€€€€€€€‘µ…É­•Ñ	É¥•È€ô€À¸Àì(€€€€€€€€‘µ…É­•Ñ½Õ¹Ð€ô€Àì(€€€€€€€€‘™±½Ý½Õ¹Ð€ô€Àì(€€€€€€€€‘™±½Ý5…Ñ¡•Ì€ô€Àì((€€€€€€€™½É•… € ‘Í•±•Ñ•…Ì€‘É½Ü¤ì(€€€€€€€€€€€€‘…ÑÕ…°€ôÍÑÉ…Í•µÀ ¡ÍÑÉ¥¹œ¤€‘É½Ýl½ÕÑ½µ”t°€UÀœ¤€ôôô€À€ü€Ä¸À€è€À¸Àì(€€€€€€€€€€€€‘µ½‘•±AÉ½‰…‰¥±¥Ñä€ô€¡™±½…Ð¤€‘É½Ýlµ½‘•±}ÁÉ½‰…‰¥±¥Ñå}ÕÀtì(€€€€€€€€€€€€‘µ½‘•±	É¥•È€¬ô€ ‘µ½‘•±AÉ½‰…‰¥±¥Ñä€´€‘…ÑÕ…°¤€¨¨€Èì((€€€€€€€€€€€¥˜€ ‘É½ÝlÕÁ}‰¥t€„ôô¹Õ±°€˜˜€‘É½ÝlÕÁ}…Í¬t€„ôô¹Õ±°¤ì(€€€€€€€€€€€€€€€€‘µ…É­•ÑAÉ½‰…‰¥±¥Ñä€ô€ ¡™±½…Ð¤€‘É½ÝlÕÁ}‰¥t€¬€¡™±½…Ð¤€‘É½ÝlÕÁ}…Í¬t¤€¼€Èì(€€€€€€€€€€€€€€€€‘µ…É­•Ñ	É¥•È€¬ô€ ‘µ…É­•ÑAÉ½‰…‰¥±¥Ñä€´€‘…ÑÕ…°¤€¨¨€Èì(€€€€€€€€€€€€€€€€‘µ…É­•Ñ½Õ¹Ð¬¬ì(€€€€€€€€€€€ô((€€€€€€€€€€€€‘¥µ‰…±…¹”€ô€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½Ýl½É‘•É}‰½½­}¥µ‰…±…¹•}ÕÀt€üü¹Õ±°¤ì(€€€€€€€€€€€¥˜€ ‘¥µ‰…±…¹”€„ôô¹Õ±°€˜˜…‰Ì ‘¥µ‰…±…¹”¤€øô€À¸ÀÔ¤ì(€€€€€€€€€€€€€€€€‘™±½Ý½Õ¹Ð¬¬ì(€€€€€€€€€€€€€€€€‘ÁÉ•‘¥Ñ•‘UÀ€ô€‘¥µ‰…±…¹”€ø€Àì(€€€€€€€€€€€€€€€€‘…ÑÕ…±UÀ€ô€‘…ÑÕ…°€ôôô€Ä¸Àì(€€€€€€€€€€€€€€€¥˜€ ‘ÁÉ•‘¥Ñ•‘UÀ€ôôô€‘…ÑÕ…±UÀ¤ì(€€€€€€€€€€€€€€€€€€€€‘™±½Ý5…Ñ¡•Ì¬¬ì(€€€€€€€€€€€€€€€ô(€€€€€€€€€€€ô(€€€€€€€ô((€€€€€€€€‘½Õ¹Ð€ô½Õ¹Ð ‘Í•±•Ñ•¤ì((€€€€€€€É•ÑÕÉ¸l(€€€€€€€€€€€€É•Í½±Ù•‘}µ…É­•ÑÌœ€ôø€‘½Õ¹Ð°(€€€€€€€€€€€€µ½‘•±}‰É¥•Èœ€ôø€‘½Õ¹Ð€ø€À€ü€‘µ½‘•±	É¥•È€¼€‘½Õ¹Ð€è¹Õ±°°(€€€€€€€€€€€€µ…É­•Ñ}‰É¥•Èœ€ôø€‘µ…É­•Ñ½Õ¹Ð€ø€À€ü€‘µ…É­•Ñ	É¥•È€¼€‘µ…É­•Ñ½Õ¹Ð€è¹Õ±°°(€€€€€€€€€€€€™±½Ý}É•Í½±Ù•‘}µ…É­•ÑÌœ€ôø€‘™±½Ý½Õ¹Ð°(€€€€€€€€€€€€™±½Ý}‘¥É•Ñ¥½¹}µ…Ñ œ€ôø€‘™±½Ý½Õ¹Ð€ø€À€ü€‘™±½Ý5…Ñ¡•Ì€¼€‘™±½Ý½Õ¹Ð€è¹Õ±°°(€€€€€€€€€€€€ÍÑ…ÑÕÌœ€ôø€‘½Õ¹Ð€ð€ÄÀÀ(€€€€€€€€€€€€€€€€ü€½±±•Ñ¥¹œ‰…Í•±¥¹”•Ù¥‘•¹”œ(€€€€€€€€€€€€€€€€è€I•…‘ä™½ÈÝ…±¬µ™½ÉÝ…ÉÉ•Ù¥•Üœ°(€€€€€€€tì(€€€ô((€€€ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸™½Éµ…ÑM¥¹…°¡…ÉÉ…ä€‘É½Ü¤è…ÉÉ…ä(€€€ì(€€€€€€€€‘ÁÉ½‰…‰¥±¥ÑåUÀ€ô€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½Ýlµ½‘•±}ÁÉ½‰…‰¥±¥Ñå}ÕÀt€üü¹Õ±°¤ì(€€€€€€€€‘É•½µµ•¹‘•‘=ÕÑ½µ”€ô¹Õ±°ì(€€€€€€€€‘É•½µµ•¹‘•‘‘”€ô¹Õ±°ì((€€€€€€€¥˜€ ‘É½ÝlÕÁ}¹•Ñ}•‘”t€„ôô¹Õ±°€˜˜€‘É½Ýl‘½Ý¹}¹•Ñ}•‘”t€„ôô¹Õ±°¤ì(€€€€€€€€€€€€‘É•½µµ•¹‘•‘=ÕÑ½µ”€ô€¡™±½…Ð¤€‘É½ÝlÕÁ}¹•Ñ}•‘”t€øô€¡™±½…Ð¤€‘É½Ýl‘½Ý¹}¹•Ñ}•‘”t(€€€€€€€€€€€€€€€€ü€UÀœ(€€€€€€€€€€€€€€€€è€½Ý¸œì(€€€€€€€€€€€€‘É•½µµ•¹‘•‘‘”€ôµ…à ¡™±½…Ð¤€‘É½ÝlÕÁ}¹•Ñ}•‘”t°€¡™±½…Ð¤€‘É½Ýl‘½Ý¹}¹•Ñ}•‘”t¤ì(€€€€€€€ô((€€€€€€€É•ÑÕÉ¸l(€€€€€€€€€€€€µ…É­•Ñ}¥œ€ôø€‘É½Ýlµ…É­•Ñ}¥t°(€€€€€€€€€€€€ÅÕ•ÍÑ¥½¸œ€ôø€‘É½ÝlÅÕ•ÍÑ¥½¸t°(€€€€€€€€€€€€½‰Í•ÉÙ•‘}…Ðœ€ôø‘…Ñ”¡Q}Q=4°€¡¥¹Ð¤€‘É½Ýl½‰Í•ÉÙ•‘}…Ðt¤°(€€€€€€€€€€€€Í•½¹‘Í}É•µ…¥¹¥¹œœ€ôø€¡¥¹Ð¤€‘É½ÝlÍ•½¹‘Í}É•µ…¥¹¥¹œt°(€€€€€€€€€€€€ÁÉ¥•}Ñ½}‰•…Ðœ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½Ýl½Á•¹}ÁÉ¥”t¤°(€€€€€€€€€€€€½Á•¹¥¹}ÁÉ¥•}ÑÉÕÍÑ•œ€ôø€¡‰½½°¤€‘É½Ýl½Á•¹}ÁÉ¥•}ÑÉÕÍÑ•t°(€€€€€€€€€€€€¡…¥¹±¥¹­}ÁÉ¥”œ€ôø€¡™±½…Ð¤€‘É½Ýl¡…¥¹±¥¹­}ÁÉ¥”t°(€€€€€€€€€€€€‰¥¹…¹•}ÁÉ¥”œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½Ýl‰¥¹…¹•}ÁÉ¥”t¤°(€€€€€€€€€€€€‘¥ÍÑ…¹”œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½Ýl‘¥ÍÑ…¹”t¤°(€€€€€€€€€€€€Ù½±…Ñ¥±¥Ñå|ØÁÌœ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½ÝlÙ½±…Ñ¥±¥Ñå|ØÁÌt¤°(€€€€€€€€€€€€ÁÉ½‰…‰¥±¥Ñå}ÕÀœ€ôø€‘ÁÉ½‰…‰¥±¥ÑåUÀ°(€€€€€€€€€€€€ÁÉ½‰…‰¥±¥Ñå}‘½Ý¸œ€ôø€‘ÁÉ½‰…‰¥±¥ÑåUÀ€ôôô¹Õ±°€ü¹Õ±°€è€Ä€´€‘ÁÉ½‰…‰¥±¥ÑåUÀ°(€€€€€€€€€€€€ÕÁ}‰¥œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½ÝlÕÁ}‰¥t¤°(€€€€€€€€€€€€ÕÁ}…Í¬œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½ÝlÕÁ}…Í¬t¤°(€€€€€€€€€€€€‘½Ý¹}‰¥œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½Ýl‘½Ý¹}‰¥t¤°(€€€€€€€€€€€€‘½Ý¹}…Í¬œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½Ýl‘½Ý¹}…Í¬t¤°(€€€€€€€€€€€€ÕÁ}‰¥‘}Í¥é”œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½ÝlÕÁ}‰¥‘}Í¥é”t€üü¹Õ±°¤°(€€€€€€€€€€€€ÕÁ}…Í­}Í¥é”œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½ÝlÕÁ}…Í­}Í¥é”t€üü¹Õ±°¤°(€€€€€€€€€€€€‘½Ý¹}‰¥‘}Í¥é”œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½Ýl‘½Ý¹}‰¥‘}Í¥é”t€üü¹Õ±°¤°(€€€€€€€€€€€€‘½Ý¹}…Í­}Í¥é”œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½Ýl‘½Ý¹}…Í­}Í¥é”t€üü¹Õ±°¤°(€€€€€€€€€€€€µ…É­•Ñ}Ù½±Õµ”œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½Ýlµ…É­•Ñ}Ù½±Õµ”t€üü¹Õ±°¤°(€€€€€€€€€€€€Ù½±Õµ•}¡…¹•|ÌÁÌœ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½ÝlÙ½±Õµ•}¡…¹•|ÌÁÌt€üü¹Õ±°¤°(€€€€€€€€€€€€Ù½±Õµ•}Á•É}Í•½¹œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½ÝlÙ½±Õµ•}Á•É}Í•½¹t€üü¹Õ±°¤°(€€€€€€€€€€€€½É‘•É}‰½½­}¥µ‰…±…¹•}ÕÀœ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½Ýl½É‘•É}‰½½­}¥µ‰…±…¹•}ÕÀt€üü¹Õ±°¤°(€€€€€€€€€€€€ÕÁ}¹•Ñ}•‘”œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½ÝlÕÁ}¹•Ñ}•‘”t¤°(€€€€€€€€€€€€‘½Ý¹}¹•Ñ}•‘”œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘É½Ýl‘½Ý¹}¹•Ñ}•‘”t¤°(€€€€€€€€€€€€É•½µµ•¹‘•‘}½ÕÑ½µ”œ€ôø€‘É•½µµ•¹‘•‘=ÕÑ½µ”°(€€€€€€€€€€€€É•½µµ•¹‘•‘}•‘”œ€ôø€‘É•½µµ•¹‘•‘‘”°(€€€€€€€€€€€€‘•¥Í¥½¸œ€ôø€‘É½Ýl‘•¥Í¥½¸t°(€€€€€€€€€€€€É•…Í½¸œ€ôø€‘É½Ýl‘•¥Í¥½¹}É•…Í½¸t°(€€€€€€€tì(€€€ô((€€€ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸™½Éµ…ÑQÉ…‘”¡…ÉÉ…ä€‘ÑÉ…‘”¤è…ÉÉ…ä(€€€ì(€€€€€€€É•ÑÕÉ¸l(€€€€€€€€€€€€¥œ€ôø€¡¥¹Ð¤€‘ÑÉ…‘•l¥t°(€€€€€€€€€€€€ÅÕ•ÍÑ¥½¸œ€ôø€‘ÑÉ…‘•lÅÕ•ÍÑ¥½¸t°(€€€€€€€€€€€€½ÕÑ½µ”œ€ôø€‘ÑÉ…‘•l½ÕÑ½µ”t°(€€€€€€€€€€€€•¹ÑÉå}ÁÉ¥”œ€ôø€¡™±½…Ð¤€‘ÑÉ…‘•l•¹ÑÉå}ÁÉ¥”t°(€€€€€€€€€€€€µ½‘•±}ÁÉ½‰…‰¥±¥Ñäœ€ôø€¡™±½…Ð¤€‘ÑÉ…‘•lµ½‘•±}ÁÉ½‰…‰¥±¥Ñät°(€€€€€€€€€€€€¹•Ñ}•‘”œ€ôø€¡™±½…Ð¤€‘ÑÉ…‘•l¹•Ñ}•‘”t°(€€€€€€€€€€€€ÍÑ…­”œ€ôø€¡™±½…Ð¤€‘ÑÉ…‘•lÍÑ…­”t°(€€€€€€€€€€€€Í¡…É•Ìœ€ôø€¡™±½…Ð¤€‘ÑÉ…‘•lÍ¡…É•Ìt°(€€€€€€€€€€€€ÍÑ…ÑÕÌœ€ôø€‘ÑÉ…‘•lÍÑ…ÑÕÌt°(€€€€€€€€€€€€Á¹°œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘ÑÉ…‘•lÁ¹°t¤°(€€€€€€€€€€€€½Á•¹•‘}…Ðœ€ôø‘…Ñ”¡Q}Q=4°€¡¥¹Ð¤€‘ÑÉ…‘•l½Á•¹•‘}…Ðt¤°(€€€€€€€€€€€€É•Í½±Ù•‘}…Ðœ€ôø€‘ÑÉ…‘•lÉ•Í½±Ù•‘}…Ðt€ôôô¹Õ±°(€€€€€€€€€€€€€€€€ü¹Õ±°(€€€€€€€€€€€€€€€€è‘…Ñ”¡Q}Q=4°€¡¥¹Ð¤€‘ÑÉ…‘•lÉ•Í½±Ù•‘}…Ðt¤°(€€€€€€€€€€€€‰•ÍÑ}•á¥Ñ}ÁÉ¥”œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘ÑÉ…‘•l‰•ÍÑ}•á¥Ñ}ÁÉ¥”t€üü¹Õ±°¤°(€€€€€€€€€€€€‰•ÍÑ}•á¥Ñ}Á¹°œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘ÑÉ…‘•l‰•ÍÑ}•á¥Ñ}Á¹°t€üü¹Õ±°¤°(€€€€€€€€€€€€‰•ÍÑ}•á¥Ñ}…Ðœ€ôø•µÁÑä ‘ÑÉ…‘•l‰•ÍÑ}•á¥Ñ}…Ðt¤(€€€€€€€€€€€€€€€€ü¹Õ±°(€€€€€€€€€€€€€€€€è‘…Ñ”¡Q}Q=4°€¡¥¹Ð¤€‘ÑÉ…‘•l‰•ÍÑ}•á¥Ñ}…Ðt¤°(€€€€€€€€€€€€‰•ÍÑ}•á¥Ñ}Í•½¹‘Í}É•µ…¥¹¥¹œœ€ôø¥ÍÍ•Ð ‘ÑÉ…‘•l‰•ÍÑ}•á¥Ñ}Í•½¹‘Í}É•µ…¥¹¥¹œt¤(€€€€€€€€€€€€€€€€ü€¡¥¹Ð¤€‘ÑÉ…‘•l‰•ÍÑ}•á¥Ñ}Í•½¹‘Í}É•µ…¥¹¥¹œt(€€€€€€€€€€€€€€€€è¹Õ±°°(€€€€€€€€€€€€™¥ÉÍÑ}ÁÉ½™¥Ñ…‰±•}…Ðœ€ôø•µÁÑä ‘ÑÉ…‘•l™¥ÉÍÑ}ÁÉ½™¥Ñ…‰±•}…Ðt¤(€€€€€€€€€€€€€€€€ü¹Õ±°(€€€€€€€€€€€€€€€€è‘…Ñ”¡Q}Q=4°€¡¥¹Ð¤€‘ÑÉ…‘•l™¥ÉÍÑ}ÁÉ½™¥Ñ…‰±•}…Ðt¤°(€€€€€€€€€€€€•á¥Ñ|ÄÁ}…Ñ¥½¸œ€ôø€‘ÑÉ…‘•l•á¥Ñ|ÄÁ}…Ñ¥½¸t€üü¹Õ±°°(€€€€€€€€€€€€•á¥Ñ|ÄÁ}‰¥œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘ÑÉ…‘•l•á¥Ñ|ÄÁ}‰¥t€üü¹Õ±°¤°(€€€€€€€€€€€€•á¥Ñ|ÄÁ}Á¹°œ€ôø€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘ÑÉ…‘•l•á¥Ñ|ÄÁ}Á¹°t€üü¹Õ±°¤°(€€€€€€€€€€€€•á¥Ñ|ÄÁ}…Ðœ€ôø•µÁÑä ‘ÑÉ…‘•l•á¥Ñ|ÄÁ}…Ðt¤(€€€€€€€€€€€€€€€€ü¹Õ±°(€€€€€€€€€€€€€€€€è‘…Ñ”¡Q}Q=4°€¡¥¹Ð¤€‘ÑÉ…‘•l•á¥Ñ|ÄÁ}…Ðt¤°(€€€€€€€€€€€€•á¥Ñ|ÄÁ}Í•½¹‘Í}É•µ…¥¹¥¹œœ€ôø¥ÍÍ•Ð ‘ÑÉ…‘•l•á¥Ñ|ÄÁ}Í•½¹‘Í}É•µ…¥¹¥¹œt¤(€€€€€€€€€€€€€€€€ü€¡¥¹Ð¤€‘ÑÉ…‘•l•á¥Ñ|ÄÁ}Í•½¹‘Í}É•µ…¥¹¥¹œt(€€€€€€€€€€€€€€€€è¹Õ±°°(€€€€€€€tì(€€€ô((€€€ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸µ…É­•ÑI•½É¡ÍÑÉ¥¹œ€‘µ…É­•Ñ%¤è…ÉÉ…ä(€€€ì(€€€€€€€€‘ÍÑ…Ñ•µ•¹Ð€ô€‘Ñ¡¥Ì´ù‘…Ñ…‰…Í”´ùÁÉ•Á…É” M1P€¨I=4µ…É­•ÑÌ]!Iµ…É­•Ñ}¥€ô€éµ…É­•Ñ}¥œ¤ì(€€€€€€€€‘ÍÑ…Ñ•µ•¹Ð´ù•á•ÕÑ”¡lœéµ…É­•Ñ}¥œ€ôø€‘µ…É­•Ñ%‘t¤ì(€€€€€€€É•ÑÕÉ¸€‘ÍÑ…Ñ•µ•¹Ð´ù™•Ñ  ¤€üèmtì(€€€ô((€€€ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸¥¹‘•áQ½­•¹Ì¡…ÉÉ…ä€‘Ñ½­•¹Ì¤è…ÉÉ…ä(€€€ì(€€€€€€€€‘¥¹‘•á•€ômtì(€€€€€€€™½É•… € ‘Ñ½­•¹Ì…Ì€‘Ñ½­•¸¤ì(€€€€€€€€€€€¥˜€¡¥Í}…ÉÉ…ä ‘Ñ½­•¸¤¤ì(€€€€€€€€€€€€€€€€‘¥¹‘•á•‘mÍÑÉÑ½±½Ý•È ¡ÍÑÉ¥¹œ¤€ ‘Ñ½­•¹l½ÕÑ½µ”t€üü€œœ¤¥t€ô€‘Ñ½­•¸ì(€€€€€€€€€€€ô(€€€€€€€ô(€€€€€€€É•ÑÕÉ¸€‘¥¹‘•á•ì(€€€ô((€€€ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸ÍÁÉ•… ý™±½…Ð€‘‰¥°€ý™±½…Ð€‘…Í¬°µ¥á•€‘™…±±‰…¬¤è€ý™±½…Ð(€€€ì(€€€€€€€¥˜€ ‘‰¥€„ôô¹Õ±°€˜˜€‘…Í¬€„ôô¹Õ±°¤ì(€€€€€€€€€€€É•ÑÕÉ¸µ…à À°€‘…Í¬€´€‘‰¥¤ì(€€€€€€€ô(€€€€€€€É•ÑÕÉ¸€‘Ñ¡¥Ì´ù¹Õµ‰•É=É9Õ±° ‘™…±±‰…¬¤ì(€€€ô((€€€ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸¹½Éµ…±‘˜¡™±½…Ð€‘Ù…±Õ”¤è™±½…Ð(€€€ì(€€€€€€€€‘…‰Í½±ÕÑ”€ô…‰Ì ‘Ù…±Õ”¤ì(€€€€€€€€‘Ð€ô€Ä€¼€ Ä€¬€À¸ÈÌÄØÐÄä€¨€‘…‰Í½±ÕÑ”¤ì(€€€€€€€€‘‘•¹Í¥Ñä€ô€À¸ÌäàäÐÈÈàÀÐÀÄÐÌÈÜ€¨•áÀ ´À¸Ô€¨€‘…‰Í½±ÕÑ”€¨€‘…‰Í½±ÕÑ”¤ì(€€€€€€€€‘ÁÉ½‰…‰¥±¥Ñä€ô€Ä€´€‘‘•¹Í¥Ñä€¨€ (€€€€€€€€€€€€À¸ÌÄäÌàÄÔÌÀ€¨€‘Ð(€€€€€€€€€€€€´€À¸ÌÔØÔØÌÜàÈ€¨€ ‘Ð€¨¨€È¤(€€€€€€€€€€€€¬€Ä¸ÜàÄÐÜÜäÌÜ€¨€ ‘Ð€¨¨€Ì¤(€€€€€€€€€€€€´€Ä¸àÈÄÈÔÔäÜà€¨€ ‘Ð€¨¨€Ð¤(€€€€€€€€€€€€¬€Ä¸ÌÌÀÈÜÐÐÈä€¨€ ‘Ð€¨¨€Ô¤(€€€€€€€€¤ì((€€€€€€€É•ÑÕÉ¸€‘Ù…±Õ”€øô€À€ü€‘ÁÉ½‰…‰¥±¥Ñä€è€Ä€´€‘ÁÉ½‰…‰¥±¥Ñäì(€€€ô((€€€ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸±…µÀ¡™±½…Ð€‘Ù…±Õ”°™±½…Ð€‘µ¥¹¥µÕ´°™±½…Ð€‘µ…á¥µÕ´¤è™±½…Ð(€€€ì(€€€€€€€É•ÑÕÉ¸µ…à ‘µ¥¹¥µÕ´°µ¥¸ ‘µ…á¥µÕ´°€‘Ù…±Õ”¤¤ì(€€€ô((€€€ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸¹Õµ‰•É=É9Õ±°¡µ¥á•€‘Ù…±Õ”¤è€ý™±½…Ð(€€€ì(€€€€€€€¥˜€ ‘Ù…±Õ”€ôôô¹Õ±°ñð€‘Ù…±Õ”€ôôô€œœñð€…¥Í}¹Õµ•É¥Œ ‘Ù…±Õ”¤¤ì(€€€€€€€€€€€É•ÑÕÉ¸¹Õ±°ì(€€€€€€€ô(€€€€€€€€‘¹Õµ‰•È€ô€¡™±½…Ð¤€‘Ù…±Õ”ì(€€€€€€€É•ÑÕÉ¸¥Í}™¥¹¥Ñ” ‘¹Õµ‰•È¤€ü€‘¹Õµ‰•È€è¹Õ±°ì(€€€ô((€€€ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸µ•Ñ„¡ÍÑÉ¥¹œ€‘­•ä¤è€ýÍÑÉ¥¹œ(€€€ì(€€€€€€€€‘ÍÑ…Ñ•µ•¹Ð€ô€‘Ñ¡¥Ì´ù‘…Ñ…‰…Í”´ùÁÉ•Á…É” (€€€€€€€€€€€€M1Pµ•Ñ…}Ù…±Õ”I=4µ½‘•±}µ•Ñ„]!Iµ•Ñ…}­•ä€ô€é­•äœ(€€€€€€€€¤ì(€€€€€€€€‘ÍÑ…Ñ•µ•¹Ð´ù•á•ÕÑ”¡lœé­•äœ€ôø€‘­•åt¤ì(€€€€€€€€‘Ù…±Õ”€ô€‘ÍÑ…Ñ•µ•¹Ð´ù™•Ñ¡½±Õµ¸ ¤ì(€€€€€€€É•ÑÕÉ¸€‘Ù…±Õ”€ôôô™…±Í”€ü¹Õ±°€è€¡ÍÑÉ¥¹œ¤€‘Ù…±Õ”ì(€€€ô((€€€ÁÉ¥Ù…Ñ”™Õ¹Ñ¥½¸Í•Ñ5•Ñ„¡ÍÑÉ¥¹œ€‘­•ä°ÍÑÉ¥¹œ€‘Ù…±Õ”¤èÙ½¥(€€€ì(€€€€€€€€‘ÍÑ…Ñ•µ•¹Ð€ô€‘Ñ¡¥Ì´ù‘…Ñ…‰…Í”´ùÁÉ•Á…É” (€€€€€€€€€€€€%9MIP%9Q<µ½‘•±}µ•Ñ„€¡µ•Ñ…}­•ä°µ•Ñ…}Ù…±Õ”¤(€€€€€€€€€€€€Y1UL€ é­•ä°€éÙ…±Õ”¤(€€€€€€€€€€€€=8=91%P¡µ•Ñ…}­•ä¤<UAQMPµ•Ñ…}Ù…±Õ”€ô•á±Õ‘•¹µ•Ñ…}Ù…±Õ”œ(€€€€€€€€¤ì(€€€€€€€€‘ÍÑ…Ñ•µ•¹Ð´ù•á•ÕÑ”¡lœé­•äœ€ôø€‘­•ä°€œéÙ…±Õ”œ€ôø€‘Ù…±Õ•t¤ì(€€€ô)ô