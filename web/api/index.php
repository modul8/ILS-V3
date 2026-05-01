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
    return in_array($v, ["drain", "culvert", "bridge", "floodgate"], true) ? $v : "";
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

    $track_url = "";
    $has_track = false;
    if ($asset_type === "drain") {
        $track_stmt = $pdo->prepare("SELECT 1 FROM asset_tracks WHERE asset_ref = :ref LIMIT 1");
        $track_stmt->execute([":ref" => $asset["id"]]);
        $has_track = (bool)$track_stmt->fetchColumn();
        if ($has_track) {
            $track_url = "api/index.php?action=download_asset_track&asset_type=drain&asset_id=" . rawurlencode((string)$asset["asset_id"]);
        }
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
        "has_track" => $has_track,
        "track_url" => $track_url,
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

function invoice_cfg(array $cfg): array {
    return [
        "base_url" => trim((string)($cfg["dolibarr_base_url"] ?? "")),
        "api_key" => trim((string)($cfg["dolibarr_api_key"] ?? "")),
        "socid" => trim((string)($cfg["dolibarr_socid"] ?? "")),
        "tva_tx" => (float)($cfg["dolibarr_tva_tx"] ?? 0.0),
    ];
}

function invoice_api_root(string $base_url): string {
    $u = rtrim(trim($base_url), "/");
    if ($u === "") return "";
    if (preg_match("~\/api\/index\.php$~i", $u)) return $u;
    return $u . "/api/index.php";
}

function invoice_dolibarr_request(string $method, string $url, string $api_key, array $data = [], int $timeout = 30): array {
    if (!function_exists("curl_init")) {
        return ["ok" => false, "status" => 0, "body" => "", "error" => "curl_missing"];
    }
    $payload = http_build_query($data);
    $headers = [
        "DOLAPIKEY: " . $api_key,
        "Accept: application/json",
        "User-Agent: ILS-V3/1.0",
    ];
    $send = function (string $request_url) use ($method, $payload, $headers, $timeout): array {
        $ch = curl_init($request_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        if (strtoupper($method) !== "GET") {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $hdrs = $headers;
            $hdrs[] = "Content-Type: application/x-www-form-urlencoded";
            curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
        }
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : "";
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ["status" => $status, "body" => (string)$body, "error" => $error];
    };

    $r = $send($url);
    if ($r["error"] !== "") return ["ok" => false, "status" => 0, "body" => "", "error" => $r["error"]];
    if ($r["status"] === 401 || $r["status"] === 403) {
        $sep = strpos($url, "?") === false ? "?" : "&";
        $r2 = $send($url . $sep . "DOLAPIKEY=" . rawurlencode($api_key));
        if ($r2["error"] !== "") return ["ok" => false, "status" => 0, "body" => "", "error" => $r2["error"]];
        return ["ok" => ($r2["status"] >= 200 && $r2["status"] < 300), "status" => $r2["status"], "body" => $r2["body"], "error" => ""];
    }
    return ["ok" => ($r["status"] >= 200 && $r["status"] < 300), "status" => $r["status"], "body" => $r["body"], "error" => ""];
}

function invoice_parse_id(string $body): ?int {
    $txt = trim($body);
    if ($txt !== "" && ctype_digit($txt)) return (int)$txt;
    $j = json_decode($body, true);
    if (is_int($j) || (is_string($j) && ctype_digit($j))) return (int)$j;
    if (is_array($j) && isset($j["id"]) && ctype_digit((string)$j["id"])) return (int)$j["id"];
    return null;
}

function invoice_qty_from_job(array $job): float {
    $meta = [];
    if (!empty($job["meta"])) {
        $m = json_decode((string)$job["meta"], true);
        if (is_array($m)) $meta = $m;
    }
    if (isset($meta["qty_km"]) && is_numeric($meta["qty_km"])) {
        $v = (float)$meta["qty_km"];
        if ($v > 0) return round($v, 3);
    }
    $desc = (string)($job["description"] ?? "");
    if (preg_match('/\((\d+)\s*-\s*(\d+)\)/', $desc, $m)) {
        $a = (int)$m[1];
        $b = (int)$m[2];
        if ($b > $a) return round(($b - $a) / 1000.0, 3);
    }
    return 1.0;
}

function invoice_desc_from_job(array $job): string {
    $desc_raw = trim((string)($job["description"] ?? ""));
    $work_order = trim((string)($job["work_order"] ?? ""));
    $asset_type = trim((string)($job["asset_type"] ?? ""));
    $asset_id = trim((string)($job["asset_id"] ?? ""));

    $first_line = $desc_raw;
    if ($first_line !== "" && strpos($first_line, " / ") !== false) {
        $parts = preg_split('/\s*\/\s*/', $first_line);
        if (is_array($parts) && !empty($parts[0])) {
            $first_line = trim((string)$parts[0]);
        }
    }
    if ($first_line === "") {
        $first_line = trim($asset_type . " " . $asset_id);
    }

    if ($work_order !== "") {
        return $first_line . "\nW/O: " . $work_order;
    }
    return $first_line;
}

function mapping_enabled(array $cfg): bool {
    return (bool)($cfg["mapping_enabled"] ?? false);
}

function mapping_api_key_valid(array $cfg): bool {
    $expected = trim((string)($cfg["mapping_api_key"] ?? ""));
    if ($expected === "") return false;

    $provided = trim((string)($_SERVER["HTTP_X_ILS_MAPPING_KEY"] ?? ""));
    if ($provided === "") $provided = trim((string)($_SERVER["HTTP_X_API_KEY"] ?? ""));
    if ($provided === "") $provided = trim((string)($_GET["mapping_key"] ?? ""));
    if ($provided === "") return false;
    return hash_equals($expected, $provided);
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

function run_mapping_cmd_json(array $cfg, array $parts): array {
    $run = run_mapping_cmd($cfg, $parts);
    $txt = trim((string)($run["output"] ?? ""));
    $data = json_decode($txt, true);
    if (is_array($data)) {
        $data["exit_code"] = (int)($run["exit_code"] ?? 1);
        $data["command"] = (string)($run["command"] ?? "");
        $data["output"] = (string)($run["output"] ?? "");
        return $data;
    }
    return [
        "ok" => false,
        "error" => "invalid_json_from_command",
        "exit_code" => (int)($run["exit_code"] ?? 1),
        "command" => (string)($run["command"] ?? ""),
        "output" => (string)($run["output"] ?? ""),
    ];
}

function run_python_json(array $cfg, array $parts): array {
    $python = trim((string)($cfg["mapping_python_bin"] ?? "python3"));
    $cmd_parts = array_merge([$python], $parts);
    $escaped = array_map("escapeshellarg", $cmd_parts);
    $cmd = implode(" ", $escaped) . " 2>&1";
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    $txt = trim(implode("\n", $output));
    $data = json_decode($txt, true);
    if (!is_array($data) && $txt !== "") {
        // Accept a JSON object on the last line when Python emits warnings/noise before it.
        $lines = preg_split("/\r\n|\n|\r/", $txt);
        if (is_array($lines)) {
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = trim((string)$lines[$i]);
                if ($line === "") continue;
                if ($line[0] !== "{") continue;
                $try = json_decode($line, true);
                if (is_array($try)) {
                    $data = $try;
                    break;
                }
            }
        }
    }
    if (is_array($data)) {
        $data["exit_code"] = $code;
        $data["command"] = $cmd;
        $data["raw_output"] = $txt;
        return $data;
    }
    return [
        "ok" => false,
        "error" => "invalid_json_from_python",
        "exit_code" => $code,
        "command" => $cmd,
        "output" => $txt,
    ];
}

function mapping_dirs(array $cfg, bool $ensure = false): array {
    $root = mapping_root($cfg);
    $input_dir = path_join($root, "input_pdfs");
    $outputs_dir = path_join($root, "outputs");
    $images_dir = path_join($outputs_dir, "images");
    $collections_dir = path_join($outputs_dir, "asset_collections");
    $scripts_dir = path_join($root, "scripts");

    if ($ensure) {
        if (!is_dir($input_dir)) @mkdir($input_dir, 0775, true);
        if (!is_dir($images_dir)) @mkdir($images_dir, 0775, true);
        if (!is_dir($collections_dir)) @mkdir($collections_dir, 0775, true);
    }
    return [
        "root" => $root,
        "input_dir" => $input_dir,
        "outputs_dir" => $outputs_dir,
        "images_dir" => $images_dir,
        "collections_dir" => $collections_dir,
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
        "control_csv_qgis" => path_join($input_dir, $map_stem . "_control_points_qgis.csv"),
        "structure_csv" => path_join($input_dir, $map_stem . "_structure_truth.csv"),
        "structure_csv_qgis" => path_join($input_dir, $map_stem . "_structure_truth_qgis.csv"),
        "structure_georef_csv" => path_join($input_dir, $map_stem . "_structure_truth_georef.csv"),
        "structure_geojson" => path_join($input_dir, $map_stem . "_structure_truth_georef.geojson"),
        "drain_tracks_geojson" => path_join($input_dir, $map_stem . "_drain_tracks.geojson"),
        "image_png" => $png,
        "image_world" => preg_replace('/\.png$/i', ".pgw", $png),
        "image_prj" => preg_replace('/\.png$/i', ".prj", $png),
    ];
}

function mapping_collection_files(array $dirs): array {
    $collections_dir = (string)($dirs["collections_dir"] ?? "");
    return [
        "all_assets" => path_join($collections_dir, "all_assets.geojson"),
        "drains" => path_join($collections_dir, "drains.geojson"),
        "culverts" => path_join($collections_dir, "culverts.geojson"),
        "bridges" => path_join($collections_dir, "bridges.geojson"),
        "floodgates" => path_join($collections_dir, "floodgates.geojson"),
    ];
}

function mapping_control_fields(): array {
    return ["pixel_x", "pixel_y", "lon", "lat", "asset_type", "asset_id", "label"];
}

function ensure_control_csv_header(string $csv_path): void {
    if (file_exists($csv_path) && filesize($csv_path) > 0) return;
    $fh = fopen($csv_path, "wb");
    if (!$fh) {
        throw new RuntimeException("control_csv_open_failed");
    }
    fputcsv($fh, mapping_control_fields());
    fclose($fh);
}

function mapping_read_control_points(string $csv_path): array {
    $rows = [];
    if (!file_exists($csv_path) || filesize($csv_path) === 0) return $rows;
    $fh = fopen($csv_path, "rb");
    if (!$fh) return $rows;
    $header = fgetcsv($fh);
    if (!is_array($header)) {
        fclose($fh);
        return $rows;
    }
    $idx = array_flip(array_map(function ($h) { return strtolower(trim((string)$h)); }, $header));
    while (($line = fgetcsv($fh)) !== false) {
        if (!is_array($line)) continue;
        $row = [];
        foreach (mapping_control_fields() as $f) {
            $k = strtolower($f);
            $i = $idx[$k] ?? null;
            $row[$f] = ($i !== null && isset($line[$i])) ? trim((string)$line[$i]) : "";
        }
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function mapping_append_control_point(string $csv_path, array $row): void {
    ensure_control_csv_header($csv_path);
    $fh = fopen($csv_path, "ab");
    if (!$fh) {
        throw new RuntimeException("control_csv_open_failed");
    }
    $ordered = [];
    foreach (mapping_control_fields() as $f) {
        $ordered[] = (string)($row[$f] ?? "");
    }
    fputcsv($fh, $ordered);
    fclose($fh);
}

function mapping_write_control_points(string $csv_path, array $rows): void {
    $fh = fopen($csv_path, "wb");
    if (!$fh) {
        throw new RuntimeException("control_csv_open_failed");
    }
    fputcsv($fh, mapping_control_fields());
    foreach ($rows as $row) {
        $ordered = [];
        foreach (mapping_control_fields() as $f) {
            $ordered[] = (string)($row[$f] ?? "");
        }
        fputcsv($fh, $ordered);
    }
    fclose($fh);
}

function mapping_write_qgis_safe_csv(string $source_csv, string $target_csv, array $fields): void {
    if (!file_exists($source_csv) || filesize($source_csv) === 0) {
        return;
    }
    $in = fopen($source_csv, "rb");
    if (!$in) return;

    $header = fgetcsv($in);
    if (!is_array($header)) {
        fclose($in);
        return;
    }
    $idx = array_flip(array_map(function ($h) { return strtolower(trim((string)$h)); }, $header));

    $out = fopen($target_csv, "wb");
    if (!$out) {
        fclose($in);
        return;
    }
    // UTF-8 BOM improves compatibility with some QGIS CSV parsing setups on Windows.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $fields);
    while (($line = fgetcsv($in)) !== false) {
        if (!is_array($line)) continue;
        $row = [];
        foreach ($fields as $f) {
            $i = $idx[strtolower($f)] ?? null;
            $v = ($i !== null && isset($line[$i])) ? trim((string)$line[$i]) : "";
            $row[] = $v;
        }
        fputcsv($out, $row);
    }
    fclose($out);
    fclose($in);
}

function mapping_structure_fields(): array {
    return ["structure_id", "structure_type", "pixel_x", "pixel_y", "lon", "lat"];
}

function mapping_upsert_drain_track_geojson(string $geojson_path, string $asset_id, string $map_stem, array $coords_lon_lat): void {
    $feature = [
        "type" => "Feature",
        "geometry" => [
            "type" => "LineString",
            "coordinates" => $coords_lon_lat,
        ],
        "properties" => [
            "asset_type" => "drain",
            "asset_id" => $asset_id,
            "map_stem" => $map_stem,
        ],
    ];

    $collection = [
        "type" => "FeatureCollection",
        "name" => basename($geojson_path, ".geojson"),
        "crs" => ["type" => "name", "properties" => ["name" => "EPSG:4326"]],
        "features" => [],
    ];
    if (file_exists($geojson_path) && filesize($geojson_path) > 0) {
        $existing_raw = file_get_contents($geojson_path);
        $existing = json_decode((string)$existing_raw, true);
        if (is_array($existing) && isset($existing["features"]) && is_array($existing["features"])) {
            $collection = $existing;
        }
    }
    $features = [];
    foreach (($collection["features"] ?? []) as $f) {
        if (!is_array($f)) continue;
        $props = is_array($f["properties"] ?? null) ? $f["properties"] : [];
        $t = strtolower(trim((string)($props["asset_type"] ?? "")));
        $id = trim((string)($props["asset_id"] ?? ""));
        if ($t === "drain" && $id === $asset_id) {
            continue;
        }
        $features[] = $f;
    }
    $features[] = $feature;
    $collection["features"] = array_values($features);
    file_put_contents($geojson_path, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function ensure_structure_csv_header(string $csv_path): void {
    if (file_exists($csv_path) && filesize($csv_path) > 0) return;
    $fh = fopen($csv_path, "wb");
    if (!$fh) {
        throw new RuntimeException("structure_csv_open_failed");
    }
    fputcsv($fh, mapping_structure_fields());
    fclose($fh);
}

function mapping_read_structure_points(string $csv_path): array {
    $rows = [];
    if (!file_exists($csv_path) || filesize($csv_path) === 0) return $rows;
    $fh = fopen($csv_path, "rb");
    if (!$fh) return $rows;
    $header = fgetcsv($fh);
    if (!is_array($header)) {
        fclose($fh);
        return $rows;
    }
    $idx = array_flip(array_map(function ($h) { return strtolower(trim((string)$h)); }, $header));
    while (($line = fgetcsv($fh)) !== false) {
        if (!is_array($line)) continue;
        $row = [];
        foreach (mapping_structure_fields() as $f) {
            $k = strtolower($f);
            $i = $idx[$k] ?? null;
            $row[$f] = ($i !== null && isset($line[$i])) ? trim((string)$line[$i]) : "";
        }
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function mapping_append_structure_point(string $csv_path, array $row): void {
    ensure_structure_csv_header($csv_path);
    $fh = fopen($csv_path, "ab");
    if (!$fh) {
        throw new RuntimeException("structure_csv_open_failed");
    }
    $ordered = [];
    foreach (mapping_structure_fields() as $f) {
        $ordered[] = (string)($row[$f] ?? "");
    }
    fputcsv($fh, $ordered);
    fclose($fh);
}

function mapping_world_file_path(string $image_path): string {
    $lower = strtolower($image_path);
    if (str_ends_with($lower, ".png")) {
        return preg_replace('/\.png$/i', ".pgw", $image_path);
    }
    if (str_ends_with($lower, ".jpg") || str_ends_with($lower, ".jpeg")) {
        return preg_replace('/\.(jpg|jpeg)$/i', ".jgw", $image_path);
    }
    return preg_replace('/\.[^.]+$/', ".wld", $image_path);
}

function mapping_load_world_affine(string $image_path): ?array {
    $world = mapping_world_file_path($image_path);
    if (!file_exists($world)) return null;
    $lines = file($world, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || count($lines) < 6) return null;
    $vals = [];
    for ($i = 0; $i < 6; $i++) {
        $v = trim((string)$lines[$i]);
        if (!is_numeric($v)) return null;
        $vals[] = (float)$v;
    }
    // world file order: a,d,b,e,c,f
    return [
        "a" => $vals[0],
        "d" => $vals[1],
        "b" => $vals[2],
        "e" => $vals[3],
        "c" => $vals[4],
        "f" => $vals[5],
    ];
}

function mapping_pixel_to_map(array $affine, float $pixel_x, float $pixel_y): array {
    $map_x = $affine["a"] * $pixel_x + $affine["b"] * $pixel_y + $affine["c"];
    $map_y = $affine["d"] * $pixel_x + $affine["e"] * $pixel_y + $affine["f"];
    return ["lon" => $map_x, "lat" => $map_y];
}

$action = $_GET["action"] ?? "";
$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
$mapping_key_ok = mapping_api_key_valid($cfg);

$public_mapping_actions = [
    "mapping_asset_lookup",
    "mapping_asset_upsert",
];

$current_user = auth_current_user();
if (
    !$current_user &&
    !($mapping_key_ok && in_array($action, $public_mapping_actions, true))
) {
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
    $sql = "SELECT a.asset_type, a.asset_id, a.work_order, a.purchase_order, a.lat, a.lon, a.updated_at";
    if ($asset_type === "drain") {
        $sql .= ", CASE WHEN t.asset_ref IS NULL THEN 0 ELSE 1 END AS has_track
                 FROM assets a
                 LEFT JOIN asset_tracks t ON t.asset_ref = a.id";
    } else {
        $sql .= ", 0 AS has_track
                 FROM assets a";
    }
    $sql .= " WHERE a.asset_type = :asset_type";
    $params = [":asset_type" => $asset_type];
    if ($q !== "") {
        $sql .= " AND (a.asset_id LIKE :q OR a.work_order LIKE :q OR a.purchase_order LIKE :q)";
        $params[":q"] = "%" . $q . "%";
    }
    $sql .= " ORDER BY a.updated_at DESC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(["ok" => true, "assets" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === "download_asset_track" && $method === "GET") {
    $asset_type = clean_asset_type((string)($_GET["asset_type"] ?? ""));
    $asset_id = trim((string)($_GET["asset_id"] ?? ""));
    if ($asset_type !== "drain" || $asset_id === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "invalid_asset_type_or_id"]);
        exit;
    }
    $asset = get_asset_row($pdo, "drain", $asset_id);
    if (!$asset) {
        http_response_code(404);
        echo json_encode(["ok" => false, "error" => "not_found"]);
        exit;
    }
    $stmt = $pdo->prepare("SELECT track_geojson FROM asset_tracks WHERE asset_ref = :ref LIMIT 1");
    $stmt->execute([":ref" => $asset["id"]]);
    $track_geojson = $stmt->fetchColumn();
    if (!$track_geojson) {
        http_response_code(404);
        echo json_encode(["ok" => false, "error" => "track_not_found"]);
        exit;
    }
    header_remove("Content-Type");
    header("Content-Type: application/geo+json; charset=utf-8");
    header("Content-Disposition: inline; filename=\"" . preg_replace('/[^A-Za-z0-9._-]/', '_', "drain_" . $asset_id . "_track.geojson") . "\"");
    echo (string)$track_geojson;
    exit;
}

if ($action === "create_asset" && $method === "POST") {
    $b = body_json();
    $asset_type_input = strtolower(trim((string)($b["asset_type"] ?? "")));
    $asset_type = $asset_type_input === "landmark" ? "landmark" : clean_asset_type($asset_type_input);
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
    $asset_type_input = strtolower(trim((string)($b["asset_type"] ?? $b["type"] ?? "")));
    $asset_type = $asset_type_input === "landmark" ? "landmark" : clean_asset_type($asset_type_input);
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
    $module_raw = trim((string)($_GET["module"] ?? ""));
    $status = trim((string)($_GET["status"] ?? ""));
    $asset_type = clean_asset_type((string)($_GET["asset_type"] ?? ""));
    $current_work = trim((string)($_GET["current_work"] ?? ""));
    $invoice_ready = trim((string)($_GET["invoice_ready"] ?? ""));
    $completed = trim((string)($_GET["completed"] ?? ""));
    $invoiced = trim((string)($_GET["invoiced"] ?? ""));
    $q = trim((string)($_GET["q"] ?? ""));
    $limit = (int)($_GET["limit"] ?? 300);
    if ($limit <= 0 || $limit > 2000) $limit = 300;

    $where = [];
    $params = [];
    if ($module_raw !== "" && strtolower($module_raw) !== "all") {
        $module = clean_job_module($module_raw);
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
    if ($current_work === "1") {
        $where[] = "j.in_current_work = 1";
    } elseif ($current_work === "0") {
        $where[] = "j.in_current_work = 0";
    }
    if ($invoice_ready === "1") {
        $where[] = "j.completed_at IS NOT NULL AND j.invoiced_at IS NULL";
    }
    if ($completed === "1") {
        $where[] = "j.completed_at IS NOT NULL";
    } elseif ($completed === "0") {
        $where[] = "j.completed_at IS NULL";
    }
    if ($invoiced === "1") {
        $where[] = "j.invoiced_at IS NOT NULL";
    } elseif ($invoiced === "0") {
        $where[] = "j.invoiced_at IS NULL";
    }
    if ($q !== "") {
        $where[] = "(j.work_order LIKE :q OR j.purchase_order LIKE :q OR j.asset_id LIKE :q OR j.description LIKE :q)";
        $params[":q"] = "%" . $q . "%";
    }

    $sql = "SELECT
              j.id, j.job_key, j.module, j.asset_type, j.asset_id, j.asset_ref,
              j.work_order, j.purchase_order, j.status, j.scheduled_date, j.description,
              j.lat, j.lon, j.updated_at, j.in_current_work, j.completed_at, j.invoiced_at, j.meta,
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

if ($action === "update_jobs_flags" && $method === "POST") {
    $is_admin = auth_is_admin($current_user);
    $b = body_json();
    $ids = $b["ids"] ?? [];
    if (!is_array($ids) || !$ids) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_ids"]);
        exit;
    }
    $ids = array_values(array_unique(array_map("intval", $ids)));
    $ids = array_values(array_filter($ids, fn($v) => $v > 0));
    if (!$ids) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "no_valid_ids"]);
        exit;
    }

    $set = [];
    $params = [];
    if (array_key_exists("in_current_work", $b)) {
        $set[] = "in_current_work = :in_current_work";
        $params[":in_current_work"] = ((int)$b["in_current_work"]) ? 1 : 0;
    }
    if (array_key_exists("mark_completed", $b) && (int)$b["mark_completed"] === 1) {
        $set[] = "status = 'completed'";
        $set[] = "completed_at = CURRENT_TIMESTAMP";
    }
    if (array_key_exists("mark_invoiced", $b) && (int)$b["mark_invoiced"] === 1) {
        $set[] = "invoiced_at = CURRENT_TIMESTAMP";
    }
    if (array_key_exists("clear_invoiced", $b) && (int)$b["clear_invoiced"] === 1) {
        $set[] = "invoiced_at = NULL";
    }
    if (array_key_exists("clear_completed", $b) && (int)$b["clear_completed"] === 1) {
        $set[] = "completed_at = NULL";
    }

    if (!$is_admin) {
        $allowed_non_admin = ["ids", "in_current_work", "mark_completed", "clear_completed"];
        foreach (array_keys($b) as $k) {
            if (!in_array((string)$k, $allowed_non_admin, true)) {
                http_response_code(403);
                echo json_encode(["ok" => false, "error" => "admin_only"]);
                exit;
            }
        }
        if (array_key_exists("in_current_work", $b) && (int)$b["in_current_work"] !== 1) {
            http_response_code(403);
            echo json_encode(["ok" => false, "error" => "admin_only"]);
            exit;
        }
        if (array_key_exists("mark_completed", $b) && (int)$b["mark_completed"] !== 1) {
            http_response_code(403);
            echo json_encode(["ok" => false, "error" => "admin_only"]);
            exit;
        }
        if (array_key_exists("clear_completed", $b) && (int)$b["clear_completed"] !== 1) {
            http_response_code(403);
            echo json_encode(["ok" => false, "error" => "admin_only"]);
            exit;
        }
    }
    if (!$set) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "nothing_to_update"]);
        exit;
    }

    $in = [];
    foreach ($ids as $i => $id) {
        $k = ":id_" . $i;
        $in[] = $k;
        $params[$k] = $id;
    }
    $sql = "UPDATE jobs SET " . implode(", ", $set) . " WHERE id IN (" . implode(",", $in) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(["ok" => true, "updated" => $stmt->rowCount()]);
    exit;
}

if ($action === "invoice_test_connection" && $method === "POST") {
    if (!auth_is_admin($current_user)) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "admin_only"]);
        exit;
    }
    $b = body_json();
    $ic = invoice_cfg($cfg);
    $base_url = trim((string)($b["base_url"] ?? $ic["base_url"]));
    $api_key = trim((string)($b["api_key"] ?? $ic["api_key"]));
    if ($base_url === "" || $api_key === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_dolibarr_config"]);
        exit;
    }
    $url = invoice_api_root($base_url) . "/invoices?limit=1";
    $r = invoice_dolibarr_request("GET", $url, $api_key, [], 12);
    if (!$r["ok"]) {
        echo json_encode(["ok" => false, "error" => "dolibarr_connection_failed", "status" => $r["status"], "detail" => substr($r["body"] ?: $r["error"], 0, 400)]);
        exit;
    }
    echo json_encode(["ok" => true, "message" => "connection_ok"]);
    exit;
}

