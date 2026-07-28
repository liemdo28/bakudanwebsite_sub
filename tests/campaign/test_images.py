"""Validates the image manifest and actual hero image files: exactly 30, 1200x630, no reuse."""
import json
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


def main():
    errors = []
    with open(os.path.join(ROOT, 'content', 'campaign', 'image-manifest.json'), encoding='utf-8') as f:
        manifest = json.load(f)

    entries = manifest['mapping']
    if len(entries) != 30:
        errors.append(f'expected exactly 30 image manifest entries, found {len(entries)}')

    sources = [e['source'] for e in entries]
    if len(sources) != len(set(sources)):
        dupes = {s for s in sources if sources.count(s) > 1}
        errors.append(f'source photo reused: {dupes}')

    for e in entries:
        out_path = os.path.join(ROOT, e['output'])
        if not os.path.isfile(out_path):
            errors.append(f'seq {e["seq"]}: output file missing: {e["output"]}')
            continue
        if e['dimensions'] != '1200x630':
            errors.append(f'seq {e["seq"]}: wrong dimensions {e["dimensions"]}, expected 1200x630')
        if not out_path.lower().endswith('.webp'):
            errors.append(f'seq {e["seq"]}: output is not WebP: {e["output"]}')
        src_path = os.path.join(ROOT, e['source'])
        if not os.path.isfile(src_path):
            errors.append(f'seq {e["seq"]}: source photo missing: {e["source"]}')
        for required in ('subject', 'provenance', 'alt_text', 'size_kb'):
            if not e.get(required):
                errors.append(f'seq {e["seq"]}: missing manifest field {required!r}')

    if errors:
        print(f'FAIL: {len(errors)} error(s):')
        for e in errors:
            print(' -', e)
        return 1
    print(f'OK: all {len(entries)} campaign images valid (1200x630 WebP, no source reuse, full metadata).')
    return 0


if __name__ == '__main__':
    sys.exit(main())
