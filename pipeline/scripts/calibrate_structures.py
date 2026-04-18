from __future__ import annotations

import argparse
import csv
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable

from structure_extract import StructureDetection, detect_structures_raw, write_detections_csv


@dataclass
class TruthPoint:
    structure_id: str
    structure_type: str
    pixel_x: float
    pixel_y: float


@dataclass
class CalibrationResult:
    min_id_confidence: float
    min_digits: int
    max_digits: int
    hue_tolerance: int
    matched: int
    predicted: int
    truth: int
    precision: float
    recall: float
    f1: float


def load_truth_points(path: Path) -> list[TruthPoint]:
    with path.open("r", encoding="utf-8-sig", newline="") as file:
        reader = csv.DictReader(file)
        fields = {name.lower(): name for name in (reader.fieldnames or [])}
        required = {"structure_id", "pixel_x", "pixel_y"}
        if not required.issubset(set(fields)):
            raise ValueError("Truth CSV must contain structure_id,pixel_x,pixel_y columns.")

        points: list[TruthPoint] = []
        for row in reader:
            sid = str(row[fields["structure_id"]]).strip()
            if not sid:
                continue
            stype = str(row.get(fields.get("structure_type", ""), "")).strip().lower() if fields.get("structure_type") else ""
            pixel_x = float(row[fields["pixel_x"]])
            pixel_y = float(row[fields["pixel_y"]])
            # Skip placeholder coordinates that have not been labeled yet.
            if pixel_x == 0.0 and pixel_y == 0.0:
                continue
            points.append(
                TruthPoint(
                    structure_id=sid,
                    structure_type=stype,
                    pixel_x=pixel_x,
                    pixel_y=pixel_y,
                )
            )
    if not points:
        raise ValueError("No truth points loaded from CSV.")
    return points


def filter_detections(
    detections: Iterable[StructureDetection],
    min_id_confidence: float,
    min_digits: int,
    max_digits: int,
) -> list[StructureDetection]:
    result: list[StructureDetection] = []
    for det in detections:
        sid = det.structure_id.strip()
        if not sid.isdigit():
            continue
        if len(sid) < min_digits or len(sid) > max_digits:
            continue
        if det.confidence < min_id_confidence:
            continue
        result.append(det)
    return result


def evaluate(
    detections: list[StructureDetection],
    truth_points: list[TruthPoint],
    pixel_tolerance: float,
) -> tuple[int, int, int, float, float, float]:
    matched_truth_indices: set[int] = set()
    matched = 0

    for det in detections:
        best_idx = -1
        best_dist_sq = pixel_tolerance * pixel_tolerance
        for idx, truth in enumerate(truth_points):
            if idx in matched_truth_indices:
                continue
            if truth.structure_id != det.structure_id:
                continue
            if truth.structure_type and truth.structure_type != det.structure_type:
                continue
            dx = det.pixel_x - truth.pixel_x
            dy = det.pixel_y - truth.pixel_y
            dist_sq = dx * dx + dy * dy
            if dist_sq <= best_dist_sq:
                best_idx = idx
                best_dist_sq = dist_sq

        if best_idx >= 0:
            matched += 1
            matched_truth_indices.add(best_idx)

    predicted = len(detections)
    truth = len(truth_points)
    precision = matched / predicted if predicted else 0.0
    recall = matched / truth if truth else 0.0
    f1 = (2 * precision * recall / (precision + recall)) if (precision + recall) else 0.0
    return matched, predicted, truth, precision, recall, f1


def write_results_csv(results: list[CalibrationResult], output_csv: Path) -> Path:
    fields = [
        "min_id_confidence",
        "min_digits",
        "max_digits",
        "hue_tolerance",
        "matched",
        "predicted",
        "truth",
        "precision",
        "recall",
        "f1",
    ]
    with output_csv.open("w", encoding="utf-8", newline="") as file:
        writer = csv.DictWriter(file, fieldnames=fields)
        writer.writeheader()
        for r in results:
            writer.writerow(
                {
                    "min_id_confidence": f"{r.min_id_confidence:.2f}",
                    "min_digits": r.min_digits,
                    "max_digits": r.max_digits,
                    "hue_tolerance": r.hue_tolerance,
                    "matched": r.matched,
                    "predicted": r.predicted,
                    "truth": r.truth,
                    "precision": f"{r.precision:.4f}",
                    "recall": f"{r.recall:.4f}",
                    "f1": f"{r.f1:.4f}",
                }
            )
    return output_csv


def parse_float_list(values: str) -> list[float]:
    return [float(v.strip()) for v in values.split(",") if v.strip()]


def parse_int_list(values: str) -> list[int]:
    return [int(v.strip()) for v in values.split(",") if v.strip()]


