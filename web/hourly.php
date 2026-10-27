<?php

/**
 * Weekendverversing van de artikelcache (stap 'artikelen' van nightly).
 *
 * Productie: elk uur GET /hourly.php vanaf localhost (geen parameters).
 * Lokaal:    php hourly.php
 *
 * Alleen za en zo (Europe/Amsterdam): za 00:00 bepaalt per bedrijf het
 * aantal artikelen, elk uur volgt het volgende stuk van ceil(totaal/48),
 * gemiste uren worden ingehaald. Na stuk 48 (zo 23:00) gaan artikelen die
 * het hele weekend niet langskwamen uit de cache, alleen als alles lukte.
 * Draait nooit tegelijk met nightly (lock per bedrijf).
 */

set_time_limit(3000);
ini_set('memory_limit', '512M');
ignore_user_abort(true);

require_once __DIR__ . '/consus_auth.php';
consus_load_auth();
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/logincheck.php';
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}

require_once __DIR__ . '/consus_data.php';

$now = new DateTimeImmutable('now');
$result = ['ok' => true, 'companies' => []];
if (consus_item_weekend_due($now) === null) {
    $result['status'] = 'geen weekend';
} else {
    try {
        $discovered = auth_discover_companies_across_active_environments();
        $names = is_array($discovered['companies'] ?? null) ? $discovered['companies'] : [];
        foreach (consus_companies_in_scope($names) as $entry) {
            $step = consus_item_weekend_step($entry['company'], $entry['company_key'], $now);
            if (($step['status'] ?? '') === 'fout') {
                $result['ok'] = false;
            }
            $result['companies'][$entry['company_key']] = $step;
        }
    } catch (Throwable $error) {
        $result['ok'] = false;
        $result['error'] = $error->getMessage();
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
