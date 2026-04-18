from __future__ import annotations

import argparse
from pathlib import Path

from drain_name_extract import extract_drain_names
from georef_map import georeference_image
from ocr_extract import DEFAULT_TEXT_OUTPUT_DIR, extract_text_from_images
from pdf_to_images import DEFAULT_IMAGE_OUTPUT_DIR, convert_pdf_to_images
from structure_extract import extract_structures_from_images

PROJECT_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_INPUT_DIR = PROJECT_ROOT / "input_pdfs"


def resolve_pdf_path(pdf_argument: str) -> Path:
    """Resolve a PDF path, using input_pdfs/ when a bare filename is provided."""
    candidate = Path(pdf_argument)

    if candidate.is_absolute() or candidate.parent != Path("."):
        resolved = candidate.resolve()
    else:
        resolved = (DEFAULT_INPUT_DIR / candidate).resolve()

    if resolved.suffix.lower() != ".pdf":
        raise ValueError(f"Expected a .pdf file, got: {resolved.name}")
    if not resolved.exists():
        raise FileNotFoundError(f"PDF file not found: {resolved}")

    return resolved


def run_pipeline(
    pdf_argument: str,
    drain_hints: list[str] | None = None,
    branch_parent: str | None = None,
    branch_codes: list[str] | None = None,
    extract_structures: bool = False,
    structure_min_id_confidence: float = 0.55,
    structure_include_empty_ids: bool = False,
    structure_min_digits: int = 2,
    structure_max_digits: int = 5,
    structure_hue_tolerance: int = 14,
    structure_include_floodgates: bool = False,
    control_points_csv: Path | None = None,
    target_crs: str = "EPSG:4326",
) -> dict[str, object]:
    pdf_path = resolve_pdf_path(pdf_argument)

    print(f"Starting pipeline for: {pdf_path.name}")
    image_paths = convert_pdf_to_images(pdf_path=pdf_path, output_dir=DEFAULT_IMAGE_OUTPUT_DIR, dpi=300)
    ocr_outputs = extract_text_from_images(image_paths=image_paths, text_output_dir=DEFAULT_TEXT_OUTPUT_DIR)
    drain_name_outputs = extract_drain_names(
        image_paths=image_paths,
        text_output_dir=DEFAULT_TEXT_OUTPUT_DIR,
        hint_names=drain_hints,
        branch_parent=branch_parent,
        branch_codes=branch_codes,
    )
    structure_outputs: dict[str, Path | int] = {}
    if extract_structures:
        structure_outputs = extract_structures_from_images(
            image_paths=image_paths,
            text_output_dir=DEFAULT_TEXT_OUTPUT_DIR,
            min_id_confidence=structure_min_id_confidence,
            include_empty_ids=structure_include_empty_ids,
            min_digits=structure_min_digits,
            max_digits=structure_max_digits,
            hue_tolerance=structure_hue_tolerance,
            include_floodgates=structure_include_floodgates,
        )

    georef_outputs: dict[str, Path | float] = {}
    if control_points_csv is not None:
        if len(image_paths) != 1:
            raise ValueError("Georeferencing currently expects a single-page map image.")
        georef_outputs = georeference_image(
            image_path=image_paths[0],
            control_points_csv=control_points_csv,
            target_crs=target_crs,
            structures_csv=structure_outputs.get("structures_csv") if structure_outputs else None,
        )

    print("Pipeline complete.")
    print(f"Images: {DEFAULT_IMAGE_OUTPUT_DIR}")
    print(f"Text:   {DEFAULT_TEXT_OUTPUT_DIR}")

    return {
        "pdf": pdf_path,
        "image_paths": image_paths,
        "combined_text": ocr_outputs["combined_file"],
        "page_text_files": ocr_outputs["page_files"],
        "drain_names_file": drain_name_outputs["combined_file"],
        "page_drain_name_files": drain_name_outputs["page_files"],
        **structure_outputs,
        **georef_outputs,
    }


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Run PDF -> PNG -> OCR pipeline.")
    parser.add_argument(
        "pdf",
        help="PDF filename in input_pdfs/ or a relative/absolute path to a PDF",
    )
    parser.add_argument(
        "--drain-hint",
        action="append",
        default=None,
        help="Optional drain-name hint phrase; can be repeated",
    )
    parser.add_argument(
        "--branch-parent",
        default=None,
        help="Optional parent drain stem for branch expansion (e.g., MAYFIELDS)",
    )
    parser.add_argument(
        "--branch-code",
        action="append",
        default=None,
        help="Optional branch code to expand under --branch-parent (e.g., B, B1)",
    )
    parser.add_argument(
        "--extract-structures",
        action="store_true",
        help="Detect culvert/bridge symbols and nearby numeric IDs into a CSV",
    )
    parser.add_argument(
        "--structure-min-id-confidence",
        type=float,
        default=0.55,
        help="Minimum OCR confidence to keep a structure ID (default: 0.55)",
    )
    parser.add_argument(
        "--structure-include-empty-ids",
        action="store_true",
        help="Include structure detections even when ID OCR fails",
    )
    parser.add_argument(
        "--structure-min-digits",
        type=int,
        default=2,
        help="Minimum digits in structure IDs (default: 2)",
    )
    parser.add_argument(
        "--structure-max-digits",
        type=int,
        default=5,
        help="Maximum digits in structure IDs (default: 5)",
    )
    parser.add_argument(
        "--structure-hue-tolerance",
        type=int,
        default=14,
        help="Hue tolerance for matching ID text color to symbol color (default: 14)",
    )
    parser.add_argument(
        "--structure-include-floodgates",
        action="store_true",
        help="Include floodgates (circles marked with F) in structure output",
    )
    parser.add_argument(
        "--control-points-csv",
        type=Path,
        default=None,
        help="Control points CSV for georeferencing (pixel_x,pixel_y,map_x,map_y or lon,lat)",
    )
    parser.add_argument(
        "--target-crs",
        default="EPSG:4326",
        help="CRS for control point map coordinates (default: EPSG:4326)",
    )
    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()

    try:
        run_pipeline(
            args.pdf,
            drain_hints=args.drain_hint,
            branch_parent=args.branch_parent,
            branch_codes=args.branch_code,
            extract_structures=args.extract_structures,
            structure_min_id_confidence=args.structure_min_id_confidence,
            structure_include_empty_ids=args.structure_include_empty_ids,
            structure_min_digits=args.structure_min_digits,
            structure_max_digits=args.structure_max_digits,
            structure_hue_tolerance=args.structure_hue_tolerance,
            structure_include_floodgates=args.structure_include_floodgates,
            control_points_csv=args.control_points_csv,
            target_crs=args.target_crs,
        )
    except Exception as exc:
        print(f"Pipeline failed: {exc}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
