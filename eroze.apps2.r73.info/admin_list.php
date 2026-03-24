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

$limitRaw = trim((string)($_POST['limit'] ?? '20'));
$limit = ctype_digit($limitRaw) ? (int)$limitRaw : 20;
if ($limit < 1) {
    $limit = 1;
}
if ($limit > 100) {
    $limit = 100;
}

$pdo = new PDO('sqlite:/working/' . $appDns . '/app.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    'SELECT id, url, title, source_type, category, summary, created_at
     FROM links
     ORDER BY datetime(created_at) DESC, id DESC
     LIMIT :limit'
);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'count' => count($items),
    'items' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
