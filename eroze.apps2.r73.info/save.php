<?php

declare(strict_types=1);

require __DIR__ . '/db.php';

$url = trim((string)($_POST['url'] ?? ''));
$title = trim((string)($_POST['title'] ?? ''));
$sourceType = trim((string)($_POST['source_type'] ?? ''));
$category = trim((string)($_POST['category'] ?? ''));
$summary = trim((string)($_POST['summary'] ?? ''));
$secret = (string)($_POST['secret'] ?? '');

$appDns = getenv('APP_DNS') ?: 'eroze.apps2.r73.info';
$secretFile = '/working/' . $appDns . '/ingest_secret.txt';
$expectedSecret = is_file($secretFile) ? trim((string)file_get_contents($secretFile)) : '';

if ($expectedSecret === '') {
    http_response_code(500);
    echo 'Server není nakonfigurován (chybí tajemství).';
    exit;
}

if (!hash_equals($expectedSecret, $secret)) {
    http_response_code(403);
    echo 'Neplatné tajemství';
    exit;
}

if ($url === '' || $category === '' || $summary === '') {
    http_response_code(422);
    echo 'Povinná pole: url, category, summary';
    exit;
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(422);
    echo 'Neplatná URL';
    exit;
}

$stmt = $pdo->prepare(
    'INSERT INTO links (url, title, source_type, category, summary) VALUES (:url, :title, :source_type, :category, :summary)'
);

$stmt->execute([
    ':url' => $url,
    ':title' => $title !== '' ? $title : null,
    ':source_type' => $sourceType !== '' ? $sourceType : null,
    ':category' => $category,
    ':summary' => $summary,
]);

header('Location: index.php');
exit;
