<?php

declare(strict_types=1);

final class MarketMode
{
    private const MODES = [
        'btc-5m' => [
            'key' => 'btc-5m',
            'asset' => 'btc',
            'asset_name' => 'Bitcoin',
            'symbol' => 'BTC',
            'interval_minutes' => 5,
            'label' => 'BTC 5-Minute',
            'chainlink_symbol' => 'btc/usd',
            'binance_symbol' => 'btcusdt',
            'database_filename' => 'paper_trader.sqlite',
        ],
        'eth-15m' => [
            'key' => 'eth-15m',
            'asset' => 'eth',
            'asset_name' => 'Ethereum',
            'symbol' => 'ETH',
            'interval_minutes' => 15,
            'label' => 'ETH 15-Minute',
            'chainlink_symbol' => 'eth/usd',
            'binance_symbol' => 'ethusdt',
            'database_filename' => 'paper_trader_eth_15m.sqlite',
        ],
    ];

    public static function fromRequest(mixed $value): array
    {
        $key = is_string($value) ? strtolower(trim($value)) : '';
        return self::MODES[$key] ?? self::MODES['btc-5m'];
    }

    public static function paperSettings(array $config, array $mode, string $projectRoot): array
    {
        $settings = (array) ($config['paper_trading'] ?? []);
        $modeOverrides = (array) (($config['paper_market_modes'] ?? [])[$mode['key']] ?? []);
        $databasePath = $projectRoot . '/storage/' . $mode['database_filename'];

        if ($mode['key'] === 'btc-5m' && isset($settings['model_database'])) {
            $databasePath = (string) $settings['model_database'];
        }
        if (isset($modeOverrides['model_database'])) {
            $databasePath = (string) $modeOverrides['model_database'];
        }

        return array_replace(
            $settings,
            $modeOverrides,
            ['model_database' => $databasePath]
        );
    }

    public static function matchesMarket(array $mode, array $market): bool
    {
        $text = strtolower(
            (string) ($market['slug'] ?? '')
            . ' '
            . (string) ($market['question'] ?? '')
        );
        $assetMatches = str_contains($text, (string) $mode['asset'])
            || str_contains($text, strtolower((string) $mode['asset_name']));
        $interval = (int) $mode['interval_minutes'];
        $intervalMatches = str_contains($text, $interval . 'm')
            || str_contains($text, $interval . ' min')
            || str_contains($text, $interval . '-minute');

        return $assetMatches && $intervalMatches;
    }
}
