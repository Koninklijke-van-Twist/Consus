<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/consus_auth.php';
consus_load_auth();
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/consus_data.php';
require_once __DIR__ . '/consus_prefs.php';
require_once __DIR__ . '/consus_retour.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Alleen POST.';
    exit;
}

$email = consus_session_email();
if ($email === '' || !consus_csrf_matches((string) ($_POST['csrf'] ?? '')) || !consus_request_is_same_origin()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'De retourinstellingen zijn niet opgeslagen. Vernieuw de pagina en probeer het opnieuw.';
    exit;
}

$department = trim((string) ($_POST['retour_department'] ?? ''));
try {
    consus_retour_settings_write(
        $department,
        [
            'min_value' => $_POST['min_value'] ?? null,
            'window_spoed' => $_POST['window_spoed'] ?? null,
            'window_voorraad' => $_POST['window_voorraad'] ?? null,
            'start_date' => $_POST['start_date'] ?? null,
        ],
        (string) ($_POST['garantie_orders'] ?? ''),
        !empty($_POST['reset'])
    );
} catch (Throwable $error) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'De retourinstellingen zijn niet opgeslagen.';
    exit;
}

$company = trim((string) ($_POST['company'] ?? ''));
if (!isset(CONSUS_COMPANIES[$company])) {
    $company = '';
}
$target = 'index.php?' . http_build_query([
    'company' => $company,
    'cost_center' => trim((string) ($_POST['cost_center'] ?? '')),
    'year' => (string) (int) ($_POST['year'] ?? 0),
], '', '&', PHP_QUERY_RFC3986) . '#retour';
header('Location: ' . $target, true, 303);
exit;
