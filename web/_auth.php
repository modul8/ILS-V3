<?php
require_once __DIR__ . "/_db.php";

function auth_current_user(): ?array {
    if (!isset($_SESSION["user_id"])) return null;
    $id = (int)$_SESSION["user_id"];
    $username = (string)($_SESSION["username"] ?? "");
    $role = (string)($_SESSION["role"] ?? "");
    if ($id <= 0 || $username === "" || $role === "") return null;
    return [
        "id" => $id,
        "username" => $username,
        "role" => $role,
    ];
}

function auth_is_admin(?array $user): bool {
    return is_array($user) && (($user["role"] ?? "") === "admin");
}

function auth_require_login(): array {
    $user = auth_current_user();
    if ($user) return $user;
    header("Location: login.php");
    exit;
}
