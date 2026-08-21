<?php
declare(strict_types=1);

const BROTH_LOG_BUSINESS_TIMEZONE = 'America/Chicago';
const BROTH_LOG_BRANCHES = [
    'B1' => ['id' => '1-T9WLdHI1MWp0kX7U2SNPOnc7nDBnrrc0njFxBUKnqo', 'tab' => 'Form Responses 1', 'name' => 'B1 The Rim'],
    'B2' => ['id' => '1qk78Spg8GmyP4RCjQYwU8Nm0bXdoyl240iUDcSkK3MQ', 'tab' => 'Form Responses 1', 'name' => 'B2 Stone Oak'],
    'B3' => ['id' => '1odx4Xq94kz50aJBuE2Q-WcZbvXdfeVFOksOeAxn4Kxw', 'tab' => 'Form Responses 1', 'name' => 'B3 Bandera'],
];
const BROTH_LOG_HEADER_ALIASES = [
    'submittedAt' => ['timestamp'],
    'employeeName' => ['employee name / nombre del empleado'],
    'notes' => ['notes / notas'],
    'correctiveAction' => ['corrective action / accion correctiva'],
    'managerComment' => ['manager comment / comentario del manager'],
    'branch' => ['store code'],
    'businessDate' => ['business date'],
    'businessTime' => ['business time'],
    'shift' => ['shift'],
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
const BROTH_LOG_READINGS = [
    ['walkInCoolerProduce', 'Walk-In Cooler (Produce)', 'cold'],
    ['walkInFreezer', 'Walk-In Freezer', 'freezer'],
    ['prepAreaCooler', 'Prep Area Cooler', 'cold'],
    ['bowlWarmer', 'Bowl Warmer', 'warm'],
    ['ramenReachInTop', 'Ramen Reach-In Top', 'cold'],
    ['ramenReachInBelow', 'Ramen Reach-In Below', 'cold'],
    ['lineFreezer', 'Line Freezer', 'freezer'],
    ['seasonedEggs', 'Seasoned Eggs', 'hot'],
    ['slicedPorkHot', 'Sliced Pork Hot', 'hot'],
    ['dicedPorkHot', 'Diced Pork Hot', 'hot'],
    ['tapasReachInTop', 'Tapas Reach-In Top', 'cold'],
    ['chickenCold', 'Chicken Cold', 'cold'],
    ['porkCold', 'Pork Cold Holding', 'cold'],
    ['tapasReachInBelow', 'Tapas Reach-In Below', 'cold'],
    ['walkInProduceRecheck', 'Walk-In Produce Recheck', 'cold'],
    ['fryerLeft', 'Fryer Left', 'fryer'],
    ['fryerRight', 'Fryer Right', 'fryer'],
    ['pastaBoilerLeft', 'Pasta Boiler Left', 'boiler'],
    ['pastaBoilerRight', 'Pasta Boiler Right', 'boiler'],
];
const BROTH_LOG_SOP = [
    'walkInCoolerProduce' => ['category' => 'Cold Holding', 'item' => 'Walk-in Cooler', 'operator' => '<=', 'target' => 40, 'action' => 'Close door, re-temp in 10 min, alert MOD if still high'],
    'walkInFreezer' => ['category' => 'Cold Holding', 'item' => 'Walk-in Freezer', 'operator' => '<=', 'target' => 0, 'action' => 'Close door, alert MOD if above 0F'],
    'prepAreaCooler' => ['category' => 'Cold Holding', 'item' => 'Prep Area Cooler', 'operator' => '<=', 'target' => 40, 'action' => 'Alert MOD; move product if above 40F'],
    'bowlWarmer' => ['category' => 'Hot Holding', 'item' => 'Bowl Warmers', 'operator' => '>=', 'target' => 100, 'action' => 'Adjust warmer and re-temp'],
    'ramenReachInTop' => ['category' => 'Cold Holding', 'item' => 'Ramen Refrigeration Top', 'operator' => '<=', 'target' => 40, 'action' => 'Do not serve exposed product if high; cool/replace'],
    'ramenReachInBelow' => ['category' => 'Cold Holding', 'item' => 'Ramen Refrigeration Below', 'operator' => '<=', 'target' => 40, 'action' => 'Cover/cool/replace and alert MOD if high'],
    'lineFreezer' => ['category' => 'Cold Holding', 'item' => 'Line Freezer', 'operator' => '<=', 'target' => 0, 'action' => 'Alert MOD; verify product condition'],
    'seasonedEggs' => ['category' => 'Hot Holding', 'item' => 'Seasoned Eggs', 'operator' => '>=', 'target' => 100, 'action' => 'Must have designated timer; verify 4-hour holding'],
    'slicedPorkHot' => ['category' => 'Hot Holding', 'item' => 'Pork Chashu', 'operator' => '>=', 'target' => 100, 'action' => 'Verify SOP; if below hot holding standard, do not serve'],
    'dicedPorkHot' => ['category' => 'Hot Holding', 'item' => 'Pork Chashu', 'operator' => '>=', 'target' => 100, 'action' => 'Verify SOP; if below hot holding standard, do not serve'],
    'tapasReachInTop' => ['category' => 'Cold Holding', 'item' => 'Tapas Refrigeration Top', 'operator' => '<=', 'target' => 40, 'action' => 'Do not serve exposed product if high; cool/replace'],
    'chickenCold' => ['category' => 'Cold Holding', 'item' => 'Chicken Chashu', 'operator' => '<=', 'target' => 40, 'action' => 'If above 40F, cover/cool/replace and alert MOD'],
    'porkCold' => ['category' => 'Cold Holding', 'item' => 'Pork Cold Holding', 'operator' => '<=', 'target' => 40, 'action' => 'If above 40F, cover/cool/replace and alert MOD'],
    'tapasReachInBelow' => ['category' => 'Cold Holding', 'item' => 'Tapas Refrigeration Below', 'operator' => '<=', 'target' => 40, 'action' => 'Cover/cool/replace and alert MOD if high'],
    'walkInProduceRecheck' => ['category' => 'Cold Holding', 'item' => 'Walk-in Cooler', 'operator' => '<=', 'target' => 40, 'action' => 'Close door, re-temp in 10 min, alert MOD if still high'],
    'fryerLeft' => ['category' => 'Cooking Equipment', 'item' => 'Fryer 1', 'operator' => '>=', 'target' => 325, 'action' => 'Adjust temperature dial and alert MOD'],
    'fryerRight' => ['category' => 'Cooking Equipment', 'item' => 'Fryer 2', 'operator' => '>=', 'target' => 325, 'action' => 'Adjust temperature dial and alert MOD'],
    'pastaBoilerLeft' => ['category' => 'Cooking Equipment', 'item' => 'Pasta Boiler 1', 'operator' => '>=', 'target' => 200, 'action' => 'Adjust temp and re-temp in 10 min'],
    'pastaBoilerRight' => ['category' => 'Cooking Equipment', 'item' => 'Pasta Boiler 2', 'operator' => '>=', 'target' => 200, 'action' => 'Adjust temp and re-temp in 10 min'],
];

function broth_log_business_now(?DateTimeImmutable $now = null): DateTimeImmutable {
    $tz = new DateTimeZone(BROTH_LOG_BUSINESS_TIMEZONE);
    return $now ? $now->setTimezone($tz) : new DateTimeImmutable('now', $tz);
}

function broth_log_business_date(?DateTimeImmutable $now = null): string {
    return broth_log_business_now($now)->format('Y-m-d');
}

function broth_log_norm(string $value): string {
    $value = strtolower(trim($value));
    $value = strtr($value, [
        'á'=>'a','à'=>'a','ả'=>'a','ã'=>'a','ạ'=>'a','ă'=>'a','ắ'=>'a','ằ'=>'a','ẳ'=>'a','ẵ'=>'a','ặ'=>'a','â'=>'a','ấ'=>'a','ầ'=>'a','ẩ'=>'a','ẫ'=>'a','ậ'=>'a',
        'é'=>'e','è'=>'e','ẻ'=>'e','ẽ'=>'e','ẹ'=>'e','ê'=>'e','ế'=>'e','ề'=>'e','ể'=>'e','ễ'=>'e','ệ'=>'e',
        'í'=>'i','ì'=>'i','ỉ'=>'i','ĩ'=>'i','ị'=>'i','ó'=>'o','ò'=>'o','ỏ'=>'o','õ'=>'o','ọ'=>'o','ô'=>'o','ố'=>'o','ồ'=>'o','ổ'=>'o','ỗ'=>'o','ộ'=>'o','ơ'=>'o','ớ'=>'o','ờ'=>'o','ở'=>'o','ỡ'=>'o','ợ'=>'o',
        'ú'=>'u','ù'=>'u','ủ'=>'u','ũ'=>'u','ụ'=>'u','ư'=>'u','ứ'=>'u','ừ'=>'u','ử'=>'u','ữ'=>'u','ự'=>'u','ý'=>'y','ỳ'=>'y','ỷ'=>'y','ỹ'=>'y','ỵ'=>'y','đ'=>'d',
    ]);
    return preg_replace('/\s+/', ' ', $value) ?: '';
}

function broth_log_number_or_null($value): ?float {
    if ($value === null || $value === '') return null;
    $clean = preg_replace('/[^\d.-]/', '', (string)$value);
    if ($clean === '' || !is_numeric($clean)) return null;
    return (float)$clean;
}

// (string)$float already renders the minimal decimal form (10.0 -> "10", 38.5 -> "38.5"); naively
// stripping trailing zero digits from the integer part silently turns 10 into 1, 100 into 1, 120
// into 12. Only strip when a decimal point is actually present.
function broth_log_format_number(float $value): string {
    $s = (string)$value;
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

function broth_log_cell_value(?array $cell): string {
    if (!$cell) return '';
    return trim((string)($cell['f'] ?? $cell['v'] ?? ''));
}

function broth_log_build_index(array $cols): array {
    $labels = array_map(fn($col) => broth_log_norm((string)($col['label'] ?? '')), $cols);
    $index = [];
    foreach (BROTH_LOG_HEADER_ALIASES as $field => $aliases) {
        $index[$field] = -1;
        $normalizedAliases = array_map('broth_log_norm', $aliases);
        foreach ($labels as $i => $label) {
            if (in_array($label, $normalizedAliases, true)) {
                $index[$field] = $i;
                break;
            }
        }
    }
    return $index;
}

function broth_log_sop_label(?array $sop): string {
    return $sop ? $sop['operator'] . ' ' . $sop['target'] . 'F' : 'No SOP target';
}

function broth_log_severity_for(?array $sop, ?float $temp): string {
    if ($temp === null) return 'missing';
    if (abs($temp) > 500) return 'critical';
    if (!$sop) return 'safe';
    $variance = $sop['operator'] === '<=' ? $temp - $sop['target'] : $sop['target'] - $temp;
    if ($variance <= 0) return 'safe';
    if ($variance <= 2) return 'warning';
    if ($variance <= 5) return 'high';
    return 'critical';
}

function broth_log_is_safe_recheck(string $stationKey, ?float $temperature): bool {
    return broth_log_severity_for(BROTH_LOG_SOP[$stationKey] ?? null, $temperature) === 'safe';
}

function broth_log_gviz_table(string $branch): array {
    $branch = strtoupper($branch);
    if (!isset(BROTH_LOG_BRANCHES[$branch])) throw new InvalidArgumentException('Invalid branch');
    $cfg = BROTH_LOG_BRANCHES[$branch];
    $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($cfg['id']) . '/gviz/tq?tqx=out:json&sheet=' . rawurlencode($cfg['tab']);
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) throw new RuntimeException("$branch sheet request failed");
    if (preg_match('/setResponse\((.*)\);?\s*$/s', $raw, $m)) $raw = $m[1];
    $json = json_decode($raw, true);
    if (!is_array($json) || ($json['status'] ?? '') !== 'ok') throw new RuntimeException("$branch sheet response invalid");
    return $json['table'] ?? ['cols' => [], 'rows' => []];
}

function broth_log_normalize_row(array $row, array $cols, string $sheetBranch): array {
    $index = broth_log_build_index($cols);
    $cells = $row['c'] ?? [];
    $get = fn(string $field) => ($index[$field] ?? -1) >= 0 ? broth_log_cell_value($cells[$index[$field]] ?? null) : '';
    $branch = strtoupper($get('branch') ?: $sheetBranch);
    $businessDate = $get('businessDate');
    $responseId = $get('responseId');
    if ($responseId === '') {
        $responseId = implode('|', [$branch, $businessDate, $get('businessTime'), $get('employeeName'), $get('submittedAt')]);
    }
    $readings = [];
    $issues = [];
    foreach (BROTH_LOG_READINGS as [$key, $label, $category]) {
        $temp = broth_log_number_or_null($get($key));
        $sop = BROTH_LOG_SOP[$key] ?? null;
        $severity = broth_log_severity_for($sop, $temp);
        $reading = [
            'key' => $key,
            'label' => $label,
            'category' => $category,
            'temperature' => $temp,
            'unit' => 'F',
            'severity' => $severity,
            'target' => broth_log_sop_label($sop),
            'correctiveAction' => $get('correctiveAction') ?: ($sop['action'] ?? ''),
        ];
        $readings[] = $reading;
        if ($severity !== 'safe') {
            $issues[] = $reading + ['status' => $get('correctiveAction') ? 'Closed' : ($severity === 'critical' ? 'Escalated' : 'Open')];
        }
    }
    $measured = array_values(array_filter($readings, fn($r) => $r['temperature'] !== null));
    $safeCount = count(array_filter($readings, fn($r) => $r['severity'] === 'safe'));
    return [
        'id' => $responseId,
        'sourceSheetId' => BROTH_LOG_BRANCHES[$sheetBranch]['id'],
        'sourceTab' => BROTH_LOG_BRANCHES[$sheetBranch]['tab'],
        'branch' => $branch,
        'submittedAt' => $get('submittedAt'),
        'employeeName' => $get('employeeName') ?: 'Unassigned',
        'notes' => $get('notes'),
        'correctiveAction' => $get('correctiveAction'),
        'managerComment' => $get('managerComment'),
        'businessDate' => $businessDate,
        'businessTime' => $get('businessTime'),
        'shift' => $get('shift'),
        'responseId' => $responseId,
        'readings' => $readings,
        'issues' => $issues,
        'metrics' => [
            'complianceRate' => count($readings) ? $safeCount / count($readings) : 0,
            'missingReadings' => count($readings) - count($measured),
        ],
    ];
}

function broth_log_fetch_branch_records(string $branch): array {
    $table = broth_log_gviz_table($branch);
    return array_map(
        fn($row) => broth_log_normalize_row($row, $table['cols'] ?? [], strtoupper($branch)),
        $table['rows'] ?? []
    );
}

function broth_log_filter_records(array $records, array $query): array {
    $date = $query['businessDate'] ?? null;
    $branch = isset($query['branch']) ? strtoupper((string)$query['branch']) : null;
    return array_values(array_filter($records, function ($record) use ($date, $branch) {
        if ($branch && $record['branch'] !== $branch) return false;
        if ($date && $record['businessDate'] !== $date) return false;
        return true;
    }));
}

function broth_log_critical_alerts_for_branch(string $branch, string $date): array {
    $alerts = [];
    foreach (broth_log_filter_records(broth_log_fetch_branch_records($branch), ['businessDate' => $date, 'branch' => $branch]) as $record) {
        foreach ($record['readings'] as $reading) {
            if ($reading['severity'] !== 'critical') continue;
            $alerts[] = [
                'branch' => $record['branch'],
                'responseId' => $record['responseId'],
                'station' => $reading['label'],
                'stationKey' => $reading['key'],
                'severity' => 'critical',
                'businessDate' => $record['businessDate'],
                'businessTime' => $record['businessTime'],
                'employee' => $record['employeeName'],
                'temperature' => $reading['temperature'] === null ? 'Not recorded' : broth_log_format_number($reading['temperature']) . 'F',
                'target' => $reading['target'],
                'correctiveAction' => $record['correctiveAction'] ?: $reading['correctiveAction'],
            ];
        }
    }
    return $alerts;
}

function broth_log_summary(array $records): array {
    $issues = [];
    foreach ($records as $record) $issues = array_merge($issues, $record['issues']);
    $readings = [];
    foreach ($records as $record) $readings = array_merge($readings, $record['readings']);
    $safe = count(array_filter($readings, fn($r) => $r['severity'] === 'safe'));
    return [
        'logs' => count($records),
        'complianceRate' => count($readings) ? $safe / count($readings) : 0,
        'criticalIssues' => count(array_filter($issues, fn($i) => $i['severity'] === 'critical')),
        'openIssues' => count(array_filter($issues, fn($i) => ($i['status'] ?? '') !== 'Closed')),
        'missingReadings' => count(array_filter($readings, fn($r) => $r['severity'] === 'missing')),
    ];
}
