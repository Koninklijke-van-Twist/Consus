<?php

/**
 * De enige volledige BC-refresh voor Consus.
 *
 * Productie: GET /nightly.php
 * Lokaal:    php nightly.php
 *
 * index.php leest daarna alleen de snapshot en doet geen OData-calls.
 *
 * Diagnose, alleen HTTP en alleen nadat logincheck de gebruiker heeft
 * toegelaten: ?log_debug=1 (ook true of yes). Dan gaat E_ALL aan, fouten
 * naar het PHP-log, en een shutdown-handler schrijft alsnog JSON als een
 * fatal of timeout de gewone response heeft overgeslagen. Zonder parameter
 * en op de CLI blijft het gedrag hetzelfde. Geen $_SERVER, geen inhoud van
 * auth.php, geen tokens.
 *
 * Tests laden alleen de helpers via define('CONSUS_NIGHTLY_LIBRARY', true).
 */

function consus_nightly_flag_requested(mixed $env, mixed $query, array $argv, string $name): bool
{
    $long = '--' . $name;
    $prefix = $name . '=';
    foreach ($argv as $arg) {
        if (!is_string($arg)) {
            continue;
        }
        if ($arg === $long) {
            return true;
        }
        if (str_starts_with($arg, $prefix)) {
            $env = substr($arg, strlen($prefix));
        }
    }

    foreach ([$env, $query] as $value) {
        if (!is_string($value) && !is_int($value)) {
            continue;
        }
        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes'], true)) {
            return true;
        }
    }

    return false;
}

function consus_nightly_force_requested(mixed $env, mixed $query, array $argv = []): bool
{
    return consus_nightly_flag_requested($env, $query, $argv, 'force');
}

function consus_nightly_full_ledger_requested(mixed $env, mixed $query, array $argv = []): bool
{
    return consus_nightly_flag_requested($env, $query, $argv, 'full');
}

function consus_nightly_log_debug_enabled(mixed $value, string $sapi): bool
{
    if ($sapi === 'cli') {
        return false;
    }
    if (!is_string($value) && !is_int($value)) {
        return false;
    }

    $normalized = strtolower(trim((string) $value));

    return in_array($normalized, ['1', 'true', 'yes'], true);
}

function consus_nightly_redact(string $message): string
{
    $message = str_replace("\0", '', $message);
    $message = preg_replace('#://[^/\s:@]+:[^/\s@]+@#', '://', $message) ?? $message;
    $message = preg_replace(
        '/\b((?:authorization|proxy-authorization)\s*:\s*)(?:basic|bearer|negotiate|ntlm)\s+\S+/i',
        '$1[redacted]',
        $message
    ) ?? $message;
    $message = preg_replace('/\b(bearer\s+)\S+/i', '$1[redacted]', $message) ?? $message;
    $message = preg_replace(
        '/\b((?:api[_-]?key|access[_-]?token|refresh[_-]?token|token|password|passwd|secret|pwd)["\']?\s*[=:]\s*)(?:"[^"]*"|\'[^\']*\'|\S+)/i',
        '$1[redacted]',
        $message
    ) ?? $message;

    return $message;
}

function consus_nightly_is_fatal_type(int $type): bool
{
    return in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
}

function consus_nightly_failure_kind(string $message): string
{
    if (stripos($message, 'Maximum execution time') !== false) {
        return 'timeout';
    }

    return 'fatal';
}

/**
 * @param array<string, mixed>|null $lastError
 * @return array{type:int,message:string,file:string,line:int}|null
 */
function consus_nightly_public_last_error(?array $lastError): ?array
{
    if ($lastError === null) {
        return null;
    }

    return [
        'type' => (int) ($lastError['type'] ?? 0),
        'message' => consus_nightly_redact((string) ($lastError['message'] ?? '')),
        'file' => str_replace("\0", '', (string) ($lastError['file'] ?? '')),
        'line' => (int) ($lastError['line'] ?? 0),
    ];
}

/**
 * @param array<string, mixed>|null $lastError
 * @return array{type:string,message:string,file:string,line:int,class?:string,last_error?:array{type:int,message:string,file:string,line:int}}
 */
function consus_nightly_debug_block(
    string $type,
    string $message,
    string $file,
    int $line,
    ?string $class = null,
    ?array $lastError = null
): array {
    $debug = [
        'type' => $type,
        'message' => consus_nightly_redact($message),
        'file' => str_replace("\0", '', $file),
        'line' => $line,
    ];
    if ($class !== null && $class !== '') {
        $debug['class'] = $class;
    }

    $publicLast = consus_nightly_public_last_error($lastError);
    if ($publicLast !== null) {
        $debug['last_error'] = $publicLast;
    }

    return $debug;
}

