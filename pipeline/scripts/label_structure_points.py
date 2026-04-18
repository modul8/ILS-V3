from __future__ import annotations

import argparse
import csv
from pathlib import Path
from typing import Any

import cv2


def ensure_csv_header(path: Path) -> None:
    if path.exists() and path.stat().st_size > 0:
        return
    with path.open("w", encoding="utf-8", newline="") as file:
        writer = csv.DictWriter(
            file,
            fieldnames=["structure_id", "structure_type", "pixel_x", "pixel_y", "lon", "lat"],
        )
        writer.writeheader()


def count_csv_rows(path: Path) -> int:
    if not path.exists() or path.stat().st_size == 0:
        return 0
    with path.open("r", encoding="utf-8-sig", newline="") as file:
        reader = csv.DictReader(file)
        return sum(1 for _ in reader)


def reset_csv(path: Path) -> None:
    with path.open("w", encoding="utf-8", newline="") as file:
        writer = csv.DictWriter(
            file,
            fieldnames=["structure_id", "structure_type", "pixel_x", "pixel_y", "lon", "lat"],
        )
        writer.writeheader()


def worldfile_path_for_image(image_path: Path) -> Path:
    suffix = image_path.suffix.lower()
    if suffix == ".png":
        return image_path.with_suffix(".pgw")
    if suffix in {".jpg", ".jpeg"}:
        return image_path.with_suffix(".jgw")
    return image_path.with_suffix(".wld")


def load_world_affine(image_path: Path) -> tuple[float, float, float, float, float, float] | None:
    world_path = worldfile_path_for_image(image_path)
    if not world_path.exists():
        return None
    lines = [line.strip() for line in world_path.read_text(encoding="utf-8").splitlines() if line.strip()]
    if len(lines) < 6:
        return None
    try:
        a = float(lines[0])
        d = float(lines[1])
        b = float(lines[2])
        e = float(lines[3])
        c = float(lines[4])
        f = float(lines[5])
    except Exception:
        return None
    return a, b, c, d, e, f


def pixel_to_map(
    affine: tuple[float, float, float, float, float, float],
    pixel_x: float,
    pixel_y: float,
) -> tuple[float, float]:
    a, b, c, d, e, f = affine
    map_x = a * pixel_x + b * pixel_y + c
    map_y = d * pixel_x + e * pixel_y + f
    return map_x, map_y


def append_point(
    path: Path,
    pixel_x: int,
    pixel_y: int,
    structure_id: str,
    structure_type: str,
    lon: str = "",
    lat: str = "",
) -> None:
    with path.open("a", encoding="utf-8", newline="") as file:
        writer = csv.DictWriter(
            file,
            fieldnames=["structure_id", "structure_type", "pixel_x", "pixel_y", "lon", "lat"],
        )
        writer.writerow(
            {
                "structure_id": structure_id.strip(),
                "structure_type": structure_type.strip().lower(),
                "pixel_x": pixel_x,
                "pixel_y": pixel_y,
                "lon": lon.strip(),
                "lat": lat.strip(),
            }
        )


def clamp(value: float, lower: float, upper: float) -> float:
    return max(lower, min(upper, value))


def map_stem_from_image_path(image_path: Path) -> str:
    stem = image_path.stem
    lower = stem.lower()
    if "_page_" in lower:
        idx = lower.rfind("_page_")
        return stem[:idx]
    return stem


def default_truth_csv_for_image(image_path: Path) -> Path:
    map_stem = map_stem_from_image_path(image_path)
    return Path("input_pdfs") / f"{map_stem}_structure_truth.csv"


