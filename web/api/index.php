<?php
header("Content-Type: application/json; charset=utf-8");
session_start();

$config_path = dirname(__DIR__) . "/config.php";
if (!file_exists($config_path)) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "config_missing"]);
    exit;
}
$cfg = require $config_path;

require_once dirname(__DIR__) . "/_db.php";
require_once dirname(__DIR__) . "/_auth.php";

function clean_asset_type(string $v): string {
    $v = strtolower(trim($v));
    return in_array($v, ["drain", "culvert", "bridge"], true) ? $v : "";
}

function body_json(): array {
    $raw = file_get_contents("php://input");
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function upload_root(): string {
    $dir = dirname(__DIR__) . "/uploads";
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function get_asset_row(PDO $pdo, string $asset_type, string $asset_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM assets WHERE asset_type = :asset_type AND asset_id = :asset_id LIMIT 1");
    $stmt->execute([":asset_type" => $asset_type, ":asset_id" => $asset_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function asset_with_relations(PDO $pdo, array $cfg, string $asset_type, string $asset_id): ?array {
    $asset = get_asset_row($pdo, $asset_type, $asset_id);
    if (!$asset) return null;

    $contacts_stmt = $pdo->prepare("SELECT id, name, phone, email FROM asset_contacts WHERE asset_ref = :ref ORDER BY id ASC");
    $contacts_stmt->execute([":ref" => $asset["id"]]);
    $contacts = $contacts_stmt->fetchAll(PDO::FETCH_ASSOC);

    $notes_stmt = $pdo->prepare(
        "SELECT n.id, n.note_text, n.created_at, u.username
         FROM asset_notes n
         JOIN users u ON u.id = n.user_ref
         WHERE n.asset_ref = :ref
         ORDER BY n.id DESC"
    );
    $notes_stmt->execute([":ref" => $asset["id"]]);
    $notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);

    $photo_stmt = $pdo->prepare("SELECT id, filename, stored_path, created_at FROM asset_photos WHERE asset_ref = :ref ORDER BY id DESC");
    $photo_stmt->execute([":ref" => $asset["id"]]);
    $photos = [];
    $base = (string)($cfg["photo_base_url"] ?? "");
    $base = rtrim($base, "/");
    if ($base === "") {
        $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
        $host = $_SERVER["HTTP_HOST"] ?? "";
        $base = "{$scheme}://{$host}";
    }
    foreach ($photo_stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $photos[] = [
            "id" => (int)$p["id"],
            "filename" => (string)$p["filename"],
            "created_at" => (string)$p["created_at"],
            "url" => $base . "/uploads/" . ltrim((string)$p["stored_path"], "/"),
        ];
    }

    return [
        "id" => (int)$asset["id"],
        "asset_type" => (string)$asset["asset_type"],
        "asset_id" => (string)$asset["asset_id"],
        "work_order" => (string)($asset["work_order"] ?? ""),
        "purchase_order" => (string)($asset["purchase_order"] ?? ""),
        "lat" => $asset["lat"] !== null ? (string)$asset["lat"] : "",
        "lon" => $asset["lon"] !== null ? (string)$asset["lon"] : "",
        "updated_at" => (string)($asset["updated_at"] ?? ""),
        "contacts" => $contacts,
        "notes" => $notes,
        "photos" => $photos,
    ];
}

$current_user = auth_current_user();
if (!$current_user) {
    http_response_code(401);
    echo json_encode(["ok" => false, "error" => "unauthorized"]);
    exit;
}

try {
    $pdo = db_connect($cfg);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "db_connect_failed"]);
    exit;
}

$action = $_GET["action"] ?? "";
$method = $_SERVER["REQUEST_METHOD"] ?? "GET";

if ($action === "get_asset" && $method === "GET") {
    $asset_type = clean_asset_type((string)($_GET["asset_type"] ?? ""));
    $asset_id = trim((string)($_GET["asset_id"] ?? ""));
    if ($asset_type === "" || $asset_id === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_asset_type_or_id"]);
        exit;
    }
    $asset = asset_with_relations($pdo, $cfg, $asset_type, $asset_id);
    if (!$asset) {
        echo json_encode(["ok" => false, "error" => "not_found"]);
        exit;
    }
    echo json_encode(["ok" => true, "asset" => $asset]);
    exit;
}

if ($action === "list_assets" && $method === "GET") {
    $asset_type = clean_asset_type((string)($_GET["asset_type"] ?? ""));
    $q = trim((string)($_GET["q"] ?? ""));
    if ($asset_type === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_asset_type"]);
        exit;
    }
    $sql = "SELECT asset_type, asset_id, work_order, purchase_order, lat, lon, updated_at
            FROM assets
            WHERE asset_type = :asset_type";
    $params = [":asset_type" => $asset_type];
    if ($q !== "") {
        $sql .= " AND (asset_id LIKE :q OR work_order LIKE :q OR purchase_order LIKE :q)";
        $params[":q"] = "%" . $q . "%";
    }
    $sql .= " ORDER BY updated_at DESC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(["ok" => true, "assets" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === "create_asset" && $method === "POST") {
    $b = body_json();
    $asset_type = clean_asset_type((string)($b["asset_type"] ?? ""));
    $asset_id = trim((string)($b["asset_id"] ?? ""));
    if ($asset_type === "" || $asset_id === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_asset_type_or_id"]);
        exit;
    }
    $existing = get_asset_row($pdo, $asset_type, $asset_id);
    if ($existing) {
        echo json_encode(["ok" => false, "error" => "already_exists"]);
        exit;
    }

    $work_order = "";
    $purchase_order = "";
    $lat = null;
    $lon = null;
    if (auth_is_admin($current_user)) {
        $work_order = trim((string)($b["work_order"] ?? ""));
        $purchase_order = trim((string)($b["purchase_order"] ?? ""));
        $lat_raw = trim((string)($b["lat"] ?? ""));
        $lon_raw = trim((string)($b["lon"] ?? ""));
        $lat = $lat_raw === "" ? null : $lat_raw;
        $lon = $lon_raw === "" ? null : $lon_raw;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO assets (asset_type, asset_id, work_order, purchase_order, lat, lon, notes)
         VALUES (:asset_type, :asset_id, :work_order, :purchase_order, :lat, :lon, '')"
    );
    $stmt->execute([
        ":asset_type" => $asset_type,
        ":asset_id" => $asset_id,
        ":work_order" => $work_order,
        ":purchase_order" => $purchase_order,
        ":lat" => $lat,
        ":lon" => $lon,
    ]);

    echo json_encode(["ok" => true]);
    exit;
}

if ($action === "update_asset_admin" && $method === "POST") {
    if (!auth_is_admin($current_user)) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "admin_only"]);
        exit;
    }
    $b = body_json();
    $asset_type = clean_asset_type((string)($b["asset_type"] ?? ""));
    $asset_id = trim((string)($b["asset_id"] ?? ""));
    if ($asset_type === "" || $asset_id === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_asset_type_or_id"]);
        exit;
    }
    $existing = get_asset_row($pdo, $asset_type, $asset_id);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(["ok" => false, "error" => "not_found"]);
        exit;
    }
    $work_order = trim((string)($b["work_order"] ?? ""));
    $purchase_order = trim((string)($b["purchase_order"] ?? ""));
    $lat_raw = trim((string)($b["lat"] ?? ""));
    $lon_raw = trim((string)($b["lon"] ?? ""));
    $lat = $lat_raw === "" ? null : $lat_raw;
    $lon = $lon_raw === "" ? null : $lon_raw;

    $stmt = $pdo->prepare(
        "UPDATE assets
         SET work_order = :work_order, purchase_order = :purchase_order, lat = :lat, lon = :lon
         WHERE asset_type = :asset_type AND asset_id = :asset_id"
    );
    $stmt->execute([
        ":work_order" => $work_order,
        ":purchase_order" => $purchase_order,
        ":lat" => $lat,
        ":lon" => $lon,
        ":asset_type" => $asset_type,
        ":asset_id" => $asset_id,
    ]);

    $asset = get_asset_row($pdo, $asset_type, $asset_id);
    $asset_ref = (int)$asset["id"];

    $pdo->prepare("DELETE FROM asset_contacts WHERE asset_ref = :ref")->execute([":ref" => $asset_ref]);
    $contacts = isset($b["contacts"]) && is_array($b["contacts"]) ? $b["contacts"] : [];
    $insert = $pdo->prepare(
        "INSERT INTO asset_contacts (asset_ref, name, phone, email) VALUES (:asset_ref, :name, :phone, :email)"
    );
    foreach ($contacts as $c) {
        if (!is_array($c)) continue;
        $name = trim((string)($c["name"] ?? ""));
        $phone = trim((string)($c["phone"] ?? ""));
        $email = trim((string)($c["email"] ?? ""));
        if ($name === "" && $phone === "" && $email === "") continue;
        $insert->execute([
            ":asset_ref" => $asset_ref,
            ":name" => $name === "" ? null : $name,
            ":phone" => $phone === "" ? null : $phone,
            ":email" => $email === "" ? null : $email,
        ]);
    }
    echo json_encode(["ok" => true]);
    exit;
}

