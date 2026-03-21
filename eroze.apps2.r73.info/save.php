<?php

declare(strict_types=1);

require __DIR__ . '/db.php';

$url = trim((string)($_POST['url'] ?? ''));
$title = trim((string)($_POST['title'] ?? ''));
$sourceType = trim((string)($_POST['source_type'] ?? ''));
$category = trim((string)($_POST['category'] ?? ''));
$summary = trim((string)($_POST['summary'] ?? ''));

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
