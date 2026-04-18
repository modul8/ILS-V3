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

function clean_job_status(string $v): string {
    $v = strtolower(trim($v));
    $allowed = ["pending", "scheduled", "in_progress", "completed", "cancelled"];
    return in_array($v, $allowed, true) ? $v : "pending";
}

function clean_job_module(string $v): string {
    $v = strtolower(trim($v));
    return $v === "" ? "work" : preg_replace("/[^a-z0-9_-]/", "_", $v);
}

function parse_csv_upload(string $tmp_path): array {
    $rows = [];
    $fh = fopen($tmp_path, "rb");
    if (!$fh) return $rows;
    $header = fgetcsv($fh);
    if (!is_array($header)) {
        fclose($fh);
        return $rows;
    }
    $header = array_map(function ($h) {
        return strtolower(trim((string)$h));
    }, $header);
    while (($line = fgetcsv($fh)) !== false) {
        if (!is_array($line)) continue;
        $row = [];
        foreach ($header as $i => $key) {
            $row[$key] = isset($line[$i]) ? trim((string)$line[$i]) : "";
        }
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function normalized_job_key(array $row): string {
    $parts = [
        strtolower(trim((string)($row["module"] ?? "work"))),
        strtolower(trim((string)($row["asset_type"] ?? ""))),
        trim((string)($row["asset_id"] ?? "")),
        trim((string)($row["work_order"] ?? "")),
        trim((string)($row["purchase_order"] ?? "")),
        trim((string)($row["description"] ?? "")),
    ];
    $raw = implode("|", $parts);
    if (trim($raw, "| ") === "") {
        return "job_" . date("YmdHis") . "_" . str_pad((string)mt_rand(0, 99999999), 8, "0", STR_PAD_LEFT);
    }
    return "job_" . substr(sha1($raw), 0, 20);
}

function mapping_enabled(array $cfg): bool {
    return (bool)($cfg["mapping_enabled"] ?? false);
}

function mapping_root(array $cfg): string {
    $p = trim((string)($cfg["mapping_pipeline_root"] ?? ""));
    return $p;
}

function path_join(string $a, string $b): string {
    return rtrim($a, "/\\") . DIRECTORY_SEPARATOR . ltrim($b, "/\\");
}

function path_within(string $path, string $base): bool {
    $real_path = realpath($path);
    $real_base = realpath($base);
    if ($real_path === false || $real_base === false) return false;
    if (DIRECTORY_SEPARATOR === "\\") {
        $real_path = strtolower($real_path);
        $real_base = strtolower($real_base);
    }
    return strpos($real_path, $real_base) === 0;
}

function run_mapping_cmd(array $cfg, array $parts): array {
    $python = trim((string)($cfg["mapping_python_bin"] ?? "python3"));
    $cmd_parts = array_merge([$python], $parts);
    $escaped = array_map("escapeshellarg", $cmd_parts);
    $cmd = implode(" ", $escaped) . " 2>&1";
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    return [
        "ok" => $code === 0,
        "exit_code" => $code,
        "command" => $cmd,
        "output" => implode("\n", $output),
    ];
}

function mapping_dirs(array $cfg, bool $ensure = false): array {
    $root = mapping_root($cfg);
    $input_dir = path_join($root, "input_pdfs");
    $outputs_dir = path_join($root, "outputs");
    $images_dir = path_join($outputs_dir, "images");
    $scripts_dir = path_join($root, "scripts");

    if ($ensure) {
        if (!is_dir($input_dir)) @mkdir($input_dir, 0775, true);
        if (!is_dir($images_dir)) @mkdir($images_dir, 0775, true);
    }
    return [
        "root" => $root,
        "input_dir" => $input_dir,
        "outputs_dir" => $outputs_dir,
        "images_dir" => $images_dir,
        "scripts_dir" => $scripts_dir,
    ];
}

function mapping_candidate_files(string $map_stem, array $dirs): array {
    $input_dir = $dirs["input_dir"];
    $images_dir = $dirs["images_dir"];
    $png = path_join($images_dir, $map_stem . "_page_001.png");
    return [
        "source_pdf" => path_join($input_dir, $map_stem . ".pdf"),
        "control_csv" => path_join($input_dir, $map_stem . "_control_points.csv"),
        "structure_csv" => path_join($input_dir, $map_stem . "_structure_truth.csv"),
        "structure_georef_csv" => path_join($input_dir, $map_stem . "_structure_truth_georef.csv"),
        "structure_geojson" => path_join($input_dir, $map_stem . "_structure_truth_georef.geojson"),
        "image_png" => $png,
        "image_world" => preg_replace('/\.png$/i', ".pgw", $png),
        "image_prj" => preg_replace('/\.png$/i', ".prj", $png),
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

if ($action === "list_jobs" && $method === "GET") {
    $module = clean_job_module((string)($_GET["module"] ?? ""));
    $status = trim((string)($_GET["status"] ?? ""));
    $asset_type = clean_asset_type((string)($_GET["asset_type"] ?? ""));
    $q = trim((string)($_GET["q"] ?? ""));
    $limit = (int)($_GET["limit"] ?? 300);
    if ($limit <= 0 || $limit > 2000) $limit = 300;

    $where = [];
    $params = [];
    if ($module !== "" && $module !== "all") {
        $where[] = "j.module = :module";
        $params[":module"] = $module;
    }
    if ($status !== "") {
        $where[] = "j.status = :status";
        $params[":status"] = clean_job_status($status);
    }
    if ($asset_type !== "") {
        $where[] = "j.asset_type = :asset_type";
        $params[":asset_type"] = $asset_type;
    }
    if ($q !== "") {
        $where[] = "(j.work_order LIKE :q OR j.purchase_order LIKE :q OR j.asset_id LIKE :q OR j.description LIKE :q)";
        $params[":q"] = "%" . $q . "%";
    }

    $sql = "SELECT
              j.id, j.job_key, j.module, j.asset_type, j.asset_id, j.asset_ref,
              j.work_order, j.purchase_order, j.status, j.scheduled_date, j.description,
              j.lat, j.lon, j.updated_at,
              a.asset_id AS matched_asset_id, a.updated_at AS asset_updated_at
            FROM jobs j
            LEFT JOIN assets a ON a.id = j.asset_ref";
    if ($where) $sql .= " WHERE " . implode(" AND ", $where);
    $sql .= " ORDER BY j.updated_at DESC LIMIT " . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(["ok" => true, "jobs" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === "import_jobs_csv" && $method === "POST") {
    if (!auth_is_admin($current_user)) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "admin_only"]);
        exit;
    }
    if (!isset($_FILES["file"]) || !isset($_FILES["file"]["tmp_name"]) || !is_uploaded_file($_FILES["file"]["tmp_name"])) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "invalid_upload"]);
        exit;
    }
    $rows = parse_csv_upload((string)$_FILES["file"]["tmp_name"]);
    if (!$rows) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "empty_csv"]);
        exit;
    }

    $sql = "INSERT INTO jobs
            (job_key, module, asset_type, asset_id, asset_ref, work_order, purchase_order, status, scheduled_date, description, lat, lon, meta)
            VALUES
            (:job_key, :module, :asset_type, :asset_id, :asset_ref, :work_order, :purchase_order, :status, :scheduled_date, :description, :lat, :lon, :meta)
            ON DUPLICATE KEY UPDATE
              module = VALUES(module),
              asset_type = VALUES(asset_type),
              asset_id = VALUES(asset_id),
              asset_ref = VALUES(asset_ref),
              work_order = VALUES(work_order),
              purchase_order = VALUES(purchase_order),
              status = VALUES(status),
              scheduled_date = VALUES(scheduled_date),
              description = VALUES(description),
              lat = VALUES(lat),
              lon = VALUES(lon),
              meta = VALUES(meta)";
    $stmt = $pdo->prepare($sql);

    $inserted = 0;
    $matched = 0;
    $unmatched = 0;
    foreach ($rows as $r) {
        $asset_type = clean_asset_type((string)($r["asset_type"] ?? ""));
        $asset_id = trim((string)($r["asset_id"] ?? ""));
        $module = clean_job_module((string)($r["module"] ?? "work"));
        $work_order = trim((string)($r["work_order"] ?? ""));
        $purchase_order = trim((string)($r["purchase_order"] ?? ""));
        $status = clean_job_status((string)($r["status"] ?? "pending"));
        $scheduled_date = trim((string)($r["scheduled_date"] ?? ""));
        $description = trim((string)($r["description"] ?? ""));
        $job_key = trim((string)($r["job_key"] ?? ""));
        if ($job_key === "") {
            $job_key = normalized_job_key([
                "module" => $module,
                "asset_type" => $asset_type,
                "asset_id" => $asset_id,
                "work_order" => $work_order,
                "purchase_order" => $purchase_order,
                "description" => $description,
            ]);
        }

        $asset_ref = null;
        $lat = null;
        $lon = null;
        $meta = ["import_source" => "csv"];
        if ($asset_type !== "" && $asset_id !== "") {
            $asset = get_asset_row($pdo, $asset_type, $asset_id);
            if ($asset) {
                $asset_ref = (int)$asset["id"];
                $lat = $asset["lat"] !== null ? $asset["lat"] : null;
                $lon = $asset["lon"] !== null ? $asset["lon"] : null;
                $matched++;
            } else {
                $unmatched++;
                $meta["unmatched_asset"] = true;
            }
        } else {
            $unmatched++;
            $meta["unmatched_asset"] = true;
        }
        if ($scheduled_date === "") $scheduled_date = null;

        $stmt->execute([
            ":job_key" => $job_key,
            ":module" => $module,
            ":asset_type" => $asset_type === "" ? null : $asset_type,
            ":asset_id" => $asset_id === "" ? null : $asset_id,
            ":asset_ref" => $asset_ref,
            ":work_order" => $work_order === "" ? null : $work_order,
            ":purchase_order" => $purchase_order === "" ? null : $purchase_order,
            ":status" => $status,
            ":scheduled_date" => $scheduled_date,
            ":description" => $description === "" ? null : $description,
            ":lat" => $lat,
            ":lon" => $lon,
            ":meta" => json_encode($meta, JSON_UNESCAPED_SLASHES),
        ]);
        $inserted++;
    }

    echo json_encode([
        "ok" => true,
        "rows" => $inserted,
        "matched_assets" => $matched,
        "unmatched_assets" => $unmatched,
    ]);
    exit;
}

