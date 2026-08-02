<?php

declare(strict_types=1);

final class OnChainSignalClient
{
    public function __construct(private readonly array $config)
    {
    }

    public function collect(): array
    {
        $generatedAt = date(DATE_ATOM);
        $errors = [];
        $mempool = null;
        $recent = null;
        $flow = $this->neutralFlowSignal();

        if (!(bool) ($this->config['enabled'] ?? false)) {
            return [
                'enabled' => false,
                'status' => 'disabled',
                'generated_at' => $generatedAt,
                'applied_to_trading' => false,
                'mempool' => null,
                'recent_transactions' => null,
                'flow_signal' => $flow,
                'errors' => [],
                'snapshot_recorded' => false,
            ];
        }

        $baseUrl = rtrim((string) ($this->config['mempool_base_url'] ?? ''), '/');
        if ($baseUrl !== '') {
            try {
                $summary = $this->getJson($baseUrl . '/mempool');
                $mempool = [
                    'transaction_count' => $this->integerOrNull($summary['count'] ?? null),
                    'virtual_size_vbytes' => $this->integerOrNull($summary['vsize'] ?? null),
                    'total_fees_btc' => $this->satsToBtc($summary['total_fee'] ?? null),
                ];
            } catch (Throwable $exception) {
                $errors[] = 'Mempool summary unavailable: ' . $exception->getMessage();
            }

            try {
                $transactions = $this->getJson($baseUrl . '/mempool/recent');
                $values = [];
                foreach ($transactions as $transaction) {
                    if (is_array($transaction) && is_numeric($transaction['value'] ?? null)) {
                        $values[] = (float) $transaction['value'];
                    }
                }
                $recent = [
                    'sample_size' => count($values),
                    'total_output_btc' => round(array_sum($values) / 100_000_000, 8),
                    'largest_output_btc' => round(($values === [] ? 0.0 : max($values)) / 100_000_000, 8),
                    'note' => 'Output values include change and are not exchange-flow attribution.',
                ];
            } catch (Throwable $exception) {
                $errors[] = 'Recent mempool sample unavailable: ' . $exception->getMessage();
            }
        }

        $feedUrl = trim((string) ($this->config['signal_feed_url'] ?? ''));
        if ($feedUrl !== '') {
            try {
                $flow = $this->buildFlowSignal($this->getJson($feedUrl));
            } catch (Throwable $exception) {
                $errors[] = 'Exchange-flow feed unavailable: ' . $exception->getMessage();
            }
        }

        $snapshot = [
            'enabled' => true,
            'status' => ($mempool !== null || $recent !== null) ? ($errors === [] ? 'observing' : 'degraded') : 'unavailable',
            'generated_at' => $generatedAt,
            'applied_to_trading' => false,
            'mempool' => $mempool,
            'recent_transactions' => $recent,
            'flow_signal' => $flow,
            'errors' => $errors,
        ];
        $snapshot['snapshot_recorded'] = $this->recordSnapshot($snapshot);

        return $snapshot;
    }

    private function neutralFlowSignal(): array
    {
        return [
            'available' => false,
            'direction' => 'neutral',
            'net_outflow_btc_5m' => null,
            'raw_score' => 0.0,
            'suggested_probability_adjustment' => 0.0,
            'note' => 'No point-in-time exchange-flow feed is configured.',
        ];
    }

    private function buildFlowSignal(array $feed): array
    {
        $asOf = strtotime((string) ($feed['as_of'] ?? ''));
        if ($asOf === false) {
            throw new RuntimeException('Feed is missing a valid as_of timestamp.');
        }
        $maxAge = max(1, (int) ($this->config['max_feed_age_seconds'] ?? 120));
        if (abs(time() - $asOf) > $maxAge) {
            throw new RuntimeException('Feed is stale.');
        }

        $inflow = $this->requiredFloat($feed, 'exchange_inflow_btc_5m');
        $outflow = $this->requiredFloat($feed, 'exchange_outflow_btc_5m');
        $netOutflow = $outflow - $inflow;
        $scale = max(0.000001, (float) ($this->config['flow_scale_btc'] ?? 50.0));
        $score = tanh($netOutflow / $scale);
        $maxAdjustment = min(0.10, max(0.0, (float) ($this->config['max_probability_adjustment'] ?? 0.03)));

        return [
            'available' => true,
            'as_of' => date(DATE_ATOM, $asOf),
            'direction' => $score > 0.10 ? 'up' : ($score < -0.10 ? 'down' : 'neutral'),
            'exchange_inflow_btc_5m' => round($inflow, 8),
            'exchange_outflow_btc_5m' => round($outflow, 8),
            'net_outflow_btc_5m' => round($netOutflow, 8),
            'raw_score' => round($score, 6),
            'suggested_probability_adjustment' => round($score * $maxAdjustment, 6),
            'note' => 'Experimental hypothesis only; positive means net exchange outflow.',
        ];
    }

    private function recordSnapshot(array $snapshot): bool
    {
        if (!(bool) ($this->config['record_snapshots'] ?? false)) {
            return false;
        }
        $path = (string) ($this->config['snapshot_log'] ?? '');
        if ($path === '') {
            return false;
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            return false;
        }
        $minimumInterval = max(1, (int) ($this->config['snapshot_min_interval_seconds'] ?? 30));
        if (is_file($path) && (time() - (int) filemtime($path)) < $minimumInterval) {
            return false;
        }
        $line = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== false;
    }

    private function getJson(string $url): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Only HTTP(S) data sources are supported.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the data request.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => max(1, (int) ($this->config['request_timeout_seconds'] ?? 4)),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'PolymarketPaperTrader-OnChainExperiment/0.1',
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $error !== '') {
            throw new RuntimeException($error !== '' ? $error : 'Empty response.');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Data source returned HTTP %d.', $status));
        }
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Unexpected data format.');
        }
        return $decoded;
    }

    private function requiredFloat(array $data, string $key): float
    {
        if (!is_numeric($data[$key] ?? null)) {
            throw new RuntimeException(sprintf('Feed is missing numeric %s.', $key));
        }
        return (float) $data[$key];
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function satsToBtc(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value / 100_000_000, 8) : null;
    }
}
