<?php

define('CONSUS_NIGHTLY_LIBRARY', true);

require_once __DIR__ . '/../web/nightly.php';

function nightly_debug_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

nightly_debug_assert(consus_nightly_log_debug_enabled('1', 'fpm-fcgi') === true, '1 zet log_debug aan');
nightly_debug_assert(consus_nightly_log_debug_enabled('true', 'apache2handler') === true, 'true zet log_debug aan');
nightly_debug_assert(consus_nightly_log_debug_enabled('YES', 'cgi-fcgi') === true, 'yes is hoofdletterongevoelig');
nightly_debug_assert(consus_nightly_log_debug_enabled('  yes  ', 'litespeed') === true, 'yes met witruimte');
nightly_debug_assert(consus_nightly_log_debug_enabled('1', 'cli') === false, 'CLI negeert log_debug');
nightly_debug_assert(consus_nightly_log_debug_enabled('true', 'cli') === false, 'CLI negeert true');
nightly_debug_assert(consus_nightly_log_debug_enabled(null, 'fpm-fcgi') === false, 'ontbrekend blijft uit');
nightly_debug_assert(consus_nightly_log_debug_enabled('', 'fpm-fcgi') === false, 'lege waarde blijft uit');
nightly_debug_assert(consus_nightly_log_debug_enabled('0', 'fpm-fcgi') === false, '0 blijft uit');
nightly_debug_assert(consus_nightly_log_debug_enabled('false', 'fpm-fcgi') === false, 'false blijft uit');
nightly_debug_assert(consus_nightly_log_debug_enabled('no', 'fpm-fcgi') === false, 'no blijft uit');
nightly_debug_assert(consus_nightly_log_debug_enabled('on', 'fpm-fcgi') === false, 'on blijft uit');
nightly_debug_assert(consus_nightly_log_debug_enabled(['1'], 'fpm-fcgi') === false, 'array blijft uit');
nightly_debug_assert(consus_nightly_log_debug_enabled(1, 'fpm-fcgi') === true, 'integer 1 telt mee');
nightly_debug_assert(consus_nightly_force_requested('1', null) === true, 'env force telt');
nightly_debug_assert(consus_nightly_force_requested(null, 'yes') === true, 'query force telt');
nightly_debug_assert(consus_nightly_force_requested(null, null, ['--force']) === true, 'cli --force telt');
nightly_debug_assert(consus_nightly_force_requested(null, null, ['force=true']) === true, 'cli force= telt');
nightly_debug_assert(consus_nightly_force_requested('0', 'false', []) === false, 'force blijft uit');
nightly_debug_assert(consus_nightly_force_requested(null, null) === false, 'zonder force geen herlaad');
nightly_debug_assert(consus_nightly_full_ledger_requested('1', null) === true, 'env CONSUS_FULL_LEDGER telt');
nightly_debug_assert(consus_nightly_full_ledger_requested(null, 'yes') === true, 'query full telt');
nightly_debug_assert(consus_nightly_full_ledger_requested(null, null, ['--full']) === true, 'cli --full telt');
nightly_debug_assert(consus_nightly_full_ledger_requested(null, null, ['full=true']) === true, 'cli full= telt');
nightly_debug_assert(consus_nightly_full_ledger_requested('0', 'false', []) === false, 'full blijft uit');
nightly_debug_assert(consus_nightly_full_ledger_requested(null, null, ['--force']) === false, 'force is geen full');
nightly_debug_assert(consus_nightly_force_requested(null, null, ['--full']) === false, 'full is geen force');

$plain = new RuntimeException('password=hunter2 bij Authorization: Bearer super-secret-token');
$withoutDebug = consus_nightly_throwable_payload($plain, 15, false);
nightly_debug_assert($withoutDebug['ok'] === false, 'catch blijft ok false');
nightly_debug_assert($withoutDebug['total_duration_ms'] === 15, 'duur blijft');
nightly_debug_assert($withoutDebug['error'] === $plain->getMessage(), 'zonder log_debug blijft de fouttekst gelijk');
nightly_debug_assert(!array_key_exists('debug', $withoutDebug), 'zonder log_debug geen debug-object');
nightly_debug_assert(
    array_keys($withoutDebug) === ['ok', 'generated_at', 'error', 'total_duration_ms'],
    'catch-keys zonder log_debug blijven gelijk'
);

$withDebug = consus_nightly_throwable_payload($plain, 15, true);
nightly_debug_assert(!str_contains($withDebug['error'], 'hunter2'), 'wachtwoord uit de fouttekst');
nightly_debug_assert(!str_contains($withDebug['error'], 'super-secret-token'), 'bearer-token uit de fouttekst');
nightly_debug_assert($withDebug['debug']['type'] === 'exception', 'throwable is exception');
nightly_debug_assert($withDebug['debug']['class'] === 'RuntimeException', 'class in debug');
nightly_debug_assert($withDebug['debug']['file'] === $plain->getFile(), 'file in debug');
nightly_debug_assert($withDebug['debug']['line'] === $plain->getLine(), 'line in debug');
nightly_debug_assert(!array_key_exists('trace', $withDebug['debug']), 'geen stacktrace');
nightly_debug_assert(!array_key_exists('last_error', $withDebug['debug']), 'catch dumpt geen losse notice');

$typeError = consus_nightly_throwable_payload(new TypeError('kapot'), 1, true);
nightly_debug_assert($typeError['debug']['type'] === 'fatal', 'Error is fatal');
nightly_debug_assert($typeError['debug']['class'] === 'TypeError', 'Error-class blijft');

