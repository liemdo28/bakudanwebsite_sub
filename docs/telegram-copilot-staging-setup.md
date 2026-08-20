# Telegram Copilot Staging Setup

This runbook prepares staging only. It does not activate production Copilot.

## Production Defaults To Preserve

- `TELEGRAM_COPILOT_ENABLED=false`
- no active production routing
- no production Copilot webhook registration
- no production Copilot worker cron
- no LLM
- existing one-way critical-alert cron remains unchanged

## Staging Requirements

Use separate staging resources:

- Telegram staging bot, separate from production
- private staging Telegram chat
- staging inbound webhook secret
- staging callback signing secret
- isolated staging SQLite database/state
- staging/test users only
- TEST-prefixed messages
- no production manager routing data

Telegram permits one webhook per bot. Do not register the production bot to a staging endpoint.

## Environment Variables

Configure these outside Git and outside the public web root:

- `TELEGRAM_COPILOT_ENABLED`: enable only in staging.
- `TELEGRAM_BOT_TOKEN`: staging bot token.
- `TELEGRAM_CHAT_ID`: private staging chat ID.
- `TELEGRAM_INCOMING_SECRET`: staging webhook secret.
- `TELEGRAM_CALLBACK_SECRET`: staging callback signing secret.
- `TELEGRAM_LEVEL3_FALLBACK`: staging fallback label/action.
- `BAKUDAN_DB_PATH`: isolated staging SQLite path.
- `BAKUDAN_TELEGRAM_ENV_FILE`: private staging env file path.

Do not paste these values into chat, GitHub comments, screenshots, logs, or source.

## Human Setup Boundary

BLOCKED - human action required:

1. Create a separate Telegram staging bot with BotFather.
2. Create a private staging Telegram chat.
3. Add the staging bot to the staging chat.
4. Collect the staging chat ID without exposing it in chat or Git.
5. Generate separate staging webhook and callback secrets.
6. Store all staging values directly on the staging server/environment.
7. Confirm the staging endpoint URL that will receive the webhook.

## Webhook Registration

Only after the staging bot and staging endpoint exist, register the webhook for the staging bot only.

Required Telegram API shape:

```text
setWebhook
url=<staging https endpoint>/api/broth-log/telegram/webhook
secret_token=<TELEGRAM_INCOMING_SECRET>
allowed_updates=["message","callback_query"]
```

Do not run this against the production bot.

## Manager Onboarding Template

Each staging user must be added by numeric Telegram user ID:

| Field | Required | Notes |
|---|---:|---|
| Numeric Telegram user ID | Yes | Verify out-of-band. |
| Display name | Yes | For human readability only. |
| Role | Yes | Example: manager, area_manager, admin. |
| Allowed branches | Yes | Explicit list such as `["B1"]`. |
| Preferred language | Yes | `en`, `es`, or `vi`. |
| Active | Yes | Must remain false until verified. |
| Escalation level | Yes | Operations-approved value. |
| Backup user ID | Optional | Numeric Telegram user ID only. |

Never authorize by Telegram username alone.

## Staging Verification Checklist

Feature flag off:

- inbound enqueue blocked
- inbox processing blocked
- ACK blocked
- resolve blocked
- incident creation blocked
- escalation blocked
- worker returns disabled / processed 0

Webhook:

- valid staging secret accepted
- invalid/missing secret rejected
- duplicate `update_id` suppressed
- unsupported payload handled safely
- raw Telegram update JSON is not stored
- sanitized metadata only

Authorization:

- deny by default
- numeric Telegram user ID required
- username alone denied
- inactive users denied
- branch permissions enforced
- cross-branch query denied
- cross-branch ACK/resolve denied

Callback:

- signed callback accepted
- expired callback rejected
- callback consumed once
- replay rejected

Incident workflow:

- ACK works for correct incident
- ACK stops reminders
- resolve requires safe recheck temperature and corrective-action note
- critical -> safe -> critical creates a new incident
- fake-clock escalation follows 0/3/6/9 minute schedule
- stale two-worker escalation snapshot is rejected

Existing one-way alert cron:

- dry-run mode still works
- remains independent of Copilot

## Production Activation Blockers

- verified numeric Telegram user IDs
- branch permissions per manager
- approved B1/B2/B3 routing
- B1 7-day pilot approval/window
- emergency fallback owner/action
- Operations/legal approval for 12-month incident/audit retention
