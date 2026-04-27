from __future__ import annotations

import argparse
from pathlib import Path

import fitz  # PyMuPDF


def rotate_pdf_in_place(pdf_path: Path, degrees: int) -> None:
    pdf_path = pdf_path.resolve()
    if not pdf_path.exists():
        raise FileNotFoundError(f"PDF not found: {pdf_path}")
    if degrees not in (90, -90, 180):
        raise ValueError("degrees must be one of: 90, -90, 180")

    rotation_step = int(degrees)
    doc = fitz.open(pdf_path)
    try:
        for page in doc:
            page.set_rotation((int(page.rotation) + rotation_step) % 360)
        tmp = pdf_path.with_suffix(".rotating.tmp.pdf")
        doc.save(tmp)
    finally:
        doc.close()

    tmp.replace(pdf_path)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Rotate all pages of a PDF in place.")
    parser.add_argument("pdf_path", type=Path, help="Path to PDF file")
    parser.add_argument("--degrees", type=int, required=True, help="Rotation degrees: 90, -90, or 180")
    return parser


def main() -> int:
    args = build_parser().parse_args()
    try:
        rotate_pdf_in_place(args.pdf_path, args.degrees)
        print(f"Rotated PDF: {args.pdf_path} ({args.degrees} degrees)")
    except Exception as exc:
        print(f"Error: {exc}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

