from __future__ import annotations

import argparse
import csv
from pathlib import Path


def write_header(path: Path, fieldnames: list[str], force: bool) -> None:
    if path.exists() and not force:
        print(f"Exists, skipped: {path}")
        return
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as file:
        writer = csv.DictWriter(file, fieldnames=fieldnames)
        writer.writeheader()
    print(f"Created: {path}")


def map_stem_from_pdf(pdf_path: Path) -> str:
    if pdf_path.suffix.lower() != ".pdf":
        raise ValueError(f"Expected .pdf file, got: {pdf_path.name}")
    return pdf_path.stem


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Create map-specific control_points and structure_truth CSV files with headers only."
    )
    parser.add_argument("pdf", type=Path, help="Map PDF path (for example input_pdfs/Map 9 - Shire of Dardanup.pdf)")
    parser.add_argument(
        "--force",
        action="store_true",
        help="Overwrite existing map-specific CSV files",
    )
    args = parser.parse_args()

    pdf_path = args.pdf.resolve()
    if not pdf_path.exists():
        print(f"Error: PDF not found: {pdf_path}")
        return 1

    stem = map_stem_from_pdf(pdf_path)
    input_dir = pdf_path.parent

    control_csv = input_dir / f"{stem}_control_points.csv"
    truth_csv = input_dir / f"{stem}_structure_truth.csv"

    write_header(control_csv, ["pixel_x", "pixel_y", "lon", "lat", "asset_type", "asset_id", "label"], args.force)
    write_header(
        truth_csv,
        ["structure_id", "structure_type", "pixel_x", "pixel_y", "lon", "lat"],
        args.force,
    )

    print("Done. These files are intentionally empty (header only).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
