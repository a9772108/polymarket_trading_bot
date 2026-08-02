<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $configFile = dirname(__DIR__, 2) . '/config/config.php';
    $fallbackFile = dirname(__DIR__, 2) . '/config/config.example.php';
    $config = require file_exists($configFile) ? $configFile : $fallbackFile;

    date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

    require_once dirname(__DIR__, 2) . '/src/OnChainSignalClient.php';
    $observer = new OnChainSignalClient($config['onchain_experiment'] ?? []);

    echo json_encode([
        'ok' => true,
        'onchain_experiment' => $observer->collect(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}
