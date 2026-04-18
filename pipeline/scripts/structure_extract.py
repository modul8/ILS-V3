from __future__ import annotations

import argparse
import csv
import math
import re
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable

import cv2
import numpy as np
import pytesseract

from ocr_extract import DEFAULT_TEXT_OUTPUT_DIR, collect_image_paths, configure_tesseract, extract_map_stem, extract_page_number


@dataclass
class StructureDetection:
    map_stem: str
    page_number: int
    image_file: str
    structure_type: str
    structure_id: str
    pixel_x: float
    pixel_y: float
    radius_px: float
    confidence: float


def preprocess_for_symbols(image: np.ndarray) -> np.ndarray:
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    blur = cv2.GaussianBlur(gray, (5, 5), 0)
    return cv2.Canny(blur, threshold1=60, threshold2=180)


def contour_center_radius(contour: np.ndarray) -> tuple[float, float, float]:
    (x, y), radius = cv2.minEnclosingCircle(contour)
    return float(x), float(y), float(radius)


def contour_circularity(contour: np.ndarray) -> float:
    area = cv2.contourArea(contour)
    perimeter = cv2.arcLength(contour, True)
    if perimeter <= 0:
        return 0.0
    return float((4.0 * math.pi * area) / (perimeter * perimeter))


def valid_symbol_radius(radius: float, image_shape: tuple[int, int, int]) -> bool:
    height, width = image_shape[:2]
    min_radius = max(3.0, min(height, width) * 0.0015)
    max_radius = min(height, width) * 0.02
    return min_radius <= radius <= max_radius


def colored_symbol_ring_ratio(hsv: np.ndarray, x: float, y: float, radius: float) -> float:
    h, w = hsv.shape[:2]
    pad = int(max(6, radius * 2))
    x1 = max(0, int(x - pad))
    y1 = max(0, int(y - pad))
    x2 = min(w, int(x + pad))
    y2 = min(h, int(y + pad))
    if x2 - x1 < 5 or y2 - y1 < 5:
        return 0.0

    local = hsv[y1:y2, x1:x2]
    yy, xx = np.ogrid[y1:y2, x1:x2]
    distance = np.sqrt((xx - x) ** 2 + (yy - y) ** 2)
    ring_mask = (distance >= radius * 0.7) & (distance <= radius * 1.4)
    if not np.any(ring_mask):
        return 0.0

    sat = local[:, :, 1]
    val = local[:, :, 2]
    colored_ratio = float(np.mean((sat[ring_mask] > 60) & (val[ring_mask] > 50)))
    return colored_ratio


def dominant_symbol_hue(hsv: np.ndarray, x: float, y: float, radius: float) -> int | None:
    h, w = hsv.shape[:2]
    pad = int(max(6, radius * 2))
    x1 = max(0, int(x - pad))
    y1 = max(0, int(y - pad))
    x2 = min(w, int(x + pad))
    y2 = min(h, int(y + pad))
    if x2 - x1 < 5 or y2 - y1 < 5:
        return None

    local = hsv[y1:y2, x1:x2]
    yy, xx = np.ogrid[y1:y2, x1:x2]
    distance = np.sqrt((xx - x) ** 2 + (yy - y) ** 2)
    ring_mask = (distance >= radius * 0.7) & (distance <= radius * 1.4)
    if not np.any(ring_mask):
        return None

    sat = local[:, :, 1]
    val = local[:, :, 2]
    hue = local[:, :, 0]
    color_mask = ring_mask & (sat > 60) & (val > 50)
    if not np.any(color_mask):
        return None
    return int(np.median(hue[color_mask]))


def hue_distance(h1: np.ndarray, h2: int) -> np.ndarray:
    diff = np.abs(h1.astype(int) - int(h2))
    return np.minimum(diff, 180 - diff)


def prepare_colored_digit_mask(local_hsv: np.ndarray, target_hue: int, hue_tolerance: int = 14) -> np.ndarray:
    hue = local_hsv[:, :, 0]
    sat = local_hsv[:, :, 1]
    val = local_hsv[:, :, 2]

    close_hue = hue_distance(hue, target_hue) <= hue_tolerance
    color_mask = close_hue & (sat >= 50) & (val >= 40)
    binary = np.zeros(hue.shape, dtype=np.uint8)
    binary[color_mask] = 255

    binary = cv2.morphologyEx(binary, cv2.MORPH_OPEN, np.ones((2, 2), np.uint8))
    binary = cv2.morphologyEx(binary, cv2.MORPH_CLOSE, np.ones((2, 2), np.uint8))
    return binary


