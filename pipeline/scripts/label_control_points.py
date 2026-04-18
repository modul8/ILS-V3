from __future__ import annotations

import argparse
import csv
import json
import os
from pathlib import Path
from typing import Any
from urllib import error as urlerror
from urllib import parse as urlparse
from urllib import request as urlrequest

import cv2


FIELDS = ["pixel_x", "pixel_y", "lon", "lat", "asset_type", "asset_id", "label"]


def ensure_csv_header(path: Path) -> None:
    if path.exists() and path.stat().st_size > 0:
        return
    with path.open("w", encoding="utf-8", newline="") as file:
        writer = csv.DictWriter(file, fieldnames=FIELDS)
        writer.writeheader()


def reset_csv(path: Path) -> None:
    with path.open("w", encoding="utf-8", newline="") as file:
        writer = csv.DictWriter(file, fieldnames=FIELDS)
        writer.writeheader()


def count_csv_rows(path: Path) -> int:
    if not path.exists() or path.stat().st_size == 0:
        return 0
    with path.open("r", encoding="utf-8-sig", newline="") as file:
        reader = csv.DictReader(file)
        return sum(1 for _ in reader)


def append_point(
    path: Path,
    pixel_x: int,
    pixel_y: int,
    lon: str,
    lat: str,
    asset_type: str,
    asset_id: str,
    label: str,
) -> None:
    with path.open("a", encoding="utf-8", newline="") as file:
        writer = csv.DictWriter(file, fieldnames=FIELDS)
        writer.writerow(
            {
                "pixel_x": pixel_x,
                "pixel_y": pixel_y,
                "lon": lon.strip(),
                "lat": lat.strip(),
                "asset_type": asset_type.strip().lower(),
                "asset_id": asset_id.strip(),
                "label": label.strip(),
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


def default_control_csv_for_image(image_path: Path) -> Path:
    map_stem = map_stem_from_image_path(image_path)
    return Path("input_pdfs") / f"{map_stem}_control_points.csv"


class AssetApiClient:
    def __init__(self, base_url: str, mapping_key: str) -> None:
        self.base_url = base_url.rstrip("/")
        self.mapping_key = mapping_key.strip()
        if not self.base_url or not self.mapping_key:
            raise ValueError("Asset API base URL and mapping key are required.")

    def _request(
        self,
        action: str,
        method: str = "GET",
        params: dict[str, str] | None = None,
        body: dict[str, str] | None = None,
    ) -> dict[str, Any]:
        query = {"action": action}
        if params:
            query.update(params)
        url = f"{self.base_url}/api/index.php?{urlparse.urlencode(query)}"
        data_bytes = None
        headers = {"X-ILS-MAPPING-KEY": self.mapping_key}
        if method.upper() == "POST":
            headers["Content-Type"] = "application/json"
            data_bytes = json.dumps(body or {}).encode("utf-8")
        req = urlrequest.Request(url=url, method=method.upper(), headers=headers, data=data_bytes)
        with urlrequest.urlopen(req, timeout=12) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
        parsed = json.loads(raw) if raw.strip() else {}
        if not isinstance(parsed, dict):
            raise RuntimeError("Unexpected API response shape.")
        return parsed

    def lookup_asset(self, asset_type: str, asset_id: str) -> dict[str, Any]:
        return self._request(
            "mapping_asset_lookup",
            "GET",
            params={"asset_type": asset_type, "asset_id": asset_id},
        )

    def upsert_asset(self, asset_type: str, asset_id: str, lon: str, lat: str) -> dict[str, Any]:
        return self._request(
            "mapping_asset_upsert",
            "POST",
            body={"asset_type": asset_type, "asset_id": asset_id, "lon": lon, "lat": lat},
        )


def build_asset_api_client(base_url: str | None = None, mapping_key: str | None = None) -> AssetApiClient | None:
    base = (base_url or os.environ.get("ILS_V3_API_BASE_URL", "")).strip()
    key = (mapping_key or os.environ.get("ILS_V3_MAPPING_API_KEY", "")).strip()
    if not base or not key:
        return None
    return AssetApiClient(base, key)


def prompt_control_point_details(
    default_type: str = "",
    asset_client: AssetApiClient | None = None,
) -> tuple[str, str, str, str, str, bool] | None:
    try:
        import tkinter as tk
        from tkinter import simpledialog
    except Exception:
        asset_type = input("Asset type [culvert/bridge/floodgate/drain] (required): ").strip().lower()
        asset_id = input("Asset number (optional): ").strip()
        lon = ""
        lat = ""
        found_in_assets = False
        if asset_client and asset_type in ("culvert", "bridge", "floodgate", "drain") and asset_id:
            try:
                result = asset_client.lookup_asset(asset_type=asset_type, asset_id=asset_id)
                if result.get("ok") and result.get("found") and result.get("has_coords"):
                    lon = str(result.get("lon", "")).strip()
                    lat = str(result.get("lat", "")).strip()
                    found_in_assets = True
                    print(f"Auto-filled from assets: lon={lon}, lat={lat}")
            except Exception as exc:
                print(f"Asset lookup failed, using manual coordinates. {exc}")
        if not lon:
            lon = input("Enter longitude (required): ").strip()
        if not lat:
            lat = input("Enter latitude (required): ").strip()
        label = input("Enter optional landmark label: ").strip()
        if not asset_type or asset_type not in ("culvert", "bridge", "floodgate", "drain") or not lon or not lat:
            return None
        return asset_type, asset_id, lon, lat, label, found_in_assets

    class ControlPointDialog(simpledialog.Dialog):
        def __init__(self, parent: Any, title: str) -> None:
            self.asset_type_options = ("culvert", "bridge", "floodgate", "drain")
            self.asset_type_placeholder = "Select asset type..."
            self.asset_client = asset_client
            self.found_in_assets = False
            base_default = (default_type or "").strip().lower()
            if base_default not in self.asset_type_options:
                base_default = self.asset_type_placeholder
            self.asset_type_var = tk.StringVar(value=base_default)
            self.asset_id_var = tk.StringVar()
            self.lon_var = tk.StringVar()
            self.lat_var = tk.StringVar()
            self.label_var = tk.StringVar()
            super().__init__(parent, title)

        def body(self, master: Any) -> Any:
            from tkinter import ttk

            tk.Label(master, text="Asset Type:").grid(row=0, column=0, sticky="w", padx=8, pady=6)
            type_combo = ttk.Combobox(
                master,
                textvariable=self.asset_type_var,
                values=(self.asset_type_placeholder, *self.asset_type_options),
                state="readonly",
                width=22,
            )
            type_combo.grid(row=0, column=1, padx=8, pady=6, sticky="w")
            if self.asset_type_var.get() in (self.asset_type_placeholder, *self.asset_type_options):
                type_combo.set(self.asset_type_var.get())
            else:
                type_combo.set(self.asset_type_placeholder)
            self.type_combo = type_combo

            tk.Label(master, text="Asset Number:").grid(row=1, column=0, sticky="w", padx=8, pady=6)
            id_entry = tk.Entry(master, textvariable=self.asset_id_var, width=24)
            id_entry.grid(row=1, column=1, padx=8, pady=6)
            self.id_entry = id_entry

            tk.Label(master, text="Longitude:").grid(row=2, column=0, sticky="w", padx=8, pady=6)
            lon_entry = tk.Entry(master, textvariable=self.lon_var, width=24)
            lon_entry.grid(row=2, column=1, padx=8, pady=6)
            self.lon_entry = lon_entry

            tk.Label(master, text="Latitude:").grid(row=3, column=0, sticky="w", padx=8, pady=6)
            lat_entry = tk.Entry(master, textvariable=self.lat_var, width=24)
            lat_entry.grid(row=3, column=1, padx=8, pady=6)
            self.lat_entry = lat_entry

            tk.Label(master, text="Landmark Label (optional):").grid(row=4, column=0, sticky="w", padx=8, pady=6)
            label_entry = tk.Entry(master, textvariable=self.label_var, width=24)
            label_entry.grid(row=4, column=1, padx=8, pady=6)
            self.label_entry = label_entry
            return lon_entry

        def validate(self) -> bool:
            from tkinter import messagebox

            self.update_idletasks()
            asset_type_raw = self.type_combo.get().strip()
            asset_type = asset_type_raw.lower()
            asset_id = self.id_entry.get().strip()
            lon = self.lon_entry.get().strip()
            lat = self.lat_entry.get().strip()
            if asset_type not in self.asset_type_options:
                messagebox.showerror("Missing Asset Type", "Select an asset type before saving.")
                return False
            self.found_in_assets = False
            if self.asset_client and asset_id:
                try:
                    result = self.asset_client.lookup_asset(asset_type=asset_type, asset_id=asset_id)
                    if result.get("ok") and result.get("found") and result.get("has_coords"):
                        lon = str(result.get("lon", "")).strip()
                        lat = str(result.get("lat", "")).strip()
                        self.lon_var.set(lon)
                        self.lat_var.set(lat)
                        self.found_in_assets = True
                except Exception as exc:
                    messagebox.showwarning("Asset Lookup Failed", f"Using manual coordinates.\n{exc}")
            if not lon or not lat:
                messagebox.showerror("Missing Coordinates", "Longitude and latitude are required.")
                return False
            try:
                float(lon)
                float(lat)
            except Exception:
                messagebox.showerror("Invalid Coordinates", "Longitude and latitude must be numeric.")
                return False
            return True

        def apply(self) -> None:
            asset_type = self.type_combo.get().strip().lower()
            if asset_type not in self.asset_type_options:
                asset_type = ""
            self.result = (
                asset_type,
                self.id_entry.get().strip(),
                self.lon_entry.get().strip(),
                self.lat_entry.get().strip(),
                self.label_entry.get().strip(),
                self.found_in_assets,
            )

    root = tk.Tk()
    root.withdraw()
    root.attributes("-topmost", True)
    dialog = ControlPointDialog(root, "Control Point Details")
    root.destroy()
    return dialog.result


def capture_control_points(
    image_path: Path,
    output_csv: Path,
    scale: float = 0.25,
    overwrite: bool = False,
    asset_api_base_url: str | None = None,
    asset_api_key: str | None = None,
) -> None:
    image = cv2.imread(str(image_path))
    if image is None:
        raise RuntimeError(f"Could not read image: {image_path}")
    if scale <= 0:
        raise ValueError("Scale must be > 0.")

    if overwrite:
        reset_csv(output_csv)
    else:
        ensure_csv_header(output_csv)
    existing_rows = count_csv_rows(output_csv)
    asset_client = build_asset_api_client(base_url=asset_api_base_url, mapping_key=asset_api_key)

    height, width = image.shape[:2]
    window_width = min(1400, max(900, int(width * min(1.0, scale))))
    window_height = min(900, max(600, int(height * min(1.0, scale))))

    window_name = "Control Points (Left click save | +/- zoom | WASD/arrows pan | Q quit)"
    cv2.namedWindow(window_name, cv2.WINDOW_NORMAL)
    cv2.resizeWindow(window_name, window_width, window_height)

    state = {
        "count": 0,
        "zoom": 1.0,
        "offset_x": 0.0,
        "offset_y": 0.0,
        "effective_scale": scale,
        "last_point": None,
        "last_type": "",
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
            details = prompt_control_point_details(default_type=state["last_type"], asset_client=asset_client)
            if details is None:
                print("Point skipped (lon/lat required).")
                return
            asset_type, asset_id, lon, lat, label, found_in_assets = details
            if not asset_type:
                print("Point skipped (asset type required).")
                return
            append_point(
                output_csv,
                pixel_x,
                pixel_y,
                lon=lon,
                lat=lat,
                asset_type=asset_type,
                asset_id=asset_id,
                label=label,
            )
            state["count"] += 1
            state["last_point"] = (pixel_x, pixel_y)
            state["last_type"] = asset_type or state["last_type"]
            if asset_client and asset_id and not found_in_assets:
                try:
                    upsert = asset_client.upsert_asset(asset_type=asset_type, asset_id=asset_id, lon=lon, lat=lat)
                    if upsert.get("ok"):
                        print(f"Asset list updated: {asset_type} {asset_id}")
                except Exception as exc:
                    print(f"Asset upsert skipped (API error): {exc}")
            print(
                f"Saved control point #{state['count']}: pixel_x={pixel_x}, pixel_y={pixel_y}, "
                f"asset_type={asset_type or '-'}, asset_id={asset_id or '-'}, "
                f"lon={lon}, lat={lat}, label={label or '-'}"
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
    if asset_client:
        print(f"Asset API: enabled ({asset_client.base_url})")
    else:
        print("Asset API: disabled (manual lon/lat only)")
    print("Left click opens lon/lat dialog and saves on OK.")
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
        cv2.putText(display, status, (12, 28), cv2.FONT_HERSHEY_SIMPLEX, 0.65, (0, 0, 255), 2, cv2.LINE_AA)
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
    parser = argparse.ArgumentParser(description="Capture map control points (pixel + lon/lat) by clicking.")
    parser.add_argument("image_path", type=Path, help="Path to map PNG image")
    parser.add_argument(
        "--output-csv",
        type=Path,
        default=None,
        help="CSV to append clicked control points (default: input_pdfs/<map_name>_control_points.csv)",
    )
    parser.add_argument("--scale", type=float, default=0.25, help="Display scale for image window (default: 0.25)")
    parser.add_argument("--overwrite", action="store_true", help="Reset output CSV to header before capturing points")
    parser.add_argument(
        "--asset-api-base-url",
        default="",
        help="ILS-V3 web base URL (for example: http://192.168.0.20:38081). Can also be set via ILS_V3_API_BASE_URL.",
    )
    parser.add_argument(
        "--asset-api-key",
        default="",
        help="ILS-V3 mapping API key. Can also be set via ILS_V3_MAPPING_API_KEY.",
    )
    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    try:
        image_path = args.image_path.resolve()
        output_csv = args.output_csv.resolve() if args.output_csv else default_control_csv_for_image(image_path).resolve()
        capture_control_points(
            image_path=image_path,
            output_csv=output_csv,
            scale=args.scale,
            overwrite=args.overwrite,
            asset_api_base_url=(args.asset_api_base_url or "").strip() or None,
            asset_api_key=(args.asset_api_key or "").strip() or None,
        )
    except Exception as exc:
        print(f"Error: {exc}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
