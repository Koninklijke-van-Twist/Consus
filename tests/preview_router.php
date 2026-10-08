<?php

/**
 * Lokale preview zonder auth.php en zonder Business Central.
 * php -S 127.0.0.1:8765 tests/preview_router.php
 */

$previewRoot = sys_get_temp_dir() . '/consus-preview';
if (!is_dir($previewRoot) && !@mkdir($previewRoot, 0775, true) && !is_dir($previewRoot)) {
    http_response_code(500);
    echo 'Previewmap kon niet worden aangemaakt.';
    return true;
}
putenv('CONSUS_SNAPSHOT_FILE=' . $previewRoot . '/consus_snapshot.json');

require_once __DIR__ . '/../web/consus_data.php';
require_once __DIR__ . '/../web/consus_usage.php';
require_once __DIR__ . '/../web/consus_prefs.php';
require_once __DIR__ . '/../web/consus_xlsx.php';
require_once __DIR__ . '/../web/consus_page.php';
require_once __DIR__ . '/preview_snapshot.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!is_array($_SESSION['user'] ?? null)) {
    $_SESSION['user'] = [];
}
$_SESSION['user']['email'] = 'preview@consus.example';
if (trim((string) ($_SESSION['consus_csrf'] ?? '')) === '') {
    $_SESSION['consus_csrf'] = 'preview-csrf-token';
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$static = [
    '/brand.css' => 'text/css; charset=utf-8',
    '/doc.svg' => 'image/svg+xml',
    '/site.webmanifest' => 'application/manifest+json',
];
if (isset($static[$path])) {
    $file = __DIR__ . '/../web' . $path;
    if (!is_file($file)) {
        http_response_code(404);
        echo 'Bestand ontbreekt.';
        return true;
    }
    header('Content-Type: ' . $static[$path]);
    readfile($file);
    return true;
}

$snapshot = preview_snapshot();
$email = 'preview@consus.example';

if ($path === '/prefs.php') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo 'Alleen POST.';
        return true;
    }
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8765';
    if (trim((string) ($_SERVER['HTTP_ORIGIN'] ?? '')) === '' && trim((string) ($_SERVER['HTTP_REFERER'] ?? '')) === '') {
        $_SERVER['HTTP_ORIGIN'] = 'http://' . $_SERVER['HTTP_HOST'];
    }
    $token = (string) ($_POST['csrf'] ?? '');
    if (!consus_csrf_matches($token) || !consus_request_is_same_origin()) {
        http_response_code(403);
        echo 'De filters zijn niet opgeslagen.';
        return true;
    }
    $filterChoice = (string) ($_POST['filter'] ?? '') === '1';
    $saved = consus_prefs_write($email, $filterChoice ? consus_prefs_filter_input($_POST) : consus_prefs_input_from_request($_POST));
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (!$filterChoice && strpos($accept, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'customers' => $saved['customers'],
            'items' => $saved['items'],
            'page_size' => $saved['page_size'],
        ], JSON_UNESCAPED_UNICODE);
        return true;
    }
    header('Location: /' . consus_prefs_filter_redirect($_POST), true, 303);
    return true;
}

if ($path === '/export.php') {
    $prefs = consus_prefs_read($email);
    consus_xlsx_download($snapshot, $prefs, $_GET);
    return true;
}

if ($path !== '/' && $path !== '/index.php') {
    http_response_code(404);
    echo 'Niet gevonden.';
    return true;
}

$prefs = consus_prefs_read($email);
consus_page_render($snapshot, $prefs, $_GET, consus_csrf_token());
return true;