def keep_reasonable_components(binary_mask: np.ndarray) -> np.ndarray:
    num_labels, labels, stats, _ = cv2.connectedComponentsWithStats(binary_mask, connectivity=8)
    cleaned = np.zeros_like(binary_mask)
    for idx in range(1, num_labels):
        x, y, w, h, area = stats[idx]
        if area < 6 or area > 2000:
            continue
        if w < 2 or h < 3:
            continue
        if w > 180 or h > 100:
            continue
        cleaned[labels == idx] = 255
    return cleaned


def ocr_digits_with_confidence(binary_mask: np.ndarray, min_digits: int = 2, max_digits: int = 5) -> tuple[str, float]:
    if binary_mask.size == 0 or not np.any(binary_mask):
        return "", 0.0

    # OCR wants dark text on light background.
    ocr_image = np.full(binary_mask.shape, 255, dtype=np.uint8)
    ocr_image[binary_mask > 0] = 0
    ocr_image = cv2.resize(ocr_image, None, fx=2.0, fy=2.0, interpolation=cv2.INTER_CUBIC)

    data = pytesseract.image_to_data(
        ocr_image,
        output_type=pytesseract.Output.DICT,
        config="--psm 7 -c tessedit_char_whitelist=0123456789",
    )

    best_id = ""
    best_conf = 0.0
    for text, conf_raw in zip(data["text"], data["conf"]):
        text = text.strip()
        if not text:
            continue
        match = re.search(rf"\d{{{min_digits},{max_digits}}}", text)
        if not match:
            continue
        conf = float(conf_raw) if conf_raw != "-1" else 0.0
        conf = max(0.0, min(100.0, conf)) / 100.0
        candidate = match.group(0)
        # Prefer higher OCR confidence, then longer digit strings.
        if conf > best_conf or (abs(conf - best_conf) < 1e-6 and len(candidate) > len(best_id)):
            best_id = candidate
            best_conf = conf

    return best_id, best_conf


def detect_floodgate_marker(image: np.ndarray, x: float, y: float, radius: float) -> tuple[bool, float]:
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    h, w = gray.shape
    pad = int(max(6, radius * 1.1))
    x1 = max(0, int(x - pad))
    y1 = max(0, int(y - pad))
    x2 = min(w, int(x + pad))
    y2 = min(h, int(y + pad))
    if x2 - x1 < 6 or y2 - y1 < 6:
        return False, 0.0

    roi_gray = gray[y1:y2, x1:x2]
    roi_gray = cv2.GaussianBlur(roi_gray, (3, 3), 0)
    variants = [
        cv2.threshold(roi_gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)[1],
        cv2.threshold(roi_gray, 0, 255, cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU)[1],
    ]

    best_conf = 0.0
    for variant in variants:
        roi = cv2.resize(variant, None, fx=2.5, fy=2.5, interpolation=cv2.INTER_CUBIC)
        data = pytesseract.image_to_data(
            roi,
            output_type=pytesseract.Output.DICT,
            config="--psm 10 -c tessedit_char_whitelist=Ff",
        )
        for text, conf_raw in zip(data["text"], data["conf"]):
            text = text.strip().upper()
            if text != "F":
                continue
            conf = float(conf_raw) if conf_raw != "-1" else 0.0
            conf = max(0.0, min(100.0, conf)) / 100.0
            best_conf = max(best_conf, conf)

    return best_conf >= 0.25, best_conf


