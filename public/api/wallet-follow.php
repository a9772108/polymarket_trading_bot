<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $projectRoot = dirname(__DIR__, 2);
    $configFile = $projectRoot . '/config/config.php';
    $config = require file_exists($configFile) ? $configFile : $projectRoot . '/config/config.example.php';
    date_default_timezone_set((string) ($config['app']['timezone'] ?? 'UTC'));
    require_once $projectRoot . '/src/WalletPaperFollower.php';
    $databasePath = (string) ($config['paper_trading']['wallet_follower_database'] ?? $projectRoot . '/storage/wallet_follower.sqlite');
    $follower = new WalletPaperFollower($config, $databasePath);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $view = strtolower(trim((string) ($_GET['view'] ?? 'latest')));
        $result = match ($view) {
            'history' => $follower->history(),
            'day' => $follower->dayStatus((string) ($_GET['wallet'] ?? ''), (string) ($_GET['date'] ?? '')),
            default => $follower->latestStatus(),
        };
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $action = strtolower(trim((string) ($payload['action'] ?? '')));
    $result = match ($action) {
        'start' => $follower->start((string) ($payload['wallet'] ?? ''), (float) ($payload['stake'] ?? 5)),
        'stop' => $follower->stop((int) ($payload['session_id'] ?? 0)),
        'poll' => $follower->poll((int) ($payload['session_id'] ?? 0)),
        default => throw new InvalidArgumentException('Unsupported wallet follower action.'),
    };
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES);
}
