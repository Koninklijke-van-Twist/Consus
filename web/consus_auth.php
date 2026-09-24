<?php

/**
 * Laadt auth.php zonder geheimen in de repository.
 *
 * Lokaal wint ~/Repositories/auth.php (naast de app-repo's, dezelfde plek als
 * bij Tim). Op de server valt het terug op web/auth.php, dat niet in git staat.
 */

function consus_auth_candidates(): array
{
    $paths = [];
    foreach (['HOME', 'USERPROFILE'] as $key) {
        $base = getenv($key);
        if (!is_string($base) || $base === '') {
            continue;
        }
        $paths[] = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'Repositories' . DIRECTORY_SEPARATOR . 'auth.php';
    }

    $paths[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'auth.php';
    $paths[] = __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';

    $unique = [];
    foreach ($paths as $path) {
        $key = strtolower(str_replace('\\', '/', $path));
        if (!isset($unique[$key])) {
            $unique[$key] = $path;
        }
    }

    return array_values($unique);
}

function consus_load_auth(): void
{
    global $baseUrl;
    if (isset($baseUrl) && is_string($baseUrl) && $baseUrl !== '') {
        return;
    }

    foreach (consus_auth_candidates() as $path) {
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }

    $message = 'auth.php niet gevonden. Lokaal: ~/Repositories/auth.php naast de repo. Op de server: web/auth.php (niet in git).';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit(1);
}
