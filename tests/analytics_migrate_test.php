<?php

define('ANALYTICS_LIBRARY_ONLY', true);
require_once __DIR__ . '/../web/analytics/analytics.php';

function analytics_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function analytics_test_pdo(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function analytics_test_column_type(PDO $pdo): string
{
    foreach ($pdo->query('PRAGMA table_info(visits)') as $column) {
        if (strtolower((string) ($column['name'] ?? '')) === 'visited_at') {
            return strtoupper((string) ($column['type'] ?? ''));
        }
    }

    return '';
}

$dir = sys_get_temp_dir() . '/consus-analytics-' . getmypid();
@mkdir($dir, 0700, true);

$textPath = $dir . '/text.sqlite';
$pdo = analytics_test_pdo($textPath);
$pdo->exec(
    'CREATE TABLE visits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        visited_at TEXT NOT NULL,
        user_email TEXT NOT NULL
    )'
);
$pdo->exec("INSERT INTO visits (visited_at, user_email) VALUES ('2026-09-01 08:00:00', 'a@kvt.test')");
analytics_migrate_visited_at_to_integer($pdo);
analytics_test_assert(analytics_test_column_type($pdo) === 'INTEGER', 'visited_at wordt integer');
analytics_test_assert((int) $pdo->query('SELECT COUNT(*) FROM visits')->fetchColumn() === 1, 'rij blijft behouden');
analytics_test_assert(!analytics_table_exists($pdo, 'visits_integer'), 'tijdelijke tabel is weg');
$email = (string) $pdo->query('SELECT user_email FROM visits')->fetchColumn();
analytics_test_assert($email === 'a@kvt.test', 'e-mail blijft gelijk');

$leftoverPath = $dir . '/leftover.sqlite';
$pdo = analytics_test_pdo($leftoverPath);
$pdo->exec(
    'CREATE TABLE visits (
        id INTEGER PRIMARY KEY,
        visited_at INTEGER NOT NULL,
        user_email TEXT NOT NULL
    )'
);
$pdo->exec("INSERT INTO visits (id, visited_at, user_email) VALUES (1, 100, 'b@kvt.test')");
$pdo->exec(
    'CREATE TABLE visits_integer (
        id INTEGER PRIMARY KEY,
        visited_at INTEGER NOT NULL,
        user_email TEXT NOT NULL
    )'
);
analytics_migrate_visited_at_to_integer($pdo);
analytics_test_assert(!analytics_table_exists($pdo, 'visits_integer'), 'afgebroken kopie blokkeert de volgende run niet');
analytics_test_assert((string) $pdo->query('SELECT user_email FROM visits')->fetchColumn() === 'b@kvt.test', 'originele visits blijft');

$renamedPath = $dir . '/renamed.sqlite';
$pdo = analytics_test_pdo($renamedPath);
$pdo->exec(
    'CREATE TABLE visits_integer (
        id INTEGER PRIMARY KEY,
        visited_at INTEGER NOT NULL,
        user_email TEXT NOT NULL
    )'
);
$pdo->exec("INSERT INTO visits_integer (id, visited_at, user_email) VALUES (3, 300, 'c@kvt.test')");
analytics_migrate_visited_at_to_integer($pdo);
analytics_test_assert(analytics_table_exists($pdo, 'visits'), 'hernoemde tabel wordt visits');
analytics_test_assert((string) $pdo->query('SELECT user_email FROM visits')->fetchColumn() === 'c@kvt.test', 'data uit de kopie blijft');

$source = (string) file_get_contents(__DIR__ . '/../web/analytics/analytics.php');
analytics_test_assert(!str_contains($source, '0666'), 'database niet wereldleesbaar');
analytics_test_assert(!str_contains($source, '0777'), 'map niet wereldschrijfbaar');

echo "OK\n";
