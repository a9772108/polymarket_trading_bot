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
    ],
    'paper_trading' => [
        'enabled' => true,
        'starting_balance' => 1000.00,
        'max_position_usd' => 5.00,
        'daily_loss_limit_usd' => 10.00,
        'minimum_edge' => 0.05,
        'assumed_slippage' => 0.01,
    ],
    'database' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=polymarket_bot;charset=utf8mb4',
        'username' => 'polymarket_user',
        'password' => 'CHANGE_ME',
    ],
];
