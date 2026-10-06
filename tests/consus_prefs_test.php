<?php

require_once __DIR__ . '/../web/consus_prefs.php';

function prefs_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/consus-prefs-' . getmypid();
@mkdir($root, 0775, true);
$saved = getenv('CONSUS_SNAPSHOT_FILE');
putenv('CONSUS_SNAPSHOT_FILE=' . $root . '/consus_snapshot.json');

try {
    prefs_assert(consus_normalize_email('  Tim@KVT.nl ') === 'tim@kvt.nl', 'e-mail wordt lowercase en getrimd');
    prefs_assert(consus_prefs_read('tim@kvt.nl') === consus_empty_prefs(), 'ontbrekend bestand is leeg');
    $path = consus_prefs_file_for_email('Tim@KVT.nl');
    prefs_assert(basename($path) === sha1('tim@kvt.nl') . '.json', 'bestand is sha1 van het genormaliseerde adres');
    prefs_assert(strpos($path, '/prefs/') !== false, 'voorkeuren staan onder prefs');

    $written = consus_prefs_write('Tim@KVT.nl', [
        'customers' => ' C1, c1, C2 ',
        'items' => [' ART-1 ', 'art-1', 'ART-2'],
    ]);
    prefs_assert($written['customers'] === ['C1', 'C2'], 'klanten worden uniek en getrimd');
    prefs_assert($written['items'] === ['ART-1', 'ART-2'], 'artikelen worden uniek, hoofdletters van de eerste blijven');
    $again = consus_prefs_read('tim@kvt.nl');
    prefs_assert($again === $written, 'lezen geeft dezelfde lijsten terug');
    prefs_assert(is_file($path), 'json-bestand staat op schijf');
    prefs_assert(!is_file($path . '.tmp.' . getmypid()), 'tijdelijk bestand is hernoemd');

    $raw = json_decode((string) file_get_contents($path), true);
    prefs_assert(is_array($raw) && ($raw['email'] ?? '') === 'tim@kvt.nl', 'opgeslagen e-mail is genormaliseerd');

    $empty = consus_prefs_read('');
    prefs_assert($empty === consus_empty_prefs(), 'lege e-mail leest niets');
    $threw = false;
    try {
        consus_prefs_write('geen-adres', ['customers' => 'C1', 'items' => []]);
    } catch (InvalidArgumentException $error) {
        $threw = true;
    }
    prefs_assert($threw, 'schrijven zonder e-mailadres faalt');

    $_SERVER['HTTP_HOST'] = 'consus.kvt.nl';
    $_SERVER['HTTP_ORIGIN'] = 'https://consus.kvt.nl';
    unset($_SERVER['HTTP_REFERER']);
    prefs_assert(consus_request_is_same_origin(), 'Origin van dezelfde host telt');
    $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
    prefs_assert(!consus_request_is_same_origin(), 'andere Origin telt niet');
    unset($_SERVER['HTTP_ORIGIN']);
    $_SERVER['HTTP_REFERER'] = 'https://consus.kvt.nl/index.php';
    prefs_assert(consus_request_is_same_origin(), 'Referer van dezelfde host telt');
    unset($_SERVER['HTTP_REFERER']);
    prefs_assert(!consus_request_is_same_origin(), 'zonder Origin en Referer telt het niet');

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['consus_csrf'] = '';
    $token = consus_csrf_token();
    prefs_assert($token !== '' && consus_csrf_matches($token), 'csrf-token klopt');
    prefs_assert(!consus_csrf_matches('anders'), 'verkeerd csrf-token faalt');
} finally {
    if ($saved === false) {
        putenv('CONSUS_SNAPSHOT_FILE');
    } else {
        putenv('CONSUS_SNAPSHOT_FILE=' . $saved);
    }
    foreach (glob($root . '/prefs/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    @rmdir($root . '/prefs');
    @rmdir($root);
}

echo "OK\n";
