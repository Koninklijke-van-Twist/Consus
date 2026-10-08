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

$department = trim((string) ($_POST['retour_afdeling'] ?? ''));
$action = (string) ($_POST['action'] ?? '');
$error = '';
try {
    switch ($action) {
        case 'save_rule':
            consus_retour_rule_save($department, [
                'vendor' => $_POST['vendor'] ?? '',
                'type' => $_POST['type'] ?? '',
                'window' => $_POST['window'] ?? '',
                'min_value' => $_POST['min_value'] ?? '',
            ], trim((string) ($_POST['rule_id'] ?? '')));
            break;
        case 'delete_rule':
            consus_retour_rule_delete($department, trim((string) ($_POST['rule_id'] ?? '')));
            break;
        case 'garantie':
            consus_retour_garantie_set(
                (string) ($_POST['invoice'] ?? ''),
                (string) ($_POST['item'] ?? ''),
                consus_retour_normalize_po_list((string) ($_POST['orders'] ?? '')),
                ($_POST['garantie'] ?? '') === '1'
            );
            break;
        default:
            throw new InvalidArgumentException('Onbekende actie.');
    }
} catch (InvalidArgumentException $invalid) {
    $error = 'regel';
} catch (Throwable $failure) {
    $error = 'opslaan';
}

$company = trim((string) ($_POST['company'] ?? ''));
if (!isset(CONSUS_COMPANIES[$company])) {
    $company = '';
}
$params = [
    'company' => $company,
    'cost_center' => trim((string) ($_POST['cost_center'] ?? '')),
    'year' => (string) (int) ($_POST['year'] ?? 0),
    'retour_afdeling' => consus_retour_valid_department($department),
];
if ($error !== '') {
    $params['retour_fout'] = $error;
}
header('Location: index.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) . '#retour', true, 303);
exit;
