# Broth Log Telegram Alerts

## Security

- Revoke any bot token that has been pasted into chat, tickets, screenshots, logs, or docs.
- Configure a new token only in hosting environment variables.
- Do not place real values in `.env.example`, source files, screenshots, or client-side JavaScript.

## Environment Variables

- `TELEGRAM_ALERTS_ENABLED`: `true` to send alerts, `false` to dry-run/store status.
- `TELEGRAM_BOT_TOKEN`: new Telegram bot token from BotFather.
- `TELEGRAM_CHAT_ID`: target chat/channel id.
- `TELEGRAM_TEST_SECRET`: random secret for `POST /api/broth-log/telegram/test`.
- `TELEGRAM_CRON_SECRET`: random secret used by the cron script and alert ingestion endpoint.
- `BROTH_LOG_TELEGRAM_ALERT_ENDPOINT`: optional; defaults to `https://bakudanramen.com/api/broth-log/telegram/alerts`.

## Endpoints

- `GET /api/broth-log/telegram/status`: admin JWT required. Returns only enabled/configured flags and last successful send time.
- `POST /api/broth-log/telegram/test`: admin JWT or `X-Broth-Log-Telegram-Secret` required. Sends a test critical alert when enabled/configured.
- `POST /api/broth-log/telegram/alerts`: admin JWT or `X-Broth-Log-Cron-Secret` required. Used by cron ingestion.

No endpoint returns the bot token or chat id.

## Cron

Run every 5 minutes on hosting:

```bash
*/5 * * * * TELEGRAM_CRON_SECRET="replace-with-random-cron-secret" php /home/hoale24new/bakudanramen.com/scripts/broth-log-telegram-cron.php >/dev/null 2>&1
```

The cron script fetches the Google Sheets server-side, checks only today's `businessDate` in `America/Chicago`, extracts critical station readings, and posts them to the API for de-duplication and delivery.

## De-Duplication

Each alert uses a stable fingerprint from store branch, response ID, station, and severity. A matching open critical alert is not sent again after dashboard refresh or repeated cron runs. If an alert disappears from the current critical set, it is marked resolved; if it later becomes critical again, it can be sent again.
