<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $projectRoot = dirname(__DIR__, 2);
    $configFile = $projectRoot . '/config/config.php';
    $fallbackFile = $projectRoot . '/config/config.example.php';
    $config = require file_exists($configFile) ? $configFile : $fallbackFile;
    date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

    require_once $projectRoot . '/src/HistoricalBacktest.php';
    $engine = new HistoricalBacktest(
        (array) ($config['polymarket'] ?? []),
        (array) ($config['paper_trading'] ?? []),
        $projectRoot
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_contains($contentType, 'application/json')) {
            throw new InvalidArgumentException('The backtest endpoint accepts JSON only.');
        }
        $body = file_get_contents('php://input');
        if ($body === false || strlen($body) > 20_000) {
            throw new InvalidArgumentException('The backtest request is invalid.');
        }
        $parameters = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($parameters)) {
            throw new InvalidArgumentException('Backtest parameters must be an object.');
        }
        $result = $engine->run($parameters);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $latest = $engine->latest();
        $result = $latest ?? [
            'ok' => true,
            'generated_at' => null,
            'run_id' => null,
            'summary' => null,
            'markets' => [],
            'equity_curve' => [],
            'assumptions' => [],
        ];
    } else {
        http_response_code(405);
        header('Allow: GET, POST');
        throw new RuntimeException('Method not allowed.');
    }

    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(http_response_code() >= 400 ? http_response_code() : 500);
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES);
}
