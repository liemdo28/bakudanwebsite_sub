# Broth Log Layout Redesign

Generated: 2026-07-21

## Before

The prior UI had useful functionality but weak operational hierarchy:

- journal and detail were not organized as a true master-detail workflow
- the right-side drawer/detail view was dense and hard to scan
- station rows compressed current, target, deviation, trend, metadata, and graphic into a row
- status summary appeared as small badges instead of scan-friendly KPIs

## After

The dashboard now uses a professional operations-style master-detail layout:

- left panel: Daily Log Journal
- right panel: the currently selected log
- only one log is selected/expanded at a time
- clicking a journal item updates the right panel immediately
- the selected row is visually highlighted

The detail panel is ordered for operational triage:

1. Overview
2. Temperature History
3. Issues
4. Timeline
5. Compliance
6. Employee / Metadata
7. Notes

## Temperature History

Station readings are now displayed as spacious information cards. Each card prioritizes:

1. station name
2. status
3. current temperature
4. deviation
5. target
6. trend
7. recorded timestamp
8. recorder

The SOP comparison graphic is a wider neutral deviation track with visible `SOP` and `Current` markers. It is not a safety-score progress bar.

## Status Summary

The old compact badges were replaced with KPI cards:

- Safe
- Warning
- High
- Critical
- Missing

These KPIs appear at the top of Temperature History.

## Responsive Behavior

Desktop:

- two-column master-detail layout
- journal remains visible while reviewing detail
- detail content uses card grids with wide comparison graphics

Tablet:

- master-detail stacks into one column
- journal remains above selected detail
- station cards reduce to one-column cards

Mobile:

- no horizontal scrolling
- journal becomes a vertical list
- selected detail appears underneath the list
- station metadata stacks cleanly

## Accessibility

Improvements:

- journal entries are buttons with visible focus states
- selected journal entry uses `aria-selected`
- status is shown as text, not color alone
- large click targets for journal selection
- detail updates use an `aria-live` region

## Known Limitations

- The static dashboard does not implement user roles.
- The journal panel is not virtualized; it is fine for current row volume but may need virtualization if logs grow substantially.
- Production verification must be repeated after deployment.