$timeout = consus_nightly_uncaught_fatal_payload(false, [
    'type' => E_ERROR,
    'message' => 'Maximum execution time of 1800 seconds exceeded',
    'file' => '/var/www/consus/web/consus_data.php',
    'line' => 1260,
]);
nightly_debug_assert(is_array($timeout), 'timeout levert JSON');
nightly_debug_assert($timeout['ok'] === false, 'timeout ok false');
nightly_debug_assert($timeout['debug']['type'] === 'timeout', 'timeout-type');
nightly_debug_assert($timeout['debug']['file'] === '/var/www/consus/web/consus_data.php', 'timeout-file');
nightly_debug_assert($timeout['debug']['line'] === 1260, 'timeout-line');
nightly_debug_assert($timeout['debug']['last_error']['type'] === E_ERROR, 'last_error type');
nightly_debug_assert($timeout['debug']['last_error']['line'] === 1260, 'last_error line');
nightly_debug_assert(!array_key_exists('class', $timeout['debug']), 'fatal heeft geen class');

$memory = consus_nightly_fatal_payload([
    'type' => E_ERROR,
    'message' => 'Allowed memory size of 536870912 bytes exhausted (tried to allocate 4096 bytes)',
    'file' => '/var/www/consus/web/nightly.php',
    'line' => 40,
]);
nightly_debug_assert(is_array($memory) && $memory['debug']['type'] === 'fatal', 'geheugen is fatal');

nightly_debug_assert(consus_nightly_fatal_payload(null) === null, 'geen last error, geen body');
nightly_debug_assert(
    consus_nightly_fatal_payload([
        'type' => E_WARNING,
        'message' => 'notice',
        'file' => 'a.php',
        'line' => 1,
    ]) === null,
    'warning is geen kale 500'
);
nightly_debug_assert(
    consus_nightly_uncaught_fatal_payload(true, [
        'type' => E_ERROR,
        'message' => 'Maximum execution time of 30 seconds exceeded',
        'file' => 'a.php',
        'line' => 2,
    ]) === null,
    'schone JSON krijgt geen tweede fatal-body'
);

$redacted = consus_nightly_redact('cURL error: https://svc:s3cret@bc.example.test/odata password=hunter2');
nightly_debug_assert(!str_contains($redacted, 's3cret'), 'userinfo uit url');
nightly_debug_assert(str_contains($redacted, 'https://bc.example.test/odata'), 'host blijft staan');
nightly_debug_assert(!str_contains($redacted, 'hunter2'), 'password= geredacteerd');
nightly_debug_assert(
    !str_contains(json_encode($timeout), '$_SERVER'),
    'payload noemt geen servervariabelen'
);

$source = (string) file_get_contents(__DIR__ . '/../web/nightly.php');
$loginAt = strpos($source, "require_once __DIR__ . '/logincheck.php';");
$enableAt = strpos($source, 'consus_nightly_enable_log_debug();');
nightly_debug_assert($loginAt !== false && $enableAt !== false && $loginAt < $enableAt, 'log_debug pas na logincheck');
nightly_debug_assert(str_contains($source, "error_reporting(E_ALL)"), 'E_ALL bij log_debug');
nightly_debug_assert(str_contains($source, "ini_set('log_errors', '1')"), 'fouten worden gelogd');
nightly_debug_assert(str_contains($source, 'register_shutdown_function'), 'shutdown-handler');
nightly_debug_assert(str_contains($source, 'application/json'), 'JSON content-type');
nightly_debug_assert(!str_contains($source, '$_SERVER['), 'geen $_SERVER-dump');
nightly_debug_assert(!str_contains($source, 'file_get_contents'), 'geen bestandsinhoud, ook niet van auth.php');
nightly_debug_assert(!str_contains($source, 'getTrace'), 'geen trace met argumenten');
$libraryAt = strpos($source, "if (defined('CONSUS_NIGHTLY_LIBRARY'))");
$loadAuthAt = strpos($source, 'consus_load_auth();');
nightly_debug_assert($libraryAt !== false && $loadAuthAt !== false && $libraryAt < $loadAuthAt, 'library-modus laadt geen auth');

$probe = sys_get_temp_dir() . '/consus-nightly-debug-' . getmypid() . '.php';
$stdoutFile = $probe . '.out';
$stderrFile = $probe . '.err';
$nightlyFile = var_export(realpath(__DIR__ . '/../web/nightly.php'), true);
file_put_contents($probe, <<<PHP
<?php
define('CONSUS_NIGHTLY_LIBRARY', true);
require {$nightlyFile};
consus_nightly_enable_log_debug();
trigger_error('Maximum execution time of 1800 seconds exceeded in auth.php password=should-not-leak', E_USER_ERROR);
PHP);

try {
    exec(
        'php ' . escapeshellarg($probe) . ' > ' . escapeshellarg($stdoutFile) . ' 2> ' . escapeshellarg($stderrFile),
        $probeOutput,
        $probeStatus
    );
    $probeBody = (string) file_get_contents($stdoutFile);
    $decoded = json_decode($probeBody, true);
    nightly_debug_assert(is_array($decoded), 'shutdown schrijft JSON, kreeg: ' . $probeBody);
    nightly_debug_assert($decoded['ok'] === false, 'shutdown ok false');
    nightly_debug_assert($decoded['debug']['type'] === 'timeout', 'user-error met execution time is timeout');
    nightly_debug_assert(!str_contains($probeBody, 'should-not-leak'), 'shutdown lekt geen wachtwoord');
    nightly_debug_assert(!str_contains($probeBody, '$_SERVER'), 'shutdown dumpt geen servervariabelen');
    nightly_debug_assert($probeStatus !== 0, 'fatal blijft een mislukte afsluiting');
} finally {
    foreach ([$probe, $stdoutFile, $stderrFile] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

echo "OK\n";
