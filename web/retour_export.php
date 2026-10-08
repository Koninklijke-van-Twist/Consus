<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/consus_auth.php';
consus_load_auth();
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/consus_data.php';
require_once __DIR__ . '/consus_xlsx.php';
require_once __DIR__ . '/consus_retour.php';

// bedrijf = BC Name (verplicht); afdeling leeg = alle afdelingen met regels van dat bedrijf.
$company = consus_retour_company_name((string) ($_GET['bedrijf'] ?? ''));
$department = consus_retour_valid_department(trim((string) ($_GET['afdeling'] ?? '')));
if ($company === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Kies eerst een bedrijf.';
    exit;
}
try {
    $result = consus_retour_candidates_for_companies(consus_retour_settings_read(), consus_retour_today(), [$company], $department);
    $binary = consus_xlsx_binary([consus_retour_export_sheet($result)]);
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'De retourlijst kon niet worden geëxporteerd.';
    exit;
}
$slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($company)), '-');
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="retourlijst-' . $slug . '-' . ($department !== '' ? 'afdeling-' . $department . '-' : '') . consus_retour_today() . '.xlsx"');
header('Content-Length: ' . (string) strlen($binary));
header('Cache-Control: no-store');
echo $binary;
