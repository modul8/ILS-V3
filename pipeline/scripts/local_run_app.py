from __future__ import annotations

import csv
import shutil
import traceback
from pathlib import Path
import tkinter as tk
from tkinter import filedialog, messagebox, ttk

from georef_map import georeference_image
from label_control_points import capture_control_points
from label_structure_points import capture_points as capture_structure_points
from pdf_to_images import convert_pdf_to_images

PROJECT_ROOT = Path(__file__).resolve().parent.parent
INPUT_DIR = PROJECT_ROOT / "input_pdfs"
IMAGE_DIR = PROJECT_ROOT / "outputs" / "images"


def ensure_header(path: Path, fieldnames: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.exists() and path.stat().st_size > 0:
        return
    with path.open("w", encoding="utf-8", newline="") as file:
        writer = csv.DictWriter(file, fieldnames=fieldnames)
        writer.writeheader()


def init_map_inputs(pdf_path: Path, overwrite: bool = False) -> tuple[Path, Path]:
    stem = pdf_path.stem
    control_csv = INPUT_DIR / f"{stem}_control_points.csv"
    truth_csv = INPUT_DIR / f"{stem}_structure_truth.csv"

    if overwrite:
        if control_csv.exists():
            control_csv.unlink()
        if truth_csv.exists():
            truth_csv.unlink()

    ensure_header(control_csv, ["pixel_x", "pixel_y", "lon", "lat", "asset_type", "asset_id", "label"])
    ensure_header(truth_csv, ["structure_id", "structure_type", "pixel_x", "pixel_y", "lon", "lat"])
    return control_csv, truth_csv


class LocalRunApp(tk.Tk):
    def __init__(self) -> None:
        super().__init__()
        self.title("Drain Map Pipeline - Local Runner")
        self.geometry("980x620")
        self.minsize(920, 560)

        self.selected_pdf_var = tk.StringVar()
        self.overwrite_control_var = tk.BooleanVar(value=True)
        self.overwrite_struct_var = tk.BooleanVar(value=False)
        self.status_var = tk.StringVar(value="Ready")

        self._build_ui()
        self.refresh_pdf_list()

    def _build_ui(self) -> None:
        root = ttk.Frame(self, padding=14)
        root.pack(fill=tk.BOTH, expand=True)

        title = ttk.Label(root, text="Drain Map Pipeline - Local Runner", font=("Segoe UI", 15, "bold"))
        title.pack(anchor=tk.W, pady=(0, 10))

        picker = ttk.LabelFrame(root, text="Map Selection", padding=10)
        picker.pack(fill=tk.X, pady=(0, 10))

        row = ttk.Frame(picker)
        row.pack(fill=tk.X)
        ttk.Label(row, text="Map PDF:", width=12).pack(side=tk.LEFT)
        self.pdf_combo = ttk.Combobox(row, textvariable=self.selected_pdf_var, state="readonly", width=72)
        self.pdf_combo.pack(side=tk.LEFT, padx=(0, 8), fill=tk.X, expand=True)
        ttk.Button(row, text="Refresh", command=self.refresh_pdf_list).pack(side=tk.LEFT)

        row2 = ttk.Frame(picker)
        row2.pack(fill=tk.X, pady=(8, 0))
        ttk.Button(row2, text="Import PDF...", command=self.import_pdf).pack(side=tk.LEFT)
        ttk.Button(row2, text="Open input_pdfs", command=self.open_input_folder).pack(side=tk.LEFT, padx=(8, 0))
        ttk.Button(row2, text="Open outputs/images", command=self.open_images_folder).pack(side=tk.LEFT, padx=(8, 0))

        steps = ttk.LabelFrame(root, text="Workflow", padding=10)
        steps.pack(fill=tk.BOTH, expand=True)

        ttk.Button(steps, text="1) Initialize Map Inputs (empty CSV headers)", command=self.step_init_inputs).pack(fill=tk.X, pady=3)
        ttk.Button(steps, text="2) Convert PDF to PNG", command=self.step_convert_pdf).pack(fill=tk.X, pady=3)

        cp_row = ttk.Frame(steps)
        cp_row.pack(fill=tk.X, pady=3)
        ttk.Button(cp_row, text="3) Capture Control Points (click map + enter lon/lat)", command=self.step_capture_control_points).pack(side=tk.LEFT, fill=tk.X, expand=True)
        ttk.Checkbutton(cp_row, text="Overwrite control CSV", variable=self.overwrite_control_var).pack(side=tk.LEFT, padx=(8, 0))

        ttk.Button(steps, text="4) Georeference Raster (.pgw/.prj)", command=self.step_georef_raster).pack(fill=tk.X, pady=3)

        sp_row = ttk.Frame(steps)
        sp_row.pack(fill=tk.X, pady=3)
        ttk.Button(sp_row, text="5) Capture Structure Truths (auto lon/lat from georef)", command=self.step_capture_structures).pack(side=tk.LEFT, fill=tk.X, expand=True)
        ttk.Checkbutton(sp_row, text="Overwrite structure CSV", variable=self.overwrite_struct_var).pack(side=tk.LEFT, padx=(8, 0))

        ttk.Button(steps, text="6) Build Structure Georef CSV + GeoJSON", command=self.step_georef_structures).pack(fill=tk.X, pady=3)
        ttk.Button(steps, text="7) Export Map Outputs...", command=self.step_export_outputs).pack(fill=tk.X, pady=3)

        notes = ttk.LabelFrame(root, text="Notes", padding=10)
        notes.pack(fill=tk.BOTH, expand=True, pady=(10, 0))
        note_text = (
            "- Step 5 auto-fills lon/lat from the map world file (.pgw).\n"
            "- Steps 4 and 6 require at least 3 valid control points in the control CSV.\n"
            "- Outputs can later be used for work-order pin import."
        )
        ttk.Label(notes, text=note_text, justify=tk.LEFT).pack(anchor=tk.W)

        status_bar = ttk.Label(root, textvariable=self.status_var, relief=tk.SUNKEN, anchor=tk.W)
        status_bar.pack(fill=tk.X, pady=(10, 0))

    def set_status(self, text: str) -> None:
        self.status_var.set(text)
        self.update_idletasks()

    def run_step(self, label: str, fn) -> None:
        try:
            self.set_status(f"{label}...")
            fn()
            self.set_status(f"{label} complete")
        except Exception as exc:
            self.set_status(f"{label} failed")
            messagebox.showerror("Error", f"{exc}\n\n{traceback.format_exc()}")

    def refresh_pdf_list(self) -> None:
        INPUT_DIR.mkdir(parents=True, exist_ok=True)
        pdfs = sorted(p.name for p in INPUT_DIR.glob("*.pdf"))
        self.pdf_combo["values"] = pdfs
        if pdfs and self.selected_pdf_var.get() not in pdfs:
            self.selected_pdf_var.set(pdfs[0])
        if not pdfs:
            self.selected_pdf_var.set("")

    def current_pdf(self) -> Path:
        name = self.selected_pdf_var.get().strip()
        if not name:
            raise RuntimeError("No map PDF selected.")
        path = INPUT_DIR / name
        if not path.exists():
            raise RuntimeError(f"Selected PDF not found: {path}")
        return path

    def map_stem(self) -> str:
        return self.current_pdf().stem

    def map_image_path(self) -> Path:
        path = IMAGE_DIR / f"{self.map_stem()}_page_001.png"
        if not path.exists():
            raise RuntimeError(f"Map image not found. Run conversion first.\nMissing: {path}")
        return path

    def control_csv_path(self) -> Path:
        return INPUT_DIR / f"{self.map_stem()}_control_points.csv"

    def structure_csv_path(self) -> Path:
        return INPUT_DIR / f"{self.map_stem()}_structure_truth.csv"

    def import_pdf(self) -> None:
        source = filedialog.askopenfilename(
            title="Select map PDF",
            filetypes=[("PDF files", "*.pdf"), ("All files", "*.*")],
        )
        if not source:
            return
        src = Path(source)
        dest = INPUT_DIR / src.name
        INPUT_DIR.mkdir(parents=True, exist_ok=True)
        if dest.exists():
            if not messagebox.askyesno("Overwrite?", f"{dest.name} already exists. Replace it?"):
                return
        shutil.copy2(src, dest)
        self.refresh_pdf_list()
        self.selected_pdf_var.set(dest.name)
        self.set_status(f"Imported {dest.name}")

    def open_input_folder(self) -> None:
        INPUT_DIR.mkdir(parents=True, exist_ok=True)
        try:
            import os
            os.startfile(str(INPUT_DIR))  # type: ignore[attr-defined]
        except Exception:
            messagebox.showinfo("Folder", str(INPUT_DIR))

    def open_images_folder(self) -> None:
        IMAGE_DIR.mkdir(parents=True, exist_ok=True)
        try:
            import os
            os.startfile(str(IMAGE_DIR))  # type: ignore[attr-defined]
        except Exception:
            messagebox.showinfo("Folder", str(IMAGE_DIR))

    def step_init_inputs(self) -> None:
        def _do() -> None:
            pdf = self.current_pdf()
            control_csv, truth_csv = init_map_inputs(pdf, overwrite=True)
            messagebox.showinfo("Initialized", f"Initialized:\n{control_csv}\n{truth_csv}")

        self.run_step("Initialize inputs", _do)

    def step_convert_pdf(self) -> None:
        def _do() -> None:
            pdf = self.current_pdf()
            convert_pdf_to_images(pdf_path=pdf, output_dir=IMAGE_DIR, dpi=300)

        self.run_step("Convert PDF", _do)

    def step_capture_control_points(self) -> None:
        def _do() -> None:
            image = self.map_image_path()
            control_csv = self.control_csv_path()
            init_map_inputs(self.current_pdf(), overwrite=False)
            capture_control_points(
                image_path=image,
                output_csv=control_csv,
                scale=0.25,
                overwrite=self.overwrite_control_var.get(),
            )

        self.run_step("Capture control points", _do)

    def step_georef_raster(self) -> None:
        def _do() -> None:
            image = self.map_image_path()
            control_csv = self.control_csv_path()
            georeference_image(image_path=image, control_points_csv=control_csv, target_crs="EPSG:4326")

        self.run_step("Georeference raster", _do)

    def step_capture_structures(self) -> None:
        def _do() -> None:
            image = self.map_image_path()
            structures_csv = self.structure_csv_path()
            init_map_inputs(self.current_pdf(), overwrite=False)
            capture_structure_points(
                image_path=image,
                output_csv=structures_csv,
                scale=0.25,
                overwrite=self.overwrite_struct_var.get(),
                auto_lon_lat_from_world=True,
            )

        self.run_step("Capture structures", _do)

    def step_georef_structures(self) -> None:
        def _do() -> None:
            image = self.map_image_path()
            control_csv = self.control_csv_path()
            structures_csv = self.structure_csv_path()
            results = georeference_image(
                image_path=image,
                control_points_csv=control_csv,
                target_crs="EPSG:4326",
                structures_csv=structures_csv,
            )
            geojson = results.get("structures_geojson")
            if geojson:
                messagebox.showinfo("Done", f"GeoJSON created:\n{geojson}")

        self.run_step("Georeference structures", _do)

    def step_export_outputs(self) -> None:
        def _do() -> None:
            export_dir = filedialog.askdirectory(title="Select export destination")
            if not export_dir:
                return
            target = Path(export_dir)
            stem = self.map_stem()
            copied = 0

            candidates = [
                INPUT_DIR / f"{stem}_control_points.csv",
                INPUT_DIR / f"{stem}_structure_truth.csv",
                INPUT_DIR / f"{stem}_structure_truth_georef.csv",
                INPUT_DIR / f"{stem}_structure_truth_georef.geojson",
                IMAGE_DIR / f"{stem}_page_001.png",
                IMAGE_DIR / f"{stem}_page_001.pgw",
                IMAGE_DIR / f"{stem}_page_001.prj",
            ]
            for src in candidates:
                if src.exists():
                    shutil.copy2(src, target / src.name)
                    copied += 1
            messagebox.showinfo("Export complete", f"Exported {copied} files to:\n{target}")

        self.run_step("Export outputs", _do)


def main() -> int:
    app = LocalRunApp()
    app.mainloop()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
