"""
Regression check for the 2026-07-21 "rim -> la cantera" find/replace incident
(commit 2af1239 corrupted Shrimp/Primary/Experiment/interim/trim/rtrim/ltrim/
isPrimary/order_url_rim/location-badge--rim-so/*_the_rim analytics labels into
garbled "la cantera" fragments across ~100+ tracked files, in two waves: the
first pass only caught letter-adjacent corruption; a second pass caught
underscore- and hyphen-adjacent identifiers like `footer_the_la cantera` and
`location-badge--la cantera-so`).

Flags "la cantera" embedded in what is clearly a machine identifier/slug/
prose-fragment rather than a standalone location reference:
  - touching a letter directly (Shla canterap) -- always flagged
  - touching an underscore (footer_the_la cantera) -- always flagged, English
    prose never contains underscores
  - touching a hyphen while lowercase (location-badge--la cantera-so) --
    flagged, since a legitimate compound-adjective use of the proper noun is
    always capitalized ("La Cantera-scoped"); only the lowercase, identifier-
    style form is corruption

Legitimate "La Cantera" / "la cantera" references (word-bounded by spaces, or
a properly-capitalized hyphenated compound adjective like "La Cantera-based")
are never flagged.

Usage: python scripts/check_no_text_corruption.py
Exit code 0 = clean, 1 = corruption signature found.
"""
import re
import subprocess
import sys

EXTS = ('.html', '.css', '.js', '.php', '.md', '.json', '.txt')

# 1) letter or underscore directly touching "la cantera", any case
# 2) hyphen directly touching "la cantera" ONLY when lowercase (identifier-style,
#    not the legitimate capitalized "La Cantera-scoped" compound-adjective form)
PATTERN = re.compile(
    r'[a-zA-Z_]la cantera|la cantera[a-zA-Z_]'
    r'|-la cantera(?![A-Z])|la cantera-(?![A-Z])'
)


def is_legitimate_compound_adjective(text, start, end):
    """'La Cantera-word' (capitalized, hyphenated adjective) is legitimate prose."""
    window = text[max(0, start - 3):end + 1]
    return bool(re.search(r'La Cantera-', window))


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
        for m in PATTERN.finditer(text):
            if is_legitimate_compound_adjective(text, m.start(), m.end()):
                continue
            line_no = text.count('\n', 0, m.start()) + 1
            hits.append((path, line_no, m.group(0)))

    if hits:
        print(f'FAIL: {len(hits)} text-corruption signature(s) found:')
        for path, line_no, span in hits:
            print(f'  {path}:{line_no}  {span!r}')
        return 1

    print('OK: no embedded "la cantera" corruption signatures found (letter/underscore/lowercase-hyphen adjacency).')
    return 0


if __name__ == '__main__':
    sys.exit(main())
