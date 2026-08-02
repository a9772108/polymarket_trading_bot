<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'Polymarket Paper Trader',
        'timezone' => 'America/Puerto_Rico',
        'environment' => 'development',
    ],
    'polymarket' => [
        'gamma_base_url' => 'https://gamma-api.polymarket.com',
        'clob_base_url' => 'https://clob.polymarket.com',
        'geoblock_url' => 'https://polymarket.com/api/geoblock',
        'market_websocket_url' => 'wss://ws-subscriptions-clob.polymarket.com/ws/market',
        'data_base_url' => 'https://data-api.polymarket.com',
        'binance_base_url' => 'https://data-api.binance.vision',
    ],
    'paper_trading' => [
        'enabled' => true,
        'starting_balance' => 1000.00,
        'max_position_usd' => 5.00,
        'daily_loss_limit_usd' => 10.00,
        'minimum_edge' => 0.05,
        'assumed_slippage' => 0.01,
        'maximum_spread' => 0.03,
        'decision_window_seconds' => 60,
        'minimum_seconds_remaining' => 15,
        'model_database' => dirname(__DIR__) . '/storage/paper_trader.sqlite',
        'backtest_database' => dirname(__DIR__) . '/storage/backtests.sqlite',
    ],
    'paper_market_modes' => [
        'btc-5m' => [],
        'eth-15m' => [
            // Optional ETH-specific safeguards can be set here.
            // 'minimum_edge' => 0.05,
            // 'decision_window_seconds' => 60,
        ],
    ],
    'onchain_experiment' => [
        'enabled' => true,
        'apply_to_trading' => false,
        'mempool_base_url' => 'https://mempool.space/api',
        'signal_feed_url' => null,
        'request_timeout_seconds' => 4,
        'max_feed_age_seconds' => 120,
        'flow_scale_btc' => 50.0,
        'max_probability_adjustment' => 0.03,
        'record_snapshots' => true,
        'snapshot_min_interval_seconds' => 30,
        'snapshot_log' => dirname(__DIR__) . '/storage/logs/onchain-snapshots.jsonl',
    ],
    'database' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=polymarket_bot;charset=utf8mb4',
        'username' => 'polymarket_user',
        'password' => 'CHANGE_ME',
    ],
];