def prompt_structure_details(
    default_type: str = "",
    default_lon: str = "",
    default_lat: str = "",
) -> tuple[str, str, str, str] | None:
    try:
        import tkinter as tk
        from tkinter import simpledialog
    except Exception:
        print("Tkinter is unavailable; using terminal input fallback.")
        structure_id = input("Enter structure_id (blank to skip): ").strip()
        if not structure_id:
            return None
        structure_type = input("Enter structure_type [culvert/bridge/floodgate]: ").strip().lower()
        lon_prompt = f"Enter longitude (optional, blank if unknown) [{default_lon}]: " if default_lon else "Enter longitude (optional, blank if unknown): "
        lat_prompt = f"Enter latitude (optional, blank if unknown) [{default_lat}]: " if default_lat else "Enter latitude (optional, blank if unknown): "
        lon = input(lon_prompt).strip() or default_lon
        lat = input(lat_prompt).strip() or default_lat
        return structure_id, structure_type, lon, lat

    class StructureDialog(simpledialog.Dialog):
        def __init__(self, parent: Any, title: str) -> None:
            self.structure_id_var = tk.StringVar()
            self.structure_type_var = tk.StringVar(value=default_type or "culvert")
            self.lon_var = tk.StringVar(value=default_lon)
            self.lat_var = tk.StringVar(value=default_lat)
            super().__init__(parent, title)

        def body(self, master: Any) -> Any:
            tk.Label(master, text="Structure ID:").grid(row=0, column=0, sticky="w", padx=8, pady=6)
            id_entry = tk.Entry(master, textvariable=self.structure_id_var, width=24)
            id_entry.grid(row=0, column=1, padx=8, pady=6)

            tk.Label(master, text="Structure Type:").grid(row=1, column=0, sticky="w", padx=8, pady=6)
            type_menu = tk.OptionMenu(master, self.structure_type_var, "culvert", "bridge", "floodgate")
            type_menu.config(width=20)
            type_menu.grid(row=1, column=1, padx=8, pady=6, sticky="w")

            tk.Label(master, text="Longitude (optional):").grid(row=2, column=0, sticky="w", padx=8, pady=6)
            lon_entry = tk.Entry(master, textvariable=self.lon_var, width=24)
            lon_entry.grid(row=2, column=1, padx=8, pady=6)

            tk.Label(master, text="Latitude (optional):").grid(row=3, column=0, sticky="w", padx=8, pady=6)
            lat_entry = tk.Entry(master, textvariable=self.lat_var, width=24)
            lat_entry.grid(row=3, column=1, padx=8, pady=6)
            return id_entry

        def validate(self) -> bool:
            value = self.structure_id_var.get().strip()
            if not value:
                return False
            return True

        def apply(self) -> None:
            self.result = (
                self.structure_id_var.get().strip(),
                self.structure_type_var.get().strip().lower(),
                self.lon_var.get().strip(),
                self.lat_var.get().strip(),
            )

    root = tk.Tk()
    root.withdraw()
    root.attributes("-topmost", True)
    dialog = StructureDialog(root, "Structure Details")
    root.destroy()
    return dialog.result


