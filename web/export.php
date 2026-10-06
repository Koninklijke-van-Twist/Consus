<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/consus_auth.php';
consus_load_auth();
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/consus_data.php';
require_once __DIR__ . '/consus_prefs.php';
require_once __DIR__ . '/consus_xlsx.php';

$email = consus_session_email();
$prefs = $email !== '' ? consus_prefs_read($email) : consus_empty_prefs();
consus_xlsx_download(consus_read_snapshot(), $prefs, $_GET);
