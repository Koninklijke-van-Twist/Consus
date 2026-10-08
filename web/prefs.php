<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/consus_auth.php';
consus_load_auth();
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/consus_data.php';
require_once __DIR__ . '/consus_usage.php';
require_once __DIR__ . '/consus_prefs.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Alleen POST.';
    exit;
}

$email = consus_session_email();
$token = (string) ($_POST['csrf'] ?? '');
if ($token === '') {
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
}
$accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
$wantsJson = strpos($accept, 'application/json') !== false;
// Keuze bovenaan (bedrijf/afdeling): opslaan en in dezelfde navigatie de
// pagina met die keuze tonen. Geen losse fetch vooraf die een latere keuze
// kan inhalen of door een lopende navigatie kan worden afgebroken.
$filterChoice = !$wantsJson && (string) ($_POST['filter'] ?? '') === '1';

if ($email === '' || !consus_csrf_matches($token) || !consus_request_is_same_origin()) {
    if ($filterChoice) {
        // Niet opgeslagen, maar de gekozen weergave wel tonen (een gewone GET).
        header('Location: ' . consus_prefs_filter_redirect($_POST), true, 303);
        exit;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'De filters zijn niet opgeslagen. Vernieuw de pagina en probeer het opnieuw.';
    exit;
}

try {
    $saved = consus_prefs_write($email, $filterChoice ? consus_prefs_filter_input($_POST) : consus_prefs_input_from_request($_POST));
} catch (Throwable $error) {
    if ($filterChoice) {
        header('Location: ' . consus_prefs_filter_redirect($_POST), true, 303);
        exit;
    }
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'De filters zijn niet opgeslagen.';
    exit;
}

if ($wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'customers' => $saved['customers'],
        'items' => $saved['items'],
        'page_size' => $saved['page_size'],
        'company' => $saved['company'],
        'cost_center' => $saved['cost_center'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Location: ' . consus_prefs_filter_redirect($_POST), true, 303);
exit;
