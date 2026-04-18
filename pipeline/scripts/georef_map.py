from __future__ import annotations

import argparse
import csv
import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import numpy as np

try:
    from pyproj import CRS  # type: ignore
except ImportError:  # pragma: no cover - optional dependency fallback
    CRS = None


@dataclass
class ControlPoint:
    pixel_x: float
    pixel_y: float
    map_x: float
    map_y: float


@dataclass
class AffineTransform:
    a: float
    b: float
    c: float
    d: float
    e: float
    f: float

    def pixel_to_map(self, pixel_x: float, pixel_y: float) -> tuple[float, float]:
        map_x = self.a * pixel_x + self.b * pixel_y + self.c
        map_y = self.d * pixel_x + self.e * pixel_y + self.f
        return map_x, map_y


def parse_float(value: str) -> float:
    return float(value.strip())


def load_control_points(control_points_csv: Path) -> list[ControlPoint]:
    points: list[ControlPoint] = []
    with control_points_csv.open("r", encoding="utf-8-sig", newline="") as file:
        reader = csv.DictReader(file)
        if reader.fieldnames is None:
            raise ValueError("Control points CSV has no header row.")

        normalized = {name.lower(): name for name in reader.fieldnames}
        required = ("pixel_x", "pixel_y")
        if not all(name in normalized for name in required):
            raise ValueError("Control points CSV must include pixel_x and pixel_y columns.")

        map_x_key = normalized.get("map_x") or normalized.get("lon") or normalized.get("longitude")
        map_y_key = normalized.get("map_y") or normalized.get("lat") or normalized.get("latitude")
        if map_x_key is None or map_y_key is None:
            raise ValueError("Control points CSV must include map_x/map_y or lon/lat columns.")

        for row in reader:
            if not row.get(normalized["pixel_x"]) or not row.get(normalized["pixel_y"]):
                continue
            points.append(
                ControlPoint(
                    pixel_x=parse_float(row[normalized["pixel_x"]]),
                    pixel_y=parse_float(row[normalized["pixel_y"]]),
                    map_x=parse_float(row[map_x_key]),
                    map_y=parse_float(row[map_y_key]),
                )
            )

    if len(points) < 3:
        raise ValueError("At least 3 control points are required for affine georeferencing.")

    return points


def fit_affine_transform(points: list[ControlPoint]) -> tuple[AffineTransform, float]:
    matrix_rows: list[list[float]] = []
    values: list[float] = []

    for point in points:
        matrix_rows.append([point.pixel_x, point.pixel_y, 1.0, 0.0, 0.0, 0.0])
        matrix_rows.append([0.0, 0.0, 0.0, point.pixel_x, point.pixel_y, 1.0])
        values.append(point.map_x)
        values.append(point.map_y)

    matrix = np.array(matrix_rows, dtype=float)
    targets = np.array(values, dtype=float)
    coeffs, _, _, _ = np.linalg.lstsq(matrix, targets, rcond=None)
    transform = AffineTransform(*coeffs.tolist())

    residuals_sq: list[float] = []
    for point in points:
        predicted_x, predicted_y = transform.pixel_to_map(point.pixel_x, point.pixel_y)
        residuals_sq.append((predicted_x - point.map_x) ** 2 + (predicted_y - point.map_y) ** 2)
    rmse = float(np.sqrt(np.mean(residuals_sq)))

    return transform, rmse


def worldfile_path_for_image(image_path: Path) -> Path:
    suffix = image_path.suffix.lower()
    if suffix == ".png":
        return image_path.with_suffix(".pgw")
    if suffix == ".jpg" or suffix == ".jpeg":
        return image_path.with_suffix(".jgw")
    return image_path.with_suffix(".wld")


def write_world_file(image_path: Path, transform: AffineTransform) -> Path:
    world_path = worldfile_path_for_image(image_path)
    lines = [
        f"{transform.a:.12f}",
        f"{transform.d:.12f}",
        f"{transform.b:.12f}",
        f"{transform.e:.12f}",
        f"{transform.c:.12f}",
        f"{transform.f:.12f}",
    ]
    world_path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return world_path


def write_prj_file(image_path: Path, target_crs: str) -> Path:
    prj_path = image_path.with_suffix(".prj")
    if CRS is not None:
        crs = CRS.from_user_input(target_crs)
        prj_path.write_text(crs.to_wkt(), encoding="utf-8")
        return prj_path

    # Fallback without pyproj for common GPS coordinate case.
    if target_crs.upper() != "EPSG:4326":
        raise RuntimeError("pyproj is required for non-EPSG:4326 CRS projection output.")

    epsg4326_wkt = (
        'GEOGCS["WGS 84",DATUM["WGS_1984",'
        'SPHEROID["WGS 84",6378137,298.257223563]],'
        'PRIMEM["Greenwich",0],UNIT["degree",0.0174532925199433],'
        'AUTHORITY["EPSG","4326"]]'
    )
    prj_path.write_text(epsg4326_wkt, encoding="utf-8")
    return prj_path


