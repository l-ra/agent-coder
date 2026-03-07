<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

function parseDurationToSeconds(string $text): ?int
{
    $text = trim(mb_strtolower($text));

    if (preg_match('/(\d+)\s*(sekund|sekundy|sekunda|s)/u', $text, $m)) {
        return (int)$m[1];
    }

    if (preg_match('/(\d+)\s*(minut|minuta|minuty|m)/u', $text, $m)) {
        return (int)$m[1] * 60;
    }

    return null;
}

function parseRepeatCount($raw): ?int
{
    if ($raw === null) {
        return null;
    }

    $text = trim((string)$raw);
    if (preg_match('/\d+/', $text, $m)) {
        return (int)$m[0];
    }

    return null;
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sanitizeUsername(string $username): string
{
    $u = trim(mb_strtolower($username));
    $u = preg_replace('/[^a-z0-9._-]+/u', '_', $u) ?? '';
    $u = trim($u, '._-');

    return $u !== '' ? $u : 'user';
}

function taskId(array $task, int $idx): string
{
    $name = (string)($task['nazev'] ?? '');
    $desc = (string)($task['popis'] ?? '');
    return 't_' . $idx . '_' . substr(sha1($name . '|' . $desc), 0, 12);
}

function perversionToColor($raw): string
{
    $value = is_numeric($raw) ? (float)$raw : 1.0;
    $value = max(1.0, min(10.0, $value));
    $t = ($value - 1.0) / 9.0;

    $r = (int)round(34 + (231 - 34) * $t);
    $g = (int)round(197 + (76 - 197) * $t);
    $b = (int)round(94 + (60 - 94) * $t);

    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

function profileBaseDir(): string
{
    return '/working';
}

function profilePath(string $domain, string $username): string
{
    $base = profileBaseDir();
    $domainSafe = preg_replace('/[^a-z0-9.-]+/i', '_', $domain) ?? 'unknown-domain';
    $userSafe = sanitizeUsername($username);

    $dir = $base . '/' . $domainSafe;
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('Profilový adresář není dostupný: ' . $dir);
    }

    return $dir . '/' . $userSafe . '.json';
}

function loadProfile(string $domain, string $username): ?array
{
    $path = profilePath($domain, $username);
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }

    $data['ratings'] = is_array($data['ratings'] ?? null) ? $data['ratings'] : [];
    $data['drawn'] = is_array($data['drawn'] ?? null) ? $data['drawn'] : [];
    $data['excluded'] = is_array($data['excluded'] ?? null) ? $data['excluded'] : [];

    return $data;
}

function saveProfile(string $domain, string $username, array $profile): void
{
    $path = profilePath($domain, $username);
    $profile['username'] = $username;
    $profile['ratings'] = is_array($profile['ratings'] ?? null) ? $profile['ratings'] : [];
    $profile['drawn'] = is_array($profile['drawn'] ?? null) ? $profile['drawn'] : [];
    $profile['excluded'] = is_array($profile['excluded'] ?? null) ? $profile['excluded'] : [];
    $profile['updated_at'] = gmdate('c');

    $written = @file_put_contents(
        $path,
        json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    if ($written === false) {
        throw new RuntimeException('Profil se nepodařilo uložit: ' . $path);
    }
}

$domain = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
$yaml = Yaml::parseFile(__DIR__ . '/ukoly.yaml');
$tasks = $yaml['ukoly'] ?? [];

if (!is_array($tasks) || count($tasks) === 0) {
    http_response_code(500);
    echo 'Seznam úkolů je prázdný nebo neplatný.';
    exit;
}

if (!isset($_SESSION['task'])) {
    $_SESSION['task'] = null;
}
if (!isset($_SESSION['auth_user'])) {
    $_SESSION['auth_user'] = null;
}

$action = $_POST['action'] ?? null;

if ($action === 'api_auth') {
    try {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            jsonResponse(['ok' => false, 'error' => 'Vyplň username i heslo.'], 400);
        }

        $profile = loadProfile($domain, $username);

        if ($profile === null) {
            $profile = [
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'ratings' => [],
                'drawn' => [],
                'excluded' => [],
                'created_at' => gmdate('c'),
            ];
            saveProfile($domain, $username, $profile);
        } else {
            $hash = (string)($profile['password_hash'] ?? '');
            if ($hash === '' || !password_verify($password, $hash)) {
                jsonResponse(['ok' => false, 'error' => 'Neplatné přihlašovací údaje.'], 401);
            }

            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                $profile['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                saveProfile($domain, $username, $profile);
            }
        }

        $_SESSION['auth_user'] = $username;
        jsonResponse(['ok' => true, 'username' => $username]);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'error' => 'Profilové úložiště není dostupné.'], 500);
    }
}