if ($action === "invoice_list_customers" && $method === "POST") {
    if (!auth_is_admin($current_user)) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "admin_only"]);
        exit;
    }
    $b = body_json();
    $ic = invoice_cfg($cfg);
    $base_url = trim((string)($b["base_url"] ?? $ic["base_url"]));
    $api_key = trim((string)($b["api_key"] ?? $ic["api_key"]));
    if ($base_url === "" || $api_key === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_dolibarr_config"]);
        exit;
    }
    $limit = (int)($b["limit"] ?? 500);
    if ($limit <= 0 || $limit > 2000) $limit = 500;
    $url = invoice_api_root($base_url) . "/thirdparties?limit=" . $limit;
    $r = invoice_dolibarr_request("GET", $url, $api_key, [], 20);
    if (!$r["ok"]) {
        echo json_encode(["ok" => false, "error" => "dolibarr_customer_list_failed", "status" => $r["status"], "detail" => substr($r["body"] ?: $r["error"], 0, 400)]);
        exit;
    }
    $decoded = json_decode($r["body"], true);
    $rows = [];
    if (is_array($decoded)) {
        $src = $decoded;
        if (isset($decoded["data"]) && is_array($decoded["data"])) $src = $decoded["data"];
        foreach ($src as $it) {
            if (!is_array($it)) continue;
            $id = (string)($it["id"] ?? "");
            if ($id === "" || !ctype_digit($id)) continue;
            $name = trim((string)($it["name"] ?? $it["nom"] ?? ""));
            $code = trim((string)($it["code_client"] ?? ""));
            $label = $name !== "" ? $name : ("Customer " . $id);
            if ($code !== "") $label .= " (" . $code . ")";
            $rows[] = ["id" => (int)$id, "label" => $label];
        }
    }
    usort($rows, function ($a, $b) {
        return strcasecmp((string)$a["label"], (string)$b["label"]);
    });
    echo json_encode(["ok" => true, "customers" => $rows]);
    exit;
}

