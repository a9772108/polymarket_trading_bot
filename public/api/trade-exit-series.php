<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        throw new RuntimeException('Method not allowed.');
    }

    $tradeId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($tradeId === false || $tradeId === null) {
        throw new InvalidArgumentException('A valid trade id is required.');
    }

    $projectRoot = dirname(__DIR__, 2);
    $configFile = $projectRoot . '/config/config.php';
    $fallbackFile = $projectRoot . '/config/config.example.php';
    $config = require file_exists($configFile) ? $configFile : $fallbackFile;

    date_default_timezone_set($config['app']['timezone'] ?? 'UTC');
    require_once $projectRoot . '/src/PaperModel.php';
    require_once $projectRoot . '/src/MarketMode.php';

    $mode = MarketMode::fromRequest($_GET['market'] ?? null);
    $paperModel = new PaperModel(
        MarketMode::paperSettings($config, $mode, $projectRoot),
        $projectRoot
    );
    $result = $paperModel->tradeExitSeries((int) $tradeId);

    if ($result === null) {
        http_response_code(404);
        throw new RuntimeException('Trade not found.');
    }

    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(http_response_code() >= 400 ? http_response_code() : 500);
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES);
}
