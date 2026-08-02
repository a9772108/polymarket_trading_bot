<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        throw new RuntimeException('This read-only endpoint accepts GET requests only.');
    }

    $projectRoot = dirname(__DIR__, 2);
    $settingsFile = $projectRoot . '/config/polymarket_international.local.php';
    $settings = file_exists($settingsFile) ? require $settingsFile : [];
    $address = strtolower(trim((string) ($settings['profile_address'] ?? '')));

    if ($address === '' || str_starts_with($address, 'paste_')) {
        echo json_encode([
            'ok' => true,
            'configured' => false,
            'execution_enabled' => false,
            'message' => 'Add your public Polymarket Profile Address to the protected local configuration.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (!preg_match('/^0x[a-f0-9]{40}$/', $address)) {
        throw new InvalidArgumentException('The configured Polymarket Profile Address is invalid.');
    }

    $positions = readPublicJson(
        'https://data-api.polymarket.com/positions?' . http_build_query([
            'user' => $address,
            'sizeThreshold' => 0.01,
            'limit' => 100,
            'sortBy' => 'CURRENT',
            'sortDirection' => 'DESC',
        ])
    );
    $values = readPublicJson(
        'https://data-api.polymarket.com/value?' . http_build_query(['user' => $address])
    );

    $normalizedPositions = [];
    foreach ($positions as $position) {
        if (!is_array($position)) {
            continue;
        }
        $normalizedPositions[] = [
            'market' => (string) ($position['title'] ?? $position['slug'] ?? 'Unnamed market'),
            'slug' => (string) ($position['slug'] ?? ''),
            'outcome' => (string) ($position['outcome'] ?? ''),
            'size' => numberOrNull($position['size'] ?? null),
            'average_price' => numberOrNull($position['avgPrice'] ?? null),
            'current_value' => numberOrNull($position['currentValue'] ?? null),
            'cash_pnl' => numberOrNull($position['cashPnl'] ?? null),
            'realized_pnl' => numberOrNull($position['realizedPnl'] ?? null),
            'redeemable' => (bool) ($position['redeemable'] ?? false),
        ];
    }

    $portfolioValue = null;
    if (isset($values[0]) && is_array($values[0])) {
        $portfolioValue = numberOrNull($values[0]['value'] ?? null);
    }

    $walletBalance = null;
    $walletBalanceAvailable = true;
    try {
        $walletBalance = readPusdBalance($address);
    } catch (Throwable) {
        $walletBalanceAvailable = false;
    }

    echo json_encode([
        'ok' => true,
        'configured' => true,
        'available' => true,
        'execution_enabled' => false,
        'portfolio' => [
            'profile_address' => $address,
            'wallet_balance' => $walletBalance,
            'wallet_balance_available' => $walletBalanceAvailable,
            'wallet_currency' => 'pUSD',
            'position_value' => $portfolioValue,
            'positions' => $normalizedPositions,
            'refreshed_at' => gmdate(DATE_ATOM),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'configured' => true,
        'execution_enabled' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(http_response_code() >= 400 ? http_response_code() : 502);
    echo json_encode([
        'ok' => false,
        'configured' => true,
        'execution_enabled' => false,
        'error' => 'The public international portfolio service is temporarily unavailable.',
    ], JSON_UNESCAPED_SLASHES);
}

function readPublicJson(string $url): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize the public portfolio request.');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($body === false || $error !== '' || $status < 200 || $status >= 300) {
        throw new RuntimeException('The public portfolio request failed.');
    }

    $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('The public portfolio response was invalid.');
    }
    return $decoded;
}

function numberOrNull(mixed $value): ?float
{
    return is_numeric($value) ? (float) $value : null;
}

function readPusdBalance(string $address): float
{
    $pusdContract = '0xC011a7E12a19f7B1f670d46F03B03f3342E82DFB';
    $balanceOfSelector = '70a08231';
    $encodedAddress = str_pad(substr($address, 2), 64, '0', STR_PAD_LEFT);
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'eth_call',
        'params' => [
            [
                'to' => $pusdContract,
                'data' => '0x' . $balanceOfSelector . $encodedAddress,
            ],
            'latest',
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $handle = curl_init('https://polygon.drpc.org');
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize the Polygon balance request.');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
        ],
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($body === false || $error !== '' || $status < 200 || $status >= 300) {
        throw new RuntimeException('The Polygon balance request failed.');
    }

    $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    $hex = is_array($decoded) ? (string) ($decoded['result'] ?? '') : '';
    if (!preg_match('/^0x[0-9a-fA-F]+$/', $hex)) {
        throw new RuntimeException('The Polygon balance response was invalid.');
    }

    $baseUnits = 0.0;
    foreach (str_split(substr($hex, 2)) as $digit) {
        $baseUnits = ($baseUnits * 16) + hexdec($digit);
    }

    return $baseUnits / 1_000_000;
}