if ($action === "invoice_create_drafts" && $method === "POST") {
    if (!auth_is_admin($current_user)) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "admin_only"]);
        exit;
    }
    $b = body_json();
    $ids = $b["ids"] ?? [];
    if (!is_array($ids) || !$ids) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_ids"]);
        exit;
    }
    $ids = array_values(array_unique(array_filter(array_map("intval", $ids), fn($v) => $v > 0)));
    if (!$ids) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "no_valid_ids"]);
        exit;
    }
    if (count($ids) > 300) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "too_many_ids"]);
        exit;
    }

    $ic = invoice_cfg($cfg);
    $base_url = trim((string)($b["base_url"] ?? $ic["base_url"]));
    $api_key = trim((string)($b["api_key"] ?? $ic["api_key"]));
    $socid = trim((string)($b["socid"] ?? $ic["socid"]));
    $tva_tx = isset($b["tva_tx"]) ? (float)$b["tva_tx"] : (float)$ic["tva_tx"];
    $line_rate = isset($b["line_rate"]) ? (float)$b["line_rate"] : 0.0;
    if ($base_url === "" || $api_key === "" || $socid === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_dolibarr_config"]);
        exit;
    }
    if ($line_rate < 0) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "invalid_line_rate"]);
        exit;
    }

    $in = [];
    $params = [];
    foreach ($ids as $i => $id) {
        $k = ":id_" . $i;
        $in[] = $k;
        $params[$k] = $id;
    }
    $sql = "SELECT id, module, asset_type, asset_id, work_order, purchase_order, status, description, meta, completed_at, invoiced_at
            FROM jobs WHERE id IN (" . implode(",", $in) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        http_response_code(404);
        echo json_encode(["ok" => false, "error" => "jobs_not_found"]);
        exit;
    }

    $ready = array_values(array_filter($rows, fn($r) => !empty($r["completed_at"]) && empty($r["invoiced_at"])));
    if (!$ready) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "no_completed_not_invoiced"]);
        exit;
    }

    $groups = [];
    foreach ($ready as $r) {
        $po = trim((string)($r["purchase_order"] ?? ""));
        if ($po === "") $po = "(no PO)";
        if (!isset($groups[$po])) $groups[$po] = [];
        $groups[$po][] = $r;
    }

    $api_root = invoice_api_root($base_url);
    $created = [];
    $marked_ids = [];
    foreach ($groups as $po => $grows) {
        $create_payload = [
            "socid" => $socid,
            "type" => "0",
            "note_public" => "ILS V3 draft invoice import",
            "ref_customer" => ($po === "(no PO)") ? "" : $po,
        ];
        $create_url = $api_root . "/invoices";
        $cr = invoice_dolibarr_request("POST", $create_url, $api_key, $create_payload, 30);
        if (!$cr["ok"]) {
            http_response_code(502);
            echo json_encode([
                "ok" => false,
                "error" => "dolibarr_create_invoice_failed",
                "status" => $cr["status"],
                "detail" => substr($cr["body"] ?: $cr["error"], 0, 500),
                "created" => $created,
            ]);
            exit;
        }
        $invoice_id = invoice_parse_id($cr["body"]);
        if (!$invoice_id) {
            http_response_code(502);
            echo json_encode(["ok" => false, "error" => "dolibarr_parse_invoice_id_failed", "detail" => substr($cr["body"], 0, 500), "created" => $created]);
            exit;
        }

        foreach ($grows as $jr) {
            $qty = invoice_qty_from_job($jr);
            $desc = invoice_desc_from_job($jr);
            $line_payload = [
                "desc" => $desc,
                "qty" => number_format($qty, 2, ".", ""),
                "subprice" => number_format($line_rate, 2, ".", ""),
                "tva_tx" => number_format($tva_tx, 2, ".", ""),
                "product_type" => "1",
            ];
            $line_url = $api_root . "/invoices/" . (int)$invoice_id . "/lines";
            $lr = invoice_dolibarr_request("POST", $line_url, $api_key, $line_payload, 30);
            if (!$lr["ok"]) {
                http_response_code(502);
                echo json_encode([
                    "ok" => false,
                    "error" => "dolibarr_add_line_failed",
                    "status" => $lr["status"],
                    "detail" => substr($lr["body"] ?: $lr["error"], 0, 500),
                    "invoice_id" => $invoice_id,
                    "job_id" => (int)$jr["id"],
                    "created" => $created,
                ]);
                exit;
            }
            $marked_ids[] = (int)$jr["id"];
            usleep(250000);
        }

        $created[] = [
            "invoice_id" => (int)$invoice_id,
            "purchase_order" => $po === "(no PO)" ? "" : $po,
            "line_count" => count($grows),
        ];
        usleep(300000);
    }

    if ($marked_ids) {
        $in2 = [];
        $p2 = [];
        foreach ($marked_ids as $i => $id) {
            $k = ":m_" . $i;
            $in2[] = $k;
            $p2[$k] = $id;
        }
        $up = $pdo->prepare("UPDATE jobs SET invoiced_at = CURRENT_TIMESTAMP WHERE id IN (" . implode(",", $in2) . ")");
        $up->execute($p2);
    }

    echo json_encode([
        "ok" => true,
        "created" => $created,
        "updated_jobs" => count($marked_ids),
    ]);
    exit;
}

