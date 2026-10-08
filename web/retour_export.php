<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/consus_auth.php';
consus_load_auth();
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/consus_data.php';
require_once __DIR__ . '/consus_xlsx.php';
require_once __DIR__ . '/consus_retour.php';

$costCenter = trim((string) ($_GET['cost_center'] ?? ''));
try {
    $result = consus_retour_candidates(consus_retour_read_data(), consus_retour_settings_read(), consus_retour_today(), $costCenter);
    $binary = consus_xlsx_binary([consus_retour_export_sheet($result)]);
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $error->getMessage();
    exit;
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="perkins-retourkandidaten-' . consus_retour_today() . '.xlsx"');
header('Content-Length: ' . (string) strlen($binary));
header('Cache-Control: no-store');
echo $binary;