if ($action === "mapping_list_pdfs" && $method === "GET") {
    if (!auth_is_admin($current_user)) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "admin_only"]);
        exit;
    }
    if (!mapping_enabled($cfg)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "mapping_disabled"]);
        exit;
    }
    $dirs = mapping_dirs($cfg, true);
    $root = $dirs["root"];
    $input_dir = $dirs["input_dir"];
    if (!is_dir($input_dir)) {
        http_response_code(404);
        echo json_encode(["ok" => false, "error" => "input_pdfs_missing"]);
        exit;
    }
    $pdfs = [];
    foreach (glob($input_dir . DIRECTORY_SEPARATOR . "*.pdf") ?: [] as $p) {
        $pdfs[] = basename($p);
    }
    sort($pdfs);
    echo json_encode(["ok" => true, "pdfs" => $pdfs, "pipeline_root" => $root]);
    exit;
}

if ($action === "mapping_upload_pdf" && $method === "POST") {
    if (!auth_is_admin($current_user)) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "admin_only"]);
        exit;
    }
    if (!mapping_enabled($cfg)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "mapping_disabled"]);
        exit;
    }
    if (!isset($_FILES["pdf"]) || !isset($_FILES["pdf"]["tmp_name"]) || !is_uploaded_file($_FILES["pdf"]["tmp_name"])) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "invalid_upload"]);
        exit;
    }
    $dirs = mapping_dirs($cfg, true);
    $input_dir = $dirs["input_dir"];
    if (!is_dir($input_dir)) {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "input_dir_unavailable"]);
        exit;
    }

    $orig = basename((string)($_FILES["pdf"]["name"] ?? "map.pdf"));
    $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext !== "pdf") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "file_must_be_pdf"]);
        exit;
    }
    $base = preg_replace("/[^A-Za-z0-9 _().-]+/", "_", (string)pathinfo($orig, PATHINFO_FILENAME));
    $base = trim((string)$base);
    if ($base === "") $base = "Map";
    $filename = $base . ".pdf";
    $dest = path_join($input_dir, $filename);
    if (file_exists($dest)) {
        $filename = $base . "_" . date("Ymd_His") . ".pdf";
        $dest = path_join($input_dir, $filename);
    }
    if (!move_uploaded_file($_FILES["pdf"]["tmp_name"], $dest)) {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "save_failed"]);
        exit;
    }
    @chmod($dest, 0664);
    echo json_encode([
        "ok" => true,
        "pdf_name" => $filename,
        "map_stem" => preg_replace('/\.pdf$/i', "", $filename),
    ]);
    exit;
}

