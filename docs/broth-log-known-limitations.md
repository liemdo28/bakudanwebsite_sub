# Broth Log Dashboard Known Limitations

Generated: 2026-07-21

## Static-Site Scope Limitations

| Limitation | Status | Notes |
|---|---|---|
| Role-based permissions | Not working | Requires backend authentication/session data. Not added per request. |
| Private Google Sheets API service account sync | Not working | Current implementation uses public Google Visualization JSONP exports. |
| Server-side cache | Not working | Static dashboard keeps state in-browser only. |
| Server-side audit trail | Not working | No backend exists to record who viewed/exported/changed dashboard state. |
| Scheduled reports | Not working | CSV/Excel/print exports work manually; scheduled daily/weekly/monthly delivery needs backend or automation integration. |
| Slack/email alerts | Not working | Notification Center is in-app only. External messages require backend/API credentials. |
| PDF export file generation | Partially working | Browser print flow works and can be saved as PDF by the user; no generated PDF file is created by the static app. |
| Excel export fidelity | Partially working | `.xls` export is Excel-compatible HTML, not a native `.xlsx` workbook. |
| Realtime updates | Partially working | Auto refresh is configurable at 30 seconds, 1 minute, 2 minutes, or 5 minutes. It is polling, not push realtime. |
| Pagination | Partially working | Current journal scrolls and home limits the embedded table; full numbered pagination is not implemented. |

## Data And Schema Limitations

| Limitation | Status | Notes |
|---|---|---|
| Public sheet availability | Partially working | Dashboard depends on Google public/export access staying enabled for the three provided sheets. |
| Hidden tabs | Not testable | Google worksheet-list feed was not available publicly; the visible spreadsheet bootstrap showed `Form Responses 1`. |
| Empty submitted rows | Partially working | B3 contains rows with branch/date/shift but empty employees/readings. Dashboard keeps them and marks missing readings/issues. |
| Unit assumptions | Partially working | Sheet values are treated as Fahrenheit based on current San Antonio food-safety data. |
| Broth-specific naming | Partially working | Current sheets are broad food-safety station logs, not broth-only batch logs. Dashboard labels the module as broth/food-safety operations and maps station readings canonically. |
| Issue severity rules | Partially working | Rules now use the provided SOP threshold workbook. Severity is still deterministic and should be reviewed by QA/operations as store SOP changes. |
| Duplicate handling with blank response IDs | Verified working | Falls back to branch/date/time/employee/submitted timestamp. No duplicates were found in current data. |

## Browser Verification Limitations

| Limitation | Status | Notes |
|---|---|---|
| Codex in-app browser localhost access | Not testable | The in-app browser reported loopback connection refused even while host `curl` and Chromium could load the route. |
| Production domain verification | Not testable | User explicitly requested not to upload production before verification; tests were local only. |
| Print dialog completion | Partially working | The print function was invoked in Chromium; saving a PDF is a browser/user action and was not completed automatically. |

## Recommended Next Steps Before Broader Rollout

1. Keep the SOP threshold workbook and dashboard mapping reviewed together whenever operations changes safe temperature targets.
2. Add backend auth before enabling employee/role-specific data restrictions.
3. Add backend scheduled jobs if email/Slack/PDF reports are required.
4. Add a native `.xlsx` export path if Excel formatting becomes important.
5. Add numbered journal pagination if logs grow beyond comfortable in-browser scrolling.