/**
 * @param array<string, mixed>|null $lastError
 * @return array{ok:false,error:string,debug:array<string, mixed>}|null
 */
function consus_nightly_fatal_payload(?array $lastError): ?array
{
    if ($lastError === null || !consus_nightly_is_fatal_type((int) ($lastError['type'] ?? 0))) {
        return null;
    }

    $message = (string) ($lastError['message'] ?? '');
    if ($message === '') {
        $message = 'Fatal error';
    }

    return [
        'ok' => false,
        'error' => consus_nightly_redact($message),
        'debug' => consus_nightly_debug_block(
            consus_nightly_failure_kind($message),
            $message,
            (string) ($lastError['file'] ?? ''),
            (int) ($lastError['line'] ?? 0),
            null,
            $lastError
        ),
    ];
}

/**
 * @param array<string, mixed>|null $lastError
 * @return array{ok:false,error:string,debug:array<string, mixed>}|null
 */
function consus_nightly_uncaught_fatal_payload(bool $responseSent, ?array $lastError): ?array
{
    if ($responseSent) {
        return null;
    }

    return consus_nightly_fatal_payload($lastError);
}

/**
 * @return array{ok:false,generated_at:string,error:string,total_duration_ms:int,debug?:array<string, mixed>}
 */
function consus_nightly_throwable_payload(Throwable $error, int $durationMs, bool $logDebug): array
{
    $message = $error->getMessage();
    $payload = [
        'ok' => false,
        'generated_at' => gmdate('c'),
        'error' => $logDebug ? consus_nightly_redact($message) : $message,
        'total_duration_ms' => $durationMs,
    ];
    if (!$logDebug) {
        return $payload;
    }

    $payload['debug'] = consus_nightly_debug_block(
        $error instanceof Error ? 'fatal' : 'exception',
        $message,
        $error->getFile(),
        $error->getLine(),
        $error::class
    );

    return $payload;
}

function consus_nightly_send_json(array $payload, int $status, bool $logDebug): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false && $logDebug) {
        $status = 500;
        $json = '{"ok":false,"error":"JSON-codering mislukt","debug":{"type":"fatal","message":"json_encode failed","file":"","line":0}}';
    }

    $level = $GLOBALS['consus_nightly_debug_buffer_level'] ?? null;
    if ($logDebug && is_int($level)) {
        while (ob_get_level() > $level) {
            if (!ob_end_clean()) {
                break;
            }
        }
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        http_response_code($status);
    }

    if ($logDebug) {
        $GLOBALS['consus_nightly_response_sent'] = true;
    }

    echo $json;
}

function consus_nightly_enable_log_debug(): void
{
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('html_errors', '0');
    ini_set('log_errors', '1');
    ini_set('zend.exception_ignore_args', '1');

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    $bufferLevel = ob_get_level();
    $GLOBALS['consus_nightly_debug_buffer_level'] = $bufferLevel;
    $GLOBALS['consus_nightly_response_sent'] = false;
    ob_start();

    set_exception_handler(static function (Throwable $error) use ($bufferLevel): void {
        $payload = [
            'ok' => false,
            'generated_at' => gmdate('c'),
            'error' => consus_nightly_redact($error->getMessage()),
            'debug' => consus_nightly_debug_block(
                $error instanceof Error ? 'fatal' : 'exception',
                $error->getMessage(),
                $error->getFile(),
                $error->getLine(),
                $error::class
            ),
        ];

        while (ob_get_level() > $bufferLevel) {
            if (!ob_end_clean()) {
                break;
            }
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            http_response_code(500);
        }

        $GLOBALS['consus_nightly_response_sent'] = true;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo is_string($json)
            ? $json
            : '{"ok":false,"error":"Fatal error","debug":{"type":"fatal","message":"Fatal error","file":"","line":0}}';
    });

    register_shutdown_function(static function () use ($bufferLevel): void {
        set_time_limit(30);

        $payload = consus_nightly_uncaught_fatal_payload(
            !empty($GLOBALS['consus_nightly_response_sent']),
            error_get_last()
        );
        if ($payload === null) {
            while (ob_get_level() > $bufferLevel) {
                if (!ob_end_flush()) {
                    break;
                }
            }
            return;
        }

        while (ob_get_level() > $bufferLevel) {
            if (!ob_end_clean()) {
                break;
            }
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            http_response_code(500);
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            echo '{"ok":false,"error":"Fatal error","debug":{"type":"fatal","message":"Fatal error","file":"","line":0}}';
            return;
        }

        echo $json;
    });
}

