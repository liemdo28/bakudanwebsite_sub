# Broth Log Canonical Data Model

## Source Workbooks

| Branch | Sheet title | Workbook ID | Visible tab | Notes |
|---|---|---|---|---|
| B1 | Food Safety | `1-T9WLdHI1MWp0kX7U2SNPOnc7nDBnrrc0njFxBUKnqo` | `Form Responses 1` | Default export returns B1 rows. |
| B2 | Food Safety Stone Oak (Responses) | `1qk78Spg8GmyP4RCjQYwU8Nm0bXdoyl240iUDcSkK3MQ` | `Form Responses 1` | Default export returns B2 rows. |
| B3 | Food Safety | `1odx4Xq94kz50aJBuE2Q-WcZbvXdfeVFOksOeAxn4Kxw` | `Form Responses 1` | Back freezer header is bilingual: `Congelador trasero / Back Freezer`. |

Google's public worksheet-list feed returned a Google "not found/open later" page, but the spreadsheet UI bootstrap and the Google Visualization export both exposed a single visible tab, `Form Responses 1`, for the inspected workbooks.

## Observed Headers

Shared fields:

| Column | Header | Canonical field |
|---|---|---|
| A | Timestamp | `submittedAt` |
| B | Employee Name / Nombre del empleado | `employeeName` |
| C | Walk-In Cooler (Produce) | `reading.walkInCoolerProduce` |
| D | Walk-In Freezer or Congelador trasero / Back Freezer | `reading.walkInFreezer` |
| E | Prep Area Cooler | `reading.prepAreaCooler` |
| F | Bowl Warmer | `reading.bowlWarmer` |
| G | Ramen Reach-In Top | `reading.ramenReachInTop` |
| H | Ramen Reach-In Below | `reading.ramenReachInBelow` |
| I | Line Freezer | `reading.lineFreezer` |
| J | Seasoned Eggs | `reading.seasonedEggs` |
| K | Sliced Pork Hot | `reading.slicedPorkHot` |
| L | Diced Pork Hot | `reading.dicedPorkHot` |
| M | Tapas Reach-In Top | `reading.tapasReachInTop` |
| N | Chicken Cold | `reading.chickenCold` |
| O | Pork Cold | `reading.porkCold` |
| P | Tapas Reach-In Below | `reading.tapasReachInBelow` |
| Q | Walk-In Produce Recheck | `reading.walkInProduceRecheck` |
| R | Fryer Left | `reading.fryerLeft` |
| S | Fryer Right | `reading.fryerRight` |
| T | Pasta Boiler Left | `reading.pastaBoilerLeft` |
| U | Pasta Boiler Right | `reading.pastaBoilerRight` |
| V | Notes / Notas | `notes` |
| W | Corrective Action / Accion correctiva | `correctiveAction` |
| X | Manager Comment / Comentario del manager | `managerComment` |
| Y | Store Code | `branch` |
| Z | Business Date | `businessDate` |
| AA | Business Time | `businessTime` |
| AB | Shift | `shift` |
| AC | Response ID | `responseId` |

Branch-specific differences:

| Branch | Difference | Mapping |
|---|---|---|
| B3 | `Congelador trasero / Back Freezer` replaces `Walk-In Freezer`. | Both normalize to `reading.walkInFreezer`. |
| B1/B2/B3 | Record volume differs by branch. | Dashboard treats missing/null cells as missing readings, not schema failure. |

## Canonical Record

```json
{
  "id": "B1|2026-07-15|18:22|Omar",
  "sourceSheetId": "1-T9WLdHI1MWp0kX7U2SNPOnc7nDBnrrc0njFxBUKnqo",
  "sourceTab": "Form Responses 1",
  "branch": "B1",
  "submittedAt": "2026-07-15T23:22:22.000Z",
  "businessDate": "2026-07-15",
  "businessTime": "18:22",
  "shift": "4PM",
  "employeeName": "Omar",
  "notes": "",
  "correctiveAction": "",
  "managerComment": "",
  "responseId": "",
  "readings": [
    {
      "key": "walkInCoolerProduce",
      "label": "Walk-In Cooler (Produce)",
      "category": "cold",
      "temperature": 40,
      "unit": "F",
      "status": "ok",
      "issueType": null,
      "severity": "normal"
    }
  ],
  "issues": [],
  "metrics": {
    "averageTemperature": 88.1,
    "highestTemperature": 352,
    "lowestTemperature": 10,
    "standardDeviation": 101.2,
    "complianceRate": 0.94,
    "missingReadings": 0,
    "riskScore": 8
  },
  "validation": {
    "duplicate": false,
    "missingRequired": [],
    "warnings": []
  }
}
```

## Validation And Compliance Rules

The sheet stores broad food-safety temperatures, not only broth temperatures. The dashboard therefore models each submitted row as one operations log containing multiple station readings.

| Category | Fields | Expected range | Issue examples |
|---|---|---|---|
| Cold holding | walk-in cooler, prep cooler, reach-ins, eggs, cold chicken/pork | `<= 41F`; freezer fields `<= 10F` | Temperature Too High, Missing Reading |
| Hot holding | bowl warmer, seasoned eggs, sliced/diced pork hot | `>= 135F`, except bowl warmer monitored as warm equipment | Temperature Too Low |
| Cooking equipment | fryers, pasta boilers | fryer `325F-375F`, boiler `200F-214F` | Temperature Too Low/High, Sensor Error |

Deduplication key priority:

1. `responseId` when populated.
2. `branch|businessDate|businessTime|employeeName|submittedAt`.

## Implementation Notes

The production pages use:

- Google Visualization JSONP for direct, public sheet reads.
- A 1-5 minute configurable refresh interval stored in `localStorage`.
- A canonical normalization layer in `js/broth-log-dashboard.js`.
- Shared UI components for KPIs, journal, detail drawer, charts, issue lists, branch comparison, employee and compliance analytics.
- Client-side exports for CSV, Excel-compatible HTML, print, and browser PDF via print-to-PDF.

Limitations:

- No private Google Sheets API service account is configured in this static site, so sync depends on the public/shareable Google Sheets export staying accessible.
- Scheduled email/Slack reports and role-based authentication need a backend or existing admin auth integration; the dashboard includes UI/status surfaces but does not send notifications.
