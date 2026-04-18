# Drain Map Pipeline

A clean Python pipeline for converting drain map PDFs into page images and OCR text outputs.

Pipeline flow:

1. PDF input
2. PNG page image generation
3. OCR extraction to per-page, per-map combined text, and global combined text
4. Orange-label drain name extraction (sorted, de-duplicated)
5. Optional culvert/bridge symbol extraction
6. Optional map georeferencing for QGIS (PNG + world file + PRJ)

## Project Structure

```text
drain-map-pipeline/
  scripts/
    calibrate_structures.py
    drain_name_extract.py
    georef_map.py
    init_map_inputs.py
    label_control_points.py
    label_structure_points.py
    local_run_app.py
    pdf_to_images.py
    ocr_extract.py
    pipeline.py
    structure_extract.py
  input_pdfs/
  outputs/
    images/
    text/
```

## Setup

1. Create and activate a virtual environment.
2. Install Python dependencies:

```bash
pip install -r requirements.txt
```

## System Prerequisites

- `PyMuPDF` is used for PDF rendering (no Poppler required).
- `pytesseract` requires **Tesseract OCR** installed separately and available on your system path.

If Tesseract is not installed, OCR will fail even if Python packages are installed.

## Run The Pipeline

From the project root:

```bash
python scripts/pipeline.py your_file.pdf
```

- If you pass only a filename (for example `your_file.pdf`), the pipeline looks in `input_pdfs/`.
- You can also pass a relative or absolute path to a PDF.
- You can provide drain-name hints to improve extraction:
  - `python scripts/pipeline.py your_file.pdf --drain-hint "HARVEY MAIN DRAIN" --drain-hint "MAYFIELDS XA"`
- You can expand hierarchical branch labels under a parent stem:
  - `python scripts/pipeline.py your_file.pdf --branch-parent "MAYFIELDS" --branch-code B --branch-code B1`
  - If you pass only `--branch-parent` (without `--branch-code`), inferred branch suggestions are written to a review file.
- You can detect culverts/bridges and georeference in the same run:
  - `python scripts/pipeline.py your_file.pdf --extract-structures --control-points-csv control_points.csv --target-crs EPSG:4326`

## Local Runner App

You can run a local desktop workflow app for end-to-end point collection and exports:

```bash
python scripts/local_run_app.py
```

The app provides:

1. Import/select PDF map from `input_pdfs/`
2. Convert PDF to PNG
3. Click control points and enter GPS coordinates
4. Georeference raster (`.pgw` + `.prj`)
5. Click structure points and label them (auto lon/lat from georef world file)
6. Generate georeferenced structures CSV + GeoJSON
7. Export map outputs for later work-order pin usage

## Georeferencing Workflow

1. Run the pipeline once to generate the map PNG.
2. Create map-specific CSV files (header-only):

```bash
python scripts/init_map_inputs.py "input_pdfs/Map 9 - Shire of Dardanup.pdf"
```

This avoids carrying over old/template rows.
3. Pick at least 3 known points on the map image and record:
   - `pixel_x,pixel_y,lon,lat` (or `pixel_x,pixel_y,map_x,map_y`).
4. Save them in the map-specific control points CSV, for example:

```csv
pixel_x,pixel_y,lon,lat
1200.5,880.2,115.352110,-33.655420
4010.0,910.7,115.401900,-33.655010
2500.9,3400.3,115.377450,-33.689320
```

5. Run georeferencing:

```bash
python scripts/georef_map.py "outputs/images/Map 11 - Shire of Busselton & Capel_page_001.png" control_points.csv --target-crs EPSG:4326
```

A starter template is included at `input_pdfs/control_points_template.csv`, but map-specific files should be initialized with `init_map_inputs.py`.

QGIS can then load the PNG directly using generated sidecar files (`.pgw` and `.prj`).

## Culvert / Bridge Extraction

Use first-pass symbol extraction (culvert circle, bridge double-ring) plus nearby number OCR:

```bash
python scripts/structure_extract.py "outputs/images/Map 11 - Shire of Busselton & Capel_page_001.png"
```

Floodgates (circles marked with `F`) are excluded by default from culvert output.  
To include them in structure output:

```bash
python scripts/structure_extract.py "outputs/images/Map 11 - Shire of Busselton & Capel_page_001.png" --include-floodgates
```

This outputs a map-specific structures CSV with pixel coordinates.  
If georeferenced, you also get a georeferenced structures CSV and GeoJSON for direct QGIS import.

### Calibration (recommended)

Create a truth file with known structure IDs and pixel coordinates, then calibrate extraction:

```bash
python scripts/calibrate_structures.py "outputs/images/Map 11 - Shire of Busselton & Capel_page_001.png" input_pdfs/structure_truth_template.csv
```

This produces:
- grid-search scores (precision/recall/F1) for extraction settings
- a best-calibrated structures CSV

A starter truth template is included at `input_pdfs/structure_truth_template.csv`.

### Quick Pixel Capture (no QGIS setup needed)

If QGIS panels differ in your version, use the built-in click helper to collect pixel coordinates:

```bash
python scripts/label_structure_points.py "outputs/images/Map 11 - Shire of Busselton & Capel_page_001.png" --scale 0.25
```

To reset and replace an existing map-specific truth CSV instead of appending:

```bash
python scripts/label_structure_points.py "outputs/images/Map 11 - Shire of Busselton & Capel_page_001.png" --scale 0.25 --overwrite
```

To auto-fill lon/lat from a georeferenced map world file (`.pgw/.jgw/.wld`):

```bash
python scripts/label_structure_points.py "outputs/images/Map 11 - Shire of Busselton & Capel_page_001.png" --scale 0.25 --auto-lon-lat-from-world
```

For clicking map control points directly (pixel + lon/lat):

```bash
python scripts/label_control_points.py "outputs/images/Map 11 - Shire of Busselton & Capel_page_001.png" --scale 0.25 --overwrite
```

Control point dialog includes:
- asset type picker (`culvert`, `bridge`, `floodgate`, `drain`)
- asset number (optional)
- lon/lat (required)
- optional free-text label

Left-click each known structure center; a dialog captures:
- `structure_id`
- `structure_type` (`culvert`, `bridge`, or `floodgate`)
- optional `lon`/`lat` (leave blank if unknown)

`pixel_x,pixel_y` are saved automatically from click position.

By default, the label file is map-specific:
- `input_pdfs/<map_name>_structure_truth.csv`
- example: `input_pdfs/Map 8 - Burekup_structure_truth.csv`

This keeps structure truth CSV files separate per map (recommended when maps overlap).

## Example

```bash
python scripts/pipeline.py sample_drain_map.pdf
```

Outputs are written to:

- `outputs/images/Map 11 - Shire of Busselton & Capel_page_001.png`, ...
- `outputs/images/debug/Map 11 - Shire of Busselton & Capel_page_001_orange_mask.png`, ...
- `outputs/text/Map 11 - Shire of Busselton & Capel_page_001.txt`, ...
- `outputs/text/Map 11 - Shire of Busselton & Capel_ocr_text.txt` (combined for that map)
- `outputs/text/ocr_text.txt` (global combined across all map OCR files)
- `outputs/text/Map 11 - Shire of Busselton & Capel_page_001_drain_names.txt`, ...
- `outputs/text/Map 11 - Shire of Busselton & Capel_page_001_drain_names_raw.txt`, ...
- `outputs/text/Map 11 - Shire of Busselton & Capel_drain_names.txt`
- `outputs/text/Map 11 - Shire of Busselton & Capel_drain_names_raw.txt`
- `outputs/text/Map 11 - Shire of Busselton & Capel_drain_candidates_review.txt` (scored suggestions for manual review)
- `outputs/text/Map 11 - Shire of Busselton & Capel_structures.csv` (culverts/bridges + IDs, pixel coords)
- `outputs/text/Map 11 - Shire of Busselton & Capel_structures_georef.csv` (map coords after georeferencing)
- `outputs/text/Map 11 - Shire of Busselton & Capel_structures_georef.geojson` (QGIS-ready points)
- `outputs/images/Map 11 - Shire of Busselton & Capel_page_001.pgw` (world file)
- `outputs/images/Map 11 - Shire of Busselton & Capel_page_001.prj` (projection definition)