if ($action === "mapping_list_outputs" && $method === "GET") {
    if (!auth_is_admin($current_user)) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "admin_only"]);
        exit;
    }
    if (!mapping_enabled($cfg)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "mapping_disabled"]);
        exit;
    }
    $map_stem = trim((string)($_GET["map_stem"] ?? ""));
    if ($map_stem === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_map_stem"]);
        exit;
    }
    $dirs = mapping_dirs($cfg, true);
    $candidates = mapping_candidate_files($map_stem, $dirs);
    $files = [];
    foreach ($candidates as $key => $path) {
        if (!is_string($path) || $path === "") continue;
        if (!file_exists($path)) continue;
        $files[] = [
            "key" => $key,
            "name" => basename($path),
            "size" => filesize($path),
            "download_url" => "api/index.php?action=mapping_download_output&map_stem=" . rawurlencode($map_stem) . "&file_key=" . rawurlencode($key),
        ];
    }
    echo json_encode(["ok" => true, "files" => $files]);
    exit;
}

if ($action === "mapping_download_output" && $method === "GET") {
    if (!auth_is_admin($current_user)) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "admin_only"]);
        exit;
    }
    if (!mapping_enabled($cfg)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "mapping_disabled"]);
        exit;
    }
    $map_stem = trim((string)($_GET["map_stem"] ?? ""));
    $file_key = trim((string)($_GET["file_key"] ?? ""));
    if ($map_stem === "" || $file_key === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_map_stem_or_file_key"]);
        exit;
    }
    $dirs = mapping_dirs($cfg, true);
    $candidates = mapping_candidate_files($map_stem, $dirs);
    $path = $candidates[$file_key] ?? "";
    if (!is_string($path) || $path === "" || !file_exists($path)) {
        http_response_code(404);
        echo json_encode(["ok" => false, "error" => "file_not_found"]);
        exit;
    }
    $ctype = "application/octet-stream";
    $lower = strtolower($path);
    if (str_ends_with($lower, ".pdf")) $ctype = "application/pdf";
    if (str_ends_with($lower, ".png")) $ctype = "image/png";
    if (str_ends_with($lower, ".csv")) $ctype = "text/csv";
    if (str_ends_with($lower, ".geojson")) $ctype = "application/geo+json";
    if (str_ends_with($lower, ".prj") || str_ends_with($lower, ".pgw")) $ctype = "text/plain";

    header("Content-Type: " . $ctype);
    header("Content-Length: " . (string)filesize($path));
    header("Content-Disposition: attachment; filename=\"" . basename($path) . "\"");
    readfile($path);
    exit;
}

