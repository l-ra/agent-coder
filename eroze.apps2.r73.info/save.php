<?php

declare(strict_types=1);

require __DIR__ . '/db.php';

$idRaw = trim((string)($_POST['id'] ?? ''));
$url = trim((string)($_POST['url'] ?? ''));
$title = trim((string)($_POST['title'] ?? ''));
$sourceType = trim((string)($_POST['source_type'] ?? ''));
$categoryDirect = trim((string)($_POST['category'] ?? ''));
$categoryExisting = trim((string)($_POST['category_existing'] ?? ''));
$categoryNew = trim((string)($_POST['category_new'] ?? ''));
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

$category = $categoryDirect;
if ($category === '') {
    if ($categoryExisting !== '') {
        $category = $categoryExisting;
    } elseif ($categoryNew !== '') {
        $category = $categoryNew;
    }
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

if ($idRaw !== '') {
    if (!ctype_digit($idRaw)) {
        http_response_code(422);
        echo 'Neplatné id';
        exit;
    }

    $stmt = $pdo->prepare(
        'UPDATE links
         SET url = :url, title = :title, source_type = :source_type, category = :category, summary = :summary
         WHERE id = :id'
    );

    $stmt->execute([
        ':url' => $url,
        ':title' => $title !== '' ? $title : null,
        ':source_type' => $sourceType !== '' ? $sourceType : null,
        ':category' => $category,
        ':summary' => $summary,
        ':id' => (int)$idRaw,
    ]);
} else {
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
}

header('Location: index.php');
exit;
