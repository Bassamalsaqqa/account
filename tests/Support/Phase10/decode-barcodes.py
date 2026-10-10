#!/usr/bin/env python3
"""
Phase 10 C2 Barcode Certification Decoder Helper
Software decoding of rasterized barcode label sheets; no hardware scanner claim.

Uses PyMuPDF (fitz) at 300 DPI to render PDF pages into raster images,
crops individual label cells using exact sheet preset geometry,
and decodes barcode symbologies using zxing-cpp.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

# Add paths to bundled libraries (no pip install allowed)
candidate_dirs = [
    Path(".ai/delegations/20261009-p9-0/pdf-tools"),
    Path(".ai/delegations/phase9-implementation/pdf-tools"),
]
if os.environ.get("PHASE10_PDFTOOLS_DIR"):
    candidate_dirs.insert(0, Path(os.environ["PHASE10_PDFTOOLS_DIR"]))
for p in Path(__file__).resolve().parents:
    candidate_dirs.append(p / ".ai/delegations/20261009-p9-0/pdf-tools")
    candidate_dirs.append(p / ".ai/delegations/phase9-implementation/pdf-tools")

for c in candidate_dirs:
    if c.is_dir() and str(c) not in sys.path:
        sys.path.insert(0, str(c))

try:
    import pymupdf
    import zxingcpp
    from PIL import Image
except ImportError as exc:
    print(f"Error importing barcode decoder libraries: {exc}", file=sys.stderr)
    print("Ensure bundled pdf-tools paths are accessible.", file=sys.stderr)
    sys.exit(2)

PRESET_GEOMETRY = {
    "a4-3x8": {
        "columns": 3,
        "rows": 8,
        "cell_width_mm": 66.0,
        "cell_height_mm": 33.0,
        "margin_left_mm": 6.0,
        "margin_top_mm": 4.0,
        "table_width_mm": 198.0,
    },
    "a4-2x7": {
        "columns": 2,
        "rows": 7,
        "cell_width_mm": 99.0,
        "cell_height_mm": 38.0,
        "margin_left_mm": 6.0,
        "margin_top_mm": 4.0,
        "table_width_mm": 198.0,
    },
}


def normalize_decoded_symbol(format_name: str, raw_text: str) -> dict[str, str]:
    """
    Normalizes scanner output across symbologies:
    - Code 128: exact text preserved (including ASCII spaces)
    - EAN-13: exact 13 digits
    - EAN-8: exact 8 digits
    - UPC-A: if reported as EAN-13 with leading '00' or '0', extracts 12 digits
    - UPC-E: zxingcpp reports 'UPC-E' and returns '0' + 12-digit expanded UPC-A;
             normalized_text extracts the 12-digit canonical UPC-A expansion.
    """
    cleaned_format = str(format_name).replace("BarcodeFormat.", "").strip()
    norm = {
        "raw_format": cleaned_format,
        "raw_text": raw_text,
        "canonical_format": cleaned_format,
        "normalized_text": raw_text,
    }

    if "Code" in cleaned_format and "128" in cleaned_format:
        norm["canonical_format"] = "Code 128"
        norm["normalized_text"] = raw_text
    elif "EAN" in cleaned_format and "13" in cleaned_format:
        if raw_text.startswith("00") and len(raw_text) == 13:
            # UPC-A encoded as EAN-13
            norm["canonical_format"] = "UPC-A"
            norm["normalized_text"] = raw_text[-12:]
        else:
            norm["canonical_format"] = "EAN-13"
            norm["normalized_text"] = raw_text
    elif "EAN" in cleaned_format and "8" in cleaned_format:
        norm["canonical_format"] = "EAN-8"
        norm["normalized_text"] = raw_text
    elif "UPC" in cleaned_format and "A" in cleaned_format:
        norm["canonical_format"] = "UPC-A"
        norm["normalized_text"] = raw_text[-12:] if len(raw_text) >= 12 else raw_text
    elif "UPC" in cleaned_format and "E" in cleaned_format:
        norm["canonical_format"] = "UPC-E"
        # zxingcpp returns 13 digits ('0' + 12-digit expanded UPC-A)
        norm["normalized_text"] = raw_text[-12:] if len(raw_text) >= 12 else raw_text

    return norm


def decode_pdf(
    pdf_path: str | Path,
    preset: str = "a4-3x8",
    expected_labels: list[dict] | None = None,
    expected_count: int | None = None,
    dpi: int = 300,
    is_rtl: bool = False,
    save_images: bool = False,
) -> dict:
    """
    Rasterize PDF pages at specified DPI, crop each label cell,
    and decode barcodes with zxingcpp.
    """
    path = Path(pdf_path)
    if not path.is_file():
        raise FileNotFoundError(f"PDF file not found: {path}")

    geom = PRESET_GEOMETRY.get(preset)
    if not geom:
        raise ValueError(f"Unsupported preset: {preset}. Supported: {list(PRESET_GEOMETRY.keys())}")

    columns = geom["columns"]
    rows = geom["rows"]
    cell_h_mm = geom["cell_height_mm"]
    margin_l_mm = geom["margin_left_mm"]
    margin_t_mm = geom["margin_top_mm"]
    table_w_mm = geom["table_width_mm"]
    cell_w_mm = table_w_mm / columns

    doc = pymupdf.open(str(path))
    cells_result = []
    total_decoded = 0
    all_passed = True

    if expected_count is None and expected_labels is not None:
        expected_count = len(expected_labels)

    for page_idx, page in enumerate(doc):
        # Render at requested DPI
        scale = dpi / 72.0
        pix = page.get_pixmap(matrix=pymupdf.Matrix(scale, scale), alpha=False)
        img = Image.frombytes("RGB", (pix.width, pix.height), pix.samples)

        if save_images:
            png_path = path.parent / f"{path.stem}_page{page_idx+1}.png"
            pix.save(str(png_path))

        # Scale factor: A4 width is 210mm
        mm = pix.width / 210.0

        for r in range(rows):
            for c in range(columns):
                cell_index = page_idx * (columns * rows) + (r * columns + c)
                if expected_count is not None and cell_index >= expected_count:
                    continue

                visual_c = (columns - 1 - c) if is_rtl else c
                left = margin_l_mm + visual_c * cell_w_mm
                right = margin_l_mm + (visual_c + 1) * cell_w_mm
                top = margin_t_mm + r * cell_h_mm
                bottom = margin_t_mm + (r + 1) * cell_h_mm

                box = (
                    round(left * mm),
                    round(top * mm),
                    round(right * mm),
                    round(bottom * mm),
                )

                cell_img = img.crop(box)
                symbols = zxingcpp.read_barcodes(cell_img)

                expected_spec = expected_labels[cell_index] if (expected_labels and cell_index < len(expected_labels)) else None

                decoded_symbols = []
                for s in symbols:
                    norm = normalize_decoded_symbol(str(s.format), s.text)
                    decoded_symbols.append({
                        "raw_text": s.text,
                        "raw_format": str(s.format),
                        "canonical_format": norm["canonical_format"],
                        "normalized_text": norm["normalized_text"],
                        "valid": s.valid,
                        "error": str(s.error) if s.error else None,
                    })

                cell_pass = len(symbols) == 1 and symbols[0].valid
                match_details = {}

                if expected_spec:
                    if len(symbols) != 1:
                        cell_pass = False
                        match_details["reason"] = f"Expected 1 symbol, found {len(symbols)}"
                    else:
                        sym = decoded_symbols[0]
                        # Check format match
                        exp_fmt = expected_spec.get("expected_format")
                        if exp_fmt and sym["canonical_format"] != exp_fmt:
                            cell_pass = False
                            match_details["format_mismatch"] = f"Expected {exp_fmt}, got {sym['canonical_format']}"

                        # Check text match
                        exp_text = expected_spec.get("expected_text")
                        if exp_text and sym["normalized_text"] != exp_text:
                            cell_pass = False
                            match_details["text_mismatch"] = f"Expected {exp_text}, got {sym['normalized_text']}"

                        # Check stored code match if specified
                        exp_stored = expected_spec.get("stored_code")
                        if exp_stored and sym["raw_text"] != exp_stored and sym["normalized_text"] != exp_stored:
                            # For UPC-E, stored_code is 8 digits, normalized_text is 12 digits UPC-A
                            exp_expanded = expected_spec.get("expected_expansion")
                            if exp_expanded and sym["normalized_text"] != exp_expanded:
                                cell_pass = False
                                match_details["upce_expansion_mismatch"] = f"Expected {exp_expanded}, got {sym['normalized_text']}"

                if not cell_pass:
                    all_passed = False

                if len(symbols) == 1:
                    total_decoded += 1

                cells_result.append({
                    "index": cell_index,
                    "page": page_idx + 1,
                    "row": r + 1,
                    "column": c + 1,
                    "visual_column": visual_c + 1,
                    "box": list(box),
                    "symbols": decoded_symbols,
                    "passed": cell_pass,
                    "expected": expected_spec,
                    "match_details": match_details,
                })

    effective_expected = expected_count if expected_count is not None else len(cells_result)

    return {
        "file": str(path),
        "preset": preset,
        "is_rtl": is_rtl,
        "dpi": dpi,
        "pages": len(doc),
        "cells_checked": len(cells_result),
        "total_decoded": total_decoded,
        "passed": all_passed and (total_decoded == effective_expected),
        "cells": cells_result,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Phase 10 Barcode Decoder Helper")
    parser.add_argument("--pdf", help="Path to PDF file to decode")
    parser.add_argument("--preset", default="a4-3x8", choices=["a4-3x8", "a4-2x7"], help="Sheet layout preset")
    parser.add_argument("--rtl", action="store_true", help="Decode in RTL reading order (e.g. Arabic)")
    parser.add_argument("--expected-count", type=int, default=None, help="Expected number of labels on sheet")
    parser.add_argument("--manifest", help="Path to JSON manifest specifying files and expected labels")
    parser.add_argument("--output", help="Path to save output JSON report")
    parser.add_argument("--dpi", type=int, default=300, help="Raster DPI (default 300)")
    parser.add_argument("--save-images", action="store_true", help="Save rendered page PNG images alongside output")

    args = parser.parse_args()

    results = []
    overall_passed = True

    if args.manifest:
        manifest_path = Path(args.manifest)
        if not manifest_path.is_file():
            print(f"Error: manifest file not found: {manifest_path}", file=sys.stderr)
            return 1
        with open(manifest_path, encoding="utf-8") as f:
            manifest_data = json.load(f)

        for item in manifest_data:
            pdf_file = item["file"]
            preset = item.get("preset", "a4-3x8")
            expected = item.get("labels", [])
            expected_count = item.get("expected_count", len(expected) if expected else None)
            dpi = item.get("dpi", args.dpi)
            is_rtl = item.get("is_rtl", item.get("rtl", item.get("locale") == "ar"))
            save_images = item.get("save_images", args.save_images)

            res = decode_pdf(
                pdf_file,
                preset=preset,
                expected_labels=expected if expected else None,
                expected_count=expected_count,
                dpi=dpi,
                is_rtl=is_rtl,
                save_images=save_images,
            )
            results.append(res)
            if not res["passed"]:
                overall_passed = False

    elif args.pdf:
        res = decode_pdf(
            args.pdf,
            preset=args.preset,
            expected_count=args.expected_count,
            dpi=args.dpi,
            is_rtl=args.rtl,
            save_images=args.save_images,
        )
        results.append(res)
        if not res["passed"]:
            overall_passed = False
    else:
        # Default behavior: scan .ai/phase10-barcode if manifest exists there
        worktree_root = Path(__file__).resolve().parents[3]
        default_manifest = worktree_root / ".ai/phase10-barcode/manifest.json"
        if not default_manifest.is_file():
            default_manifest = Path(".ai/phase10-barcode/manifest.json")
        if default_manifest.is_file():
            with open(default_manifest, encoding="utf-8") as f:
                manifest_data = json.load(f)
            for item in manifest_data:
                res = decode_pdf(
                    item["file"],
                    preset=item.get("preset", "a4-3x8"),
                    expected_labels=item.get("labels", []),
                    expected_count=item.get("expected_count"),
                    dpi=args.dpi,
                    is_rtl=item.get("is_rtl", item.get("rtl", item.get("locale") == "ar")),
                )
                results.append(res)
                if not res["passed"]:
                    overall_passed = False
        else:
            print("No --pdf or --manifest specified, and no default manifest found.", file=sys.stderr)
            parser.print_help()
            return 1

    summary = [
        {
            "file": Path(r["file"]).name,
            "preset": r["preset"],
            "is_rtl": r["is_rtl"],
            "dpi": r["dpi"],
            "pages": r["pages"],
            "cells_checked": r["cells_checked"],
            "total_decoded": r["total_decoded"],
            "passed": r["passed"],
        }
        for r in results
    ]

    print(json.dumps(summary, indent=2))

    if args.output:
        out_path = Path(args.output)
        out_path.parent.mkdir(parents=True, exist_ok=True)
        with open(out_path, "w", encoding="utf-8") as f:
            json.dump(results, f, indent=2)
        print(f"Detailed decode report written to {out_path}")

    return 0 if overall_passed else 1


if __name__ == "__main__":
    sys.exit(main())
