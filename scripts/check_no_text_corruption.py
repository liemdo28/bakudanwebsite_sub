"""
Regression check for the 2026-07-21 "rim -> la cantera" find/replace incident
(commit 2af1239 corrupted Shrimp/Primary/Experiment/interim/trim/rtrim/ltrim/
isPrimary into garbled "la cantera" fragments across ~85 tracked files).

Flags any tracked text file where "la cantera" is directly touching a letter
on either side (the corruption signature) -- legitimate "La Cantera" /
"la cantera" references are always word-bounded (space/punctuation on both
sides) and are never flagged.

Usage: python scripts/check_no_text_corruption.py
Exit code 0 = clean, 1 = corruption signature found.
"""
import re
import subprocess
import sys

EXTS = ('.html', '.css', '.js', '.php', '.md', '.json', '.txt')


def main():
    tracked = subprocess.run(
        ['git', 'ls-files'], capture_output=True, text=True, check=True
    ).stdout.splitlines()

    hits = []
    for path in [p for p in tracked if p.lower().endswith(EXTS)]:
        try:
            with open(path, encoding='utf-8', errors='ignore') as f:
                text = f.read()
        except OSError:
            continue
        for m in re.finditer(r'[a-zA-Z]la cantera|la cantera[a-zA-Z]', text, re.IGNORECASE):
            line_no = text.count('\n', 0, m.start()) + 1
            hits.append((path, line_no, m.group(0)))

    if hits:
        print(f'FAIL: {len(hits)} text-corruption signature(s) found (letter directly touching "la cantera"):')
        for path, line_no, span in hits:
            print(f'  {path}:{line_no}  {span!r}')
        return 1

    print('OK: no embedded "la cantera" corruption signatures found.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