if ($action === "invoice_create_manual_draft" && $method === "POST") {
    if (!auth_is_admin($current_user)) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "admin_only"]);
        exit;
    }
    $b = body_json();
    $ic = invoice_cfg($cfg);
    $base_url = trim((string)($b["base_url"] ?? $ic["base_url"]));
    $api_key = trim((string)($b["api_key"] ?? $ic["api_key"]));
    $socid = trim((string)($b["socid"] ?? $ic["socid"]));
    $tva_tx = isset($b["tva_tx"]) ? (float)$b["tva_tx"] : (float)$ic["tva_tx"];
    $service_desc = trim((string)($b["service_desc"] ?? ""));
    $work_order = trim((string)($b["work_order"] ?? ""));
    $service_date = trim((string)($b["service_date"] ?? ""));
    $ref_customer = trim((string)($b["ref_customer"] ?? ""));
    $hours_qty = isset($b["hours_qty"]) ? (float)$b["hours_qty"] : 0.0;
    $hours_rate = isset($b["hours_rate"]) ? (float)$b["hours_rate"] : 0.0;
    $chem_qty = isset($b["chem_qty"]) ? (float)$b["chem_qty"] : 0.0;
    $chem_rate = isset($b["chem_rate"]) ? (float)$b["chem_rate"] : 0.0;

    if ($base_url === "" || $api_key === "" || $socid === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_dolibarr_config"]);
        exit;
    }
    if ($service_desc === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_service_desc"]);
        exit;
    }
    if ($hours_qty < 0 || $hours_rate < 0 || $chem_qty < 0 || $chem_rate < 0) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "negative_values_not_allowed"]);
        exit;
    }
    if ($hours_qty <= 0 || $hours_rate <= 0) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "hours_and_rate_required"]);
        exit;
    }
    if ($chem_qty > 0 && $chem_rate <= 0) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_rate_for_nonzero_qty"]);
        exit;
    }

    $api_root = invoice_api_root($base_url);
    $note_public = "ILS V3 manual draft invoice";
    if ($service_date !== "") {
        $note_public .= " (" . $service_date . ")";
    }
    $create_payload = [
        "socid" => $socid,
        "type" => "0",
        "note_public" => $note_public,
        "ref_customer" => $ref_customer,
    ];
    $create_url = $api_root . "/invoices";
    $cr = invoice_dolibarr_request("POST", $create_url, $api_key, $create_payload, 30);
    if (!$cr["ok"]) {
        http_response_code(502);
        echo json_encode([
            "ok" => false,
            "error" => "dolibarr_create_invoice_failed",
            "status" => $cr["status"],
            "detail" => substr($cr["body"] ?: $cr["error"], 0, 500),
        ]);
        exit;
    }
    $invoice_id = invoice_parse_id($cr["body"]);
    if (!$invoice_id) {
        http_response_code(502);
        echo json_encode(["ok" => false, "error" => "dolibarr_parse_invoice_id_failed", "detail" => substr($cr["body"], 0, 500)]);
        exit;
    }

    $main_desc = $service_desc;
    if ($work_order !== "") {
        $main_desc .= "\nW/O " . $work_order;
    }
    $line_defs = [];
    $line_defs[] = [
        "desc" => $main_desc,
        "qty" => $hours_qty,
        "subprice" => $hours_rate,
    ];
    if ($chem_qty > 0) {
        $line_defs[] = [
            "desc" => "Chemicals /L",
            "qty" => $chem_qty,
            "subprice" => $chem_rate,
        ];
    }

    foreach ($line_defs as $line) {
        $line_payload = [
            "desc" => (string)$line["desc"],
            "qty" => number_format((float)$line["qty"], 2, ".", ""),
            "subprice" => number_format((float)$line["subprice"], 2, ".", ""),
            "tva_tx" => number_format($tva_tx, 2, ".", ""),
            "product_type" => "1",
        ];
        $line_url = $api_root . "/invoices/" . (int)$invoice_id . "/lines";
        $lr = invoice_dolibarr_request("POST", $line_url, $api_key, $line_payload, 30);
        if (!$lr["ok"]) {
            http_response_code(502);
            echo json_encode([
                "ok" => false,
                "error" => "dolibarr_add_line_failed",
                "status" => $lr["status"],
                "detail" => substr($lr["body"] ?: $lr["error"], 0, 500),
                "invoice_id" => $invoice_id,
            ]);
            exit;
        }
        usleep(200000);
    }

    echo json_encode([
        "ok" => true,
        "invoice_id" => (int)$invoice_id,
        "line_count" => count($line_defs),
    ]);
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

