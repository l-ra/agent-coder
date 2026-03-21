<?php

declare(strict_types=1);

$dbPath = __DIR__ . '/data/app.sqlite';
$dsn = 'sqlite:' . $dbPath;

$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        url TEXT NOT NULL,
        title TEXT,
        source_type TEXT,
        category TEXT NOT NULL,
        summary TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime("now"))
    )'
);

$pdo->exec('CREATE INDEX IF NOT EXISTS idx_links_category ON links(category)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_links_created_at ON links(created_at DESC)');
