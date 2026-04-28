from __future__ import annotations

import argparse
import json
from collections import deque
from pathlib import Path
from typing import Any

import cv2
import numpy as np


def load_world_affine(image_path: Path) -> tuple[float, float, float, float, float, float]:
    world_path = image_path.with_suffix(".pgw")
    if not world_path.exists():
        raise FileNotFoundError(f"World file not found: {world_path}")
    vals = [float(x.strip()) for x in world_path.read_text(encoding="utf-8").splitlines() if x.strip()]
    if len(vals) < 6:
        raise ValueError("World file must contain 6 numeric lines.")
    return vals[0], vals[1], vals[2], vals[3], vals[4], vals[5]


def pixel_to_map(affine: tuple[float, float, float, float, float, float], x: int, y: int) -> tuple[float, float]:
    a, d, b, e, c, f = affine
    lon = (a * x) + (b * y) + c
    lat = (d * x) + (e * y) + f
    return lon, lat


def red_mask(image_bgr: np.ndarray) -> np.ndarray:
    hsv = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2HSV)
    low1 = np.array([0, 70, 40], dtype=np.uint8)
    high1 = np.array([12, 255, 255], dtype=np.uint8)
    low2 = np.array([168, 70, 40], dtype=np.uint8)
    high2 = np.array([180, 255, 255], dtype=np.uint8)
    mask1 = cv2.inRange(hsv, low1, high1)
    mask2 = cv2.inRange(hsv, low2, high2)
    mask = cv2.bitwise_or(mask1, mask2)
    kernel = np.ones((3, 3), dtype=np.uint8)
    mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, kernel, iterations=1)
    return (mask > 0).astype(np.uint8)


def snap_to_mask(mask: np.ndarray, x: int, y: int, max_radius: int = 80) -> tuple[int, int]:
    h, w = mask.shape
    x = max(0, min(w - 1, x))
    y = max(0, min(h - 1, y))
    if mask[y, x]:
        return x, y
    for r in range(1, max_radius + 1):
        x0 = max(0, x - r)
        x1 = min(w - 1, x + r)
        y0 = max(0, y - r)
        y1 = min(h - 1, y + r)
        for xx in range(x0, x1 + 1):
            if mask[y0, xx]:
                return xx, y0
            if mask[y1, xx]:
                return xx, y1
        for yy in range(y0 + 1, y1):
            if mask[yy, x0]:
                return x0, yy
            if mask[yy, x1]:
                return x1, yy
    raise ValueError("Could not snap click to red drain line. Click closer to drain.")


NEIGHBORS = [
    (1, 0, 0),
    (-1, 0, 1),
    (0, 1, 2),
    (0, -1, 3),
    (1, 1, 4),
    (-1, 1, 5),
    (1, -1, 6),
    (-1, -1, 7),
]
BACK = [1, 0, 3, 2, 7, 6, 5, 4]


def bfs_path(mask: np.ndarray, start: tuple[int, int], end: tuple[int, int]) -> list[tuple[int, int]]:
    h, w = mask.shape
    sx, sy = start
    ex, ey = end
    visited = np.zeros((h, w), dtype=np.uint8)
    parent = np.full((h, w), 255, dtype=np.uint8)

    q: deque[tuple[int, int]] = deque()
    q.append((sx, sy))
    visited[sy, sx] = 1

    found = False
    while q:
        x, y = q.popleft()
        if x == ex and y == ey:
            found = True
            break
        for dx, dy, code in NEIGHBORS:
            nx = x + dx
            ny = y + dy
            if nx < 0 or ny < 0 or nx >= w or ny >= h:
                continue
            if visited[ny, nx] or mask[ny, nx] == 0:
                continue
            visited[ny, nx] = 1
            parent[ny, nx] = BACK[code]
            q.append((nx, ny))

    if not found:
        raise ValueError("No connected red drain path found between selected points.")

    path: list[tuple[int, int]] = []
    x, y = ex, ey
    path.append((x, y))
    while not (x == sx and y == sy):
        p = int(parent[y, x])
        if p == 255:
            raise ValueError("Path backtrack failed.")
        dx, dy, _ = NEIGHBORS[p]
        x += dx
        y += dy
        path.append((x, y))
    path.reverse()
    return path


def path_with_gap_bridge(
    mask: np.ndarray,
    start: tuple[int, int],
    end: tuple[int, int],
    max_dilate_steps: int = 4,
) -> tuple[list[tuple[int, int]], int]:
    try:
        return bfs_path(mask, start, end), 0
    except ValueError:
        pass

    kernel = np.ones((3, 3), dtype=np.uint8)
    work = mask.copy()
    for step in range(1, max_dilate_steps + 1):
        work = cv2.dilate(work, kernel, iterations=1)
        try:
            return bfs_path(work, start, end), step
        except ValueError:
            continue
    raise ValueError("No connected red drain path found between selected points.")


def simplify_polyline(path: list[tuple[int, int]], epsilon: float = 1.5) -> list[tuple[int, int]]:
    if len(path) <= 2:
        return path
    arr = np.array(path, dtype=np.float32).reshape((-1, 1, 2))
    approx = cv2.approxPolyDP(arr, epsilon=epsilon, closed=False)
    out = [(int(round(p[0][0])), int(round(p[0][1]))) for p in approx]
    if out[0] != path[0]:
        out.insert(0, path[0])
    if out[-1] != path[-1]:
        out.append(path[-1])
    return out


def trace_drain(image_path: Path, start_x: int, start_y: int, end_x: int, end_y: int) -> dict[str, Any]:
    image = cv2.imread(str(image_path), cv2.IMREAD_COLOR)
    if image is None:
        raise FileNotFoundError(f"Could not read image: {image_path}")
    mask = red_mask(image)
    start = snap_to_mask(mask, start_x, start_y)
    end = snap_to_mask(mask, end_x, end_y)
    path, dilate_steps_used = path_with_gap_bridge(mask, start, end)
    simple = simplify_polyline(path)
    affine = load_world_affine(image_path)

    coord_points = []
    for x, y in simple:
        lon, lat = pixel_to_map(affine, x, y)
        coord_points.append([float(f"{lon:.6f}"), float(f"{lat:.6f}")])

    return {
        "ok": True,
        "start_snap": [start[0], start[1]],
        "end_snap": [end[0], end[1]],
        "pixel_points": [[x, y] for x, y in simple],
        "coord_points": coord_points,
        "point_count": len(simple),
        "gap_bridge_px": int(dilate_steps_used),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Trace a drain path on red map lines.")
    parser.add_argument("image_path", type=Path)
    parser.add_argument("--start-x", type=int, required=True)
    parser.add_argument("--start-y", type=int, required=True)
    parser.add_argument("--end-x", type=int, required=True)
    parser.add_argument("--end-y", type=int, required=True)
    args = parser.parse_args()
    try:
        result = trace_drain(
            image_path=args.image_path.resolve(),
            start_x=args.start_x,
            start_y=args.start_y,
            end_x=args.end_x,
            end_y=args.end_y,
        )
        print(json.dumps(result))
        return 0
    except Exception as exc:
        print(json.dumps({"ok": False, "error": str(exc)}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
