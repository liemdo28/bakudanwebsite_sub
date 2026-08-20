<?php
declare(strict_types=1);

/**
 * Cron entrypoint for server-side Broth Log Telegram alerts.
 *
 * Required env on hosting:
 * - TELEGRAM_WEBHOOK_SECRET or TELEGRAM_CRON_SECRET
 * - BROTH_LOG_TELEGRAM_ALERT_ENDPOINT, optional, defaults to https://bakudanramen.com/api/broth-log/telegram/alerts
 *
 * Telegram bot token/chat id are read only by api/index.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const BUSINESS_TIMEZONE = 'America/Chicago';
const SHEETS = [
    'B1' => ['id' => '1-T9WLdHI1MWp0kX7U2SNPOnc7nDBnrrc0njFxBUKnqo', 'tab' => 'Form Responses 1'],
    'B2' => ['id' => '1qk78Spg8GmyP4RCjQYwU8Nm0bXdoyl240iUDcSkK3MQ', 'tab' => 'Form Responses 1'],
    'B3' => ['id' => '1odx4Xq94kz50aJBuE2Q-WcZbvXdfeVFOksOeAxn4Kxw', 'tab' => 'Form Responses 1'],
];
const HEADER_ALIASES = [
    'submittedAt' => ['timestamp'],
    'employeeName' => ['employee name / nombre del empleado'],
    'correctiveAction' => ['corrective action / accion correctiva'],
    'branch' => ['store code'],
    'businessDate' => ['business date'],
    'businessTime' => ['business time'],
    'responseId' => ['response id'],
    'walkInCoolerProduce' => ['walk-in cooler (produce)'],
    'walkInFreezer' => ['walk-in freezer', 'congelador trasero / back freezer'],
    'prepAreaCooler' => ['prep area cooler'],
    'bowlWarmer' => ['bowl warmer'],
    'ramenReachInTop' => ['ramen reach-in top'],
    'ramenReachInBelow' => ['ramen reach-in below'],
    'lineFreezer' => ['line freezer'],
    'seasonedEggs' => ['seasoned eggs'],
    'slicedPorkHot' => ['sliced pork hot'],
    'dicedPorkHot' => ['diced pork hot'],
    'tapasReachInTop' => ['tapas reach-in top'],
    'chickenCold' => ['chicken cold'],
    'porkCold' => ['pork cold'],
    'tapasReachInBelow' => ['tapas reach-in below'],
    'walkInProduceRecheck' => ['walk-in produce recheck'],
    'fryerLeft' => ['fryer left'],
    'fryerRight' => ['fryer right'],
    'pastaBoilerLeft' => ['pasta boiler left'],
    'pastaBoilerRight' => ['pasta boiler right'],
];
const READINGS = [
    ['walkInCoolerProduce', 'Walk-In Cooler (Produce)'],
    ['walkInFreezer', 'Walk-In Freezer'],
    ['prepAreaCooler', 'Prep Area Cooler'],
    ['bowlWarmer', 'Bowl Warmer'],
    ['ramenReachInTop', 'Ramen Reach-In Top'],
    ['ramenReachInBelow', 'Ramen Reach-In Below'],
    ['lineFreezer', 'Line Freezer'],
    ['seasonedEggs', 'Seasoned Eggs'],
    ['slicedPorkHot', 'Sliced Pork Hot'],
    ['dicedPorkHot', 'Diced Pork Hot'],
    ['tapasReachInTop', 'Tapas Reach-In Top'],
    ['chickenCold', 'Chicken Cold'],
    ['porkCold', 'Pork Cold Holding'],
    ['tapasReachInBelow', 'Tapas Reach-In Below'],
    ['walkInProduceRecheck', 'Walk-In Produce Recheck'],
    ['fryerLeft', 'Fryer Left'],
    ['fryerRight', 'Fryer Right'],
    ['pastaBoilerLeft', 'Pasta Boiler Left'],
    ['pastaBoilerRight', 'Pasta Boiler Right'],
];
const SOP = [
    'walkInCoolerProduce' => ['operator' => '<=', 'target' => 40, 'action' => 'Close door, re-temp in 10 min, alert MOD if still high'],
    'walkInFreezer' => ['operator' => '<=', 'target' => 0, 'action' => 'Close door, alert MOD if above 0F'],
    'prepAreaCooler' => ['operator' => '<=', 'target' => 40, 'action' => 'Alert MOD; move product if above 40F'],
    'bowlWarmer' => ['operator' => '>=', 'target' => 100, 'action' => 'Adjust warmer and re-temp'],
    'ramenReachInTop' => ['operator' => '<=', 'target' => 40, 'action' => 'Do not serve exposed product if high; cool/replace'],
    'ramenReachInBelow' => ['operator' => '<=', 'target' => 40, 'action' => 'Cover/cool/replace and alert MOD if high'],
    'lineFreezer' => ['operator' => '<=', 'target' => 0, 'action' => 'Alert MOD; verify product condition'],
    'seasonedEggs' => ['operator' => '>=', 'target' => 100, 'action' => 'Must have designated timer; verify 4-hour holding'],
    'slicedPorkHot' => ['operator' => '>=', 'target' => 100, 'action' => 'Verify SOP; if below hot holding standard, do not serve'],
    'dicedPorkHot' => ['operator' => '>=', 'target' => 100, 'action' => 'Verify SOP; if below hot holding standard, do not serve'],
    'tapasReachInTop' => ['operator' => '<=', 'target' => 40, 'action' => 'Do not serve exposed product if high; cool/replace'],
    'chickenCold' => ['operator' => '<=', 'target' => 40, 'action' => 'If above 40F, cover/cool/replace and alert MOD'],
    'porkCold' => ['operator' => '<=', 'target' => 40, 'action' => 'If above 40F, cover/cool/replace and alert MOD'],
    'tapasReachInBelow' => ['operator' => '<=', 'target' => 40, 'action' => 'Cover/cool/replace and alert MOD if high'],
    'walkInProduceRecheck' => ['operator' => '<=', 'target' => 40, 'action' => 'Close door, re-temp in 10 min, alert MOD if still high'],
    'fryerLeft' => ['operator' => '>=', 'target' => 325, 'action' => 'Adjust temperature dial and alert MOD'],
    'fryerRight' => ['operator' => '>=', 'target' => 325, 'action' => 'Adjust temperature dial and alert MOD'],
    'pastaBoilerLeft' => ['operator' => '>=', 'target' => 200, 'action' => 'Adjust temp and re-temp in 10 min'],
    'pastaBoilerRight' => ['operator' => '>=', 'target' => 200, 'action' => 'Adjust temp and re-temp in 10 min'],
];
const LOCK_TTL_SECONDS = 240;

function today_chicago(): string {
    return (new DateTimeImmutable('now', new DateTimeZone(BUSINESS_TIMEZONE)))->format('Y-m-d');
}

function norm(string $value): string {
    return strtolower(trim($value));
}

function cell_value(?array $cell): string {
    if (!$cell) return '';
    return trim((string)($cell['f'] ?? $cell['v'] ?? ''));
}

function number_or_null(string $value): ?float {
    if ($value === '') return null;
    $clean = preg_replace('/[^\d.-]/', '', $value);
    if ($clean === '' || !is_numeric($clean)) return null;
    return (float)$clean;
}

function build_index(array $cols): array {
    $labels = array_map(fn($col) => norm((string)($col['label'] ?? '')), $cols);
    $index = [];
    foreach (HEADER_ALIASES as $field => $aliases) {
        $index[$field] = -1;
        foreach ($labels as $i => $label) {
            if (in_array($label, $aliases, true)) {
                $index[$field] = $i;
                break;
            }
        }
    }
    return $index;
}

function gviz_json(string $branch): array {
    $cfg = SHEETS[$branch];
    $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($cfg['id']) . '/gviz/tq?tqx=out:json&sheet=' . rawurlencode($cfg['tab']);
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) throw new RuntimeException("$branch sheet request failed");
    if (preg_match('/setResponse\((.*)\);?\s*$/s', $raw, $m)) $raw = $m[1];
    $json = json_decode($raw, true);
    if (!is_array($json) || ($json['status'] ?? '') !== 'ok') throw new RuntimeException("$branch sheet response invalid");
    return $json['table'] ?? ['cols' => [], 'rows' => []];
}

function severity_for(array $sop, ?float $temp): string {
    if ($temp === null) return 'missing';
    if (abs($temp) > 500) return 'critical';
    $variance = $sop['operator'] === '<=' ? $temp - $sop['target'] : $sop['target'] - $temp;
    if ($variance <= 0) return 'safe';
    if ($variance <= 2) return 'warning';
    if ($variance <= 5) return 'high';
    return 'critical';
}

function alerts_from_branch(string $branch, string $date): array {
    $table = gviz_json($branch);
    $index = build_index($table['cols'] ?? []);
    $alerts = [];
    foreach (($table['rows'] ?? []) as $row) {
        $cells = $row['c'] ?? [];
        $get = fn(string $field) => ($index[$field] ?? -1) >= 0 ? cell_value($cells[$index[$field]] ?? null) : '';
        $businessDate = $get('businessDate');
        if ($businessDate !== $date) continue;
        $responseId = $get('responseId') ?: implode('|', [$branch, $businessDate, $get('businessTime'), $get('employeeName')]);
        foreach (READINGS as [$key, $label]) {
            $sop = SOP[$key] ?? null;
            if (!$sop) continue;
            $temp = number_or_null($get($key));
            if (severity_for($sop, $temp) !== 'critical') continue;
            $alerts[] = [
                'branch' => $get('branch') ?: $branch,
                'responseId' => $responseId,
                'station' => $label,
                'severity' => 'critical',
                'businessDate' => $businessDate,
                'businessTime' => $get('businessTime'),
                'employee' => $get('employeeName') ?: 'Unassigned',
                'temperature' => $temp === null ? 'Not recorded' : rtrim(rtrim((string)$temp, '0'), '.') . 'F',
                'target' => $sop['operator'] . ' ' . $sop['target'] . 'F',
                'correctiveAction' => $get('correctiveAction') ?: $sop['action'],
            ];
        }
    }
    return $alerts;
}

function post_alerts(array $alerts): array {
    $endpoint = trim((string)(getenv('BROTH_LOG_TELEGRAM_ALERT_ENDPOINT') ?: 'https://bakudanramen.com/api/broth-log/telegram/alerts'));
    $parts = parse_url($endpoint);
    if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
        throw new RuntimeException('BROTH_LOG_TELEGRAM_ALERT_ENDPOINT must be an https URL');
    }
    $secret = trim((string)(getenv('TELEGRAM_WEBHOOK_SECRET') ?: getenv('TELEGRAM_CRON_SECRET') ?: getenv('BROTH_LOG_TELEGRAM_CRON_SECRET') ?: ''));
    if ($secret === '') throw new RuntimeException('TELEGRAM_WEBHOOK_SECRET is required');
    if (preg_match('/[\r\n]/', $secret)) throw new RuntimeException('Telegram webhook secret is invalid');
    $payload = json_encode(['alerts' => $alerts]);
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'timeout' => 20,
        'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\nX-Broth-Log-Cron-Secret: $secret\r\n",
        'content' => $payload,
    ]]);
    $raw = @file_get_contents($endpoint, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int)$m[1];
    if ($raw === false || $code < 200 || $code >= 300) throw new RuntimeException("alert endpoint failed with HTTP $code");
    $json = json_decode($raw, true);
    if (!is_array($json)) throw new RuntimeException('alert endpoint returned invalid JSON');
    return $json;
}

function acquire_lock() {
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bakudan-broth-log-telegram.lock';
    if (is_file($path) && time() - (int)filemtime($path) > LOCK_TTL_SECONDS) {
        @unlink($path);
    }
    $fh = fopen($path, 'c');
    if (!$fh) throw new RuntimeException('Unable to open cron lock');
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another broth Telegram cron run is active');
    }
    ftruncate($fh, 0);
    fwrite($fh, (string)getmypid());
    return $fh;
}

function option_enabled(array $argv, string $option): bool {
    return in_array($option, $argv, true);
}

try {
    $lock = acquire_lock();
    $dryRun = option_enabled($argv, '--dry-run');
    $date = today_chicago();
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg)) {
            $date = $arg;
            break;
        }
    }
    $alerts = [];
    foreach (array_keys(SHEETS) as $branch) {
        $alerts = array_merge($alerts, alerts_from_branch($branch, $date));
    }
    if ($dryRun) {
        echo json_encode(['dry_run' => true, 'date' => $date, 'critical_alerts' => count($alerts)], JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }
    $result = post_alerts($alerts);
    echo json_encode(['date' => $date, 'critical_alerts' => count($alerts), 'result' => $result], JSON_PRETTY_PRINT) . PHP_EOL;
    flock($lock, LOCK_UN);
    fclose($lock);
} catch (Throwable $e) {
    $message = preg_replace('/[0-9]{8,12}:AA[A-Za-z0-9_-]{20,}/', '[redacted-token]', $e->getMessage()) ?: 'cron failed';
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