def has_double_ring_marker(image: np.ndarray, x: float, y: float, radius: float) -> tuple[bool, float]:
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    h, w = gray.shape
    pad = int(max(10, radius * 2.5))
    x1 = max(0, int(x - pad))
    y1 = max(0, int(y - pad))
    x2 = min(w, int(x + pad))
    y2 = min(h, int(y + pad))
    if x2 - x1 < 12 or y2 - y1 < 12:
        return False, 0.0

    roi = gray[y1:y2, x1:x2]
    roi = cv2.medianBlur(roi, 5)
    circles = cv2.HoughCircles(
        roi,
        cv2.HOUGH_GRADIENT,
        dp=1.1,
        minDist=6,
        param1=100,
        param2=12,
        minRadius=max(3, int(radius * 0.45)),
        maxRadius=max(6, int(radius * 1.8)),
    )
    if circles is None:
        return False, 0.0

    cx = x - x1
    cy = y - y1
    near_center_radii: list[float] = []
    for cx2, cy2, r2 in np.round(circles[0, :], 2):
        if math.hypot(cx2 - cx, cy2 - cy) <= max(3.0, radius * 0.5):
            near_center_radii.append(float(r2))

    if len(near_center_radii) < 2:
        return False, 0.0

    near_center_radii.sort()
    spread = near_center_radii[-1] - near_center_radii[0]
    if spread < 1.5:
        return False, 0.0

    conf = min(1.0, spread / max(2.0, radius))
    return True, conf


def detect_symbol_candidates(image: np.ndarray) -> tuple[list[tuple[float, float, float]], list[tuple[float, float, float]]]:
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    gray = cv2.medianBlur(gray, 5)
    circles = cv2.HoughCircles(
        gray,
        cv2.HOUGH_GRADIENT,
        dp=1.2,
        minDist=18,
        param1=120,
        param2=16,
        minRadius=3,
        maxRadius=16,
    )
    if circles is None:
        return [], []

    candidates: list[tuple[float, float, float]] = []
    for x, y, radius in np.round(circles[0, :], 2):
        if not valid_symbol_radius(float(radius), image.shape):
            continue
        if x < 2 or y < 2 or x >= image.shape[1] - 2 or y >= image.shape[0] - 2:
            continue
        candidates.append((float(x), float(y), float(radius)))

    deduped_candidates: list[tuple[float, float, float]] = []
    for x, y, radius in sorted(candidates, key=lambda v: v[2], reverse=True):
        duplicate = False
        for ex, ey, er in deduped_candidates:
            if math.hypot(x - ex, y - ey) <= 3.0 and abs(radius - er) <= 2.0:
                duplicate = True
                break
        if not duplicate:
            deduped_candidates.append((x, y, radius))

    hsv = cv2.cvtColor(image, cv2.COLOR_BGR2HSV)
    ranked_candidates: list[tuple[float, float, float, float]] = []
    for x, y, radius in deduped_candidates:
        ratio = colored_symbol_ring_ratio(hsv, x, y, radius)
        if ratio >= 0.22:
            ranked_candidates.append((ratio, x, y, radius))

    ranked_candidates.sort(reverse=True)
    filtered_candidates = [(x, y, radius) for ratio, x, y, radius in ranked_candidates[:220]]

    bridges: list[tuple[float, float, float]] = []
    used_indices: set[int] = set()
    for i, (x_outer, y_outer, r_outer) in enumerate(filtered_candidates):
        for j, (x_inner, y_inner, r_inner) in enumerate(filtered_candidates):
            if i == j:
                continue
            if r_outer <= r_inner:
                continue
            center_distance = math.hypot(x_outer - x_inner, y_outer - y_inner)
            radius_ratio = r_outer / max(r_inner, 1e-6)
            if center_distance <= 2.5 and 1.2 <= radius_ratio <= 2.2:
                bridges.append((x_outer, y_outer, r_outer))
                used_indices.add(i)
                used_indices.add(j)
                break

    culverts: list[tuple[float, float, float]] = []
    for idx, (x, y, radius) in enumerate(filtered_candidates):
        if idx in used_indices:
            continue
        culverts.append((x, y, radius))

    return culverts, bridges


