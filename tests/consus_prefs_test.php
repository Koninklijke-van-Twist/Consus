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
    prefs_assert($written['page_size'] === 20, 'paginagrootte valt terug op 20');
    prefs_assert($again === $written, 'lezen geeft dezelfde lijsten terug');
    $sized = consus_prefs_write('tim@kvt.nl', ['page_size' => 150]);
    prefs_assert($sized['page_size'] === 150, 'geldige paginagrootte blijft staan');
    prefs_assert($sized['customers'] === ['C1', 'C2'], 'paginagrootte wist de klanten niet');
    prefs_assert($sized['items'] === ['ART-1', 'ART-2'], 'paginagrootte wist de artikelen niet');
    prefs_assert($sized['hide_zero'] === false, 'nul-regels wegfilteren staat standaard uit');
    $hidden = consus_prefs_write('tim@kvt.nl', consus_prefs_input_from_request(['csrf' => 'x', 'hide_zero' => '1']));
    prefs_assert($hidden['hide_zero'] === true, 'nul-regels wegfilteren wordt opgeslagen');
    prefs_assert($hidden['page_size'] === 150 && $hidden['customers'] === ['C1', 'C2'], 'nul-filter wist paginagrootte en klanten niet');
    prefs_assert(consus_prefs_read('tim@kvt.nl')['hide_zero'] === true, 'nul-filter blijft na opnieuw lezen aan');
    prefs_assert(consus_prefs_write('tim@kvt.nl', ['page_size' => 150])['hide_zero'] === true, 'paginagrootte wist het nul-filter niet');
    prefs_assert(consus_prefs_write('tim@kvt.nl', ['customers' => 'C1, C2', 'items' => 'ART-1, ART-2'])['hide_zero'] === true, 'filteropslag (zonder vinkje) wist het nul-filter niet');
    prefs_assert(consus_prefs_write('tim@kvt.nl', ['hide_zero' => '0'])['hide_zero'] === false, 'nul-filter weer uit');
    prefs_assert(consus_prefs_write('tim@kvt.nl', ['hide_zero' => 'onzin'])['hide_zero'] === false, 'onbekende waarde is uit');
    prefs_assert(consus_prefs_normalize_flag(true) && consus_prefs_normalize_flag('on') && consus_prefs_normalize_flag(1), 'aan-waarden');
    prefs_assert(!consus_prefs_normalize_flag(null) && !consus_prefs_normalize_flag('') && !consus_prefs_normalize_flag([1]) && !consus_prefs_normalize_flag(2), 'uit-waarden');
    prefs_assert(!array_key_exists('hide_zero', consus_prefs_input_from_request(['customers' => 'C1'])), 'zonder vinkje in het verzoek geen nul-filterwijziging');
    $kept = consus_prefs_write('tim@kvt.nl', ['customers' => 'C9']);
    prefs_assert($kept['page_size'] === 150, 'filteropslag houdt de paginagrootte');
    prefs_assert($kept['items'] === ['ART-1', 'ART-2'], 'alleen meegestuurde klanten wijzigen');
    $invalid = consus_prefs_write('tim@kvt.nl', ['page_size' => 15]);
    prefs_assert($invalid['page_size'] === 20, 'ongeldige paginagrootte wordt 20');
    prefs_assert(consus_prefs_input_from_request(['page_size' => '50', 'extra' => 'nee']) === ['page_size' => '50'], 'request houdt alleen bekende velden');
    // Bedrijf en afdeling per gebruiker, server-side.
    prefs_assert($invalid['company'] === null && $invalid['cost_center'] === null, 'nog geen keuze: null');
    $chosen = consus_prefs_write('tim@kvt.nl', ['company' => ' Hunter van Twist ', 'cost_center' => '15']);
    prefs_assert($chosen['company'] === 'Hunter van Twist' && $chosen['cost_center'] === '15', 'bedrijf en afdeling opgeslagen');
    prefs_assert($chosen['page_size'] === 20 && $chosen['customers'] === ['C9'], 'keuze wist de andere voorkeuren niet');
    $other = consus_prefs_write('ariadne@kvt.nl', ['company' => 'Koninklijke van Twist']);
    prefs_assert(consus_prefs_read('tim@kvt.nl')['company'] === 'Hunter van Twist' && $other['company'] === 'Koninklijke van Twist' && $other['cost_center'] === null, 'per gebruiker');
    $all = consus_prefs_write('tim@kvt.nl', ['company' => '', 'cost_center' => '']);
    prefs_assert($all['company'] === '' && $all['cost_center'] === '', 'Alle is een bewuste keuze (leeg, niet null)');
    $bad = consus_prefs_write('tim@kvt.nl', ['company' => "x|<script>"]);
    prefs_assert($bad['company'] === '', 'ongeldige keuze overschrijft niets');
    prefs_assert(consus_prefs_input_from_request(['company' => 'A', 'cost_center' => '5']) === ['company' => 'A', 'cost_center' => '5'], 'request neemt bedrijf en afdeling mee');
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

// Keuze bovenaan: opslaan en doorsturen in één navigatie, afdeling alleen bij een bedrijf.
$redirect = consus_prefs_filter_redirect(['company' => 'Koninklijke van Twist', 'cost_center' => ' 15 ', 'year' => '2026']);
if ($redirect !== 'index.php?company=Koninklijke%20van%20Twist&cost_center=15&year=2026') {
    throw new RuntimeException('filter-redirect met bedrijf en afdeling: ' . $redirect);
}
if (consus_prefs_filter_redirect(['company' => '', 'cost_center' => '15', 'year' => '2026']) !== 'index.php?company=&cost_center=&year=2026') {
    throw new RuntimeException('filter-redirect bij Alle hoort geen afdeling mee te sturen');
}
if (consus_prefs_filter_redirect(['company' => 'Hunter van Twist', 'cost_center' => '<x>']) !== 'index.php?company=Hunter%20van%20Twist&cost_center=&year=') {
    throw new RuntimeException('filter-redirect laat ongeldige afdeling weg');
}
if (consus_prefs_filter_input(['company' => '', 'cost_center' => '15']) !== ['company' => '', 'cost_center' => '']) {
    throw new RuntimeException('filter-input: bij Alle geen onthouden afdeling');
}
if (consus_prefs_filter_input(['company' => 'KVT Germany', 'cost_center' => '10', 'customers' => 'X']) !== ['company' => 'KVT Germany', 'cost_center' => '10']) {
    throw new RuntimeException('filter-input: alleen bedrijf en afdeling, samen');
}

echo "OK\n";