if (defined('CONSUS_NIGHTLY_LIBRARY')) {
    return;
}

set_time_limit(10800);
ini_set('max_execution_time', '10800');
ini_set('memory_limit', '512M');
ignore_user_abort(true);

require_once __DIR__ . '/consus_auth.php';
consus_load_auth();
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/logincheck.php';
}

$logDebug = false;
if (PHP_SAPI !== 'cli') {
    $logDebug = consus_nightly_log_debug_enabled($_GET['log_debug'] ?? null, PHP_SAPI);
    if ($logDebug) {
        consus_nightly_enable_log_debug();
    }
}

require_once __DIR__ . '/consus_data.php';

$startedAt = hrtime(true);
$GLOBALS['consus_progress_echo'] = PHP_SAPI === 'cli';
$cliArgv = PHP_SAPI === 'cli' && isset($argv) && is_array($argv) ? $argv : [];
$force = consus_nightly_force_requested(
    getenv('CONSUS_NIGHTLY_FORCE'),
    PHP_SAPI === 'cli' ? null : ($_GET['force'] ?? null),
    $cliArgv
);
$fullLedger = consus_nightly_full_ledger_requested(
    getenv('CONSUS_FULL_LEDGER'),
    PHP_SAPI === 'cli' ? null : ($_GET['full'] ?? null),
    $cliArgv
);

try {
    $snapshot = consus_run_nightly($force, $fullLedger);
    $payload = [
        'ok' => ($snapshot['errors'] ?? []) === [],
        'generated_at' => (string) ($snapshot['generated_at'] ?? gmdate('c')),
        'rows' => count($snapshot['rows'] ?? []),
        'companies' => $snapshot['companies'] ?? [],
        'warnings' => $snapshot['warnings'] ?? [],
        'errors' => $snapshot['errors'] ?? [],
        'locations' => $snapshot['locations'] ?? [],
        'total_duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
    ];

    if (PHP_SAPI === 'cli') {
        echo sprintf(
            "%s generated_at=%s rows=%d duration=%dms\n",
            $payload['ok'] ? 'OK' : 'PARTIAL',
            $payload['generated_at'],
            $payload['rows'],
            $payload['total_duration_ms']
        );
        foreach ($payload['companies'] as $company) {
            $note = '';
            if (!empty($company['stale'])) {
                $note = ' (oude data behouden)';
            } elseif (!empty($company['resumed'])) {
                $note = ' (al ververst vandaag, overgeslagen)';
            } elseif (!empty($company['checkpoint_resumed'])) {
                $note = ' (hervat binnen het bedrijf)';
            }
            if (($company['ledger_mode'] ?? '') === 'warm' && empty($company['resumed']) && empty($company['stale'])) {
                $note .= ' (grootboek sinds ' . (string) ($company['ledger_overlap_from'] ?? '') . ')';
            }
            echo sprintf(
                "  %s: duration=%dms%s\n",
                (string) ($company['company'] ?? ''),
                (int) ($company['duration_ms'] ?? 0),
                $note
            );
        }
        foreach ($payload['warnings'] as $warning) {
            echo sprintf(
                "  WARN %s: %s\n",
                (string) ($warning['company'] ?? ''),
                (string) ($warning['warning'] ?? '')
            );
        }
        foreach ($payload['errors'] as $error) {
            echo sprintf(
                "  ERROR %s: %s\n",
                (string) ($error['company'] ?? ''),
                (string) ($error['error'] ?? '')
            );
        }
        if ($payload['locations'] !== []) {
            echo '  locaties: ' . implode(', ', $payload['locations']) . "\n";
        }
        exit($payload['ok'] ? 0 : 1);
    }

    consus_nightly_send_json($payload, $payload['ok'] ? 200 : 207, $logDebug);
} catch (Throwable $error) {
    $payload = consus_nightly_throwable_payload(
        $error,
        (int) round((hrtime(true) - $startedAt) / 1_000_000),
        $logDebug
    );
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'FAIL ' . $error->getMessage() . "\n");
        exit(1);
    }

    consus_nightly_send_json($payload, 500, $logDebug);
}