def extract_nearby_id(
    image: np.ndarray,
    x: float,
    y: float,
    radius: float,
    min_digits: int = 2,
    max_digits: int = 5,
    hue_tolerance: int = 14,
) -> tuple[str, float]:
    if not (math.isfinite(x) and math.isfinite(y) and math.isfinite(radius)):
        return "", 0.0

    hsv = cv2.cvtColor(image, cv2.COLOR_BGR2HSV)
    target_hue = dominant_symbol_hue(hsv, x, y, radius)
    if target_hue is None:
        return "", 0.0

    h, w = image.shape[:2]
    search = int(max(20, radius * 6))
    r = int(max(4, radius * 1.5))

    regions = [
        (int(x - search), int(y - search), int(x - r), int(y + search)),  # left
        (int(x + r), int(y - search), int(x + search), int(y + search)),  # right
        (int(x - search), int(y - search), int(x + search), int(y - r)),  # top
        (int(x - search), int(y + r), int(x + search), int(y + search)),  # bottom
    ]

    best_id = ""
    best_score = 0.0
    best_conf = 0.0
    for x1, y1, x2, y2 in regions:
        x1 = max(0, x1)
        y1 = max(0, y1)
        x2 = min(w, x2)
        y2 = min(h, y2)
        if x2 - x1 < 8 or y2 - y1 < 8:
            continue

        roi_hsv = hsv[y1:y2, x1:x2]
        if roi_hsv.size == 0 or roi_hsv.shape[0] < 3 or roi_hsv.shape[1] < 3:
            continue

        color_mask = prepare_colored_digit_mask(roi_hsv, target_hue=target_hue, hue_tolerance=hue_tolerance)
        color_mask = keep_reasonable_components(color_mask)
        candidate, conf = ocr_digits_with_confidence(color_mask, min_digits=min_digits, max_digits=max_digits)
        if not candidate:
            continue

        score = conf * 1.2 + min(0.3, len(candidate) * 0.05)
        if score > best_score:
            best_id = candidate
            best_score = score
            best_conf = conf

    confidence = best_conf if best_id else 0.0
    return best_id, confidence


def deduplicate_detections(detections: list[StructureDetection], distance_threshold: float = 10.0) -> list[StructureDetection]:
    deduped: list[StructureDetection] = []
    for detection in sorted(detections, key=lambda d: d.confidence, reverse=True):
        duplicate = False
        for existing in deduped:
            if detection.structure_type != existing.structure_type:
                continue
            dist = math.hypot(detection.pixel_x - existing.pixel_x, detection.pixel_y - existing.pixel_y)
            if dist <= distance_threshold:
                duplicate = True
                break
        if not duplicate:
            deduped.append(detection)
    return deduped


def detect_structures_raw(
    image_paths: Iterable[Path],
    min_digits: int = 1,
    max_digits: int = 5,
    hue_tolerance: int = 14,
) -> tuple[str, list[StructureDetection]]:
    configure_tesseract()
    sorted_paths = collect_image_paths(image_paths)
    map_stem = extract_map_stem(sorted_paths[0])
    if any(extract_map_stem(path) != map_stem for path in sorted_paths):
        raise ValueError("All image paths must belong to the same map when extracting structures.")

    detections: list[StructureDetection] = []
    for image_path in sorted_paths:
        page_number = extract_page_number(image_path)
        image = cv2.imread(str(image_path))
        if image is None:
            raise RuntimeError(f"Could not read image: {image_path}")

        culverts, bridges = detect_symbol_candidates(image)
        all_candidates = culverts + bridges
        # Merge nearby duplicates before classification.
        merged_candidates: list[tuple[float, float, float]] = []
        for x, y, radius in sorted(all_candidates, key=lambda t: t[2], reverse=True):
            duplicate = False
            for ex, ey, er in merged_candidates:
                if math.hypot(x - ex, y - ey) <= 3.0 and abs(radius - er) <= 2.5:
                    duplicate = True
                    break
            if not duplicate:
                merged_candidates.append((x, y, radius))

        for x, y, radius in merged_candidates:
            is_floodgate, floodgate_conf = detect_floodgate_marker(image, x, y, radius)
            is_bridge, bridge_conf = has_double_ring_marker(image, x, y, radius)
            if is_floodgate:
                structure_type = "floodgate"
            elif is_bridge:
                structure_type = "bridge"
            else:
                structure_type = "culvert"
            structure_id, ocr_conf = extract_nearby_id(
                image,
                x,
                y,
                radius,
                min_digits=min_digits,
                max_digits=max_digits,
                hue_tolerance=hue_tolerance,
            )
            detections.append(
                StructureDetection(
                    map_stem=map_stem,
                    page_number=page_number,
                    image_file=image_path.name,
                    structure_type=structure_type,
                    structure_id=structure_id,
                    pixel_x=x,
                    pixel_y=y,
                    radius_px=radius,
                    confidence=max(
                        ocr_conf,
                        floodgate_conf if is_floodgate else 0.0,
                        bridge_conf if is_bridge else 0.0,
                    ),
                )
            )

    return map_stem, deduplicate_detections(detections)


