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
if (!isset($_SESSION['counter'])) {
    $_SESSION['counter'] = null;
}
if (!isset($_SESSION['counter_done'])) {
    $_SESSION['counter_done'] = false;
}

$action = $_POST['action'] ?? null;

if ($action === 'draw') {
    $idx = random_int(0, count($tasks) - 1);
    $task = $tasks[$idx];

    $_SESSION['task'] = $task;
    $_SESSION['counter_done'] = false;

    $repeat = parseRepeatCount($task['pocet_opakovani'] ?? null);
    $_SESSION['counter'] = $repeat;

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($action === 'counter_decrement' && is_array($_SESSION['task'])) {
    if (is_int($_SESSION['counter']) && $_SESSION['counter'] > 0) {
        $_SESSION['counter']--;
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($action === 'counter_done' && is_array($_SESSION['task'])) {
    $_SESSION['counter_done'] = true;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($action === 'reset_task') {
    $_SESSION['task'] = null;
    $_SESSION['counter'] = null;
    $_SESSION['counter_done'] = false;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$currentTask = $_SESSION['task'];
?><!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lechtivity – losování úkolů</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; background: #111827; color: #f9fafb; }
        .wrap { max-width: 720px; margin: 0 auto; padding: 20px; }
        .card { background: #1f2937; border-radius: 16px; padding: 18px; margin-bottom: 16px; }
        h1 { font-size: 1.5rem; margin-top: 0; }
        h2 { margin-top: 0; }
        button { background: #ec4899; color: #fff; border: 0; border-radius: 12px; padding: 10px 14px; font-weight: 700; cursor: pointer; }
        button.secondary { background: #374151; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
        .muted { color: #9ca3af; }
        .timer { font-size: 2rem; font-weight: 700; margin: 10px 0; }
        .ok { color: #34d399; font-weight: 700; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Lechtivity</h1>
        <p>Seznam úkolů je schovaný. Klikni a vylosuje se náhodný úkol.</p>
        <form method="post">
            <input type="hidden" name="action" value="draw">
            <button type="submit">🎲 Vylosovat úkol</button>
        </form>
    </div>

    <?php if (is_array($currentTask)): ?>
        <?php
            $durationRaw = $currentTask['doba_trvani'] ?? null;
            $durationSeconds = is_string($durationRaw) ? parseDurationToSeconds($durationRaw) : null;
            $repeatRaw = $currentTask['pocet_opakovani'] ?? null;
        ?>
        <div class="card">
            <h2><?= htmlspecialchars((string)($currentTask['nazev'] ?? 'Bez názvu')) ?></h2>
            <p><?= nl2br(htmlspecialchars((string)($currentTask['popis'] ?? ''))) ?></p>
            <p class="muted">Pro: <?= htmlspecialchars((string)($currentTask['pro'] ?? 'neuvedeno')) ?> · Míra perverze: <?= htmlspecialchars((string)($currentTask['mira_perverze'] ?? 'neuvedeno')) ?></p>

            <?php if (is_string($durationRaw)): ?>
                <p><strong>Délka trvání:</strong> <?= htmlspecialchars($durationRaw) ?></p>
                <div id="timerSection">
                    <div class="timer" id="timerValue">00:00</div>
                    <div class="actions">
                        <button type="button" id="startBtn">▶ Start</button>
                        <button type="button" id="pauseBtn" class="secondary">⏸ Pauza</button>
                        <button type="button" id="resetBtn" class="secondary">↺ Reset</button>
                    </div>
                    <?php if (is_int($durationSeconds)): ?>
                        <p class="muted">Tip: cílový čas je <?= (int)$durationSeconds ?> s.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($repeatRaw !== null): ?>
                <hr>
                <p><strong>Počet opakování (zadání):</strong> <?= htmlspecialchars((string)$repeatRaw) ?></p>
                <p><strong>Zbývá:</strong> <?= (int)($_SESSION['counter'] ?? 0) ?></p>
                <?php if (!empty($_SESSION['counter_done'])): ?>
                    <p class="ok">✅ Splněno</p>
                <?php endif; ?>
                <div class="actions">
                    <form method="post">
                        <input type="hidden" name="action" value="counter_decrement">
                        <button type="submit">-1</button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="action" value="counter_done">
                        <button type="submit" class="secondary">Potvrdit splnění</button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="actions">
                <form method="post">
                    <input type="hidden" name="action" value="reset_task">
                    <button type="submit" class="secondary">Vyčistit úkol</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
(() => {
    const timerValue = document.getElementById('timerValue');
    if (!timerValue) return;

    let elapsed = 0;
    let interval = null;

    const render = () => {
        const mm = String(Math.floor(elapsed / 60)).padStart(2, '0');
        const ss = String(elapsed % 60).padStart(2, '0');
        timerValue.textContent = `${mm}:${ss}`;
    };

    document.getElementById('startBtn')?.addEventListener('click', () => {
        if (interval) return;
        interval = setInterval(() => {
            elapsed += 1;
            render();
        }, 1000);
    });

    document.getElementById('pauseBtn')?.addEventListener('click', () => {
        if (!interval) return;
        clearInterval(interval);
        interval = null;
    });

    document.getElementById('resetBtn')?.addEventListener('click', () => {
        if (interval) {
            clearInterval(interval);
            interval = null;
        }
        elapsed = 0;
        render();
    });

    render();
})();
</script>
</body>
</html>
