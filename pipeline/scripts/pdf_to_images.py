from __future__ import annotations

import argparse
from pathlib import Path

import fitz

PROJECT_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_IMAGE_OUTPUT_DIR = PROJECT_ROOT / "outputs" / "images"


def ensure_directory(path: Path) -> Path:
    """Create a directory if it does not already exist."""
    path.mkdir(parents=True, exist_ok=True)
    return path


def convert_pdf_to_images(
    pdf_path: Path,
    output_dir: Path = DEFAULT_IMAGE_OUTPUT_DIR,
    dpi: int = 300,
) -> list[Path]:
    """Convert all pages of a PDF into PNG files and return generated image paths."""
    pdf_path = pdf_path.resolve()
    output_dir = ensure_directory(output_dir.resolve())

    if not pdf_path.exists():
        raise FileNotFoundError(f"PDF not found: {pdf_path}")
    if pdf_path.suffix.lower() != ".pdf":
        raise ValueError(f"Input must be a PDF file: {pdf_path}")

    scale = dpi / 72.0
    matrix = fitz.Matrix(scale, scale)

    generated_paths: list[Path] = []

    try:
        with fitz.open(pdf_path) as document:
            total_pages = document.page_count
            if total_pages == 0:
                raise RuntimeError(f"No pages were found in PDF: {pdf_path}")

            print(f"Converting '{pdf_path.name}' to PNG ({total_pages} pages at {dpi} DPI)...")
            for index in range(total_pages):
                page = document.load_page(index)
                pixmap = page.get_pixmap(matrix=matrix)

                image_path = output_dir / f"{pdf_path.stem}_page_{index + 1:03d}.png"
                pixmap.save(str(image_path))
                generated_paths.append(image_path)
                print(f"  [{index + 1}/{total_pages}] Saved {image_path.name}")

    except Exception as exc:  # pragma: no cover - defensive fallback
        raise RuntimeError(f"Failed to convert PDF '{pdf_path.name}': {exc}") from exc

    print(f"Finished image conversion. Output directory: {output_dir}")
    return generated_paths


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Convert a PDF into ordered PNG page images.")
    parser.add_argument("pdf_path", type=Path, help="Path to input PDF")
    parser.add_argument("--dpi", type=int, default=300, help="Image DPI (default: 300)")
    parser.add_argument(
        "--output-dir",
        type=Path,
        default=DEFAULT_IMAGE_OUTPUT_DIR,
        help="Directory for generated PNG files",
    )
    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()

    try:
        convert_pdf_to_images(pdf_path=args.pdf_path, output_dir=args.output_dir, dpi=args.dpi)
    except Exception as exc:
        print(f"Error: {exc}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