if ($action === "mapping_run" && $method === "POST") {
    if (!auth_is_admin($current_user)) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "admin_only"]);
        exit;
    }
    if (!mapping_enabled($cfg)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "mapping_disabled"]);
        exit;
    }
    $b = body_json();
    $operation = trim((string)($b["operation"] ?? ""));
    $pdf_name = basename((string)($b["pdf_name"] ?? ""));
    $map_stem = trim((string)($b["map_stem"] ?? ""));

    $dirs = mapping_dirs($cfg, true);
    $root = $dirs["root"];
    $scripts_dir = $dirs["scripts_dir"];
    $input_dir = $dirs["input_dir"];
    $images_dir = $dirs["images_dir"];
    if (!is_dir($scripts_dir) || !is_dir($input_dir)) {
        http_response_code(404);
        echo json_encode(["ok" => false, "error" => "pipeline_paths_missing"]);
        exit;
    }

    $cmd_parts = [];
    if ($operation === "init_inputs") {
        if ($pdf_name === "") {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "missing_pdf_name"]);
            exit;
        }
        $pdf_path = path_join($input_dir, $pdf_name);
        if (!file_exists($pdf_path) || !path_within($pdf_path, $input_dir)) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "invalid_pdf"]);
            exit;
        }
        $cmd_parts = [path_join($scripts_dir, "init_map_inputs.py"), "--pdf", $pdf_path];
    } elseif ($operation === "convert_pdf") {
        if ($pdf_name === "") {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "missing_pdf_name"]);
            exit;
        }
        $pdf_path = path_join($input_dir, $pdf_name);
        if (!file_exists($pdf_path) || !path_within($pdf_path, $input_dir)) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "invalid_pdf"]);
            exit;
        }
        $cmd_parts = [path_join($scripts_dir, "pdf_to_images.py"), $pdf_path, "--output-dir", $images_dir, "--dpi", "300"];
    } elseif ($operation === "georef_map") {
        if ($map_stem === "") {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "missing_map_stem"]);
            exit;
        }
        $image = path_join($images_dir, $map_stem . "_page_001.png");
        $control_csv = path_join($input_dir, $map_stem . "_control_points.csv");
        if (!file_exists($image) || !file_exists($control_csv)) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "missing_image_or_control_csv"]);
            exit;
        }
        $cmd_parts = [path_join($scripts_dir, "georef_map.py"), $image, $control_csv, "--target-crs", "EPSG:4326"];
    } elseif ($operation === "build_structure_geojson") {
        if ($map_stem === "") {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "missing_map_stem"]);
            exit;
        }
        $image = path_join($images_dir, $map_stem . "_page_001.png");
        $control_csv = path_join($input_dir, $map_stem . "_control_points.csv");
        $structures_csv = path_join($input_dir, $map_stem . "_structure_truth.csv");
        if (!file_exists($image) || !file_exists($control_csv) || !file_exists($structures_csv)) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "missing_required_files"]);
            exit;
        }
        $cmd_parts = [
            path_join($scripts_dir, "georef_map.py"),
            $image,
            $control_csv,
            "--target-crs",
            "EPSG:4326",
            "--structures-csv",
            $structures_csv,
        ];
    } else {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "invalid_operation"]);
        exit;
    }

    $run = run_mapping_cmd($cfg, $cmd_parts);
    echo json_encode($run);
    exit;
}

http_response_code(404);
echo json_encode(["ok" => false, "error" => "unknown_action"]);