if ($action === "add_note" && $method === "POST") {
    $b = body_json();
    $asset_type = clean_asset_type((string)($b["asset_type"] ?? ""));
    $asset_id = trim((string)($b["asset_id"] ?? ""));
    $note_text = trim((string)($b["note_text"] ?? ""));
    if ($asset_type === "" || $asset_id === "" || $note_text === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_fields"]);
        exit;
    }
    $asset = get_asset_row($pdo, $asset_type, $asset_id);
    if (!$asset) {
        http_response_code(404);
        echo json_encode(["ok" => false, "error" => "asset_not_found"]);
        exit;
    }
    $stmt = $pdo->prepare("INSERT INTO asset_notes (asset_ref, user_ref, note_text) VALUES (:asset_ref, :user_ref, :note_text)");
    $stmt->execute([
        ":asset_ref" => (int)$asset["id"],
        ":user_ref" => (int)$current_user["id"],
        ":note_text" => $note_text,
    ]);
    echo json_encode(["ok" => true]);
    exit;
}

if ($action === "upload_photo" && $method === "POST") {
    $asset_type = clean_asset_type((string)($_POST["asset_type"] ?? ""));
    $asset_id = trim((string)($_POST["asset_id"] ?? ""));
    if ($asset_type === "" || $asset_id === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_asset_type_or_id"]);
        exit;
    }
    if (!isset($_FILES["photo"]) || !isset($_FILES["photo"]["tmp_name"]) || !is_uploaded_file($_FILES["photo"]["tmp_name"])) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "invalid_upload"]);
        exit;
    }
    $asset = get_asset_row($pdo, $asset_type, $asset_id);
    if (!$asset) {
        http_response_code(404);
        echo json_encode(["ok" => false, "error" => "asset_not_found"]);
        exit;
    }
    $orig = basename((string)($_FILES["photo"]["name"] ?? "photo.jpg"));
    $ext = pathinfo($orig, PATHINFO_EXTENSION);
    $ext = $ext !== "" ? "." . strtolower($ext) : ".jpg";
    $safe = preg_replace("/[^a-z0-9_.-]/i", "_", pathinfo($orig, PATHINFO_FILENAME));
    if (!$safe) $safe = "photo";
    $fname = $asset_type . "_" . preg_replace("/[^a-z0-9_.-]/i", "_", $asset_id) . "_" . date("Ymd_His") . "_" . $safe . $ext;

    $dir = upload_root();
    $asset_dir = $dir . "/" . $asset_type;
    if (!is_dir($asset_dir)) @mkdir($asset_dir, 0775, true);
    $dest = $asset_dir . "/" . $fname;
    if (!move_uploaded_file($_FILES["photo"]["tmp_name"], $dest)) {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "photo_save_failed"]);
        exit;
    }

    $lat = trim((string)($_POST["lat"] ?? ""));
    $lon = trim((string)($_POST["lon"] ?? ""));
    $stmt = $pdo->prepare(
        "INSERT INTO asset_photos (asset_ref, filename, stored_path, lat, lon)
         VALUES (:asset_ref, :filename, :stored_path, :lat, :lon)"
    );
    $stmt->execute([
        ":asset_ref" => (int)$asset["id"],
        ":filename" => $orig,
        ":stored_path" => $asset_type . "/" . $fname,
        ":lat" => $lat === "" ? null : $lat,
        ":lon" => $lon === "" ? null : $lon,
    ]);
    echo json_encode(["ok" => true]);
    exit;
}

http_response_code(404);
echo json_encode(["ok" => false, "error" => "unknown_action"]);
