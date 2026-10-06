<?php

require_once __DIR__ . '/consus_data.php';

function consus_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

/**
 * @return array{customers:array<int, string>,items:array<int, string>}
 */
function consus_empty_prefs(): array
{
    return [
        'customers' => [],
        'items' => [],
    ];
}

function consus_prefs_directory(): string
{
    return dirname(consus_snapshot_file()) . '/prefs';
}

function consus_prefs_file_for_email(string $email): string
{
    $email = consus_normalize_email($email);
    if ($email === '' || strpos($email, '@') === false) {
        throw new InvalidArgumentException('Geen geldig e-mailadres voor de voorkeuren.');
    }

    return consus_prefs_directory() . '/' . sha1($email) . '.json';
}

/**
 * @param mixed $value
 * @return array<int, string>
 */
function consus_prefs_normalize_list($value, int $limit = 500): array
{
    if (is_string($value)) {
        $split = preg_split('/\s*,\s*/', $value);
        $value = is_array($split) ? $split : [];
    }
    if (!is_array($value)) {
        return [];
    }
    $out = [];
    foreach ($value as $item) {
        if (is_array($item)) {
            continue;
        }
        $text = trim((string) $item);
        if ($text === '' || strlen($text) > 80) {
            continue;
        }
        $key = strtolower($text);
        if (!isset($out[$key])) {
            $out[$key] = $text;
        }
        if (count($out) >= $limit) {
            break;
        }
    }

    return array_values($out);
}

/**
 * @return array{customers:array<int, string>,items:array<int, string>}
 */
function consus_prefs_read(string $email): array
{
    $email = consus_normalize_email($email);
    if ($email === '' || strpos($email, '@') === false) {
        return consus_empty_prefs();
    }
    $path = consus_prefs_file_for_email($email);
    if (!is_file($path)) {
        return consus_empty_prefs();
    }
    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return consus_empty_prefs();
    }

    return [
        'customers' => consus_prefs_normalize_list($decoded['customers'] ?? []),
        'items' => consus_prefs_normalize_list($decoded['items'] ?? []),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array{customers:array<int, string>,items:array<int, string>}
 */
function consus_prefs_write(string $email, array $input): array
{
    $email = consus_normalize_email($email);
    $prefs = [
        'email' => $email,
        'customers' => consus_prefs_normalize_list($input['customers'] ?? []),
        'items' => consus_prefs_normalize_list($input['items'] ?? []),
    ];
    $path = consus_prefs_file_for_email($email);
    consus_write_json_locked($path, $prefs);

    return [
        'customers' => $prefs['customers'],
        'items' => $prefs['items'],
    ];
}

/**
 * Atomair schrijven met een aparte lock, zoals de snapshot.
 *
 * @param array<string, mixed> $payload
 */
function consus_write_json_locked(string $path, array $payload): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Map voor voorkeuren kon niet worden aangemaakt.');
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('Voorkeuren konden niet als JSON worden gecodeerd.');
    }
    $json .= "\n";

    $lockPath = $path . '.lock';
    $lock = @fopen($lockPath, 'c+');
    if ($lock === false) {
        throw new RuntimeException('Voorkeuren-lock kon niet worden geopend.');
    }

    $temporary = $path . '.tmp.' . getmypid();
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Voorkeuren-lock kon niet worden verkregen.');
        }
        if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException('Tijdelijke voorkeuren konden niet worden geschreven.');
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Voorkeuren konden niet atomair worden vervangen.');
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function consus_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $token = (string) ($_SESSION['consus_csrf'] ?? '');
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        $_SESSION['consus_csrf'] = $token;
    }

    return $token;
}

function consus_csrf_matches(string $provided): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $token = (string) ($_SESSION['consus_csrf'] ?? '');
    if ($token === '' || $provided === '') {
        return false;
    }

    return hash_equals($token, $provided);
}

function consus_request_host(): string
{
    return strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
}

function consus_origin_matches_host(string $url, string $host): bool
{
    $host = strtolower(trim($host));
    if ($url === '' || $host === '') {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    $originHost = strtolower((string) ($parts['host'] ?? ''));
    if ($originHost === '') {
        return false;
    }
    $port = $parts['port'] ?? null;
    $origin = $originHost . (is_int($port) ? ':' . $port : '');
    $hostOnly = preg_replace('/:\d+$/', '', $host);
    if (!is_string($hostOnly)) {
        $hostOnly = $host;
    }

    return $origin === $host || $originHost === $hostOnly;
}

function consus_request_is_same_origin(): bool
{
    $host = consus_request_host();
    if ($host === '') {
        return false;
    }
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '') {
        return consus_origin_matches_host($origin, $host);
    }
    $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referer === '') {
        return false;
    }

    return consus_origin_matches_host($referer, $host);
}

function consus_session_email(): string
{
    return consus_normalize_email((string) ($_SESSION['user']['email'] ?? ''));
}