def calibrate(
    image_path: Path,
    truth_csv: Path,
    output_dir: Path,
    pixel_tolerance: float = 35.0,
    min_conf_grid: list[float] | None = None,
    min_digits_grid: list[int] | None = None,
    max_digits_grid: list[int] | None = None,
    hue_tolerance_grid: list[int] | None = None,
) -> dict[str, Path | float | int]:
    min_conf_grid = min_conf_grid or [0.40, 0.50, 0.60, 0.70]
    min_digits_grid = min_digits_grid or [2, 3]
    max_digits_grid = max_digits_grid or [3, 4]
    hue_tolerance_grid = hue_tolerance_grid or [10, 14, 18]

    image_path = image_path.resolve()
    truth_csv = truth_csv.resolve()
    output_dir = output_dir.resolve()
    output_dir.mkdir(parents=True, exist_ok=True)

    truth_points = load_truth_points(truth_csv)
    all_results: list[CalibrationResult] = []
    best_result: CalibrationResult | None = None
    best_filtered: list[StructureDetection] = []

    for hue_tolerance in hue_tolerance_grid:
        _, raw = detect_structures_raw(
            image_paths=[image_path],
            min_digits=1,
            max_digits=5,
            hue_tolerance=hue_tolerance,
        )

        for min_conf in min_conf_grid:
            for min_digits in min_digits_grid:
                for max_digits in max_digits_grid:
                    if max_digits < min_digits:
                        continue
                    filtered = filter_detections(
                        raw,
                        min_id_confidence=min_conf,
                        min_digits=min_digits,
                        max_digits=max_digits,
                    )
                    matched, predicted, truth, precision, recall, f1 = evaluate(
                        filtered,
                        truth_points=truth_points,
                        pixel_tolerance=pixel_tolerance,
                    )
                    result = CalibrationResult(
                        min_id_confidence=min_conf,
                        min_digits=min_digits,
                        max_digits=max_digits,
                        hue_tolerance=hue_tolerance,
                        matched=matched,
                        predicted=predicted,
                        truth=truth,
                        precision=precision,
                        recall=recall,
                        f1=f1,
                    )
                    all_results.append(result)
                    if best_result is None or result.f1 > best_result.f1:
                        best_result = result
                        best_filtered = filtered

    assert best_result is not None
    all_results.sort(key=lambda r: (r.f1, r.recall, r.precision), reverse=True)

    map_stem = image_path.stem.replace("_page_001", "")
    results_csv = output_dir / f"{map_stem}_structure_calibration_results.csv"
    write_results_csv(all_results, results_csv)

    best_csv = output_dir / f"{map_stem}_structures_calibrated_best.csv"
    write_detections_csv(best_filtered, best_csv)

    print("Best calibration result:")
    print(
        f"  hue_tolerance={best_result.hue_tolerance}, "
        f"min_id_confidence={best_result.min_id_confidence:.2f}, "
        f"digits={best_result.min_digits}-{best_result.max_digits}"
    )
    print(
        f"  matched={best_result.matched}/{best_result.truth}, "
        f"predicted={best_result.predicted}, "
        f"precision={best_result.precision:.3f}, "
        f"recall={best_result.recall:.3f}, f1={best_result.f1:.3f}"
    )
    print(f"Calibration grid results: {results_csv}")
    print(f"Best calibrated detections: {best_csv}")

    return {
        "results_csv": results_csv,
        "best_csv": best_csv,
        "best_f1": best_result.f1,
        "best_precision": best_result.precision,
        "best_recall": best_result.recall,
    }


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Calibrate structure ID extraction against labeled truth points.")
    parser.add_argument("image_path", type=Path, help="Map PNG image path")
    parser.add_argument("truth_csv", type=Path, help="Truth CSV with structure_id,structure_type,pixel_x,pixel_y")
    parser.add_argument(
        "--output-dir",
        type=Path,
        default=Path("outputs") / "text",
        help="Directory for calibration outputs",
    )
    parser.add_argument(
        "--pixel-tolerance",
        type=float,
        default=35.0,
        help="Maximum pixel distance for matching predicted vs truth (default: 35)",
    )
    parser.add_argument(
        "--min-conf-grid",
        default="0.40,0.50,0.60,0.70",
        help="Comma-separated min confidence values to test",
    )
    parser.add_argument(
        "--min-digits-grid",
        default="2,3",
        help="Comma-separated min digit lengths to test",
    )
    parser.add_argument(
        "--max-digits-grid",
        default="3,4",
        help="Comma-separated max digit lengths to test",
    )
    parser.add_argument(
        "--hue-tolerance-grid",
        default="10,14,18",
        help="Comma-separated hue tolerance values to test",
    )
    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    try:
        calibrate(
            image_path=args.image_path,
            truth_csv=args.truth_csv,
            output_dir=args.output_dir,
            pixel_tolerance=args.pixel_tolerance,
            min_conf_grid=parse_float_list(args.min_conf_grid),
            min_digits_grid=parse_int_list(args.min_digits_grid),
            max_digits_grid=parse_int_list(args.max_digits_grid),
            hue_tolerance_grid=parse_int_list(args.hue_tolerance_grid),
        )
    except Exception as exc:
        print(f"Error: {exc}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
