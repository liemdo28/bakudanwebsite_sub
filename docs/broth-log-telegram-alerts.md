# Broth Log Telegram Alerts

## Security

- Revoke any bot token that has been pasted into chat, tickets, screenshots, logs, or docs.
- Configure a new token only in hosting environment variables.
- Do not place real values in `.env.example`, source files, screenshots, or client-side JavaScript.

## Environment Variables

- `TELEGRAM_ALERTS_ENABLED`: `true` to send alerts, `false` to dry-run/store status.
- `TELEGRAM_BOT_TOKEN`: new Telegram bot token from BotFather.
- `TELEGRAM_CHAT_ID`: target chat/channel id.
- `TELEGRAM_WEBHOOK_SECRET`: random secret for the test endpoint and cron alert ingestion.
- `BROTH_LOG_TELEGRAM_ALERT_ENDPOINT`: optional; defaults to `https://www.bakudanramen.com/api/broth-log/telegram/alerts`.

Production also reads the same values from this private file when present:

```text
/home/hoale24new/bakudan-app/config/broth-log-telegram.env
```

Keep this file outside the public web root with owner-only permissions.

## Endpoints

- `GET /api/broth-log/telegram/status`: admin JWT required. Returns only enabled/configured flags and last successful send time.
- `POST /api/broth-log/telegram/test`: admin JWT or `X-Broth-Log-Telegram-Secret` required. Sends a test critical alert when enabled/configured.
- `POST /api/broth-log/telegram/alerts`: admin JWT or `X-Broth-Log-Cron-Secret` required. Used by cron ingestion.

No endpoint returns the bot token or chat id.

## Cron

Run every 5 minutes on hosting:

```bash
*/5 * * * * /usr/bin/php /home/hoale24new/bakudanramen.com/scripts/broth-log-telegram-cron.php >> /home/hoale24new/bakudan-app/logs/broth-telegram.log 2>&1
```

The cron script is CLI-only, uses a short-lived lock to avoid overlapping runs, fetches the Google Sheets server-side, checks only today's `businessDate` in `America/Chicago`, extracts critical station readings, and posts them to the API for de-duplication and delivery.

Dry-run before enabling live sends:

```bash
/usr/bin/php /home/hoale24new/bakudanramen.com/scripts/broth-log-telegram-cron.php --dry-run
```

## De-Duplication

Each alert uses a stable fingerprint from store branch, response ID, station, and severity. The API takes an atomic SQLite write lock before sending so overlapping cron runs cannot send the same open critical incident twice. A matching open critical alert is not sent again after dashboard refresh or repeated cron runs. If an alert disappears from the current critical set, it is marked resolved; if it later becomes critical again, it can be sent again.
