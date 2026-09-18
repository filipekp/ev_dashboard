<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive', true);

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['status' => 'error', 'code' => 'method_not_allowed']);
    exit;
}

try {
    $raw = (string)file_get_contents('php://input');
    $token = trim((string)($_GET['token'] ?? ''));
    $removed = $app->kiaPleos()->handleSharingEnd($raw, $token);
    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'connections_removed' => $removed,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[EV Stats Kia sharing-end callback] ' . $e->getMessage());
    http_response_code(401);
    echo json_encode(['status' => 'error', 'code' => 'invalid_callback']);
}
