from __future__ import annotations

import argparse
import difflib
import re
from collections import defaultdict
from pathlib import Path
from typing import Iterable

import cv2
import numpy as np
import pytesseract

from ocr_extract import (
    DEFAULT_TEXT_OUTPUT_DIR,
    collect_image_paths,
    configure_tesseract,
    extract_map_stem,
    extract_page_number,
)

PROJECT_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_DEBUG_MASK_DIR = PROJECT_ROOT / "outputs" / "images" / "debug"
DEFAULT_HINT_NAMES = [
    "MAYFIELDS DRAIN",
    "HARVEY MAIN DRAIN",
    "LITTLE HARVEY DRAIN",
    "MAYFIELDS XA",
]
ROAD_WORDS = {"RD", "ROAD", "HWY", "HIGHWAY", "ST", "STREET", "AVE", "DR"}


def ensure_directory(path: Path) -> Path:
    path.mkdir(parents=True, exist_ok=True)
    return path


def normalize_candidate(text: str) -> str:
    cleaned = text.strip().upper()
    cleaned = re.sub(r"\s+", " ", cleaned)
    cleaned = re.sub(r"[^A-Z0-9\-/() .]", "", cleaned)
    return cleaned.strip(" .-_")


def hint_words_from_names(hint_names: Iterable[str]) -> set[str]:
    words: set[str] = set()
    for name in hint_names:
        for token in normalize_candidate(name).split():
            if token:
                words.add(token)
    return words


def closest_hint_word(token: str, hint_words: set[str], min_ratio: float = 0.78) -> str:
    best_word = token
    best_ratio = min_ratio
    for hint in hint_words:
        ratio = difflib.SequenceMatcher(a=token, b=hint).ratio()
        if ratio > best_ratio:
            best_ratio = ratio
            best_word = hint
    return best_word


def apply_hint_word_corrections(line: str, hint_words: set[str]) -> str:
    if not hint_words:
        return line

    corrected_tokens: list[str] = []
    for token in line.split():
        core = token.strip("()")
        if len(core) >= 3:
            core = closest_hint_word(core, hint_words)
        corrected_tokens.append(core)

    return normalize_candidate(" ".join(corrected_tokens))


def mask_orange_text(image: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    """Create orange mask and an OCR-ready binary image."""
    hsv = cv2.cvtColor(image, cv2.COLOR_BGR2HSV)

    lower_orange = np.array([5, 70, 60], dtype=np.uint8)
    upper_orange = np.array([30, 255, 255], dtype=np.uint8)
    mask = cv2.inRange(hsv, lower_orange, upper_orange)

    kernel = np.ones((2, 2), np.uint8)
    mask = cv2.morphologyEx(mask, cv2.MORPH_OPEN, kernel)
    mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, kernel)

    masked = cv2.bitwise_and(image, image, mask=mask)
    gray = cv2.cvtColor(masked, cv2.COLOR_BGR2GRAY)
    _, binary = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)

    prepared = cv2.bitwise_not(binary)
    return mask, prepared


def prepare_fullpage_for_ocr(image: np.ndarray) -> np.ndarray:
    """Create a fallback OCR image from full page content."""
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    enlarged = cv2.resize(gray, None, fx=2.0, fy=2.0, interpolation=cv2.INTER_CUBIC)
    return cv2.threshold(enlarged, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)[1]


def rotate_image(image: np.ndarray, angle: int) -> np.ndarray:
    if angle == 0:
        return image
    if angle == 90:
        return cv2.rotate(image, cv2.ROTATE_90_CLOCKWISE)
    if angle == 180:
        return cv2.rotate(image, cv2.ROTATE_180)
    if angle == 270:
        return cv2.rotate(image, cv2.ROTATE_90_COUNTERCLOCKWISE)
    raise ValueError(f"Unsupported rotation angle: {angle}")