def write_detections_csv(detections: list[StructureDetection], output_file: Path) -> Path:
    fieldnames = [
        "map_stem",
        "page_number",
        "image_file",
        "structure_type",
        "structure_id",
        "pixel_x",
        "pixel_y",
        "radius_px",
        "confidence",
    ]
    with output_file.open("w", encoding="utf-8", newline="") as file:
        writer = csv.DictWriter(file, fieldnames=fieldnames)
        writer.writeheader()
        for detection in detections:
            writer.writerow(
                {
                    "map_stem": detection.map_stem,
                    "page_number": detection.page_number,
                    "image_file": detection.image_file,
                    "structure_type": detection.structure_type,
                    "structure_id": detection.structure_id,
                    "pixel_x": f"{detection.pixel_x:.2f}",
                    "pixel_y": f"{detection.pixel_y:.2f}",
                    "radius_px": f"{detection.radius_px:.2f}",
                    "confidence": f"{detection.confidence:.3f}",
                }
            )
    return output_file


def extract_structures_from_images(
    image_paths: Iterable[Path],
    text_output_dir: Path = DEFAULT_TEXT_OUTPUT_DIR,
    min_id_confidence: float = 0.55,
    include_empty_ids: bool = False,
    min_digits: int = 2,
    max_digits: int = 5,
    hue_tolerance: int = 14,
    include_floodgates: bool = False,
) -> dict[str, Path | list[Path] | int]:
    map_stem, detections = detect_structures_raw(
        image_paths=image_paths,
        min_digits=min_digits,
        max_digits=max_digits,
        hue_tolerance=hue_tolerance,
    )

    text_output_dir = text_output_dir.resolve()
    text_output_dir.mkdir(parents=True, exist_ok=True)

    filtered = []
    for detection in detections:
        if detection.structure_type == "floodgate" and not include_floodgates:
            continue
        if detection.structure_id and detection.confidence >= min_id_confidence:
            filtered.append(detection)
        elif include_empty_ids and not detection.structure_id:
            filtered.append(detection)

    deduped = deduplicate_detections(filtered)
    output_csv = text_output_dir / f"{map_stem}_structures.csv"
    write_detections_csv(deduped, output_csv)
    print(
        f"Saved structures CSV: {output_csv} "
        f"({len(deduped)} detections; min_id_confidence={min_id_confidence:.2f})"
    )
    return {"structures_csv": output_csv, "detections_count": len(deduped)}


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Extract culvert/bridge symbols and IDs from map images.")
    parser.add_argument("image_paths", nargs="+", type=Path, help="Map image paths")
    parser.add_argument(
        "--output-dir",
        type=Path,
        default=DEFAULT_TEXT_OUTPUT_DIR,
        help="Directory for structure CSV output",
    )
    parser.add_argument(
        "--min-id-confidence",
        type=float,
        default=0.55,
        help="Minimum OCR confidence to keep a structure ID (default: 0.55)",
    )
    parser.add_argument(
        "--include-empty-ids",
        action="store_true",
        help="Include structure detections even when no ID could be read",
    )
    parser.add_argument(
        "--min-digits",
        type=int,
        default=2,
        help="Minimum digits in detected structure ID (default: 2)",
    )
    parser.add_argument(
        "--max-digits",
        type=int,
        default=5,
        help="Maximum digits in detected structure ID (default: 5)",
    )
    parser.add_argument(
        "--hue-tolerance",
        type=int,
        default=14,
        help="Hue tolerance when matching ID text to symbol color (default: 14)",
    )
    parser.add_argument(
        "--include-floodgates",
        action="store_true",
        help="Include circles marked with 'F' (floodgates) in output",
    )
    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    try:
        extract_structures_from_images(
            args.image_paths,
            text_output_dir=args.output_dir,
            min_id_confidence=args.min_id_confidence,
            include_empty_ids=args.include_empty_ids,
            min_digits=args.min_digits,
            max_digits=args.max_digits,
            hue_tolerance=args.hue_tolerance,
            include_floodgates=args.include_floodgates,
        )
    except Exception as exc:
        print(f"Error: {exc}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
