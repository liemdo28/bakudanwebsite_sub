"""Static regression checks for the Broth Log current-day dashboard UX."""
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding='utf-8')


def test_today_operations_panel_is_rendered_before_kpis():
    js = text('js/broth-log-dashboard.js')
    assert 'function todayOperations(records, summary)' in js
    assert 'return `${todayOperations(records, summary)}${dailyRecords(records)}`;' in js
    assert 'if (state.filters.dateRange !== \'today\') return `${kpis(summary)}${masterDetail(records, summary)}`;' in js


def test_current_day_panel_shows_operational_fields():
    js = text('js/broth-log-dashboard.js')
    for phrase in [
        'Selected date',
        'Business date',
        'readings recorded',
        'missing/incomplete',
        'Item / Station',
        'Entered Temp',
        'Target',
        'Status',
        'Corrective Action',
        'Employee',
    ]:
        assert phrase in js or phrase in text('css/broth-log.css')


def test_issues_only_filter_and_problem_first_sort_exist():
    js = text('js/broth-log-dashboard.js')
    assert 'data-action="issuesOnly"' in js
    assert "state.filters.issue = state.filters.issue === 'open' ? 'all' : 'open'" in js
    assert 'function recordPriority(row)' in js
    assert 'function dailyReadingRows(records' in js
    assert 'function dailyReadingRow({ record, reading })' in js
    assert "readingPriority(b.reading) - readingPriority(a.reading)" in js
    assert "state.filters.dateRange === 'today'" in js
    assert "includeSafe: state.filters.issue !== 'open' && state.filters.issue !== 'critical'" in js


def test_calendar_date_picker_and_url_state_exist():
    js = text('js/broth-log-dashboard.js')
    for phrase in [
        'getInitialSelectedDate',
        'selectedDate: getInitialSelectedDate()',
        'type="date"',
        'data-filter="selectedDate"',
        'updateDateUrl',
        'applyUrlState',
        "window.addEventListener('popstate', applyUrlState)",
        'window.history.pushState',
        "url.searchParams.set('date', state.filters.selectedDate)",
        "url.searchParams.delete('range')",
        'isValidDateKey',
    ]:
        assert phrase in js


def test_today_reset_store_and_legacy_today_url_are_preserved():
    js = text('js/broth-log-dashboard.js')
    assert 'function currentDayUrl()' in js
    assert "url.searchParams.set('range', 'today')" in js
    assert "url.searchParams.set('store', state.activeBranch)" in js
    assert 'state.filters.selectedDate = businessToday();' in js
    assert "localStorage.setItem('brothSelectedStore', state.activeBranch)" in js


def test_daily_empty_safe_issue_and_future_states_exist():
    js = text('js/broth-log-dashboard.js')
    for phrase in [
        'No Broth Log records for',
        'readings recorded · No issues',
        'Future date selected',
        'isFutureBusinessDate',
        'reading${problemReadings.length === 1 ?',
    ]:
        assert phrase in js


def test_exports_and_system_info_are_secondary():
    js = text('js/broth-log-dashboard.js')
    assert '<details class="bd-action-menu">' in js
    assert '<details class="bd-system-info">' in js
    assert 'Source rows:' in js
    assert js.index('<div class="bd-filters">') < js.index('<details class="bd-system-info">')


def test_mobile_current_day_styles_prevent_horizontal_layout_pressure():
    css = text('css/broth-log.css')
    assert '.bd-today-ops' in css
    assert '.bd-today-issue-grid' in css
    assert '.bd-date-picker' in css
    assert '.bd-daily-table' in css
    assert '.bd-daily-row' in css
    assert '@media (max-width: 720px)' in css
    assert '.bd-today-issue-grid,' in css
    assert '.bd-daily-header' in css


def test_asset_version_was_bumped():
    html = text('broth-log.html')
    assert 'daily-date-picker' in html


def main() -> int:
    tests = [(name, fn) for name, fn in globals().items() if name.startswith('test_') and callable(fn)]
    for name, fn in tests:
        fn()
        print(f'PASS {name}')
    print(f'\nAll {len(tests)} current-day dashboard UX tests passed.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
