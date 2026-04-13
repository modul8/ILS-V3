<?php
function db_connect(array $cfg): PDO {
    $host = (string)($cfg["db_host"] ?? "127.0.0.1");
    $port = (string)($cfg["db_port"] ?? "3306");
    $name = (string)($cfg["db_name"] ?? "");
    $user = (string)($cfg["db_user"] ?? "");
    $pass = (string)($cfg["db_pass"] ?? "");
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}