if ($action === "import_jobs_xlsx_bundle" && $method === "POST") {
    if (!auth_is_admin($current_user)) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "admin_only"]);
        exit;
    }
    if (!isset($_FILES["work_file"]) || !isset($_FILES["work_file"]["tmp_name"]) || !is_uploaded_file($_FILES["work_file"]["tmp_name"])) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_work_file"]);
        exit;
    }
    if (!isset($_FILES["job_files"]) || !isset($_FILES["job_files"]["tmp_name"])) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_job_files"]);
        exit;
    }

    $tmp_dir = upload_root() . "/tmp_jobs_import_" . date("Ymd_His") . "_" . mt_rand(1000, 9999);
    @mkdir($tmp_dir, 0775, true);
    $work_tmp = $tmp_dir . "/work.xlsx";
    if (!move_uploaded_file($_FILES["work_file"]["tmp_name"], $work_tmp)) {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "work_file_save_failed"]);
        exit;
    }

    $job_paths = [];
    $names = $_FILES["job_files"]["name"] ?? [];
    $tmps = $_FILES["job_files"]["tmp_name"] ?? [];
    if (!is_array($tmps)) {
        $tmps = [$tmps];
        $names = is_array($names) ? $names : [$names];
    }
    foreach ($tmps as $i => $tmp_name) {
        if (!$tmp_name || !is_uploaded_file($tmp_name)) continue;
        $base = basename((string)($names[$i] ?? ("job_" . $i . ".xlsx")));
        $dest = $tmp_dir . "/" . preg_replace("/[^a-z0-9_.-]/i", "_", $base);
        if ($dest === $tmp_dir . "/") $dest = $tmp_dir . "/job_" . $i . ".xlsx";
        if (move_uploaded_file($tmp_name, $dest)) {
            $job_paths[] = $dest;
        }
    }
    if (!$job_paths) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "no_valid_job_files"]);
        exit;
    }

    $script = dirname(__DIR__) . "/../pipeline/scripts/import_jobs_bundle.py";
    if (!file_exists($script)) {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "import_script_missing"]);
        exit;
    }
    $cmd_parts = array_merge([$script, "--work", $work_tmp, "--jobs"], $job_paths);
    $parsed = run_python_json($cfg, $cmd_parts);
    if (!($parsed["ok"] ?? false)) {
        http_response_code(400);
        $detail = trim((string)($parsed["output"] ?? ""));
        if ($detail === "") $detail = trim((string)($parsed["raw_output"] ?? ""));
        if ($detail === "") $detail = trim((string)($parsed["error"] ?? "unknown"));
        echo json_encode([
            "ok" => false,
            "error" => "xlsx_parse_failed",
            "detail" => $detail,
        ]);
        exit;
    }
    $dry_run = (string)($_GET["dry_run"] ?? "") === "1";
    $rows = $parsed["rows"] ?? [];
    if (!is_array($rows) || !$rows) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "no_rows_parsed"]);
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
              lat = COALESCE(VALUES(lat), lat),
              lon = COALESCE(VALUES(lon), lon),
              meta = VALUES(meta)";
    $stmt = $dry_run ? null : $pdo->prepare($sql);

    $inserted = 0;
    $matched = 0;
    $unmatched = 0;
    $preview = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
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
        $meta = is_array($r["meta"] ?? null) ? $r["meta"] : [];
        $meta["import_source"] = "xlsx_bundle";
        if ($asset_type !== "" && $asset_id !== "") {
            $asset = get_asset_row($pdo, $asset_type, $asset_id);
            if (!$asset) {
                $ins = $pdo->prepare(
                    "INSERT INTO assets (asset_type, asset_id, work_order, purchase_order, lat, lon, notes)
                     VALUES (:asset_type, :asset_id, '', '', NULL, NULL, '')"
                );
                try {
                    $ins->execute([
                        ":asset_type" => $asset_type,
                        ":asset_id" => $asset_id,
                    ]);
                } catch (Throwable $e) {
                    // Ignore duplicate race, try lookup again.
                }
                $asset = get_asset_row($pdo, $asset_type, $asset_id);
            }
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

        if ($dry_run) {
            if (count($preview) < 12) {
                $preview[] = [
                    "job_key" => $job_key,
                    "module" => $module,
                    "asset_type" => $asset_type,
                    "asset_id" => $asset_id,
                    "work_order" => $work_order,
                    "purchase_order" => $purchase_order,
                    "matched_asset" => $asset_ref !== null,
                ];
            }
        } else {
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
        }
        $inserted++;
    }

    echo json_encode([
        "ok" => true,
        "dry_run" => $dry_run,
        "rows" => $inserted,
        "matched_assets" => $matched,
        "unmatched_assets" => $unmatched,
        "counts" => $parsed["counts"] ?? null,
        "preview" => $preview,
    ]);
    exit;
}