def is_likely_label_text(line: str, confidence: float, min_confidence: float) -> bool:
    if confidence < min_confidence:
        return False
    if len(line) < 4 or len(line) > 64:
        return False
    if not re.search(r"[A-Z]", line):
        return False

    tokens = [token for token in line.split() if token]
    if not tokens:
        return False

    letters = sum(1 for ch in line if ch.isalpha())
    if letters < 3:
        return False

    one_char_tokens = sum(1 for token in tokens if len(token) == 1)
    if one_char_tokens > max(1, len(tokens) // 2):
        return False

    if re.fullmatch(r"[-/(). ]+", line):
        return False

    return True


def extract_lines_with_confidence(image: np.ndarray, angles: tuple[int, ...]) -> list[tuple[str, float]]:
    lines: list[tuple[str, float]] = []

    for angle in angles:
        rotated = rotate_image(image, angle)
        data = pytesseract.image_to_data(rotated, output_type=pytesseract.Output.DICT, config="--psm 11")
        grouped_words: dict[tuple[int, int, int], list[tuple[str, float]]] = defaultdict(list)

        for i, text in enumerate(data["text"]):
            text = text.strip()
            if not text:
                continue

            conf_raw = data["conf"][i]
            confidence = float(conf_raw) if conf_raw != "-1" else -1.0
            if confidence < 0:
                continue

            key = (data["block_num"][i], data["par_num"][i], data["line_num"][i])
            grouped_words[key].append((text, confidence))

        for words in grouped_words.values():
            joined = " ".join(word for word, _ in words)
            mean_conf = sum(conf for _, conf in words) / len(words)
            lines.append((joined, mean_conf))

    return lines


def extract_name_phrases_from_line(line: str) -> set[str]:
    names: set[str] = set()

    for pattern in (
        r"\b([A-Z]{3,}(?:\s+[A-Z]{2,}){0,4}\s+DRAIN)\b",
        r"\b([A-Z]{3,}(?:\s+[A-Z]{2,}){0,3}\s+XA)\b",
    ):
        for match in re.finditer(pattern, line):
            phrase = normalize_candidate(match.group(1))
            if not phrase:
                continue
            tokens = phrase.split()
            if any(token in ROAD_WORDS for token in tokens):
                continue
            names.add(phrase)

    return names


def build_hint_phrase_matches(observed_tokens: set[str], hint_names: Iterable[str]) -> set[str]:
    matched: set[str] = set()

    for hint in hint_names:
        normalized_hint = normalize_candidate(hint)
        if not normalized_hint:
            continue

        hint_tokens = [token for token in normalized_hint.split() if token]
        if not hint_tokens:
            continue

        matched_tokens = 0
        for hint_token in hint_tokens:
            token_match = any(
                difflib.SequenceMatcher(a=hint_token, b=observed).ratio() >= 0.75
                for observed in observed_tokens
            )
            if not token_match:
                continue
            matched_tokens += 1

        # Allow partial matching for hard OCR maps:
        # - 2-token hints require both
        # - 3+ token hints require at least 2/3 tokens
        min_required = len(hint_tokens) if len(hint_tokens) <= 2 else max(2, int(len(hint_tokens) * 0.67))
        if matched_tokens >= min_required:
            matched.add(normalized_hint)

    return matched


def score_review_candidate(line: str) -> int:
    tokens = [token for token in line.split() if token]
    score = 0

    if "DRAIN" in tokens or "DRAIN" in line:
        score += 4
    if "MAIN" in tokens:
        score += 2
    if "XA" in tokens:
        score += 2
    if any(keyword in line for keyword in ("CHANNEL", "CREEK", "CANAL", "BRANCH", "TRIBUTARY")):
        score += 2
    if any(token in ROAD_WORDS for token in tokens):
        score -= 4
    if "RESERVE" in tokens or "RESERVES" in tokens:
        score -= 3
    if len(tokens) < 2:
        score -= 2
    if len(line) < 6:
        score -= 1
    if re.match(r"^\d", line):
        score -= 3

    return score


def derive_phrase_candidates_from_raw(line: str) -> set[str]:
    phrases: set[str] = set()

    patterns = (
        r"\b([A-Z]{3,}(?:\s+[A-Z]{2,}){0,4}\s+DRAIN)\b",
        r"\b([A-Z]{3,}(?:\s+[A-Z]{2,}){0,4}\s+XA)\b",
    )
    for pattern in patterns:
        for match in re.finditer(pattern, line):
            phrase = normalize_candidate(match.group(1))
            if not phrase:
                continue
            tokens = phrase.split()
            if tokens and not re.match(r"^\d", tokens[0]):
                phrases.add(phrase)

    return phrases


def infer_branch_codes(raw_candidates: set[str]) -> list[tuple[int, str]]:
    scored_codes: dict[str, int] = {}
    for candidate in raw_candidates:
        token = candidate.strip()
        if not re.fullmatch(r"[A-Z]\d{0,2}", token):
            continue

        score = 1
        if any(ch.isdigit() for ch in token):
            score += 2
        if token[0] in {"I", "O", "Q", "Z"}:
            score -= 1

        if score >= 2:
            scored_codes[token] = max(scored_codes.get(token, 0), score)

    return sorted(((score, code) for code, score in scored_codes.items()), key=lambda item: (-item[0], item[1]))


def extract_review_candidates(raw_candidates: set[str], selected_names: set[str]) -> list[tuple[int, str]]:
    best_scores: dict[str, int] = {}

    for candidate in raw_candidates:
        derived_phrases = derive_phrase_candidates_from_raw(candidate)
        for phrase in derived_phrases:
            if phrase in selected_names:
                continue
            best_scores[phrase] = max(best_scores.get(phrase, 0), 6)

        if candidate in selected_names:
            continue
        score = score_review_candidate(candidate)
        if score >= 2:
            best_scores[candidate] = max(best_scores.get(candidate, 0), score)

    # Highest score first, then alphabetical.
    scored = [(score, name) for name, score in best_scores.items()]
    scored.sort(key=lambda item: (-item[0], item[1]))
    return scored


def save_debug_outputs(
    map_stem: str,
    page_number: int,
    mask: np.ndarray,
    prepared: np.ndarray,
    fullpage_prepared: np.ndarray,
    debug_output_dir: Path,
) -> tuple[Path, Path, Path]:
    ensure_directory(debug_output_dir)
    mask_path = debug_output_dir / f"{map_stem}_page_{page_number:03d}_orange_mask.png"
    prepared_path = debug_output_dir / f"{map_stem}_page_{page_number:03d}_orange_prepared.png"
    full_path = debug_output_dir / f"{map_stem}_page_{page_number:03d}_fullpage_prepared.png"

    cv2.imwrite(str(mask_path), mask)
    cv2.imwrite(str(prepared_path), prepared)
    cv2.imwrite(str(full_path), fullpage_prepared)
    return mask_path, prepared_path, full_path


def extract_drain_names(
    image_paths: Iterable[Path],
    text_output_dir: Path = DEFAULT_TEXT_OUTPUT_DIR,
    debug_output_dir: Path | None = DEFAULT_DEBUG_MASK_DIR,
    save_debug_masks: bool = True,
    min_confidence: float = 40.0,
    hint_names: Iterable[str] | None = None,
    branch_parent: str | None = None,
    branch_codes: Iterable[str] | None = None,
) -> dict[str, Path | list[Path] | list[str]]:
    """Extract likely drain names from map labels."""
    tesseract_cmd = configure_tesseract()
    sorted_paths = collect_image_paths(image_paths)
    text_output_dir = ensure_directory(text_output_dir.resolve())
    map_stem = extract_map_stem(sorted_paths[0])
    if any(extract_map_stem(path) != map_stem for path in sorted_paths):
        raise ValueError("All image paths must belong to the same map when extracting drain names.")

    hint_names = list(hint_names) if hint_names else list(DEFAULT_HINT_NAMES)
    hint_words = hint_words_from_names(hint_names)
    normalized_branch_parent = normalize_candidate(branch_parent or "")
    normalized_branch_codes = [
        normalize_candidate(code)
        for code in (branch_codes or [])
        if normalize_candidate(code)
    ]

    all_raw_candidates: set[str] = set()
    all_filtered_candidates: set[str] = set()
    raw_page_files: list[Path] = []
    filtered_page_files: list[Path] = []
    review_page_files: list[Path] = []
    all_review_candidates: list[tuple[int, str]] = []

    if save_debug_masks and debug_output_dir is not None:
        debug_output_dir = ensure_directory(debug_output_dir.resolve())

    print(f"Extracting likely drain names from {len(sorted_paths)} page image(s)...")
    print(f"Using Tesseract executable: {tesseract_cmd}")
    print(f"Drain hints: {', '.join(hint_names)}")

    for index, image_path in enumerate(sorted_paths, start=1):
        page_number = extract_page_number(image_path)
        if page_number == 10**9:
            page_number = index

        image = cv2.imread(str(image_path))
        if image is None:
            raise RuntimeError(f"Could not read image: {image_path}")

        mask, orange_prepared = mask_orange_text(image)
        fullpage_prepared = prepare_fullpage_for_ocr(image)

        if save_debug_masks and debug_output_dir is not None:
            mask_path, prepared_path, full_path = save_debug_outputs(
                map_stem,
                page_number,
                mask,
                orange_prepared,
                fullpage_prepared,
                debug_output_dir,
            )
            print(f"    Debug images: {mask_path.name}, {prepared_path.name}, {full_path.name}")

        raw_candidates: set[str] = set()
        filtered_label_lines: set[str] = set()
        phrase_candidates: set[str] = set()
        observed_tokens: set[str] = set()

        ocr_passes = [
            (orange_prepared, (0, 90, 180, 270)),
            (fullpage_prepared, (0, 270)),
        ]

        for prepared_image, angles in ocr_passes:
            for raw_line, confidence in extract_lines_with_confidence(prepared_image, angles):
                line = normalize_candidate(raw_line)
                if not line:
                    continue

                corrected = apply_hint_word_corrections(line, hint_words)
                raw_candidates.add(corrected)
                observed_tokens.update(corrected.split())

                if is_likely_label_text(corrected, confidence=confidence, min_confidence=min_confidence):
                    filtered_label_lines.add(corrected)
                    phrase_candidates.update(extract_name_phrases_from_line(corrected))

        phrase_candidates.update(build_hint_phrase_matches(observed_tokens, hint_names))

        # Optional deterministic branch expansion, e.g. MAYFIELDS + B1 -> MAYFIELDS B1.
        if normalized_branch_parent and normalized_branch_codes:
            for code in normalized_branch_codes:
                if re.fullmatch(r"[A-Z]\d{0,2}", code):
                    phrase_candidates.add(f"{normalized_branch_parent} {code}")

        sorted_raw_page = sorted(raw_candidates)
        sorted_filtered_page = sorted(phrase_candidates if phrase_candidates else filtered_label_lines)
        review_candidates_page = extract_review_candidates(raw_candidates, set(sorted_filtered_page))

        # If a parent is provided but explicit branch codes are not, infer possible branch codes
        # and emit them to the review list rather than the confirmed names list.
        if normalized_branch_parent and not normalized_branch_codes:
            for score, inferred_code in infer_branch_codes(raw_candidates):
                suggestion = f"{normalized_branch_parent} {inferred_code}"
                if suggestion not in set(sorted_filtered_page):
                    review_candidates_page.append((score + 2, suggestion))

        # Deduplicate page review suggestions by keeping the best score per name.
        page_best_scores: dict[str, int] = {}
        for score, name in review_candidates_page:
            if name not in page_best_scores or score > page_best_scores[name]:
                page_best_scores[name] = score
        review_candidates_page = sorted(
            ((score, name) for name, score in page_best_scores.items()),
            key=lambda item: (-item[0], item[1]),
        )

        all_raw_candidates.update(sorted_raw_page)
        all_filtered_candidates.update(sorted_filtered_page)
        all_review_candidates.extend(review_candidates_page)

        raw_page_file = text_output_dir / f"{map_stem}_page_{page_number:03d}_drain_names_raw.txt"
        raw_page_file.write_text("\n".join(sorted_raw_page) + ("\n" if sorted_raw_page else ""), encoding="utf-8")
        raw_page_files.append(raw_page_file)

        filtered_page_file = text_output_dir / f"{map_stem}_page_{page_number:03d}_drain_names.txt"
        filtered_page_file.write_text(
            "\n".join(sorted_filtered_page) + ("\n" if sorted_filtered_page else ""),
            encoding="utf-8",
        )
        filtered_page_files.append(filtered_page_file)

        review_page_file = text_output_dir / f"{map_stem}_page_{page_number:03d}_drain_candidates_review.txt"
        review_page_file.write_text(
            "\n".join(f"{score}\t{name}" for score, name in review_candidates_page)
            + ("\n" if review_candidates_page else ""),
            encoding="utf-8",
        )
        review_page_files.append(review_page_file)

        print(
            f"  [{index}/{len(sorted_paths)}] Saved {filtered_page_file.name} "
            f"({len(sorted_filtered_page)} filtered / {len(sorted_raw_page)} raw)"
        )

    sorted_raw_names = sorted(all_raw_candidates)
    sorted_filtered_names = sorted(all_filtered_candidates)

    raw_combined_file = text_output_dir / f"{map_stem}_drain_names_raw.txt"
    raw_combined_file.write_text("\n".join(sorted_raw_names) + ("\n" if sorted_raw_names else ""), encoding="utf-8")

    combined_file = text_output_dir / f"{map_stem}_drain_names.txt"
    combined_file.write_text(
        "\n".join(sorted_filtered_names) + ("\n" if sorted_filtered_names else ""),
        encoding="utf-8",
    )

    print(
        f"Finished drain-name extraction. Combined list: {combined_file} "
        f"({len(sorted_filtered_names)} filtered / {len(sorted_raw_names)} raw)"
    )

    # Merge page review candidates and keep best score per name.
    best_review_scores: dict[str, int] = {}
    for score, name in all_review_candidates:
        if name not in best_review_scores or score > best_review_scores[name]:
            best_review_scores[name] = score

    sorted_review = sorted(best_review_scores.items(), key=lambda item: (-item[1], item[0]))
    review_file = text_output_dir / f"{map_stem}_drain_candidates_review.txt"
    review_file.write_text(
        "\n".join(f"{score}\t{name}" for name, score in sorted_review) + ("\n" if sorted_review else ""),
        encoding="utf-8",
    )
    print(f"Review candidates file: {review_file} ({len(sorted_review)} candidates)")

    return {
        "combined_file": combined_file,
        "raw_combined_file": raw_combined_file,
        "review_file": review_file,
        "page_files": filtered_page_files,
        "raw_page_files": raw_page_files,
        "review_page_files": review_page_files,
        "names": sorted_filtered_names,
        "raw_names": sorted_raw_names,
    }


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Extract likely drain names from map labels.")
    parser.add_argument(
        "image_paths",
        nargs="+",
        type=Path,
        help="Image paths to process (e.g., outputs/images/page_001.png ...)",
    )
    parser.add_argument(
        "--output-dir",
        type=Path,
        default=DEFAULT_TEXT_OUTPUT_DIR,
        help="Directory for drain-name text outputs",
    )
    parser.add_argument(
        "--debug-dir",
        type=Path,
        default=DEFAULT_DEBUG_MASK_DIR,
        help="Directory for debug images",
    )
    parser.add_argument(
        "--min-confidence",
        type=float,
        default=40.0,
        help="Minimum OCR line confidence for filtered names (default: 40)",
    )
    parser.add_argument(
        "--hint-name",
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
        "--no-debug-masks",
        action="store_true",
        help="Do not write debug images",
    )
    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    try:
        extract_drain_names(
            args.image_paths,
            text_output_dir=args.output_dir,
            debug_output_dir=args.debug_dir,
            save_debug_masks=not args.no_debug_masks,
            min_confidence=args.min_confidence,
            hint_names=args.hint_name,
            branch_parent=args.branch_parent,
            branch_codes=args.branch_code,
        )
    except Exception as exc:
        print(f"Error: {exc}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