def capture_points(
    image_path: Path,
    output_csv: Path,
    scale: float = 0.25,
    overwrite: bool = False,
    auto_lon_lat_from_world: bool = False,
) -> None:
    image = cv2.imread(str(image_path))
    if image is None:
        raise RuntimeError(f"Could not read image: {image_path}")
    if scale <= 0:
        raise ValueError("Scale must be > 0.")

    affine = load_world_affine(image_path) if auto_lon_lat_from_world else None
    if auto_lon_lat_from_world and affine is None:
        raise RuntimeError(
            f"Auto lon/lat requested, but no valid world file found for image: {worldfile_path_for_image(image_path)}"
        )

    if overwrite:
        reset_csv(output_csv)
    else:
        ensure_csv_header(output_csv)
    existing_rows = count_csv_rows(output_csv)

    height, width = image.shape[:2]
    window_width = min(1400, max(900, int(width * min(1.0, scale))))
    window_height = min(900, max(600, int(height * min(1.0, scale))))

    window_name = "Click Points (Left click save | +/- zoom | WASD/arrows pan | Q quit)"
    cv2.namedWindow(window_name, cv2.WINDOW_NORMAL)
    cv2.resizeWindow(window_name, window_width, window_height)

    state = {
        "count": 0,
        "zoom": 1.0,
        "offset_x": 0.0,
        "offset_y": 0.0,
        "effective_scale": scale,
        "last_point": None,
        "last_type": "culvert",
    }

    def recenter_viewport() -> None:
        source_w = max(1.0, window_width / state["effective_scale"])
        source_h = max(1.0, window_height / state["effective_scale"])
        max_x = max(0.0, width - source_w)
        max_y = max(0.0, height - source_h)
        state["offset_x"] = clamp(state["offset_x"], 0.0, max_x)
        state["offset_y"] = clamp(state["offset_y"], 0.0, max_y)

    def update_scale(new_zoom: float) -> None:
        old_scale = state["effective_scale"]
        center_x = state["offset_x"] + (window_width / max(old_scale, 1e-6)) / 2.0
        center_y = state["offset_y"] + (window_height / max(old_scale, 1e-6)) / 2.0
        state["zoom"] = clamp(new_zoom, 0.25, 12.0)
        state["effective_scale"] = scale * state["zoom"]
        source_w = max(1.0, window_width / state["effective_scale"])
        source_h = max(1.0, window_height / state["effective_scale"])
        state["offset_x"] = center_x - source_w / 2.0
        state["offset_y"] = center_y - source_h / 2.0
        recenter_viewport()

    def on_mouse(event: int, x: int, y: int, flags: int, param: object) -> None:
        if event == cv2.EVENT_LBUTTONDOWN:
            pixel_x = int(round(state["offset_x"] + x / state["effective_scale"]))
            pixel_y = int(round(state["offset_y"] + y / state["effective_scale"]))
            pixel_x = max(0, min(width - 1, pixel_x))
            pixel_y = max(0, min(height - 1, pixel_y))
            default_lon = ""
            default_lat = ""
            if affine is not None:
                lon_val, lat_val = pixel_to_map(affine, pixel_x, pixel_y)
                default_lon = f"{lon_val:.6f}"
                default_lat = f"{lat_val:.6f}"

            details = prompt_structure_details(
                default_type=state["last_type"],
                default_lon=default_lon,
                default_lat=default_lat,
            )
            if details is None:
                print("Point skipped.")
                return
            structure_id, structure_type, lon, lat = details
            append_point(
                output_csv,
                pixel_x,
                pixel_y,
                structure_id=structure_id,
                structure_type=structure_type,
                lon=lon,
                lat=lat,
            )
            state["count"] += 1
            state["last_type"] = structure_type
            state["last_point"] = (pixel_x, pixel_y)
            print(
                f"Saved point #{state['count']}: "
                f"id={structure_id}, type={structure_type}, pixel_x={pixel_x}, pixel_y={pixel_y}, "
                f"lon={lon or '-'}, lat={lat or '-'}"
            )
        elif event == cv2.EVENT_MOUSEWHEEL:
            if flags > 0:
                update_scale(state["zoom"] * 1.15)
            else:
                update_scale(state["zoom"] / 1.15)

    cv2.setMouseCallback(window_name, on_mouse)

    print(f"Image: {image_path}")
    print(f"Output CSV: {output_csv}")
    print(f"Existing rows in CSV: {existing_rows}")
    if affine is not None:
        print(f"Auto lon/lat from world file: {worldfile_path_for_image(image_path)}")
    print("Left click opens a details box (ID + type) and saves on OK.")
    print("Mouse wheel or +/- to zoom. WASD or arrows to pan. Press Q to quit.")

    while True:
        source_w = max(1, int(round(window_width / state["effective_scale"])))
        source_h = max(1, int(round(window_height / state["effective_scale"])))
        recenter_viewport()

        x1 = int(round(state["offset_x"]))
        y1 = int(round(state["offset_y"]))
        x2 = min(width, x1 + source_w)
        y2 = min(height, y1 + source_h)
        crop = image[y1:y2, x1:x2]
        if crop.size == 0:
            break

        display = cv2.resize(crop, (window_width, window_height), interpolation=cv2.INTER_LINEAR)

        status = f"Saved: {state['count']} | Zoom: {state['zoom']:.2f}x | Offset: ({x1},{y1})"
        cv2.putText(
            display,
            status,
            (12, 28),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.65,
            (0, 0, 255),
            2,
            cv2.LINE_AA,
        )
        if state["last_point"] is not None:
            last_x, last_y = state["last_point"]
            cv2.putText(
                display,
                f"Last point: ({last_x}, {last_y})",
                (12, 56),
                cv2.FONT_HERSHEY_SIMPLEX,
                0.6,
                (0, 0, 255),
                2,
                cv2.LINE_AA,
            )
        cv2.imshow(window_name, display)
        key = cv2.waitKeyEx(30)
        key_low = key & 0xFF
        if key_low in (ord("q"), 27):
            break
        if key_low in (ord("+"), ord("=")):
            update_scale(state["zoom"] * 1.15)
        elif key_low in (ord("-"), ord("_")):
            update_scale(state["zoom"] / 1.15)
        else:
            step_x = max(20.0, source_w * 0.15)
            step_y = max(20.0, source_h * 0.15)
            if key_low == ord("a") or key == 2424832:
                state["offset_x"] -= step_x
            elif key_low == ord("d") or key == 2555904:
                state["offset_x"] += step_x
            elif key_low == ord("w") or key == 2490368:
                state["offset_y"] -= step_y
            elif key_low == ord("s") or key == 2621440:
                state["offset_y"] += step_y

    cv2.destroyAllWindows()


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Capture pixel x/y points from a map image by clicking.")
    parser.add_argument("image_path", type=Path, help="Path to map PNG image")
    parser.add_argument(
        "--output-csv",
        type=Path,
        default=None,
        help="CSV to append clicked points (default: input_pdfs/<map_name>_structure_truth.csv)",
    )
    parser.add_argument(
        "--scale",
        type=float,
        default=0.25,
        help="Display scale for the image window (default: 0.25)",
    )
    parser.add_argument(
        "--overwrite",
        action="store_true",
        help="Reset output CSV to header before capturing clicks",
    )
    parser.add_argument(
        "--auto-lon-lat-from-world",
        action="store_true",
        help="Auto-fill lon/lat from image world file (.pgw/.jgw/.wld)",
    )
    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    try:
        image_path = args.image_path.resolve()
        output_csv = args.output_csv.resolve() if args.output_csv else default_truth_csv_for_image(image_path).resolve()
        capture_points(
            image_path,
            output_csv,
            scale=args.scale,
            overwrite=args.overwrite,
            auto_lon_lat_from_world=args.auto_lon_lat_from_world,
        )
    except Exception as exc:
        print(f"Error: {exc}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
