<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/consus_auth.php';
consus_load_auth();
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/consus_data.php';
require_once __DIR__ . '/consus_prefs.php';
require_once __DIR__ . '/consus_retour.php';
require_once __DIR__ . '/consus_companies.php';

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

// Bedrijf en afdeling komen uit de keuze bovenaan de pagina. Het bedrijf moet
// in de bedrijvenlijst staan; opgeslagen wordt de BC Name.
$resolvedCompany = consus_company_resolve((string) ($_POST['company'] ?? ''), consus_company_catalog(false));
$company = $resolvedCompany['name'] ?? '';
$department = trim((string) ($_POST['cost_center'] ?? ''));
$action = (string) ($_POST['action'] ?? '');
$error = '';
try {
    if ($company === '') {
        throw new InvalidArgumentException('Kies eerst een bedrijf.');
    }
    switch ($action) {
        case 'save_rule':
            consus_retour_rule_save($company, $department, [
                'vendor' => $_POST['vendor'] ?? '',
                'type' => $_POST['type'] ?? '',
                'window' => $_POST['window'] ?? '',
                'min_value' => $_POST['min_value'] ?? '',
            ], trim((string) ($_POST['rule_id'] ?? '')));
            break;
        case 'delete_rule':
            consus_retour_rule_delete($company, $department, trim((string) ($_POST['rule_id'] ?? '')));
            break;
        case 'garantie':
            consus_retour_garantie_set(
                $company,
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

$params = [
    'company' => $company !== '' ? $company : trim((string) ($_POST['company'] ?? '')),
    'cost_center' => $department,
    'year' => (string) (int) ($_POST['year'] ?? 0),
];
if ($error !== '') {
    $params['retour_fout'] = $error;
}
header('Location: index.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) . '#retour', true, 303);
exit;
