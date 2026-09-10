"""Regenerate the MoneyWise PWA icons from Mlogo/MoneywiseLOGO.png.

Usage (from the project root):  python scripts/generate-pwa-icons.py
Requires Pillow + numpy. Always overwrites, so every file's pixel size matches
the "sizes" declared in manifest.json (Chrome rejects icons that don't match).
After regenerating, bump the ?v= query in manifest.json and includes/pwa-head.php.
"""
from pathlib import Path

import numpy as np
from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
LOGO = ROOT / "Mlogo" / "MoneywiseLOGO.png"
OUT = ROOT / "assets" / "pwa-icons"

ANY_SIZES = [72, 96, 128, 144, 152, 192, 384, 512]
MASKABLE_SIZES = [192, 512]
# Android crops maskable icons to a circle/squircle. Shrink the logo so the shield
# stays inside the safe zone, and fill the margin with the logo's dark backdrop.
MASKABLE_SCALE = 0.78
FEATHER = 0.06  # fraction of the shrunken logo used to blend its edge into the fill


def save(img: Image.Image, name: str, size: int) -> None:
    path = OUT / name
    img.resize((size, size), Image.LANCZOS).save(path, optimize=True)
    with Image.open(path) as check:
        assert check.size == (size, size), f"{name} is {check.size}, expected {size}x{size}"
        print(f"{name}: {check.size[0]}x{check.size[1]}")


def main() -> None:
    logo = Image.open(LOGO).convert("RGBA")
    side = min(logo.size)
    left, top = (logo.width - side) // 2, (logo.height - side) // 2
    logo = logo.crop((left, top, left + side, top + side))

    arr = np.asarray(logo)
    border = np.concatenate([arr[0], arr[-1], arr[:, 0], arr[:, -1]])[:, :3]
    bg = tuple(int(c) for c in border.mean(axis=0))

    full = Image.new("RGB", logo.size, bg)
    full.paste(logo, mask=logo.getchannel("A"))

    inner = int(side * MASKABLE_SCALE)
    idx = np.arange(inner)
    edge = np.minimum(idx, idx[::-1])
    dist = np.minimum.outer(edge, edge)
    mask = np.clip(dist / (inner * FEATHER), 0, 1) * 255
    maskable = Image.new("RGB", logo.size, bg)
    offset = (side - inner) // 2
    maskable.paste(full.resize((inner, inner), Image.LANCZOS), (offset, offset),
                   Image.fromarray(mask.astype(np.uint8)))

    OUT.mkdir(parents=True, exist_ok=True)
    for size in ANY_SIZES:
        save(full, f"icon-{size}.png", size)
    for size in MASKABLE_SIZES:
        save(maskable, f"icon-maskable-{size}.png", size)
    save(full, "apple-touch-icon.png", 180)
    save(full, "favicon-32.png", 32)


if __name__ == "__main__":
    main()
