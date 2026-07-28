"""
Validates content/campaign/campaign-manifest.json structure: exactly 30
articles, strict 48h cadence, unique slugs/ids/titles, valid ISO timestamps.
"""
import json
import os
import sys
from datetime import datetime, timezone

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
MANIFEST_PATH = os.path.join(ROOT, 'content', 'campaign', 'campaign-manifest.json')


def load():
    with open(MANIFEST_PATH, encoding='utf-8') as f:
        return json.load(f)


def parse_ts(s):
    return datetime.strptime(s, '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=timezone.utc)


def main():
    errors = []
    data = load()
    articles = data['articles']

    if len(articles) != 30:
        errors.append(f'expected exactly 30 articles, found {len(articles)}')

    seqs = [a['seq'] for a in articles]
    if seqs != list(range(1, len(articles) + 1)):
        errors.append(f'seq must be 1..N with no gaps, got {seqs}')

    for key in ('id', 'slug', 'title'):
        values = [a[key] for a in articles]
        dupes = {v for v in values if values.count(v) > 1}
        if dupes:
            errors.append(f'duplicate {key} values: {dupes}')

    timestamps = [parse_ts(a['publish_at']) for a in articles]
    if timestamps != sorted(timestamps):
        errors.append('publish_at values are not strictly increasing')

    for i in range(1, len(timestamps)):
        delta = timestamps[i] - timestamps[i - 1]
        if delta.total_seconds() != 48 * 3600:
            errors.append(
                f'article #{i+1} is {delta} after #{i}, expected exactly 48h '
                f'({articles[i-1]["slug"]} -> {articles[i]["slug"]})'
            )

    for a in articles:
        if parse_ts(a['publish_at']).strftime('%H:%M') != '14:00':
            errors.append(f'{a["slug"]}: publish_at should be 14:00 UTC (09:00 America/Chicago)')
        if 'status' in a:
            errors.append(f'{a["slug"]}: manifest entries must not carry a status field -- '
                           f'campaign-state.json is the sole status authority (see test_state_authority.py)')

    if errors:
        print(f'FAIL: {len(errors)} manifest validation error(s):')
        for e in errors:
            print(' -', e)
        return 1

    print(f'OK: manifest valid -- {len(articles)} articles, strict 48h cadence, unique ids/slugs/titles.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
