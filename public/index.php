<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = rtrim(is_string($path) ? $path : '/', '/');
if ($path === '') {
    $path = '/';
}

if ($path !== '/api/v1/hanja/batch') {
    http_response_code(404);
    echo json_encode(['error' => 'not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    hanja_boot();

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($payload) || !isset($payload['tokens']) || !is_array($payload['tokens'])) {
        http_response_code(400);
        echo json_encode(['error' => 'tokens array required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $matches = hanja_lookup(hanja_pdo(), $payload['tokens']);
    echo json_encode(['matches' => (object) $matches], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[hanja] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server error'], JSON_UNESCAPED_UNICODE);
}
