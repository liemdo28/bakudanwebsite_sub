# Broth Log Temperature Visualization

Generated: 2026-07-21

## Model

The detail drawer uses an SOP deviation model, not a normalized safety-score progress bar.

Each station displays:

- current temperature from the Google Sheet
- SOP target from `TEMPERATURE_SOP`
- deviation from that target
- severity text
- trend versus the previous available reading for the same station and branch
- recorded timestamp
- recorder name

## SOP Comparison

The dashboard supports both maximum and minimum thresholds:

| Operator | Safe condition | Deviation basis |
|---|---|---|
| `<=` | current temperature is less than or equal to target | current minus target |
| `>=` | current temperature is greater than or equal to target | current minus target |

Examples:

| Current | Target | Displayed deviation | Status meaning |
|---:|---|---|---|
| `45F` | `<= 40F` | `+5F above limit` | High |
| `32F` | `<= 40F` | `-8F below/equal limit` | Safe |
| `153F` | `>= 100F` | `+53F above minimum` | Safe |
| `95F` | `>= 100F` | `-5F below minimum` | High |

## Severity Rules

Severity is configured in `SEVERITY_RULES`:

| Severity | Variance outside SOP |
|---|---:|
| Safe | `<= 0F` outside target |
| Warning | `> 0F` and `<= 2F` outside target |
| High | `> 2F` and `<= 5F` outside target |
| Critical | `> 5F` outside target |
| Missing | blank, malformed, or unavailable reading |

Color is paired with text so users do not need to rely on color alone:

- Safe: green
- Warning: yellow
- High: orange
- Critical: red
- Missing: neutral gray

## Trend Logic

For each station, the dashboard looks for the most recent prior record in the same branch with a numeric reading for the same canonical station key.

Trend text:

- `Up +nF in x min/hr`
- `Down -nF in x min/hr`
- `Stable`
- `No prior reading`

The trend also notes risk direction:

- For maximum-threshold stations (`<=`), rising temperature increases risk.
- For minimum-threshold stations (`>=`), falling temperature increases risk.

## Category Mapping

Station grouping is defined in `STATION_GROUPS`:

- Cold Storage
- Freezers
- Prep and Service Line
- Hot Holding
- Cooked Food
- Cooking Equipment
- Other

B3 `Congelador trasero / Back Freezer` still maps to canonical `walkInFreezer`, so it uses the same freezer SOP target.

## Missing And Invalid Data

Missing/non-numeric readings are:

- shown as `Not recorded`
- classified as `Missing`
- styled neutral gray
- excluded from average/min/max calculations
- counted as validation/issue warnings

The dashboard does not infer blanks as zero.

## Known Limitations

- Trend only compares submitted records already loaded in the browser.
- The static dashboard polls Google Sheets; it is not a push realtime system.
- Severity thresholds are deterministic and should be reviewed whenever Bakudan SOP changes.
