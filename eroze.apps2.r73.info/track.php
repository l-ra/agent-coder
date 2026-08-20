<?php

declare(strict_types=1);

require __DIR__ . '/db.php';

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(404);
    echo 'Neplatné ID';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM links WHERE id = :id');
$stmt->execute([':id' => $id]);
$item = $stmt->fetch();

if (!$item) {
    http_response_code(404);
    echo 'Záznam nenalezen';
    exit;
}

// Simple URL availability check via HEAD
$urlStatus = null;
$urlStatusCode = 0;

$ctx = stream_context_create([
    'http' => [
        'method' => 'HEAD',
        'timeout' => 6,
        'follow_location' => true,
        'max_redirects' => 3,
        'user_agent' => 'Mozilla/5.0 (compatible; ErozeMonitor/1.0)',
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ],
]);

$headers = @get_headers((string)$item['url'], 0, $ctx);
if ($headers && isset($headers[0])) {
    $lastHeader = is_array($headers) ? (string)end($headers) : (string)$headers;
    preg_match('/HTTP\/\d\.?\d?\s+(\d{3})/', $lastHeader, $m);
    if (!empty($m[1])) {
        $urlStatusCode = (int)$m[1];
        $urlStatus = match (true) {
            $urlStatusCode >= 200 && $urlStatusCode < 300 => '✅ Dostupný',
            $urlStatusCode >= 300 && $urlStatusCode < 400 => '⚠️ Přesměrování',
            $urlStatusCode >= 400 && $urlStatusCode < 500 => '❌ Chyba klienta',
            $urlStatusCode >= 500 => '❌ Chyba serveru',
            default => '❓ Neznámý',
        };
        $urlStatus .= ' (HTTP ' . $urlStatusCode . ')';
    }
} else {
    $urlStatus = '❌ Nedostupný / timeout';
}
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sledování – <?= htmlspecialchars((string)($item['title'] ?: '#' . $id)) ?> – Eroze právního státu</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; max-width: 780px; }
        h1 { margin: 0 0 8px 0; }
        .muted { color: #666; font-size: 14px; }
        .card { border: 1px solid #ddd; border-radius: 8px; padding: 14px; margin: 12px 0; }
        .badge { display: inline-block; background: #eef; color: #224; border-radius: 999px; padding: 2px 10px; font-size: 12px; }
        .status-card { background: #f5f5f5; border-left: 4px solid #888; padding: 10px 14px; margin: 12px 0; border-radius: 4px; }
        .status-ok { border-left-color: #2a2; background: #edf7ed; }
        .status-warn { border-left-color: #da2; background: #fff8e6; }
        .status-err { border-left-color: #c22; background: #fdeaea; }
        .info-row { margin: 4px 0; }
        .info-label { font-weight: 600; display: inline-block; width: 100px; }
        .btn { display: inline-block; padding: 8px 14px; border: 1px solid #ccc; border-radius: 6px; background: #fff; cursor: pointer; text-decoration: none; color: #000; font-size: 14px; }
        .btn:hover { background: #eee; }
    </style>
</head>
<body>
    <p><a href="index.php">&larr; Zpět na seznam</a></p>
    <div class="card">
        <div class="muted"><?= htmlspecialchars((string)$item['created_at']) ?> · <?= htmlspecialchars((string)($item['source_type'] ?: 'neuvedeno')) ?></div>
        <?php if (!empty($item['title'])): ?><h2><?= htmlspecialchars((string)$item['title']) ?></h2><?php endif; ?>
        <div><a href="<?= htmlspecialchars((string)$item['url']) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string)$item['url']) ?></a></div>
        <p><span class="badge"><?= htmlspecialchars((string)$item['category']) ?></span></p>
        <p><?= nl2br(htmlspecialchars((string)$item['summary'])) ?></p>
    </div>

    <h2>Stav odkazu</h2>
    <div class="status-card <?= $urlStatusCode >= 200 && $urlStatusCode < 300 ? 'status-ok' : ($urlStatusCode > 0 ? ($urlStatusCode < 400 ? 'status-warn' : 'status-err') : 'status-err') ?>">
        <p style="margin:0;font-size:18px;font-weight:600;"><?= htmlspecialchars((string)($urlStatus ?? 'Nezkontrolováno')) ?></p>
        <p class="muted" style="margin:4px 0 0 0;">Kontrola: HEAD požadavek s 6s timeoutem</p>
    </div>

    <p><a href="?id=<?= $id ?>" class="btn">🔄 Znovu zkontrolovat</a></p>
</body>
</html>