if ($action === "mapping_asset_lookup" && $method === "GET") {
    if (!$mapping_key_ok) {
        http_response_code(401);
        echo json_encode(["ok" => false, "error" => "unauthorized"]);
        exit;
    }
    $asset_type = clean_asset_type((string)($_GET["asset_type"] ?? ""));
    $asset_id = trim((string)($_GET["asset_id"] ?? ""));
    if ($asset_type === "" || $asset_id === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_asset_type_or_id"]);
        exit;
    }

    $asset = get_asset_row($pdo, $asset_type, $asset_id);
    if (!$asset) {
        echo json_encode(["ok" => true, "found" => false]);
        exit;
    }

    $lat = $asset["lat"] !== null ? trim((string)$asset["lat"]) : "";
    $lon = $asset["lon"] !== null ? trim((string)$asset["lon"]) : "";
    echo json_encode([
        "ok" => true,
        "found" => true,
        "asset_type" => (string)$asset["asset_type"],
        "asset_id" => (string)$asset["asset_id"],
        "lat" => $lat,
        "lon" => $lon,
        "has_coords" => ($lat !== "" && $lon !== ""),
    ]);
    exit;
}

if ($action === "mapping_asset_upsert" && $method === "POST") {
    if (!$mapping_key_ok) {
        http_response_code(401);
        echo json_encode(["ok" => false, "error" => "unauthorized"]);
        exit;
    }
    $b = body_json();
    $asset_type = clean_asset_type((string)($b["asset_type"] ?? ""));
    $asset_id = trim((string)($b["asset_id"] ?? ""));
    $lat_raw = trim((string)($b["lat"] ?? ""));
    $lon_raw = trim((string)($b["lon"] ?? ""));
    if ($asset_type === "" || $asset_id === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_asset_type_or_id"]);
        exit;
    }
    if ($lat_raw === "" || $lon_raw === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_lat_or_lon"]);
        exit;
    }
    if (!is_numeric($lat_raw) || !is_numeric($lon_raw)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "invalid_lat_or_lon"]);
        exit;
    }

    $asset = get_asset_row($pdo, $asset_type, $asset_id);
    if ($asset) {
        $stmt = $pdo->prepare(
            "UPDATE assets
             SET lat = :lat, lon = :lon
             WHERE asset_type = :asset_type AND asset_id = :asset_id"
        );
        $stmt->execute([
            ":lat" => $lat_raw,
            ":lon" => $lon_raw,
            ":asset_type" => $asset_type,
            ":asset_id" => $asset_id,
        ]);
        echo json_encode(["ok" => true, "created" => false, "updated" => true]);
        exit;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO assets (asset_type, asset_id, work_order, purchase_order, lat, lon, notes)
         VALUES (:asset_type, :asset_id, '', '', :lat, :lon, '')"
    );
    $stmt->execute([
        ":asset_type" => $asset_type,
        ":asset_id" => $asset_id,
        ":lat" => $lat_raw,
        ":lon" => $lon_raw,
    ]);

    echo json_encode(["ok" => true, "created" => true, "updated" => false]);
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

if ($action === "mapping_list_asset_collections" && $method === "GET") {
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
    $files = [];
    foreach (mapping_collection_files($dirs) as $key => $path) {
        if (!file_exists($path)) continue;
        $files[] = [
            "key" => $key,
            "name" => basename($path),
            "size" => filesize($path),
            "download_url" => "api/index.php?action=mapping_download_asset_collection&file_key=" . rawurlencode($key),
        ];
    }
    echo json_encode(["ok" => true, "files" => $files]);
    exit;
}

if ($action === "mapping_get_control_points" && $method === "GET") {
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
    $files = mapping_candidate_files($map_stem, $dirs);
    $image = (string)($files["image_png"] ?? "");
    $control_csv = (string)($files["control_csv"] ?? "");
    $control_csv_qgis = (string)($files["control_csv_qgis"] ?? "");
    if ($image === "" || !file_exists($image)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "image_png_missing"]);
        exit;
    }
    if ($control_csv === "") {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "control_csv_path_missing"]);
        exit;
    }
    ensure_control_csv_header($control_csv);
    if ($control_csv_qgis !== "") {
        mapping_write_qgis_safe_csv($control_csv, $control_csv_qgis, mapping_control_fields());
    }
    $dims = @getimagesize($image);
    $points = mapping_read_control_points($control_csv);
    echo json_encode([
        "ok" => true,
        "image_url" => "api/index.php?action=mapping_download_output&map_stem=" . rawurlencode($map_stem) . "&file_key=image_png",
        "image_width" => is_array($dims) ? (int)($dims[0] ?? 0) : 0,
        "image_height" => is_array($dims) ? (int)($dims[1] ?? 0) : 0,
        "points" => $points,
    ]);
    exit;
}

