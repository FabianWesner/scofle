#!/usr/bin/env python3
"""Normalise an uploaded image, then run px-image2pptx with argv-only subprocesses."""

from __future__ import annotations

import argparse
import os
import shutil
import subprocess
import sys
from pathlib import Path

from PIL import Image, ImageOps


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("input")
    parser.add_argument("-o", "--output", required=True)
    parser.add_argument("--lang", default="auto", choices=["auto", "en", "ch"])
    parser.add_argument("--max-inpaint-size", default="2048")

    return parser.parse_args()


def ensure_inside(path: Path, root: Path) -> Path:
    resolved = path.resolve()

    if root not in [resolved, *resolved.parents]:
        raise RuntimeError(f"{resolved} escapes the version work directory")

    return resolved


def normalise_image(path: Path) -> None:
    with Image.open(path) as image:
        image.load()
        image = ImageOps.exif_transpose(image)

        if image.mode in {"RGBA", "LA"} or ("transparency" in image.info):
            rgba = image.convert("RGBA")
            background = Image.new("RGBA", rgba.size, (255, 255, 255, 255))
            background.alpha_composite(rgba)
            image = background.convert("RGB")
        elif image.mode == "CMYK":
            image = image.convert("RGB")
        elif image.mode not in {"RGB", "L"}:
            image = image.convert("RGB")

        suffix = path.suffix.lower()

        if suffix in {".jpg", ".jpeg"}:
            image.save(path, format="JPEG", quality=95, optimize=True)
        elif suffix == ".png":
            image.save(path, format="PNG", optimize=True)
        else:
            raise RuntimeError("Only PNG and JPEG are supported")


def sanitize_metadata(output: Path) -> None:
    try:
        from pptx import Presentation
    except Exception:
        return

    presentation = Presentation(str(output))
    props = presentation.core_properties
    props.author = "image-to-powerpoint"
    props.last_modified_by = "image-to-powerpoint"
    props.title = ""
    props.subject = ""
    props.keywords = ""
    props.comments = ""
    presentation.save(str(output))


def run_px_image2pptx(input_path: Path, output_path: Path, lang: str, max_inpaint_size: str) -> int:
    local_executable = Path(sys.executable).parent / "px-image2pptx"
    executable = str(local_executable) if local_executable.is_file() else shutil.which("px-image2pptx")

    if executable is None:
        print("px-image2pptx executable not found next to Python or in PATH", file=sys.stderr)

        return 1

    command = [
        executable,
        str(input_path),
        "-o",
        str(output_path),
        "--lang",
        lang,
        "--max-inpaint-size",
        max_inpaint_size,
    ]
    completed = subprocess.run(command, check=False)

    return completed.returncode


def main() -> int:
    args = parse_args()
    root = Path.cwd().resolve()
    input_path = ensure_inside(Path(args.input), root)
    output_path = ensure_inside(Path(args.output), root)

    normalise_image(input_path)
    exit_code = run_px_image2pptx(input_path, output_path, args.lang, args.max_inpaint_size)

    if exit_code != 0:
        return exit_code

    if not output_path.is_file() or output_path.stat().st_size == 0:
        print("px-image2pptx did not produce a non-empty pptx", file=sys.stderr)

        return 1

    sanitize_metadata(output_path)

    return 0


if __name__ == "__main__":
    os.environ.setdefault("PYTHONUNBUFFERED", "1")
    raise SystemExit(main())
