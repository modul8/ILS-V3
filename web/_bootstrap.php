<?php
session_start();

$config_path = __DIR__ . "/config.php";
if (!file_exists($config_path)) {
    http_response_code(500);
    echo "Missing config.php. Copy config.sample.php to config.php first.";
    exit;
}
$cfg = require $config_path;

require_once __DIR__ . "/_auth.php";
$current_user = auth_require_login();
