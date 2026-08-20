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

$totalCount = (int)$pdo->query('SELECT COUNT(*) FROM links')->fetchColumn();
$categoryStatsRows = $pdo->query('SELECT category, COUNT(*) AS cnt FROM links GROUP BY category ORDER BY category ASC')->fetchAll();
$categoryCounts = [];
foreach ($categoryStatsRows as $row) {
    $catName = (string)$row['category'];
    $categoryCounts[$catName] = (int)$row['cnt'];
}
$categories = array_keys($categoryCounts);
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Eroze právního státu – sběr odkazů</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; max-width: 980px; }
        h1 { margin: 0; }
        .muted { color: #666; font-size: 14px; }
        .card { border: 1px solid #ddd; border-radius: 8px; padding: 14px; margin: 12px 0; }
        label { display: block; margin-top: 8px; font-weight: 600; }
        input[type="text"], input[type="url"], input[type="password"], textarea, select { width: 100%; padding: 8px; margin-top: 4px; box-sizing: border-box; }
        textarea { min-height: 100px; }
        button { margin-top: 12px; padding: 10px 14px; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .badge { display: inline-block; background: #eef; color: #224; border-radius: 999px; padding: 2px 10px; font-size: 12px; }
        .topbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .hidden { display: none; }
        .small { font-size: 13px; }
        .titlebar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .icon-btn {
            width: 34px;
            height: 34px;
            padding: 0;
            margin: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            line-height: 1;
            border: 1px solid #ccc;
            border-radius: 8px;
            background: #fff;
            cursor: pointer;
        }
        .record-card { position: relative; padding-right: 52px; }
        .card-edit-btn { position: absolute; top: 10px; right: 10px; }
        .admin-bar { background: #fdf6e3; border: 1px solid #ccc; border-radius: 8px; padding: 10px 14px; margin: 12px 0; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .admin-bar.hidden { display: none; }
        .admin-bar input[type="password"] { width: 200px; padding: 6px 8px; }
        .admin-bar button { padding: 6px 12px; margin: 0; }
        .btn-small { padding: 4px 10px; font-size: 12px; border: 1px solid #ccc; border-radius: 6px; background: #fff; cursor: pointer; text-decoration: none; color: #000; }
        .btn-small:hover { background: #eee; }
        .btn-danger { border-color: #c22; color: #c22; }
        .btn-danger:hover { background: #fdeaea; }
        .record-actions { margin-top: 8px; display: flex; gap: 6px; }
        .admin-only { display: none; }
        .admin-visible .admin-only { display: inline-block; }
    </style>
</head>
<body>
    <div class="titlebar">
        <h1>Eroze právního státu</h1>
        <div style="display:flex;gap:6px;">
            <button type="button" class="icon-btn" id="adminLoginBtn" onclick="toggleAdmin()" aria-label="Admin přihlášení" title="Admin přihlášení">🔑</button>
            <button type="button" class="icon-btn" id="toggleFormBtn" onclick="toggleAddForm()" aria-label="Přidat nový záznam" title="Přidat nový záznam">➕</button>
        </div>
    </div>

    <div class="admin-bar hidden" id="adminBar">
        <label for="adminPasskey">Passkey:</label>
        <input type="password" id="adminPasskey" placeholder="Admin passkey" onkeydown="if(event.key==='Enter')adminLogin()">
        <button type="button" onclick="adminLogin()">🔓 Přihlásit</button>
        <span id="adminStatus"></span>
        <button type="button" class="btn-small" id="adminLogoutBtn" onclick="adminLogout()" style="display:none;">🚪 Odhlásit</button>
    </div>

    <p class="muted">Databáze odkazů + ručně/AI vytvořená shrnutí + kategorie.</p>
    <p>
        Tahle stránka slouží jako průběžný archiv případů, které mohou souviset s oslabováním pravidel právního státu.
        Každý záznam obsahuje odkaz na zdroj, stručné shrnutí a tematickou kategorii, aby šlo případy snadno filtrovat a porovnávat v čase.
    </p>

    <div class="card hidden" id="addFormCard">
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

            <label for="category_existing">Kategorie (preferuj existující štítek)</label>
            <select id="category_existing" name="category_existing">
                <option value="">-- vyber existující štítek --</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars((string)$cat) ?>"><?= htmlspecialchars((string)$cat) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="category_new">Nový štítek (jen pokud žádný existující není vhodný)</label>
            <input id="category_new" name="category_new" type="text" placeholder="např. střet zájmů, personální čistky, legislativní obcházení">

            <label for="summary">Shrnutí</label>
            <textarea id="summary" name="summary" required placeholder="Krátké shrnutí: co se stalo, proč je to relevantní pro erozi právního státu."></textarea>

            <label for="secret">Tajemství</label>
            <input id="secret" name="secret" type="password" required placeholder="Sdílené tajemství pro zápis i úpravu">

            <button type="submit">Přidat záznam</button>
            <p class="muted small">Pokud vyplníš existující kategorii i nový štítek, použije se existující kategorie.</p>
        </form>
    </div>

    <div class="topbar">
        <strong>Filtr:</strong>
        <a href="index.php"><span class="badge">vše (<?= $totalCount ?>)</span></a>
        <?php foreach ($categories as $cat): ?>
            <a href="?category=<?= urlencode((string)$cat) ?>"><span class="badge"><?= htmlspecialchars((string)$cat) ?> (<?= (int)($categoryCounts[(string)$cat] ?? 0) ?>)</span></a>
        <?php endforeach; ?>
    </div>

    <?php if (!$items): ?>
        <p class="muted">Zatím žádné záznamy.</p>
    <?php else: ?>
        <?php foreach ($items as $item): ?>
            <article class="card record-card" id="item-<?= (int)$item['id'] ?>">
                <button type="button" class="icon-btn card-edit-btn" onclick="toggleEditForm(<?= (int)$item['id'] ?>)" aria-label="Upravit záznam" title="Upravit záznam">✏️</button>
                <div class="muted"><?= htmlspecialchars((string)$item['created_at']) ?> · <?= htmlspecialchars((string)($item['source_type'] ?: 'neuvedeno')) ?></div>
                <?php if (!empty($item['title'])): ?><h3><?= htmlspecialchars((string)$item['title']) ?></h3><?php endif; ?>
                <div><a href="<?= htmlspecialchars((string)$item['url']) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string)$item['url']) ?></a></div>
                <p><span class="badge"><?= htmlspecialchars((string)$item['category']) ?></span></p>
                <p><?= nl2br(htmlspecialchars((string)$item['summary'])) ?></p>
                <div class="record-actions">
                    <a href="track.php?id=<?= (int)$item['id'] ?>" class="btn-small">🔍 Sledovat</a>
                    <button type="button" class="btn-small btn-danger admin-only" onclick="adminDelete(<?= (int)$item['id'] ?>)">🗑 Smazat</button>
                </div>

                <div class="card hidden" id="editForm-<?= (int)$item['id'] ?>">
                    <form method="post" action="save.php">
                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">

                        <label for="url-<?= (int)$item['id'] ?>">Odkaz</label>
                        <input id="url-<?= (int)$item['id'] ?>" name="url" type="url" required value="<?= htmlspecialchars((string)$item['url']) ?>">

                        <div class="row">
                            <div>
                                <label for="title-<?= (int)$item['id'] ?>">Titulek (volitelné)</label>
                                <input id="title-<?= (int)$item['id'] ?>" name="title" type="text" value="<?= htmlspecialchars((string)$item['title']) ?>">
                            </div>
                            <div>
                                <label for="source_type-<?= (int)$item['id'] ?>">Zdroj</label>
                                <select id="source_type-<?= (int)$item['id'] ?>" name="source_type">
                                    <option value="" <?= ($item['source_type'] ?? '') === '' ? 'selected' : '' ?>>-- vyber --</option>
                                    <option value="web" <?= ($item['source_type'] ?? '') === 'web' ? 'selected' : '' ?>>web</option>
                                    <option value="x" <?= ($item['source_type'] ?? '') === 'x' ? 'selected' : '' ?>>X/Twitter</option>
                                    <option value="facebook" <?= ($item['source_type'] ?? '') === 'facebook' ? 'selected' : '' ?>>Facebook</option>
                                    <option value="youtube" <?= ($item['source_type'] ?? '') === 'youtube' ? 'selected' : '' ?>>YouTube</option>
                                    <option value="tiktok" <?= ($item['source_type'] ?? '') === 'tiktok' ? 'selected' : '' ?>>TikTok</option>
                                    <option value="other" <?= ($item['source_type'] ?? '') === 'other' ? 'selected' : '' ?>>jiné</option>
                                </select>
                            </div>
                        </div>

                        <label for="category_existing-<?= (int)$item['id'] ?>">Kategorie (preferuj existující štítek)</label>
                        <select id="category_existing-<?= (int)$item['id'] ?>" name="category_existing">
                            <option value="">-- vyber existující štítek --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars((string)$cat) ?>" <?= ((string)$item['category'] === (string)$cat) ? 'selected' : '' ?>><?= htmlspecialchars((string)$cat) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label for="category_new-<?= (int)$item['id'] ?>">Nový štítek (jen pokud žádný existující není vhodný)</label>
                        <input id="category_new-<?= (int)$item['id'] ?>" name="category_new" type="text" placeholder="nový štítek (volitelné)">

                        <label for="summary-<?= (int)$item['id'] ?>">Shrnutí</label>
                        <textarea id="summary-<?= (int)$item['id'] ?>" name="summary" required><?= htmlspecialchars((string)$item['summary']) ?></textarea>

                        <label for="secret-<?= (int)$item['id'] ?>">Tajemství</label>
                        <input id="secret-<?= (int)$item['id'] ?>" name="secret" type="password" required placeholder="Stejné tajemství jako pro přidání">

                        <button type="submit">Uložit změny</button>
                        <p class="muted small">Pokud vyplníš existující kategorii i nový štítek, použije se existující kategorie.</p>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>

    <script>
        let adminPasskey = '';

        function toggleAddForm() {
            const card = document.getElementById('addFormCard');
            const btn = document.getElementById('toggleFormBtn');
            const isHidden = card.classList.contains('hidden');

            if (isHidden) {
                card.classList.remove('hidden');
                btn.textContent = '✖';
                btn.setAttribute('aria-label', 'Zavřít formulář');
                btn.setAttribute('title', 'Zavřít formulář');
            } else {
                card.classList.add('hidden');
                btn.textContent = '➕';
                btn.setAttribute('aria-label', 'Přidat nový záznam');
                btn.setAttribute('title', 'Přidat nový záznam');
            }
        }

        function toggleEditForm(id) {
            const form = document.getElementById('editForm-' + id);
            if (!form) return;
            form.classList.toggle('hidden');
        }

        function toggleAdmin() {
            const bar = document.getElementById('adminBar');
            bar.classList.toggle('hidden');
            if (!bar.classList.contains('hidden')) {
                document.getElementById('adminPasskey').focus();
            }
        }

        function adminLogin() {
            const passkey = document.getElementById('adminPasskey').value;
            const status = document.getElementById('adminStatus');
            if (!passkey) {
                status.textContent = '⚠️ Zadej passkey';
                return;
            }

            const formData = new URLSearchParams();
            formData.set('passkey', passkey);

            status.textContent = '⏳ Ověřuji...';

            fetch('admin_verify.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString(),
            })
            .then(res => {
                if (!res.ok) throw new Error('Neplatný passkey');
                return res.json();
            })
            .then(data => {
                if (data.ok) {
                    adminPasskey = passkey;
                    status.textContent = '✅ Přihlášen jako admin';
                    status.style.color = '#2a2';
                    document.getElementById('adminLoginBtn').textContent = '🔓';
                    document.getElementById('adminLogoutBtn').style.display = 'inline-block';
                    document.querySelectorAll('.admin-only').forEach(el => el.classList.add('admin-visible'));
                    document.querySelectorAll('.record-card').forEach(el => el.classList.add('admin-visible'));
                }
            })
            .catch(err => {
                status.textContent = '❌ ' + err.message;
                status.style.color = '#c22';
                adminPasskey = '';
            });
        }

        function adminLogout() {
            adminPasskey = '';
            document.getElementById('adminPasskey').value = '';
            document.getElementById('adminStatus').textContent = '';
            document.getElementById('adminLoginBtn').textContent = '🔑';
            document.getElementById('adminLogoutBtn').style.display = 'none';
            document.querySelectorAll('.admin-only').forEach(el => el.classList.remove('admin-visible'));
            document.querySelectorAll('.record-card').forEach(el => el.classList.remove('admin-visible'));
        }

        function adminDelete(id) {
            if (!adminPasskey) {
                alert('Nejprve se přihlas jako admin.');
                return;
            }
            if (!confirm('Opravdu smazat záznam #' + id + '?')) return;

            const formData = new URLSearchParams();
            formData.set('id', String(id));
            formData.set('secret', adminPasskey);

            fetch('admin_delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString(),
            })
            .then(res => {
                if (!res.ok) throw new Error('Smazání selhalo');
                return res.json();
            })
            .then(data => {
                if (data.ok) {
                    const el = document.getElementById('item-' + id);
                    if (el) el.remove();
                }
            })
            .catch(err => {
                alert('Chyba: ' + err.message);
            });
        }
    </script>
</body>
</html>