def georeference_structures(
    structures_csv: Path,
    transform: AffineTransform,
    target_crs: str,
) -> tuple[Path, Path]:
    rows: list[dict[str, str]] = []
    with structures_csv.open("r", encoding="utf-8-sig", newline="") as file:
        reader = csv.DictReader(file)
        fieldnames = list(reader.fieldnames or [])
        if "pixel_x" not in fieldnames or "pixel_y" not in fieldnames:
            raise ValueError("Structure CSV must include pixel_x and pixel_y columns.")

        out_fields = fieldnames + ["map_x", "map_y", "target_crs"]
        for row in reader:
            pixel_x = float(row["pixel_x"])
            pixel_y = float(row["pixel_y"])
            map_x, map_y = transform.pixel_to_map(pixel_x, pixel_y)
            row["map_x"] = f"{map_x:.10f}"
            row["map_y"] = f"{map_y:.10f}"
            row["target_crs"] = target_crs
            rows.append(row)

    georef_csv = structures_csv.with_name(structures_csv.stem + "_georef.csv")
    with georef_csv.open("w", encoding="utf-8", newline="") as file:
        writer = csv.DictWriter(file, fieldnames=out_fields)
        writer.writeheader()
        writer.writerows(rows)

    features: list[dict[str, Any]] = []
    for row in rows:
        props = {k: v for k, v in row.items() if k not in {"map_x", "map_y"}}
        features.append(
            {
                "type": "Feature",
                "geometry": {
                    "type": "Point",
                    "coordinates": [float(row["map_x"]), float(row["map_y"])],
                },
                "properties": props,
            }
        )

    geojson = {
        "type": "FeatureCollection",
        "name": georef_csv.stem,
        "crs": {"type": "name", "properties": {"name": target_crs}},
        "features": features,
    }
    geojson_path = georef_csv.with_suffix(".geojson")
    geojson_path.write_text(json.dumps(geojson, indent=2), encoding="utf-8")
    return georef_csv, geojson_path


def georeference_image(
    image_path: Path,
    control_points_csv: Path,
    target_crs: str = "EPSG:4326",
    structures_csv: Path | None = None,
) -> dict[str, Path | float]:
    image_path = image_path.resolve()
    control_points_csv = control_points_csv.resolve()
    if not image_path.exists():
        raise FileNotFoundError(f"Image not found: {image_path}")
    if not control_points_csv.exists():
        raise FileNotFoundError(f"Control points CSV not found: {control_points_csv}")

    points = load_control_points(control_points_csv)
    transform, rmse = fit_affine_transform(points)
    world_file = write_world_file(image_path, transform)
    prj_file = write_prj_file(image_path, target_crs)

    print(f"Georeferenced image: {image_path.name}")
    print(f"Control points used: {len(points)}")
    print(f"Affine RMSE: {rmse:.6f} map units")
    print(f"World file: {world_file}")
    print(f"Projection file: {prj_file}")

    results: dict[str, Path | float] = {
        "image": image_path,
        "world_file": world_file,
        "prj_file": prj_file,
        "rmse": rmse,
    }

    if structures_csv is not None:
        structures_csv = structures_csv.resolve()
        if not structures_csv.exists():
            raise FileNotFoundError(f"Structures CSV not found: {structures_csv}")
        georef_csv, geojson = georeference_structures(structures_csv, transform, target_crs)
        print(f"Georeferenced structures CSV: {georef_csv}")
        print(f"Georeferenced structures GeoJSON: {geojson}")
        results["structures_georef_csv"] = georef_csv
        results["structures_geojson"] = geojson

    return results


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Georeference a map image using control points.")
    parser.add_argument("image_path", type=Path, help="Path to map image (PNG/JPG)")
    parser.add_argument("control_points_csv", type=Path, help="CSV with pixel_x,pixel_y,map_x,map_y or lon,lat")
    parser.add_argument(
        "--target-crs",
        default="EPSG:4326",
        help="Target CRS for map_x/map_y coordinates (default: EPSG:4326)",
    )
    parser.add_argument(
        "--structures-csv",
        type=Path,
        default=None,
        help="Optional structures CSV with pixel coordinates to georeference",
    )
    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    try:
        georeference_image(
            image_path=args.image_path,
            control_points_csv=args.control_points_csv,
            target_crs=args.target_crs,
            structures_csv=args.structures_csv,
        )
    except Exception as exc:
        print(f"Error: {exc}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
