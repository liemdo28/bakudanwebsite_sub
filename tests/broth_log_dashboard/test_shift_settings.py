"""Static regression checks for the Broth Log Settings screen and shift-compliance UX."""
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding='utf-8')


def test_ranges_button_renamed_to_settings():
    js = text('js/broth-log-dashboard.js')
    assert 'data-action="toggleRanges"' in js
    assert '>Settings</button>' in js
    assert '>Ranges</button>' not in js


def test_settings_summary_shows_authoritative_temperature_ranges():
    js = text('js/broth-log-dashboard.js')
    assert 'function settingsSummary()' in js
    assert 'A. Temperature Ranges' in js
    assert 'B. Shift Record Times' in js
    assert 'the same ranges used for alert detection and Resolve validation' in js


def test_legacy_range_editor_is_clearly_labeled_non_authoritative():
    js = text('js/broth-log-dashboard.js')
    assert 'Advanced: Custom Display Ranges' in js
    assert 'does not change the ranges used for real alert detection or Resolve validation' in js


def test_settings_fetches_canonical_config_from_dedicated_endpoint():
    js = text('js/broth-log-dashboard.js')
    assert "const SETTINGS_API = '/api/broth-log/settings';" in js
    assert 'async function loadCanonicalSettings()' in js
    assert 'loadCanonicalSettings()' in js  # called somewhere, not just defined


def test_php_settings_endpoint_reads_canonical_sop_not_the_inert_custom_ranges_row():
    php = text('api/index.php')
    assert "$path === '/broth-log/settings'" in php
    assert 'foreach (BROTH_LOG_SOP as $key => $sop)' in php


def test_shift_window_constants_match_business_rule():
    php = text('api/broth-log-core.php')
    assert "'AM' => ['start' => '10:00', 'end' => '11:00']" in php
    assert "'PM' => ['start' => '16:00', 'end' => '17:00']" in php
    js = text('js/broth-log-dashboard.js')
    assert "AM: { start: '10:00', end: '11:00' }" in js
    assert "PM: { start: '16:00', end: '17:00' }" in js


def test_shift_compliance_functions_exist_in_both_php_and_js():
    php = text('api/broth-log-core.php')
    for fn in ['broth_log_shift_assignment', 'broth_log_shift_timing_status', 'broth_log_shift_daily_status']:
        assert f'function {fn}(' in php
    js = text('js/broth-log-dashboard.js')
    for fn in ['function shiftAssignment(', 'function shiftTimingStatus(', 'function shiftDailyStatus(']:
        assert fn in js


def test_shift_compliance_uses_unfiltered_records_not_the_display_filtered_list():
    js = text('js/broth-log-dashboard.js')
    # Regression: the shift compliance block must read from state.recordsByBranch directly,
    # never from the already issue/shift-filtered `records` array a caller might pass in -
    # otherwise "Issues Only" mode would wrongly report a clean, on-time submission as MISSING.
    assert 'function shiftComplianceBlock(selectedDate)' in js
    assert 'state.recordsByBranch[branch] || []).filter(row => row.businessDate === selectedDate)' in js


def test_shift_compliance_block_is_independent_of_temperature_status():
    js = text('js/broth-log-dashboard.js')
    assert 'function shiftComplianceBlock' in js
    assert '${shiftComplianceBlock(selectedDate)}' in js
    # Rendered as its own section, not merged into the temperature status text/class.
    assert 'bd-shift-compliance' in js


def test_not_yet_due_vs_missing_labels_present():
    js = text('js/broth-log-dashboard.js')
    for label in ['On Time', 'Early', 'Late', 'Missing', 'Not Yet Due']:
        assert label in js
