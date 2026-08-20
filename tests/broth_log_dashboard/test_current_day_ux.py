"""Static regression checks for the Broth Log current-day dashboard UX."""
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding='utf-8')


def test_today_operations_panel_is_rendered_before_kpis():
    js = text('js/broth-log-dashboard.js')
    assert 'function todayOperations(records, summary)' in js
    assert '${todayOperations(records, summary)}' in js
    assert js.index('${todayOperations(records, summary)}') < js.index('${kpis(summary)}')


def test_current_day_panel_shows_operational_fields():
    js = text('js/broth-log-dashboard.js')
    for phrase in [
        'Current Day',
        'Business date',
        'Critical',
        'Warning/Open',
        'Safe readings',
        'Missing/incomplete',
        'Recorded',
        'Required',
        'Corrective',
        'Incident',
    ]:
        assert phrase in js or phrase in text('css/broth-log.css')


def test_issues_only_filter_and_problem_first_sort_exist():
    js = text('js/broth-log-dashboard.js')
    assert 'data-action="issuesOnly"' in js
    assert "state.filters.issue = state.filters.issue === 'open' ? 'all' : 'open'" in js
    assert 'function recordPriority(row)' in js
    assert "state.filters.dateRange === 'today'" in js


def test_mobile_current_day_styles_prevent_horizontal_layout_pressure():
    css = text('css/broth-log.css')
    assert '.bd-today-ops' in css
    assert '.bd-today-issue-grid' in css
    assert '@media (max-width: 720px)' in css
    assert '.bd-today-counts,' in css
    assert '.bd-today-issue-grid,' in css


def test_asset_version_was_bumped():
    html = text('broth-log.html')
    assert 'current-day-ux' in html


def main() -> int:
    tests = [(name, fn) for name, fn in globals().items() if name.startswith('test_') and callable(fn)]
    for name, fn in tests:
        fn()
        print(f'PASS {name}')
    print(f'\nAll {len(tests)} current-day dashboard UX tests passed.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
