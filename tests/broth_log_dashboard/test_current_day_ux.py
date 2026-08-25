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
    assert 'vn-to-cdt' in html


def test_dashboard_uses_paper_sop_ranges_and_texas_time():
    js = text('js/broth-log-dashboard.js')
    for phrase in [
        'min: 30',
        'max: 45',
        'min: -20',
        'max: 5',
        'min: 350',
        'max: 360',
        'function dateFromBusinessTimeParts',
        'function alignDateToBusinessDate',
        "BUSINESS_TIMEZONE_LABEL = 'San Antonio time'",
        "SHEET_TIMESTAMP_TIMEZONE = 'Asia/Ho_Chi_Minh'",
        'BUSINESS_DAY_START_HOUR',
        'function parseSheetTimestampString',
        "timeZone: BUSINESS_TIMEZONE",
        "hour12: true",
        "timeZoneName: 'short'",
    ]:
        assert phrase in js


def test_current_day_cards_and_table_show_shift():
    js = text('js/broth-log-dashboard.js')
    css = text('css/broth-log.css')
    for phrase in [
        'displayShift(record.shift, record.submittedAt)',
        'displayShift(row.shift, row.submittedAt)',
        '<div><dt>Shift</dt><dd>',
        '<span>Time</span><span>Item / Station</span><span>Employee</span><span>Shift</span>',
    ]:
        assert phrase in js
    assert 'content: "Shift: "' in css


def test_shift_filter_uses_inferred_am_pm_shifts():
    js = text('js/broth-log-dashboard.js')
    assert "inferShift(row.shift, row.submittedAt) !== state.filters.shift" in js
    assert "option('AM', 'AM shift', state.filters.shift)" in js
    assert "option('PM', 'PM shift', state.filters.shift)" in js
    assert "state.records.map(r => r.shift).filter(Boolean)" not in js


def test_manager_range_editor_tool_exists_and_recalculates():
    js = text('js/broth-log-dashboard.js')
    css = text('css/broth-log.css')
    for phrase in [
        "RANGE_STORAGE_KEY = 'brothTemperatureRangesV1'",
        "RANGE_API = '/api/broth-log/ranges'",
        'function rangeEditor()',
        'data-action="toggleRanges"',
        'id="rangeEditorForm"',
        'data-range-min=',
        'data-range-max=',
        'Save ranges',
        'data-action="resetRanges"',
        'function saveRangesFromForm',
        'function loadSharedRanges',
        'function saveSharedRanges',
        "localStorage.getItem('bkdn_token')",
        'await saveSharedRanges(ranges)',
        'rebuildRecordsWithCurrentRanges()',
        'Shared ranges saved. Current readings recalculated for all managers.',
    ]:
        assert phrase in js
    assert '.bd-range-tool' in css
    assert '.bd-range-row' in css


def test_shared_range_api_routes_exist():
    api = text('api/index.php')
    for phrase in [
        "function validate_broth_log_ranges",
        "$path === '/broth-log/ranges' && $METHOD === 'GET'",
        "$path === '/broth-log/ranges' && in_array($METHOD, ['POST', 'PUT', 'PATCH'], true)",
        "role_check($user, $EDIT)",
        "broth_log_temperature_ranges",
        "audit_log($user, 'broth_log_ranges_update'",
    ]:
        assert phrase in api


def main() -> int:
    tests = [(name, fn) for name, fn in globals().items() if name.startswith('test_') and callable(fn)]
    for name, fn in tests:
        fn()
        print(f'PASS {name}')
    print(f'\nAll {len(tests)} current-day dashboard UX tests passed.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