$currentUser = is_string($_SESSION['auth_user']) ? $_SESSION['auth_user'] : null;
$currentProfile = $currentUser ? loadProfile($domain, $currentUser) : null;

if ($currentUser && $currentProfile === null) {
    $_SESSION['auth_user'] = null;
    $currentUser = null;
}

if ($action === 'logout') {
    $_SESSION['auth_user'] = null;
    $_SESSION['task'] = null;
    header('Location: ' . $_SERVER['PHP_SELF'] . '?logged_out=1');
    exit;
}

if ($action === 'reset_profile_state') {
    if ($currentUser && is_array($currentProfile)) {
        $resetDrawn = ($_POST['reset_drawn'] ?? '0') === '1';
        $resetRatings = ($_POST['reset_ratings'] ?? '0') === '1';
        $resetExcluded = ($_POST['reset_excluded'] ?? '0') === '1';

        if (!$resetDrawn && !$resetRatings && !$resetExcluded) {
            $_SESSION['draw_message'] = 'Vyber aspoň jednu část profilu k resetu.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }

        if ($resetDrawn) {
            $currentProfile['drawn'] = [];
        }
        if ($resetRatings) {
            $currentProfile['ratings'] = [];
        }
        if ($resetExcluded) {
            $currentProfile['excluded'] = [];
        }

        try {
            saveProfile($domain, $currentUser, $currentProfile);
            $parts = [];
            if ($resetDrawn) { $parts[] = 'vylosované'; }
            if ($resetRatings) { $parts[] = 'oblíbenost'; }
            if ($resetExcluded) { $parts[] = 'vyřazení'; }
            $_SESSION['draw_message'] = 'Resetováno: ' . implode(', ', $parts) . '.';
        } catch (Throwable $e) {
            $_SESSION['draw_message'] = 'Reset se nepodařilo uložit.';
        }
    }

    $_SESSION['task'] = null;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($action === 'draw') {
    if (!$currentUser || !is_array($currentProfile)) {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $includeDrawn = ($_POST['include_drawn'] ?? '0') === '1';

    $eligible = [];
    foreach ($tasks as $idx => $task) {
        if (!is_array($task)) {
            continue;
        }
        $tid = taskId($task, (int)$idx);
        $alreadyDrawn = !empty($currentProfile['drawn'][$tid]);
        $isExcluded = !empty($currentProfile['excluded'][$tid]);
        if ($isExcluded) {
            continue;
        }

        if ($includeDrawn || !$alreadyDrawn) {
            $task['_task_id'] = $tid;
            $eligible[] = $task;
        }
    }

    if (count($eligible) === 0) {
        $_SESSION['task'] = null;
        $_SESSION['draw_message'] = 'Žádná nová otázka k losování. Zaškrtni „včetně už zobrazených“. '; 
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $idx = random_int(0, count($eligible) - 1);
    $task = $eligible[$idx];
    $taskId = (string)$task['_task_id'];

    $_SESSION['task'] = $task;

    $currentProfile['drawn'][$taskId] = true;
    try {
        saveProfile($domain, $currentUser, $currentProfile);
        $_SESSION['draw_message'] = null;
    } catch (Throwable $e) {
        $_SESSION['draw_message'] = 'Nepodařilo se uložit profil.';
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($action === 'api_rate_task') {
    if (!is_array($_SESSION['task']) || !$currentUser || !is_array($currentProfile)) {
        jsonResponse(['ok' => false, 'error' => 'Nepřihlášený uživatel nebo chybějící úkol.'], 401);
    }

    $vote = (string)($_POST['vote'] ?? '');
    if (!in_array($vote, ['like', 'dislike', 'none'], true)) {
        jsonResponse(['ok' => false, 'error' => 'Neplatné hodnocení.'], 400);
    }

    $task = $_SESSION['task'];
    $tid = (string)($task['_task_id'] ?? '');
    if ($tid === '') {
        jsonResponse(['ok' => false, 'error' => 'Chybí identifikátor úkolu.'], 400);
    }

    try {
        if ($vote === 'none') {
            unset($currentProfile['ratings'][$tid]);
        } else {
            $currentProfile['ratings'][$tid] = $vote;
        }
        saveProfile($domain, $currentUser, $currentProfile);
        jsonResponse(['ok' => true, 'vote' => $vote]);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'error' => 'Nepodařilo se uložit hodnocení.'], 500);
    }
}

if ($action === 'complete_task') {
    $_SESSION['task'] = null;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($action === 'skip_task') {
    if (is_array($_SESSION['task']) && $currentUser && is_array($currentProfile)) {
        $tid = (string)($_SESSION['task']['_task_id'] ?? '');
        if ($tid !== '') {
            unset($currentProfile['drawn'][$tid]);
            try {
                saveProfile($domain, $currentUser, $currentProfile);
            } catch (Throwable $e) {
                $_SESSION['draw_message'] = 'Nepodařilo se změnit stav úkolu.';
            }
        }
    }

    $_SESSION['task'] = null;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($action === 'exclude_task') {
    if (is_array($_SESSION['task']) && $currentUser && is_array($currentProfile)) {
        $tid = (string)($_SESSION['task']['_task_id'] ?? '');
        if ($tid !== '') {
            $currentProfile['excluded'][$tid] = true;
            $currentProfile['drawn'][$tid] = true;
            try {
                saveProfile($domain, $currentUser, $currentProfile);
                $_SESSION['draw_message'] = 'Úkol byl vyřazen z losování.';
            } catch (Throwable $e) {
                $_SESSION['draw_message'] = 'Nepodařilo se vyřadit úkol.';
            }
        }
    }

    $_SESSION['task'] = null;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$currentTask = $_SESSION['task'];

$taskOverview = [];
$currentTaskId = is_array($currentTask) ? (string)($currentTask['_task_id'] ?? '') : '';
foreach ($tasks as $idx => $task) {
    if (!is_array($task)) {
        continue;
    }

    $tid = taskId($task, (int)$idx);
    $isExcluded = !empty($currentProfile['excluded'][$tid]);
    $taskOverview[] = [
        'id' => $tid,
        'color' => perversionToColor($task['mira_perverze'] ?? null),
        'drawn' => !empty($currentProfile['drawn'][$tid]),
        'excluded' => $isExcluded,
        'active' => $currentTaskId !== '' && $currentTaskId === $tid,
        'label' => (string)($task['nazev'] ?? 'Úkol') . ' · perverze ' . (string)($task['mira_perverze'] ?? '?') . ($isExcluded ? ' · vyřazeno' : ''),
    ];
}

$drawMessage = $_SESSION['draw_message'] ?? null;
unset($_SESSION['draw_message']);
?><!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lechtivity – losování úkolů</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: system-ui, sans-serif;
            margin: 0;
            background: #111827;
            color: #f9fafb;
            line-height: 1.45;
        }
        .wrap {
            width: min(100%, 720px);
            margin: 0 auto;
            padding: clamp(12px, 3vw, 20px);
        }
        .card {
            background: #1f2937;
            border-radius: 16px;
            padding: clamp(14px, 3vw, 18px);
            margin-bottom: 16px;
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .header-right { display: flex; align-items: center; gap: 10px; }
        .brand {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding-right: 6px;
        }
        .brand::before {
            content: '';
            position: absolute;
            left: -8px;
            top: 50%;
            width: 44px;
            height: 44px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(236,72,153,0.28) 0%, rgba(236,72,153,0) 72%);
            transform: translateY(-50%);
            pointer-events: none;
            filter: blur(1px);
        }
        .brand-title {
            margin: 0;
            font-size: clamp(1.35rem, 4.5vw, 1.75rem);
            letter-spacing: 0.2px;
        }
        .brand-heart {
            font-size: 1rem;
            animation: pulseHeart 1.9s ease-in-out infinite;
            transform-origin: center;
        }
        .brand-bar {
            height: 4px;
            border-radius: 999px;
            margin-top: 10px;
            background: linear-gradient(90deg, #22c55e, #84cc16, #f59e0b, #ef4444, #ec4899, #22c55e);
            background-size: 220% 100%;
            animation: barFlow 6s linear infinite;
            opacity: 0.92;
        }
        @keyframes barFlow {
            0% { background-position: 0% 0; }
            100% { background-position: 220% 0; }
        }
        @keyframes pulseHeart {
            0%, 100% { transform: scale(1); opacity: 0.85; }
            50% { transform: scale(1.22); opacity: 1; }
        }
        .overview {
            margin-top: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 2px;
            align-items: flex-start;
        }
        .overview-task {
            width: 5px;
            height: 5px;
            border-radius: 1px;
            border: 1px solid transparent;
        }
        .overview-task.drawn {
            filter: brightness(0.55);
            opacity: 0.92;
        }
        .overview-task.excluded {
            filter: grayscale(1) brightness(0.45);
            border-color: #6b7280;
            position: relative;
        }
        .overview-task.excluded::after {
            content: '';
            position: absolute;
            left: -1px;
            top: -1px;
            width: 5px;
            height: 5px;
            border-top: 1px solid #d1d5db;
            transform: rotate(45deg);
            transform-origin: center;
            opacity: 0.9;
        }
        .overview-task.active {
            border-color: #f9fafb;
            transform: scale(1.6);
            box-shadow: 0 0 0 1px #111827;
            z-index: 1;
            position: relative;
        }
        .overview-help {
            margin-top: 8px;
            font-size: 0.86rem;
        }
        h1 { margin: 0; }
        h2 { margin-top: 0; font-size: clamp(1.15rem, 4.2vw, 1.45rem); }
        p { margin: 0 0 10px; }
        hr { border: 0; border-top: 1px solid #374151; margin: 14px 0; }
        button {
            width: 100%;
            min-height: 44px;
            background: #ec4899;
            color: #fff;
            border: 0;
            border-radius: 12px;
            padding: 10px 14px;
            font-weight: 700;
            cursor: pointer;
        }
        button.secondary { background: #374151; }
        .icon-btn {
            width: auto;
            min-width: 0;
            min-height: 0;
            border: 0;
            background: transparent;
            color: #9ca3af;
            font-size: 1.6rem;
            line-height: 1;
            padding: 2px 6px;
            cursor: pointer;
        }
        .logout-btn {
            width: auto;
            min-width: 0;
            min-height: 38px;
            border-radius: 10px;
            background: #374151;
            padding: 6px 10px;
            font-size: 1.05rem;
        }
        dialog.reset-dialog {
            border: 1px solid #4b5563;
            border-radius: 14px;
            background: #1f2937;
            color: #f9fafb;
            width: min(92vw, 360px);
            padding: 0;
        }
        dialog.reset-dialog::backdrop {
            background: rgba(0, 0, 0, 0.6);
        }
        .reset-dialog-inner {
            padding: 14px;
        }
        .reset-dialog h3 {
            margin: 0 0 10px;
            font-size: 1rem;
        }
        .reset-options {
            display: grid;
            gap: 10px;
            margin: 8px 0 12px;
        }
        .reset-options label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        .reset-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .reset-actions button {
            width: auto;
            min-width: 120px;
        }
        .icon-btn.active-like { color: #34d399; }
        .icon-btn.active-dislike { color: #f87171; }
        .is-hidden { display: none !important; }
        .counter-tap {
            display: inline-block;
            margin-top: 6px;
            font-size: clamp(1.6rem, 7vw, 2.2rem);
            font-weight: 700;
            color: #f9fafb;
            padding: 8px 12px;
            border-radius: 10px;
            background: #111827;
            border: 1px solid #374151;
            user-select: none;
            cursor: pointer;
        }
        .actions {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            margin-top: 12px;
        }
        .actions form { margin: 0; }
        .muted { color: #9ca3af; }
        .timer { font-size: clamp(1.8rem, 8vw, 2.4rem); font-weight: 700; margin: 10px 0; }
        .ok { color: #34d399; font-weight: 700; }
        .warn { color: #fbbf24; font-weight: 700; }
        .row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .field { margin-top: 10px; }
        .field input {
            width: 100%;
            min-height: 44px;
            border-radius: 10px;
            border: 1px solid #4b5563;
            background: #111827;
            color: #f9fafb;
            padding: 10px 12px;
        }
        label.inline { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; }
        #authStatus { margin-top: 8px; }

        @media (min-width: 560px) {
            button { width: auto; min-width: 140px; }
            .actions {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }
            .actions form button { width: auto; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="header">
            <div class="brand" aria-label="Lechtivity">
                <h1 class="brand-title">Lechtivity</h1>
                <span class="brand-heart" aria-hidden="true">💗</span>
            </div>
            <div id="headerUserBox" class="header-right" style="display:none;">
                <span class="muted" id="headerUser">—</span>
                <button type="button" id="resetOpenBtn" class="logout-btn" title="Reset profilu" aria-label="Reset profilu">♻️</button>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="logout-btn" title="Odhlásit" aria-label="Odhlásit">🚪</button>
                </form>
            </div>
        </div>
        <div class="brand-bar" aria-hidden="true"></div>

        <dialog id="resetDialog" class="reset-dialog">
            <div class="reset-dialog-inner">
                <h3>Reset profilu</h3>
                <form method="post">
                    <input type="hidden" name="action" value="reset_profile_state">
                    <div class="reset-options">
                        <label><input type="checkbox" name="reset_drawn" value="1" checked> Reset vylosovaných</label>
                        <label><input type="checkbox" name="reset_ratings" value="1"> Reset oblíbenosti</label>
                        <label><input type="checkbox" name="reset_excluded" value="1" checked> Reset vyřazení</label>
                    </div>
                    <div class="reset-actions">
                        <button type="submit" class="secondary">Provést reset</button>
                        <button type="button" class="secondary" id="resetCloseBtn">Zrušit</button>
                    </div>
                </form>
            </div>
        </dialog>

        <div class="overview" aria-label="Přehled úkolů">
            <?php foreach ($taskOverview as $item): ?>
                <span
                    class="overview-task<?= $item['drawn'] ? ' drawn' : '' ?><?= $item['excluded'] ? ' excluded' : '' ?><?= $item['active'] ? ' active' : '' ?>"
                    style="background: <?= htmlspecialchars($item['color']) ?>"
                    title="<?= htmlspecialchars($item['label']) ?>"
                    aria-label="<?= htmlspecialchars($item['label']) ?>"
                ></span>
            <?php endforeach; ?>
        </div>
        <p class="muted overview-help">Přehled: zelená → červená podle míry perverze, tmavé = už vylosované, přeškrtnuté = vyřazené, bíle ohraničené = aktuálně zobrazený úkol.</p>

        <div id="authBlock" style="display: none;">
            <p><strong>Přihlášení / registrace</strong></p>
            <p class="muted">Při prvním použití se profil automaticky zaregistruje.</p>
            <div class="field">
                <input id="username" type="text" placeholder="Username" autocomplete="username">
            </div>
            <div class="field">
                <input id="password" type="password" placeholder="Heslo" autocomplete="current-password">
            </div>
            <div class="actions">
                <button type="button" id="authBtn">Pokračovat</button>
            </div>
            <p id="authStatus" class="muted"></p>
        </div>

        <div id="appBlock" style="display: none;">
            <?php if (!is_array($currentTask)): ?>
                <p>Aplikace pro párovou hru. Vylosujte si úkol a hrajte si.</p>
                <form method="post" class="row">
                    <input type="hidden" name="action" value="draw">
                    <label class="inline">
                        <input type="checkbox" name="include_drawn" value="1"> Včetně už zobrazených
                    </label>
                    <button type="submit">🎲 Vylosovat úkol</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (is_string($drawMessage) && $drawMessage !== ''): ?>
            <p class="warn"><?= htmlspecialchars($drawMessage) ?></p>
        <?php endif; ?>
    </div>

    <?php if (is_array($currentTask)): ?>
        <?php
            $durationRaw = $currentTask['doba_trvani'] ?? null;
            $durationSeconds = is_string($durationRaw) ? parseDurationToSeconds($durationRaw) : null;
            $repeatRaw = $currentTask['pocet_opakovani'] ?? null;
            $currentTaskId = (string)($currentTask['_task_id'] ?? '');
            $ratingNow = (string)($currentProfile['ratings'][$currentTaskId] ?? '');
        ?>
        <div class="card">
            <h2><?= htmlspecialchars((string)($currentTask['nazev'] ?? 'Bez názvu')) ?></h2>
            <p><?= nl2br(htmlspecialchars((string)($currentTask['popis'] ?? ''))) ?></p>
            <p class="muted">Pro: <?= htmlspecialchars((string)($currentTask['pro'] ?? 'neuvedeno')) ?> · Míra perverze: <?= htmlspecialchars((string)($currentTask['mira_perverze'] ?? 'neuvedeno')) ?></p>

            <div class="row" id="ratingRow" data-current="<?= htmlspecialchars($ratingNow) ?>">
                <p style="margin:0;"><strong>Hodnocení:</strong></p>
                <button type="button" id="rateDown" class="icon-btn <?= $ratingNow === 'dislike' ? 'active-dislike' : '' ?> <?= $ratingNow === 'like' ? 'is-hidden' : '' ?>" aria-label="Nelíbí" title="Nelíbí">👎</button>
                <button type="button" id="rateUp" class="icon-btn <?= $ratingNow === 'like' ? 'active-like' : '' ?> <?= $ratingNow === 'dislike' ? 'is-hidden' : '' ?>" aria-label="Líbí" title="Líbí">👍</button>
                <span id="ratingStatus" class="muted"></span>
            </div>

            <?php if (is_string($durationRaw)): ?>
                <hr>
                <p><strong>Délka trvání:</strong> <?= htmlspecialchars($durationRaw) ?></p>
                <div id="timerSection">
                    <div class="timer" id="timerValue" title="Tukni pro spuštění" data-seconds="<?= (int)($durationSeconds ?? 0) ?>">00:00</div>
                    <p class="muted">Tukni na stopky pro spuštění odpočtu. Běží bez pauzy až do obnovení stránky.</p>
                    <?php if (is_int($durationSeconds)): ?>
                        <p class="muted">Odpočet: <?= (int)$durationSeconds ?> s.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($repeatRaw !== null): ?>
                <?php $repeatInitial = parseRepeatCount($repeatRaw); ?>
                <hr>
                <p><strong>Počet opakování:</strong></p>
                <div id="repeatCount" class="counter-tap" data-initial="<?= (int)($repeatInitial ?? 0) ?>"><?= (int)($repeatInitial ?? 0) ?></div>
                <p class="muted">Tuknutím se počet sníží o 1 (jen lokálně v prohlížeči).</p>
            <?php endif; ?>

            <div class="actions">
                <form method="post">
                    <input type="hidden" name="action" value="complete_task">
                    <button type="submit" class="secondary">Hotovo</button>
                </form>
                <form method="post">
                    <input type="hidden" name="action" value="skip_task">
                    <button type="submit" class="secondary">Přeskočit</button>
                </form>
                <form method="post" onsubmit="return confirm('Opravdu vyřadit tento úkol z losování?');">
                    <input type="hidden" name="action" value="exclude_task">
                    <button type="submit" class="secondary">Vyřadit</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
(() => {
    const authBlock = document.getElementById('authBlock');
    const appBlock = document.getElementById('appBlock');
    const authBtn = document.getElementById('authBtn');
    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    const authStatus = document.getElementById('authStatus');
    const headerUserBox = document.getElementById('headerUserBox');
    const headerUser = document.getElementById('headerUser');
    const resetDialog = document.getElementById('resetDialog');
    const resetOpenBtn = document.getElementById('resetOpenBtn');
    const resetCloseBtn = document.getElementById('resetCloseBtn');

    const setAuthView = (authenticated, username = '') => {
        if (!authBlock || !appBlock) return;
        authBlock.style.display = authenticated ? 'none' : 'block';
        appBlock.style.display = authenticated ? 'block' : 'none';
        if (headerUserBox) headerUserBox.style.display = authenticated ? 'flex' : 'none';
        if (headerUser) headerUser.textContent = authenticated ? username : '';
    };

    const authenticate = async (username, password) => {
        const data = new URLSearchParams();
        data.set('action', 'api_auth');
        data.set('username', username);
        data.set('password', password);

        const res = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: data.toString()
        });

        return res.json();
    };

    const params = new URLSearchParams(window.location.search);
    if (params.get('logged_out') === '1') {
        localStorage.removeItem('lechtivity_username');
        localStorage.removeItem('lechtivity_password');
        const cleanUrl = window.location.pathname;
        window.history.replaceState({}, '', cleanUrl);
    }

    const storedUsername = localStorage.getItem('lechtivity_username') || '';
    const storedPassword = localStorage.getItem('lechtivity_password') || '';

    const tryStored = async () => {
        if (!storedUsername || !storedPassword) {
            setAuthView(false);
            if (usernameInput && storedUsername) usernameInput.value = storedUsername;
            return;
        }

        try {
            const result = await authenticate(storedUsername, storedPassword);
            if (result.ok) {
                setAuthView(true, storedUsername);
                return;
            }
        } catch (e) {
            // no-op, fallback to auth form
        }

        setAuthView(false);
        if (usernameInput) usernameInput.value = storedUsername;
    };

    authBtn?.addEventListener('click', async () => {
        const username = (usernameInput?.value || '').trim();
        const password = (passwordInput?.value || '');

        if (!username || !password) {
            if (authStatus) authStatus.textContent = 'Vyplň username i heslo.';
            return;
        }

        if (authStatus) authStatus.textContent = 'Ověřuji…';

        try {
            const result = await authenticate(username, password);
            if (!result.ok) {
                if (authStatus) authStatus.textContent = result.error || 'Přihlášení selhalo.';
                return;
            }

            localStorage.setItem('lechtivity_username', username);
            localStorage.setItem('lechtivity_password', password);
            if (authStatus) authStatus.textContent = 'Přihlášeno.';
            setAuthView(true, username);
        } catch (e) {
            if (authStatus) authStatus.textContent = 'Chyba spojení se serverem.';
        }
    });

    tryStored();

    resetOpenBtn?.addEventListener('click', () => {
        if (!resetDialog) return;
        if (typeof resetDialog.showModal === 'function') {
            resetDialog.showModal();
        }
    });

    resetCloseBtn?.addEventListener('click', () => {
        if (!resetDialog) return;
        resetDialog.close();
    });

    resetDialog?.addEventListener('click', (e) => {
        const rect = resetDialog.getBoundingClientRect();
        const inDialog = rect.top <= e.clientY && e.clientY <= rect.top + rect.height
            && rect.left <= e.clientX && e.clientX <= rect.left + rect.width;
        if (!inDialog) {
            resetDialog.close();
        }
    });

    const ratingStatus = document.getElementById('ratingStatus');
    const ratingRow = document.getElementById('ratingRow');
    const rateUp = document.getElementById('rateUp');
    const rateDown = document.getElementById('rateDown');

    const applyRatingUI = (vote) => {
        if (!rateUp || !rateDown) return;
        rateUp.classList.toggle('active-like', vote === 'like');
        rateDown.classList.toggle('active-dislike', vote === 'dislike');

        if (vote === 'like') {
            rateUp.classList.remove('is-hidden');
            rateDown.classList.add('is-hidden');
        } else if (vote === 'dislike') {
            rateDown.classList.remove('is-hidden');
            rateUp.classList.add('is-hidden');
        } else {
            rateUp.classList.remove('is-hidden');
            rateDown.classList.remove('is-hidden');
        }

        if (ratingRow) ratingRow.dataset.current = vote;
    };

    const sendRating = async (vote) => {
        const data = new URLSearchParams();
        data.set('action', 'api_rate_task');
        data.set('vote', vote);

        const res = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: data.toString()
        });

        return res.json();
    };

    rateUp?.addEventListener('click', async () => {
        const current = ratingRow?.dataset.current || '';
        const vote = current === 'like' ? 'none' : 'like';
        if (ratingStatus) ratingStatus.textContent = 'Ukládám…';
        try {
            const result = await sendRating(vote);
            if (!result.ok) throw new Error(result.error || 'error');
            applyRatingUI(vote === 'none' ? '' : vote);
            if (ratingStatus) ratingStatus.textContent = 'Uloženo';
        } catch (e) {
            if (ratingStatus) ratingStatus.textContent = 'Chyba uložení';
        }
    });

    rateDown?.addEventListener('click', async () => {
        const current = ratingRow?.dataset.current || '';
        const vote = current === 'dislike' ? 'none' : 'dislike';
        if (ratingStatus) ratingStatus.textContent = 'Ukládám…';
        try {
            const result = await sendRating(vote);
            if (!result.ok) throw new Error(result.error || 'error');
            applyRatingUI(vote === 'none' ? '' : vote);
            if (ratingStatus) ratingStatus.textContent = 'Uloženo';
        } catch (e) {
            if (ratingStatus) ratingStatus.textContent = 'Chyba uložení';
        }
    });

    const timerValue = document.getElementById('timerValue');
    if (timerValue) {
        let remaining = Number(timerValue.dataset.seconds || '0');
        if (!Number.isFinite(remaining) || remaining < 0) remaining = 0;
        let started = false;

        const audioCtx = (window.AudioContext || window.webkitAudioContext) ? new (window.AudioContext || window.webkitAudioContext)() : null;

        const beep = (frequency = 880, durationMs = 120, volume = 0.05) => {
            if (!audioCtx) return;
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.value = frequency;
            gain.gain.value = volume;
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            setTimeout(() => {
                osc.stop();
            }, durationMs);
        };

        const finalSound = () => {
            beep(520, 220, 0.08);
            setTimeout(() => beep(360, 380, 0.08), 230);
        };

        const render = () => {
            const mm = String(Math.floor(remaining / 60)).padStart(2, '0');
            const ss = String(remaining % 60).padStart(2, '0');
            timerValue.textContent = `${mm}:${ss}`;
        };

        timerValue.addEventListener('click', async () => {
            if (started) return;
            started = true;
            if (audioCtx && audioCtx.state === 'suspended') {
                try { await audioCtx.resume(); } catch (e) {}
            }

            if (remaining <= 0) {
                finalSound();
                if (navigator.vibrate) navigator.vibrate([200, 120, 260]);
                return;
            }

            const int = setInterval(() => {
                remaining -= 1;
                render();

                if (remaining > 0 && remaining <= 5) {
                    beep(1100, 90, 0.06);
                }

                if (remaining <= 0) {
                    clearInterval(int);
                    finalSound();
                    if (navigator.vibrate) navigator.vibrate([220, 140, 320]);
                }
            }, 1000);
        });

        render();
    }

    const repeatCount = document.getElementById('repeatCount');
    if (repeatCount) {
        let value = Number(repeatCount.dataset.initial || '0');
        if (!Number.isFinite(value) || value < 0) value = 0;

        const renderCount = () => {
            repeatCount.textContent = String(value);
        };

        repeatCount.addEventListener('click', () => {
            if (value > 0) {
                value -= 1;
                renderCount();
            }
        });

        renderCount();
    }
})();
</script>
</body>
</html>
