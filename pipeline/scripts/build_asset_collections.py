from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any


ASSET_TYPES = ("drain", "culvert", "bridge", "floodgate")
TYPE_COLORS = {
    "drain": "#1b9e77",
    "culvert": "#1f78b4",
    "bridge": "#e66101",
    "floodgate": "#6a3d9a",
}


def infer_map_stem(path: Path) -> str:
    suffix = "_structure_truth_georef.geojson"
    name = path.name
    if name.endswith(suffix):
        return name[: -len(suffix)]
    return path.stem


def load_json(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8-sig") as f:
        return json.load(f)


def normalize_feature(feature: dict[str, Any], map_stem: str) -> dict[str, Any] | None:
    geom = feature.get("geometry") or {}
    if geom.get("type") != "Point":
        return None
    coords = geom.get("coordinates")
    if not isinstance(coords, list) or len(coords) < 2:
        return None

    props = feature.get("properties") or {}
    asset_type = str(props.get("asset_type") or props.get("structure_type") or "").strip().lower()
    asset_id = str(props.get("asset_id") or props.get("structure_id") or "").strip()
    if asset_type not in ASSET_TYPES or asset_id == "":
        return None

    source = str(props.get("source") or "").strip() or "unknown"
    label = str(props.get("label") or "").strip()
    color = TYPE_COLORS.get(asset_type, "#666666")
    return {
        "type": "Feature",
        "geometry": {
            "type": "Point",
            "coordinates": [float(coords[0]), float(coords[1])],
        },
        "properties": {
            "asset_type": asset_type,
            "asset_id": asset_id,
            "source": source,
            "map_stem": map_stem,
            "label": label,
            # Common style keys many GIS tools can use or map from quickly.
            "symbol_color": color,
            "marker-color": color,
            "marker-size": "small",
            "marker-symbol": "circle",
        },
    }


def feature_rank(feature: dict[str, Any]) -> tuple[int, int]:
    props = feature.get("properties") or {}
    source = str(props.get("source") or "").strip().lower()
    source_rank = 2 if source == "structure_point" else 1 if source == "control_point" else 0
    has_label = 1 if str(props.get("label") or "").strip() else 0
    return source_rank, has_label


def build_collections(input_dir: Path, output_dir: Path) -> dict[str, Path]:
    input_dir = input_dir.resolve()
    output_dir = output_dir.resolve()
    output_dir.mkdir(parents=True, exist_ok=True)

    by_key: dict[tuple[str, str], dict[str, Any]] = {}
    for geojson_path in sorted(input_dir.glob("*_structure_truth_georef.geojson")):
        data = load_json(geojson_path)
        features = data.get("features") or []
        if not isinstance(features, list):
            continue
        map_stem = infer_map_stem(geojson_path)
        for raw in features:
            if not isinstance(raw, dict):
                continue
            norm = normalize_feature(raw, map_stem)
            if norm is None:
                continue
            props = norm["properties"]
            key = (str(props["asset_type"]), str(props["asset_id"]))
            existing = by_key.get(key)
            if existing is None or feature_rank(norm) > feature_rank(existing):
                by_key[key] = norm

    all_features = sorted(
        by_key.values(),
        key=lambda f: (
            str((f.get("properties") or {}).get("asset_type", "")),
            str((f.get("properties") or {}).get("asset_id", "")),
        ),
    )

    def fc(features: list[dict[str, Any]], name: str) -> dict[str, Any]:
        return {
            "type": "FeatureCollection",
            "name": name,
            "crs": {"type": "name", "properties": {"name": "EPSG:4326"}},
            "features": features,
        }

    files: dict[str, Path] = {}
    all_path = output_dir / "all_assets.geojson"
    all_path.write_text(json.dumps(fc(all_features, "all_assets"), indent=2), encoding="utf-8")
    files["all_assets"] = all_path

    for asset_type in ASSET_TYPES:
        features = [f for f in all_features if (f.get("properties") or {}).get("asset_type") == asset_type]
        path = output_dir / f"{asset_type}s.geojson"
        path.write_text(json.dumps(fc(features, f"{asset_type}s"), indent=2), encoding="utf-8")
        files[asset_type] = path

    return files


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Build combined asset GeoJSON collections from map outputs.")
    parser.add_argument("input_dir", type=Path, help="Directory containing *_structure_truth_georef.geojson files")
    parser.add_argument("output_dir", type=Path, help="Directory to write combined GeoJSON files")
    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    try:
        files = build_collections(args.input_dir, args.output_dir)
        print(f"Wrote {len(files)} collection file(s):")
        for key, path in files.items():
            print(f"- {key}: {path}")
    except Exception as exc:
        print(f"Error: {exc}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

