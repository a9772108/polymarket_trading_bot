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

    require_once $projectRoot . '/src/PolymarketClient.php';
    require_once $projectRoot . '/src/PaperModel.php';
    require_once $projectRoot . '/src/MarketMode.php';

    $mode = MarketMode::fromRequest($_GET['market'] ?? null);
    $paperModel = new PaperModel(
        MarketMode::paperSettings($config, $mode, $projectRoot),
        $projectRoot
    );
    $client = new PolymarketClient((array) $config['polymarket']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_contains($contentType, 'application/json')) {
            throw new InvalidArgumentException('The observation endpoint accepts JSON only.');
        }

        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || strlen($rawBody) > 100_000) {
            throw new InvalidArgumentException('The observation payload is invalid.');
        }

        $payload = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('The observation payload must be an object.');
        }
        if (!MarketMode::matchesMarket($mode, (array) ($payload['market'] ?? []))) {
            throw new InvalidArgumentException('The observation does not match the selected paper market.');
        }

        $result = $paperModel->observe($payload);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $result = $paperModel->summary();
    } else {
        http_response_code(405);
        header('Allow: GET, POST');
        throw new RuntimeException('Method not allowed.');
    }

    $paperModel->settleExpiredMarkets($client);
    $result = $paperModel->summary();

    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(http_response_code() >= 400 ? http_response_code() : 500);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}