if ($action === "mapping_add_control_point" && $method === "POST") {
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
    $map_stem = trim((string)($b["map_stem"] ?? ""));
    $asset_type_input = strtolower(trim((string)($b["asset_type"] ?? $b["type"] ?? "")));
    $asset_type = $asset_type_input === "landmark" ? "landmark" : clean_asset_type($asset_type_input);
    $asset_id = trim((string)($b["asset_id"] ?? ""));
    $pixel_x_raw = trim((string)($b["pixel_x"] ?? ""));
    $pixel_y_raw = trim((string)($b["pixel_y"] ?? ""));
    $lon_raw = trim((string)($b["lon"] ?? ""));
    $lat_raw = trim((string)($b["lat"] ?? ""));
    $label = trim((string)($b["label"] ?? ""));

    if ($map_stem === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_map_stem"]);
        exit;
    }
    if ($asset_type === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_asset_type"]);
        exit;
    }
    if (!is_numeric($pixel_x_raw) || !is_numeric($pixel_y_raw)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "invalid_pixel_coords"]);
        exit;
    }
    $pixel_x = (int)round((float)$pixel_x_raw);
    $pixel_y = (int)round((float)$pixel_y_raw);

    $coord_source = "manual";
    $asset_action = "none";
    $asset = null;
    if ($asset_type !== "landmark" && $asset_id !== "") {
        $asset = get_asset_row($pdo, $asset_type, $asset_id);
        if (($lon_raw === "" || $lat_raw === "") && $asset) {
            $asset_lon = $asset["lon"] !== null ? trim((string)$asset["lon"]) : "";
            $asset_lat = $asset["lat"] !== null ? trim((string)$asset["lat"]) : "";
            if ($asset_lon !== "" && $asset_lat !== "") {
                $lon_raw = $asset_lon;
                $lat_raw = $asset_lat;
                $coord_source = "asset";
            }
        }
    }
    if ($lon_raw === "" || $lat_raw === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_lon_or_lat"]);
        exit;
    }
    if (!is_numeric($lon_raw) || !is_numeric($lat_raw)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "invalid_lon_or_lat"]);
        exit;
    }

    if ($asset_type !== "landmark" && $asset_id !== "") {
        if ($asset) {
            if ($coord_source !== "asset") {
                $stmt = $pdo->prepare(
                    "UPDATE assets SET lat = :lat, lon = :lon
                     WHERE asset_type = :asset_type AND asset_id = :asset_id"
                );
                $stmt->execute([
                    ":lat" => $lat_raw,
                    ":lon" => $lon_raw,
                    ":asset_type" => $asset_type,
                    ":asset_id" => $asset_id,
                ]);
                $asset_action = "updated";
            }
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO assets (asset_type, asset_id, work_order, purchase_order, lat, lon, notes)
                 VALUES (:asset_type, :asset_id, '', '', :lat, :lon, '')"
            );
            $stmt->execute([
                ":asset_type" => $asset_type,
                ":asset_id" => $asset_id,
                ":lat" => $lat_raw,
                ":lon" => $lon_raw,
            ]);
            $asset_action = "created";
        }
    }

    $dirs = mapping_dirs($cfg, true);
    $files = mapping_candidate_files($map_stem, $dirs);
    $control_csv = (string)($files["control_csv"] ?? "");
    $control_csv_qgis = (string)($files["control_csv_qgis"] ?? "");
    if ($control_csv === "") {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "control_csv_path_missing"]);
        exit;
    }
    mapping_append_control_point($control_csv, [
        "pixel_x" => (string)$pixel_x,
        "pixel_y" => (string)$pixel_y,
        "lon" => $lon_raw,
        "lat" => $lat_raw,
        "asset_type" => $asset_type,
        "asset_id" => $asset_id,
        "label" => $label,
    ]);
    if ($control_csv_qgis !== "") {
        mapping_write_qgis_safe_csv($control_csv, $control_csv_qgis, mapping_control_fields());
    }

    echo json_encode([
        "ok" => true,
        "coord_source" => $coord_source,
        "asset_action" => $asset_action,
        "point" => [
            "pixel_x" => (string)$pixel_x,
            "pixel_y" => (string)$pixel_y,
            "lon" => $lon_raw,
            "lat" => $lat_raw,
            "asset_type" => $asset_type,
            "asset_id" => $asset_id,
            "label" => $label,
        ],
    ]);
    exit;
}

if ($action === "mapping_delete_control_point" && $method === "POST") {
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
    $map_stem = trim((string)($b["map_stem"] ?? ""));
    $index_raw = $b["index"] ?? null;
    if ($map_stem === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_map_stem"]);
        exit;
    }
    if (!is_numeric((string)$index_raw)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_index"]);
        exit;
    }
    $index = (int)$index_raw;
    if ($index < 0) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "invalid_index"]);
        exit;
    }
    $dirs = mapping_dirs($cfg, true);
    $files = mapping_candidate_files($map_stem, $dirs);
    $control_csv = (string)($files["control_csv"] ?? "");
    $control_csv_qgis = (string)($files["control_csv_qgis"] ?? "");
    if ($control_csv === "") {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "control_csv_path_missing"]);
        exit;
    }
    ensure_control_csv_header($control_csv);
    $rows = mapping_read_control_points($control_csv);
    if (!isset($rows[$index])) {
        http_response_code(404);
        echo json_encode(["ok" => false, "error" => "point_not_found"]);
        exit;
    }
    array_splice($rows, $index, 1);
    mapping_write_control_points($control_csv, $rows);
    if ($control_csv_qgis !== "") {
        mapping_write_qgis_safe_csv($control_csv, $control_csv_qgis, mapping_control_fields());
    }
    echo json_encode(["ok" => true, "remaining" => count($rows)]);
    exit;
}

if ($action === "mapping_get_structure_points" && $method === "GET") {
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
    $files = mapping_candidate_files($map_stem, $dirs);
    $image = (string)($files["image_png"] ?? "");
    $structures_csv = (string)($files["structure_csv"] ?? "");
    $structures_csv_qgis = (string)($files["structure_csv_qgis"] ?? "");
    if ($image === "" || !file_exists($image)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "image_png_missing"]);
        exit;
    }
    if ($structures_csv === "") {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "structure_csv_path_missing"]);
        exit;
    }
    ensure_structure_csv_header($structures_csv);
    if ($structures_csv_qgis !== "") {
        mapping_write_qgis_safe_csv($structures_csv, $structures_csv_qgis, mapping_structure_fields());
    }
    $dims = @getimagesize($image);
    $points = mapping_read_structure_points($structures_csv);
    $affine = mapping_load_world_affine($image);
    echo json_encode([
        "ok" => true,
        "image_url" => "api/index.php?action=mapping_download_output&map_stem=" . rawurlencode($map_stem) . "&file_key=image_png",
        "image_width" => is_array($dims) ? (int)($dims[0] ?? 0) : 0,
        "image_height" => is_array($dims) ? (int)($dims[1] ?? 0) : 0,
        "world_file_found" => $affine !== null,
        "points" => $points,
    ]);
    exit;
}

if ($action === "mapping_add_structure_point" && $method === "POST") {
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
    $map_stem = trim((string)($b["map_stem"] ?? ""));
    $structure_type = clean_asset_type((string)($b["structure_type"] ?? $b["type"] ?? ""));
    $structure_id = trim((string)($b["structure_id"] ?? ""));
    $pixel_x_raw = trim((string)($b["pixel_x"] ?? ""));
    $pixel_y_raw = trim((string)($b["pixel_y"] ?? ""));
    $label = trim((string)($b["label"] ?? ""));
    $upsert_asset = (bool)($b["upsert_asset"] ?? true);

    if ($map_stem === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_map_stem"]);
        exit;
    }
    if ($structure_type === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_structure_type"]);
        exit;
    }
    if ($structure_id === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_structure_id"]);
        exit;
    }
    if (!is_numeric($pixel_x_raw) || !is_numeric($pixel_y_raw)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "invalid_pixel_coords"]);
        exit;
    }

    $dirs = mapping_dirs($cfg, true);
    $files = mapping_candidate_files($map_stem, $dirs);
    $image = (string)($files["image_png"] ?? "");
    $structures_csv = (string)($files["structure_csv"] ?? "");
    $structures_csv_qgis = (string)($files["structure_csv_qgis"] ?? "");
    if ($image === "" || !file_exists($image)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "image_png_missing"]);
        exit;
    }
    if ($structures_csv === "") {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "structure_csv_path_missing"]);
        exit;
    }
    $affine = mapping_load_world_affine($image);
    if ($affine === null) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "world_file_missing"]);
        exit;
    }

    $pixel_x = (int)round((float)$pixel_x_raw);
    $pixel_y = (int)round((float)$pixel_y_raw);
    $coords = mapping_pixel_to_map($affine, (float)$pixel_x, (float)$pixel_y);
    $lon = number_format((float)$coords["lon"], 6, ".", "");
    $lat = number_format((float)$coords["lat"], 6, ".", "");

    mapping_append_structure_point($structures_csv, [
        "structure_id" => $structure_id,
        "structure_type" => $structure_type,
        "pixel_x" => (string)$pixel_x,
        "pixel_y" => (string)$pixel_y,
        "lon" => $lon,
        "lat" => $lat,
    ]);
    if ($structures_csv_qgis !== "") {
        mapping_write_qgis_safe_csv($structures_csv, $structures_csv_qgis, mapping_structure_fields());
    }

    $asset_action = "none";
    if ($upsert_asset) {
        $asset = get_asset_row($pdo, $structure_type, $structure_id);
        if ($asset) {
            $stmt = $pdo->prepare(
                "UPDATE assets SET lat = :lat, lon = :lon
                 WHERE asset_type = :asset_type AND asset_id = :asset_id"
            );
            $stmt->execute([
                ":lat" => $lat,
                ":lon" => $lon,
                ":asset_type" => $structure_type,
                ":asset_id" => $structure_id,
            ]);
            $asset_action = "updated";
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO assets (asset_type, asset_id, work_order, purchase_order, lat, lon, notes)
                 VALUES (:asset_type, :asset_id, '', '', :lat, :lon, :notes)"
            );
            $stmt->execute([
                ":asset_type" => $structure_type,
                ":asset_id" => $structure_id,
                ":lat" => $lat,
                ":lon" => $lon,
                ":notes" => $label === "" ? "" : ("Map label: " . $label),
            ]);
            $asset_action = "created";
        }
    }

    echo json_encode([
        "ok" => true,
        "asset_action" => $asset_action,
        "point" => [
            "structure_id" => $structure_id,
            "structure_type" => $structure_type,
            "pixel_x" => (string)$pixel_x,
            "pixel_y" => (string)$pixel_y,
            "lon" => $lon,
            "lat" => $lat,
        ],
    ]);
    exit;
}

