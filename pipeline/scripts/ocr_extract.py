from __future__ import annotations

import argparse
import os
import re
import shutil
from pathlib import Path
from typing import Iterable

import cv2
import pytesseract

PROJECT_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_TEXT_OUTPUT_DIR = PROJECT_ROOT / "outputs" / "text"


def ensure_directory(path: Path) -> Path:
    """Create a directory if it does not already exist."""
    path.mkdir(parents=True, exist_ok=True)
    return path


def configure_tesseract() -> Path:
    """Resolve and configure the Tesseract executable for pytesseract."""
    candidates: list[Path] = []

    env_cmd = os.environ.get("TESSERACT_CMD")
    if env_cmd:
        candidates.append(Path(env_cmd))

    which_cmd = shutil.which("tesseract")
    if which_cmd:
        candidates.append(Path(which_cmd))

    # Common Windows install locations.
    candidates.extend(
        [
            Path(r"C:\Program Files\Tesseract-OCR\tesseract.exe"),
            Path(r"C:\Program Files (x86)\Tesseract-OCR\tesseract.exe"),
        ]
    )

    for candidate in candidates:
        resolved = candidate.expanduser().resolve()
        if resolved.exists():
            pytesseract.pytesseract.tesseract_cmd = str(resolved)
            return resolved

    raise RuntimeError(
        "Tesseract executable was not found. Install Tesseract OCR and either: "
        "1) add it to PATH and restart your terminal, or 2) set TESSERACT_CMD "
        "to the full tesseract.exe path."
    )


def extract_page_number(path: Path) -> int:
    """Return page number from filename like page_001.png; fallback to a high value."""
    match = re.search(r"page_(\d+)", path.stem.lower())
    return int(match.group(1)) if match else 10**9


def extract_map_stem(path: Path) -> str:
    """Return map stem from image filename like <map>_page_001.png."""
    match = re.match(r"(.+)_page_\d+$", path.stem, flags=re.IGNORECASE)
    return match.group(1) if match else path.stem


def preprocess_image(image_path: Path):
    """Load image and apply grayscale + thresholding for OCR."""
    image = cv2.imread(str(image_path))
    if image is None:
        raise RuntimeError(f"Could not read image: {image_path}")

    grayscale = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    thresholded = cv2.threshold(
        grayscale,
        0,
        255,
        cv2.THRESH_BINARY + cv2.THRESH_OTSU,
    )[1]
    return thresholded


def collect_image_paths(images: Iterable[Path]) -> list[Path]:
    paths = [Path(p).resolve() for p in images]
    if not paths:
        raise ValueError("No image paths were provided for OCR.")
    missing = [str(path) for path in paths if not path.exists()]
    if missing:
        raise FileNotFoundError(f"Missing image files: {', '.join(missing)}")

    return sorted(paths, key=lambda p: (extract_page_number(p), p.name.lower()))


def refresh_global_combined_ocr(text_output_dir: Path) -> Path:
    """Rebuild global combined OCR file from all per-map OCR text files."""
    map_combined_files = sorted(
        path
        for path in text_output_dir.glob("*_ocr_text.txt")
        if path.name.lower() != "ocr_text.txt"
    )

    sections: list[str] = []
    for map_file in map_combined_files:
        map_name = map_file.stem.replace("_ocr_text", "")
        content = map_file.read_text(encoding="utf-8").strip()
        if not content:
            continue
        sections.append(f"=== MAP: {map_name} ===\n{content}")

    global_file = text_output_dir / "ocr_text.txt"
    global_file.write_text("\n\n".join(sections) + ("\n" if sections else ""), encoding="utf-8")
    return global_file


def extract_text_from_images(
    image_paths: Iterable[Path],
    text_output_dir: Path = DEFAULT_TEXT_OUTPUT_DIR,
) -> dict[str, Path | list[Path]]:
    """Run OCR for image paths and write per-page and combined text outputs."""
    tesseract_cmd = configure_tesseract()
    sorted_paths = collect_image_paths(image_paths)
    text_output_dir = ensure_directory(text_output_dir.resolve())

    page_files: list[Path] = []
    combined_blocks: list[str] = []
    total = len(sorted_paths)
    map_stem = extract_map_stem(sorted_paths[0])
    if any(extract_map_stem(path) != map_stem for path in sorted_paths):
        raise ValueError("All image paths must belong to the same map when running OCR extraction.")

    print(f"Running OCR for {total} page image(s)...")
    print(f"Using Tesseract executable: {tesseract_cmd}")
    for index, image_path in enumerate(sorted_paths, start=1):
        page_number = extract_page_number(image_path)
        if page_number == 10**9:
            page_number = index

        processed = preprocess_image(image_path)
        text = pytesseract.image_to_string(processed).strip()

        page_file = text_output_dir / f"{map_stem}_page_{page_number:03d}.txt"
        page_file.write_text(text + "\n", encoding="utf-8")
        page_files.append(page_file)

        combined_blocks.append(f"--- PAGE {page_number} ---\n{text}")
        print(f"  [{index}/{total}] Saved {page_file.name}")

    combined_file = text_output_dir / f"{map_stem}_ocr_text.txt"
    combined_file.write_text("\n\n".join(combined_blocks) + "\n", encoding="utf-8")
    global_combined_file = refresh_global_combined_ocr(text_output_dir)

    print(f"Finished OCR map file: {combined_file}")
    print(f"Updated global combined file: {global_combined_file}")
    return {"combined_file": combined_file, "global_combined_file": global_combined_file, "page_files": page_files}


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Run OCR extraction from page images.")
    parser.add_argument(
        "image_paths",
        nargs="+",
        type=Path,
        help="Ordered image paths to process (e.g., outputs/images/page_001.png ...)",
    )
    parser.add_argument(
        "--output-dir",
        type=Path,
        default=DEFAULT_TEXT_OUTPUT_DIR,
        help="Directory for OCR text outputs",
    )
    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()

    try:
        extract_text_from_images(args.image_paths, text_output_dir=args.output_dir)
    except Exception as exc:
        print(f"Error: {exc}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
