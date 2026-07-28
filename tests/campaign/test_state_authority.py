"""
Enforces that campaign-state.json is the ONLY source of publishing status:
  - the manifest must never contain a 'status' field
  - no campaign source file may read/write manifest[...]['status']
  - every state id maps 1:1 onto a manifest entry id (no orphans either way)
"""
import glob
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


def test_manifest_has_no_status_field():
    with open(os.path.join(ROOT, 'content', 'campaign', 'campaign-manifest.json'), encoding='utf-8') as f:
        manifest = json.load(f)
    offenders = [a['seq'] for a in manifest['articles'] if 'status' in a]
    assert not offenders, f'manifest entries must not carry a status field, found on seq {offenders}'


def test_no_code_reads_status_off_a_manifest_entry():
    # crude but effective: scan campaign source for the shape entry['status'] /
    # entry.get('status') / a['status'] applied to something that came from
    # load_manifest() rather than load_state() -- we specifically forbid the
    # exact patterns that would indicate reading status from a manifest dict.
    forbidden_patterns = [
        r"entry\[.status.\]",
        r"entry\.get\(.status.\)",
        r"manifest\[.status.\]",
    ]
    offenders = []
    for path in glob.glob(os.path.join(ROOT, 'scripts', 'campaign', '*.py')):
        with open(path, encoding='utf-8') as f:
            text = f.read()
        for pattern in forbidden_patterns:
            if re.search(pattern, text):
                offenders.append((path, pattern))
    assert not offenders, f'found manifest-status reads: {offenders}'


def test_state_ids_map_one_to_one_onto_manifest():
    with open(os.path.join(ROOT, 'content', 'campaign', 'campaign-manifest.json'), encoding='utf-8') as f:
        manifest = json.load(f)
    with open(os.path.join(ROOT, 'content', 'campaign', 'campaign-state.json'), encoding='utf-8') as f:
        state = json.load(f)

    manifest_ids = {a['id'] for a in manifest['articles']}
    state_ids = set(state['articles'].keys())

    only_in_manifest = manifest_ids - state_ids
    only_in_state = state_ids - manifest_ids
    assert not only_in_manifest, f'manifest ids missing from state: {only_in_manifest}'
    assert not only_in_state, f'state ids not present in manifest (orphans): {only_in_state}'
    assert len(manifest_ids) == 30, f'expected 30 manifest ids, got {len(manifest_ids)}'


def test_state_is_sole_status_authority_smoke():
    with open(os.path.join(ROOT, 'content', 'campaign', 'campaign-state.json'), encoding='utf-8') as f:
        state = json.load(f)
    valid_statuses = {'draft', 'approved', 'scheduled', 'publishing', 'published', 'failed'}
    for article_id, rec in state['articles'].items():
        assert rec['status'] in valid_statuses, f'{article_id}: invalid status {rec["status"]!r}'


TESTS = [v for k, v in list(globals().items()) if k.startswith('test_')]


def main():
    failed = []
    for t in TESTS:
        try:
            t()
            print(f'PASS {t.__name__}')
        except AssertionError as e:
            failed.append(t.__name__)
            print(f'FAIL {t.__name__}: {e}')
    if failed:
        print(f'\n{len(failed)}/{len(TESTS)} failed')
        return 1
    print(f'\nAll {len(TESTS)} state-authority tests passed.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