if ($action === "mapping_get_trace_map" && $method === "GET") {
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
    $files = mapping_candidate_files($map_stem, $dirs);
    $image = (string)($files["image_png"] ?? "");
    if ($image === "" || !file_exists($image)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "image_png_missing"]);
        exit;
    }
    $dims = @getimagesize($image);
    $affine = mapping_load_world_affine($image);
    echo json_encode([
        "ok" => true,
        "image_url" => "api/index.php?action=mapping_download_output&map_stem=" . rawurlencode($map_stem) . "&file_key=image_png",
        "image_width" => is_array($dims) ? (int)($dims[0] ?? 0) : 0,
        "image_height" => is_array($dims) ? (int)($dims[1] ?? 0) : 0,
        "world_file_found" => $affine !== null,
    ]);
    exit;
}

if ($action === "mapping_trace_drain" && $method === "POST") {
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
    $map_stem = trim((string)($b["map_stem"] ?? ""));
    $sx = (int)($b["start_x"] ?? 0);
    $sy = (int)($b["start_y"] ?? 0);
    $ex = (int)($b["end_x"] ?? 0);
    $ey = (int)($b["end_y"] ?? 0);
    if ($map_stem === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_map_stem"]);
        exit;
    }
    $dirs = mapping_dirs($cfg, true);
    $files = mapping_candidate_files($map_stem, $dirs);
    $image = (string)($files["image_png"] ?? "");
    $world = (string)($files["image_world"] ?? "");
    if ($image === "" || !file_exists($image) || $world === "" || !file_exists($world)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_image_or_world"]);
        exit;
    }
    $cmd_parts = [
        path_join((string)$dirs["scripts_dir"], "trace_drain_path.py"),
        $image,
        "--start-x",
        (string)$sx,
        "--start-y",
        (string)$sy,
        "--end-x",
        (string)$ex,
        "--end-y",
        (string)$ey,
    ];
    $res = run_mapping_cmd_json($cfg, $cmd_parts);
    if (!($res["ok"] ?? false)) {
        http_response_code(400);
    }
    echo json_encode($res);
    exit;
}

if ($action === "mapping_save_drain_track" && $method === "POST") {
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
    $map_stem = trim((string)($b["map_stem"] ?? ""));
    $drain_id = trim((string)($b["drain_id"] ?? ""));
    $coords = $b["coord_points"] ?? null;
    if ($map_stem === "" || $drain_id === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_map_stem_or_drain_id"]);
        exit;
    }
    if (!is_array($coords) || count($coords) < 2) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "invalid_coord_points"]);
        exit;
    }
    $line_coords = [];
    foreach ($coords as $pt) {
        if (!is_array($pt) || count($pt) < 2) continue;
        $lon = (float)$pt[0];
        $lat = (float)$pt[1];
        $line_coords[] = [(float)number_format($lon, 6, ".", ""), (float)number_format($lat, 6, ".", "")];
    }
    if (count($line_coords) < 2) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "invalid_coord_points"]);
        exit;
    }
    $mid_idx = (int)floor((count($line_coords) - 1) / 2);
    $mid_lon = (string)$line_coords[$mid_idx][0];
    $mid_lat = (string)$line_coords[$mid_idx][1];

    $asset = get_asset_row($pdo, "drain", $drain_id);
    $asset_action = "none";
    $pin_updated = false;
    if (!$asset) {
        $stmt = $pdo->prepare(
            "INSERT INTO assets (asset_type, asset_id, work_order, purchase_order, lat, lon, notes)
             VALUES ('drain', :asset_id, '', '', :lat, :lon, '')"
        );
        $stmt->execute([
            ":asset_id" => $drain_id,
            ":lat" => $mid_lat,
            ":lon" => $mid_lon,
        ]);
        $asset = get_asset_row($pdo, "drain", $drain_id);
        $asset_action = "created";
        $pin_updated = true;
    }
    $asset_ref = (int)($asset["id"] ?? 0);
    if ($asset_ref <= 0) {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "asset_lookup_failed"]);
        exit;
    }

    $track_geojson = json_encode([
        "type" => "Feature",
        "geometry" => ["type" => "LineString", "coordinates" => $line_coords],
        "properties" => ["asset_type" => "drain", "asset_id" => $drain_id, "map_stem" => $map_stem],
    ], JSON_UNESCAPED_SLASHES);

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO asset_tracks (asset_ref, map_stem, track_geojson)
             VALUES (:asset_ref, :map_stem, :track_geojson)
             ON DUPLICATE KEY UPDATE map_stem = VALUES(map_stem), track_geojson = VALUES(track_geojson)"
        );
        $stmt->execute([
            ":asset_ref" => $asset_ref,
            ":map_stem" => $map_stem,
            ":track_geojson" => $track_geojson,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "asset_tracks_table_missing"]);
        exit;
    }
    $existing_lat = $asset["lat"] !== null ? trim((string)$asset["lat"]) : "";
    $existing_lon = $asset["lon"] !== null ? trim((string)$asset["lon"]) : "";
    if ($existing_lat === "" || $existing_lon === "") {
        $stmt = $pdo->prepare(
            "UPDATE assets
             SET lat = :lat, lon = :lon
             WHERE id = :id"
        );
        $stmt->execute([
            ":lat" => $mid_lat,
            ":lon" => $mid_lon,
            ":id" => $asset_ref,
        ]);
        $pin_updated = true;
    }

    $dirs = mapping_dirs($cfg, true);
    $files = mapping_candidate_files($map_stem, $dirs);
    $tracks_geojson = (string)($files["drain_tracks_geojson"] ?? "");
    if ($tracks_geojson !== "") {
        mapping_upsert_drain_track_geojson($tracks_geojson, $drain_id, $map_stem, $line_coords);
    }

    echo json_encode([
        "ok" => true,
        "asset_action" => $asset_action,
        "drain_id" => $drain_id,
        "lon" => $mid_lon,
        "lat" => $mid_lat,
        "pin_updated" => $pin_updated,
        "point_count" => count($line_coords),
    ]);
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

if ($action === "mapping_download_asset_collection" && $method === "GET") {
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
    $file_key = trim((string)($_GET["file_key"] ?? ""));
    if ($file_key === "") {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "missing_file_key"]);
        exit;
    }
    $dirs = mapping_dirs($cfg, true);
    $path = mapping_collection_files($dirs)[$file_key] ?? "";
    if ($path === "" || !file_exists($path)) {
        http_response_code(404);
        echo json_encode(["ok" => false, "error" => "file_not_found"]);
        exit;
    }
    header("Content-Type: application/geo+json");
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
        $cmd_parts = [path_join($scripts_dir, "init_map_inputs.py"), $pdf_path];
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
    } elseif ($operation === "rotate_pdf") {
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
        $degrees = (int)($b["degrees"] ?? 0);
        if (!in_array($degrees, [90, -90, 180], true)) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "invalid_rotation_degrees"]);
            exit;
        }
        $cmd_parts = [path_join($scripts_dir, "rotate_pdf.py"), $pdf_path, "--degrees", (string)$degrees];
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
            "--include-control-assets",
        ];
    } elseif ($operation === "build_asset_collections") {
        $collections_dir = (string)($dirs["collections_dir"] ?? "");
        if ($collections_dir === "") {
            http_response_code(500);
            echo json_encode(["ok" => false, "error" => "collections_dir_missing"]);
            exit;
        }
        if (!is_dir($collections_dir) && !@mkdir($collections_dir, 0775, true)) {
            http_response_code(500);
            echo json_encode(["ok" => false, "error" => "collections_dir_unavailable"]);
            exit;
        }
        $cmd_parts = [
            path_join($scripts_dir, "build_asset_collections.py"),
            $input_dir,
            $collections_dir,
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
