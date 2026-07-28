"""
Crops/resizes a real source photo to a 1200x630 WebP hero image for a
campaign article. Never overwrites the source image; writes only to
images/campaign/.

Usage: python scripts/campaign/build_images.py <source_path> <output_slug>
"""
import os
import sys
from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
TARGET_W, TARGET_H = 1200, 630
TARGET_RATIO = TARGET_W / TARGET_H


def build_hero(source_path, output_slug, focus='center'):
    src = os.path.join(ROOT, source_path)
    out_rel = f'images/campaign/{output_slug}-hero.webp'
    out_abs = os.path.join(ROOT, out_rel)
    os.makedirs(os.path.dirname(out_abs), exist_ok=True)

    im = Image.open(src)
    im = im.convert('RGB')
    w, h = im.size
    src_ratio = w / h

    if src_ratio > TARGET_RATIO:
        # source is wider than target -- crop width
        new_w = int(h * TARGET_RATIO)
        if focus == 'center':
            left = (w - new_w) // 2
        else:
            left = 0
        im = im.crop((left, 0, left + new_w, h))
    else:
        # source is taller than target -- crop height
        new_h = int(w / TARGET_RATIO)
        top = (h - new_h) // 2  # center crop vertically (mobile-safe: keeps subject centered)
        im = im.crop((0, top, w, top + new_h))

    im = im.resize((TARGET_W, TARGET_H), Image.LANCZOS)
    im.save(out_abs, 'WEBP', quality=82, method=6)

    size_kb = round(os.path.getsize(out_abs) / 1024, 1)
    print(f'{out_rel}  ({TARGET_W}x{TARGET_H}, {size_kb} KB)  <- {source_path}')
    return out_rel


if __name__ == '__main__':
    build_hero(sys.argv[1], sys.argv[2])
