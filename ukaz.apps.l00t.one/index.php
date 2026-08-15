<?php

declare(strict_types=1);

const APP_DNS = 'ukaz.apps.l00t.one';
const SHARE_TTL_SECONDS = 600;
const MAX_UPLOAD_BYTES = 12_000_000;

date_default_timezone_set('Europe/Prague');

$dataDir = '/working/' . APP_DNS;
if (!is_dir($dataDir)) {
    $fallback = __DIR__ . '/data';
    if (!is_dir($fallback)) {
        mkdir($fallback, 0775, true);
    }
    $dataDir = $fallback;
}

$uploadDir = $dataDir . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

$db = new PDO('sqlite:' . $dataDir . '/ukaz.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$db->exec(
    'CREATE TABLE IF NOT EXISTS photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        share_token TEXT NOT NULL UNIQUE,
        track_token TEXT NOT NULL UNIQUE,
        file_name TEXT NOT NULL,
        original_name TEXT NOT NULL,
        mime_type TEXT NOT NULL,
        text_note TEXT NOT NULL DEFAULT "",
        created_at TEXT NOT NULL,
        expires_at TEXT NOT NULL
    )'
);
$db->exec(
    'CREATE TABLE IF NOT EXISTS views (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        photo_id INTEGER NOT NULL,
        viewed_at TEXT NOT NULL,
        ip_hash TEXT NOT NULL,
        user_agent TEXT NOT NULL,
        referer TEXT NOT NULL,
        FOREIGN KEY(photo_id) REFERENCES photos(id)
    )'
);
$db->exec(
    'CREATE TABLE IF NOT EXISTS replies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        photo_id INTEGER NOT NULL,
        file_name TEXT NOT NULL,
        original_name TEXT NOT NULL,
        mime_type TEXT NOT NULL,
        text_note TEXT NOT NULL DEFAULT "",
        created_at TEXT NOT NULL,
        ip_hash TEXT NOT NULL,
        user_agent TEXT NOT NULL,
        FOREIGN KEY(photo_id) REFERENCES photos(id)
    )'
);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nowIso(): string
{
    return date('c');
}

function absoluteUrl(array $params): string
{
    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $host = $_SERVER['HTTP_HOST'] ?? APP_DNS;
    $scheme = $forwardedProto !== ''
        ? strtok($forwardedProto, ',')
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || !str_starts_with($host, '127.0.0.1') ? 'https' : 'http');
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
    return $scheme . '://' . $host . $path . '?' . http_build_query($params);
}

function ipHash(): string
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    return hash('sha256', (string)$ip);
}

function uploadPasswordFile(string $dataDir): string
{
    return $dataDir . '/upload_password.txt';
}

function expectedPassword(string $dataDir): ?string
{
    $file = uploadPasswordFile($dataDir);
    if (!is_file($file)) {
        return null;
    }
    $password = trim((string)file_get_contents($file));
    return $password === '' ? null : $password;
}

function requireUploadPassword(string $dataDir): void
{
    $expected = expectedPassword($dataDir);
    if ($expected === null) {
        throw new RuntimeException('Neni nastaveno heslo pro upload.');
    }
    $given = (string)($_POST['password'] ?? '');
    if (!hash_equals($expected, $given)) {
        throw new RuntimeException('Spatne heslo.');
    }
}

function validateImageUpload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Soubor se nepodarilo nahrat.');
    }
    if (($file['size'] ?? 0) <= 0 || (int)$file['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Soubor je moc velky nebo prazdny.');
    }

    $tmpName = (string)$file['tmp_name'];
    $info = @getimagesize($tmpName);
    if ($info === false) {
        throw new RuntimeException('Soubor neni platny obrazek.');
    }

    $mime = (string)($info['mime'] ?? '');
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Podporovane jsou JPG, PNG, GIF a WEBP.');
    }

    return [$mime, $extensions[$mime]];
}

function storeImage(array $file, string $uploadDir, string $prefix): array
{
    [$mime, $extension] = validateImageUpload($file);
    $stored = $prefix . '-' . bin2hex(random_bytes(16)) . '.' . $extension;
    $target = $uploadDir . '/' . $stored;
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
        throw new RuntimeException('Soubor se nepodarilo ulozit.');
    }
    chmod($target, 0664);
    return [$stored, $mime, (string)($file['name'] ?? 'fotka')];
}

