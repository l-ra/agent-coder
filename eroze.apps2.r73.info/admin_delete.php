<?php

declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Pouze POST';
    exit;
}

$appDns = getenv('APP_DNS') ?: 'eroze.apps2.r73.info';
$adminSecretFile = '/working/' . $appDns . '/admin_secret.txt';
$expectedSecret = is_file($adminSecretFile)
    ? trim((string)file_get_contents($adminSecretFile))
    : '';

if ($expectedSecret === '') {
    http_response_code(500);
    echo 'Server není nakonfigurován (chybí admin tajemství).';
    exit;
}

$secret = (string)($_POST['secret'] ?? '');
if (!hash_equals($expectedSecret, $secret)) {
    http_response_code(403);
    echo 'Neplatné admin tajemství';
    exit;
}

$url = trim((string)($_POST['url'] ?? ''));
$idRaw = trim((string)($_POST['id'] ?? ''));

if ($url === '' && $idRaw === '') {
    http_response_code(422);
    echo 'Povinné pole: url nebo id';
    exit;
}

$pdo = new PDO('sqlite:/working/' . $appDns . '/app.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($idRaw !== '') {
    if (!ctype_digit($idRaw)) {
        http_response_code(422);
        echo 'Neplatné id';
        exit;
    }

    $stmt = $pdo->prepare('DELETE FROM links WHERE id = :id');
    $stmt->execute([':id' => (int)$idRaw]);
} else {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        http_response_code(422);
        echo 'Neplatná URL';
        exit;
    }

    $stmt = $pdo->prepare('DELETE FROM links WHERE url = :url');
    $stmt->execute([':url' => $url]);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'deleted' => $stmt->rowCount(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
