<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Pouze POST']);
    exit;
}

$appDns = getenv('APP_DNS') ?: 'eroze.apps2.r73.info';
$adminSecretFile = '/working/' . $appDns . '/admin_secret.txt';
$expectedSecret = is_file($adminSecretFile)
    ? trim((string)file_get_contents($adminSecretFile))
    : '';

if ($expectedSecret === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server není nakonfigurován (chybí admin tajemství)']);
    exit;
}

$passkey = (string)($_POST['passkey'] ?? '');
if (hash_equals($expectedSecret, $passkey)) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Neplatný passkey']);
}