function findPhotoByShare(PDO $db, string $token): ?array
{
    $stmt = $db->prepare('SELECT * FROM photos WHERE share_token = :token');
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function findPhotoByTrack(PDO $db, string $token): ?array
{
    $stmt = $db->prepare('SELECT * FROM photos WHERE track_token = :token');
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function findPhotoByAssetToken(PDO $db, string $token): ?array
{
    return findPhotoByShare($db, $token) ?? findPhotoByTrack($db, $token);
}

function photoExpired(array $photo): bool
{
    return strtotime((string)$photo['expires_at']) < time();
}

function renderHeader(string $title): void
{
    echo '<!doctype html><html lang="cs"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<style>
        :root { color-scheme: light; --ink:#202124; --muted:#667085; --line:#d7dde5; --accent:#0f766e; --danger:#b42318; --bg:#f6f7f9; --panel:#fff; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: Arial, sans-serif; color:var(--ink); background:var(--bg); }
        main { width:min(920px, calc(100vw - 28px)); margin:28px auto; }
        .bar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:18px; }
        h1 { font-size:26px; line-height:1.15; margin:0; letter-spacing:0; }
        h2 { font-size:18px; margin:0 0 12px; }
        .muted { color:var(--muted); font-size:14px; }
        .panel, .item { background:var(--panel); border:1px solid var(--line); border-radius:8px; padding:16px; margin:14px 0; }
        label { display:block; font-weight:700; margin:12px 0 6px; }
        input[type=password], textarea, input[type=file] { width:100%; padding:10px; border:1px solid var(--line); border-radius:6px; background:#fff; font:inherit; }
        textarea { min-height:92px; resize:vertical; }
        button, .button { display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:9px 13px; border:1px solid #0d665f; border-radius:6px; background:var(--accent); color:#fff; font-weight:700; text-decoration:none; cursor:pointer; font:inherit; }
        .copyrow { display:grid; grid-template-columns:1fr auto; gap:8px; align-items:center; margin:10px 0; }
        .copyrow input { min-width:0; padding:10px; border:1px solid var(--line); border-radius:6px; font:inherit; }
        .photo { display:block; max-width:100%; max-height:72vh; margin:14px auto; border-radius:8px; border:1px solid var(--line); background:#fff; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px,1fr)); gap:12px; }
        .stat { border:1px solid var(--line); border-radius:8px; padding:12px; background:#fff; }
        .stat strong { display:block; font-size:24px; }
        table { width:100%; border-collapse:collapse; background:#fff; }
        th, td { text-align:left; border-bottom:1px solid var(--line); padding:9px 7px; font-size:14px; vertical-align:top; }
        .thumb { width:100%; max-height:280px; object-fit:contain; border:1px solid var(--line); border-radius:8px; background:#fff; }
        .error { color:var(--danger); font-weight:700; }
        @media (max-width: 620px) { main { margin:16px auto; width:min(100vw - 18px, 920px); } .copyrow { grid-template-columns:1fr; } th:nth-child(3), td:nth-child(3) { display:none; } }
    </style></head><body><main>';
}

function renderFooter(): void
{
    echo '</main><script>
        function copyValue(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.select();
            document.execCommand("copy");
        }
    </script></body></html>';
}

function renderError(string $message): void
{
    renderHeader('Chyba');
    echo '<div class="panel"><p class="error">' . h($message) . '</p><p><a class="button" href="/">Zpet</a></p></div>';
    renderFooter();
}

$action = (string)($_GET['action'] ?? '');

try {
    if ($action === 'asset') {
        $token = (string)($_GET['token'] ?? '');
        $kind = (string)($_GET['kind'] ?? 'photo');
        $id = (int)($_GET['id'] ?? 0);
        $photo = findPhotoByAssetToken($db, $token);
        if (!$photo) {
            http_response_code(404);
            exit;
        }
        if ($kind === 'photo') {
            $fileName = (string)$photo['file_name'];
            $mime = (string)$photo['mime_type'];
        } elseif ($kind === 'reply') {
            $stmt = $db->prepare('SELECT * FROM replies WHERE id = :id AND photo_id = :photo_id');
            $stmt->execute([':id' => $id, ':photo_id' => (int)$photo['id']]);
            $reply = $stmt->fetch();
            if (!$reply) {
                http_response_code(404);
                exit;
            }
            $fileName = (string)$reply['file_name'];
            $mime = (string)$reply['mime_type'];
        } else {
            http_response_code(404);
            exit;
        }
        $path = $uploadDir . '/' . $fileName;
        if (!is_file($path)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
        requireUploadPassword($dataDir);
        [$stored, $mime, $original] = storeImage($_FILES['photo'] ?? [], $uploadDir, 'photo');
        $shareToken = bin2hex(random_bytes(24));
        $trackToken = bin2hex(random_bytes(24));
        $createdAt = nowIso();
        $expiresAt = date('c', time() + SHARE_TTL_SECONDS);
        $note = trim((string)($_POST['text_note'] ?? ''));

        $stmt = $db->prepare('INSERT INTO photos (share_token, track_token, file_name, original_name, mime_type, text_note, created_at, expires_at) VALUES (:share, :track, :file, :original, :mime, :note, :created, :expires)');
        $stmt->execute([
            ':share' => $shareToken,
            ':track' => $trackToken,
            ':file' => $stored,
            ':original' => $original,
            ':mime' => $mime,
            ':note' => $note,
            ':created' => $createdAt,
            ':expires' => $expiresAt,
        ]);

        $shareUrl = absoluteUrl(['s' => $shareToken]);
        $trackUrl = absoluteUrl(['t' => $trackToken]);
        renderHeader('Odkazy');
        echo '<div class="bar"><h1>Nahrano</h1><a class="button" href="/">Dalsi fotka</a></div>';
        echo '<div class="panel"><p class="muted">Sdileci odkaz plati do ' . h(date('H:i:s', strtotime($expiresAt))) . '.</p>';
        echo '<label>Sdileci odkaz</label><div class="copyrow"><input id="shareUrl" readonly value="' . h($shareUrl) . '"><button onclick="copyValue(\'shareUrl\')">Kopirovat</button></div>';
        echo '<label>Sledovaci odkaz</label><div class="copyrow"><input id="trackUrl" readonly value="' . h($trackUrl) . '"><button onclick="copyValue(\'trackUrl\')">Kopirovat</button></div></div>';
        renderFooter();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reply') {
        $shareToken = (string)($_POST['share_token'] ?? '');
        $photo = findPhotoByShare($db, $shareToken);
        if (!$photo || photoExpired($photo)) {
            throw new RuntimeException('Sdileci odkaz uz neplati.');
        }
        [$stored, $mime, $original] = storeImage($_FILES['reply_photo'] ?? [], $uploadDir, 'reply');
        $stmt = $db->prepare('INSERT INTO replies (photo_id, file_name, original_name, mime_type, text_note, created_at, ip_hash, user_agent) VALUES (:photo_id, :file, :original, :mime, :note, :created, :ip, :ua)');
        $stmt->execute([
            ':photo_id' => (int)$photo['id'],
            ':file' => $stored,
            ':original' => $original,
            ':mime' => $mime,
            ':note' => trim((string)($_POST['reply_note'] ?? '')),
            ':created' => nowIso(),
            ':ip' => ipHash(),
            ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
        renderHeader('Odpoved odeslana');
        echo '<div class="panel"><h1>Odpoved odeslana</h1><p class="muted">Fotka se ukaze ve sledovaci strance.</p></div>';
        renderFooter();
        exit;
    }

    $shareToken = (string)($_GET['s'] ?? '');
    if ($shareToken !== '') {
        $photo = findPhotoByShare($db, $shareToken);
        if (!$photo) {
            renderError('Sdileci odkaz neexistuje.');
            exit;
        }
        if (photoExpired($photo)) {
            renderError('Sdileci odkaz uz vyprsel.');
            exit;
        }

        $stmt = $db->prepare('INSERT INTO views (photo_id, viewed_at, ip_hash, user_agent, referer) VALUES (:photo_id, :viewed, :ip, :ua, :referer)');
        $stmt->execute([
            ':photo_id' => (int)$photo['id'],
            ':viewed' => nowIso(),
            ':ip' => ipHash(),
            ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ':referer' => substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 500),
        ]);

        renderHeader('Fotka');
        echo '<div class="bar"><h1>Fotka</h1><span class="muted">Platne do ' . h(date('H:i:s', strtotime((string)$photo['expires_at']))) . '</span></div>';
        if ((string)$photo['text_note'] !== '') {
            echo '<div class="panel">' . nl2br(h((string)$photo['text_note'])) . '</div>';
        }
        echo '<img class="photo" src="?action=asset&amp;kind=photo&amp;token=' . h($shareToken) . '" alt="Sdilena fotka">';
        echo '<div class="panel"><h2>Odpovedet fotkou</h2><form method="post" action="?action=reply" enctype="multipart/form-data">';
        echo '<input type="hidden" name="share_token" value="' . h($shareToken) . '">';
        echo '<label for="reply_photo">Fotka</label><input id="reply_photo" name="reply_photo" type="file" accept="image/jpeg,image/png,image/gif,image/webp" required>';
        echo '<label for="reply_note">Text k odpovedi</label><textarea id="reply_note" name="reply_note"></textarea>';
        echo '<button type="submit">Odeslat odpoved</button></form></div>';
        renderFooter();
        exit;
    }

    $trackToken = (string)($_GET['t'] ?? '');
    if ($trackToken !== '') {
        $photo = findPhotoByTrack($db, $trackToken);
        if (!$photo) {
            renderError('Sledovaci odkaz neexistuje.');
            exit;
        }
        $viewsStmt = $db->prepare('SELECT * FROM views WHERE photo_id = :photo_id ORDER BY datetime(viewed_at) DESC, id DESC');
        $viewsStmt->execute([':photo_id' => (int)$photo['id']]);
        $views = $viewsStmt->fetchAll();

        $repliesStmt = $db->prepare('SELECT * FROM replies WHERE photo_id = :photo_id ORDER BY datetime(created_at) DESC, id DESC');
        $repliesStmt->execute([':photo_id' => (int)$photo['id']]);
        $replies = $repliesStmt->fetchAll();

        renderHeader('Sledovani');
        echo '<div class="bar"><h1>Sledovani fotky</h1><span class="muted">' . (photoExpired($photo) ? 'sdileni vyprselo' : 'sdileni aktivni') . '</span></div>';
        echo '<div class="grid"><div class="stat"><strong>' . count($views) . '</strong><span class="muted">zobrazeni</span></div><div class="stat"><strong>' . count($replies) . '</strong><span class="muted">odpovedi fotkou</span></div><div class="stat"><strong>' . h(date('H:i:s', strtotime((string)$photo['expires_at']))) . '</strong><span class="muted">platnost sdileni</span></div></div>';
        if ((string)$photo['text_note'] !== '') {
            echo '<div class="panel">' . nl2br(h((string)$photo['text_note'])) . '</div>';
        }
        echo '<img class="photo" src="?action=asset&amp;kind=photo&amp;token=' . h($trackToken) . '" alt="Sdilena fotka">';

        echo '<div class="panel"><h2>Zobrazeni</h2>';
        if (!$views) {
            echo '<p class="muted">Zatim nic.</p>';
        } else {
            echo '<table><thead><tr><th>Cas</th><th>IP hash</th><th>Prohlizec</th></tr></thead><tbody>';
            foreach ($views as $view) {
                echo '<tr><td>' . h(date('d.m. H:i:s', strtotime((string)$view['viewed_at']))) . '</td><td>' . h(substr((string)$view['ip_hash'], 0, 12)) . '</td><td>' . h((string)$view['user_agent']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        echo '<div class="panel"><h2>Odpovedi</h2>';
        if (!$replies) {
            echo '<p class="muted">Zatim zadne odpovedi.</p>';
        } else {
            echo '<div class="grid">';
            foreach ($replies as $reply) {
                echo '<div class="item"><img class="thumb" src="?action=asset&amp;kind=reply&amp;id=' . (int)$reply['id'] . '&amp;token=' . h($trackToken) . '" alt="Odpoved fotkou">';
                echo '<p class="muted">' . h(date('d.m. H:i:s', strtotime((string)$reply['created_at']))) . '</p>';
                if ((string)$reply['text_note'] !== '') {
                    echo '<p>' . nl2br(h((string)$reply['text_note'])) . '</p>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
        renderFooter();
        exit;
    }

    renderHeader('Ukaz');
    echo '<div class="bar"><h1>Ukaz</h1><span class="muted">docasne fotky na 10 minut</span></div>';
    if (expectedPassword($dataDir) === null) {
        echo '<div class="panel"><p class="error">Upload heslo jeste neni nastavene na serveru.</p></div>';
    }
    echo '<div class="panel"><form method="post" action="?action=upload" enctype="multipart/form-data">';
    echo '<label for="password">Heslo pro upload</label><input id="password" name="password" type="password" required>';
    echo '<label for="photo">Fotka</label><input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/gif,image/webp" required>';
    echo '<label for="text_note">Text k fotce</label><textarea id="text_note" name="text_note" placeholder="Volitelny text, ktery se ukaze u fotky."></textarea>';
    echo '<button type="submit">Nahrat a vytvorit odkazy</button></form></div>';
    renderFooter();
} catch (Throwable $e) {
    http_response_code(400);
    renderError($e->getMessage());
}
