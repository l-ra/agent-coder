<?php

declare(strict_types=1);

require __DIR__ . '/db.php';

$categoryFilter = trim((string)($_GET['category'] ?? ''));
$params = [];

$sql = 'SELECT id, url, title, source_type, category, summary, created_at FROM links';
if ($categoryFilter !== '') {
    $sql .= ' WHERE category = :category';
    $params[':category'] = $categoryFilter;
}
$sql .= ' ORDER BY datetime(created_at) DESC, id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$categories = $pdo->query('SELECT DISTINCT category FROM links ORDER BY category ASC')->fetchAll(PDO::FETCH_COLUMN);
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Eroze právního státu – sběr odkazů</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; max-width: 980px; }
        h1 { margin-bottom: 8px; }
        .muted { color: #666; font-size: 14px; }
        .card { border: 1px solid #ddd; border-radius: 8px; padding: 14px; margin: 12px 0; }
        label { display: block; margin-top: 8px; font-weight: 600; }
        input[type="text"], input[type="url"], textarea, select { width: 100%; padding: 8px; margin-top: 4px; box-sizing: border-box; }
        textarea { min-height: 100px; }
        button { margin-top: 12px; padding: 10px 14px; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .badge { display: inline-block; background: #eef; color: #224; border-radius: 999px; padding: 2px 10px; font-size: 12px; }
        .topbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    </style>
</head>
<body>
    <h1>Eroze právního státu</h1>
    <p class="muted">Databáze odkazů + ručně/AI vytvořená shrnutí + kategorie.</p>

    <div class="card">
        <form method="post" action="save.php">
            <label for="url">Odkaz</label>
            <input id="url" name="url" type="url" required placeholder="https://...">

            <div class="row">
                <div>
                    <label for="title">Titulek (volitelné)</label>
                    <input id="title" name="title" type="text" placeholder="Název článku/postu">
                </div>
                <div>
                    <label for="source_type">Zdroj</label>
                    <select id="source_type" name="source_type">
                        <option value="">-- vyber --</option>
                        <option value="web">web</option>
                        <option value="x">X/Twitter</option>
                        <option value="facebook">Facebook</option>
                        <option value="youtube">YouTube</option>
                        <option value="tiktok">TikTok</option>
                        <option value="other">jiné</option>
                    </select>
                </div>
            </div>

            <label for="category">Kategorie</label>
            <input id="category" name="category" type="text" required placeholder="např. střet zájmů, personální čistky, legislativní obcházení">

            <label for="summary">Shrnutí</label>
            <textarea id="summary" name="summary" required placeholder="Krátké shrnutí: co se stalo, proč je to relevantní pro erozi právního státu."></textarea>

            <button type="submit">Přidat záznam</button>
        </form>
    </div>

    <div class="topbar">
        <strong>Filtr:</strong>
        <a href="index.php">vše</a>
        <?php foreach ($categories as $cat): ?>
            <a href="?category=<?= urlencode((string)$cat) ?>"><span class="badge"><?= htmlspecialchars((string)$cat) ?></span></a>
        <?php endforeach; ?>
    </div>

    <?php if (!$items): ?>
        <p class="muted">Zatím žádné záznamy.</p>
    <?php else: ?>
        <?php foreach ($items as $item): ?>
            <article class="card">
                <div class="muted"><?= htmlspecialchars((string)$item['created_at']) ?> · <?= htmlspecialchars((string)($item['source_type'] ?: 'neuvedeno')) ?></div>
                <?php if (!empty($item['title'])): ?><h3><?= htmlspecialchars((string)$item['title']) ?></h3><?php endif; ?>
                <div><a href="<?= htmlspecialchars((string)$item['url']) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string)$item['url']) ?></a></div>
                <p><span class="badge"><?= htmlspecialchars((string)$item['category']) ?></span></p>
                <p><?= nl2br(htmlspecialchars((string)$item['summary'])) ?></p>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
