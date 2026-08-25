(function () {
    'use strict';

    const SHEETS = {
        B1: { id: '1-T9WLdHI1MWp0kX7U2SNPOnc7nDBnrrc0njFxBUKnqo', tab: 'Form Responses 1', name: 'B1 The Rim' },
        B2: { id: '1qk78Spg8GmyP4RCjQYwU8Nm0bXdoyl240iUDcSkK3MQ', tab: 'Form Responses 1', name: 'B2 Stone Oak' },
        B3: { id: '1odx4Xq94kz50aJBuE2Q-WcZbvXdfeVFOksOeAxn4Kxw', tab: 'Form Responses 1', name: 'B3 Bandera' }
    };

    const PATH_BRANCHES = {
        '/broth-log-b1': 'B1',
        '/broth-log-b2': 'B2',
        '/broth-log-b3': 'B3'
    };

    const SYNC_CONFIG = {
        defaultIntervalSeconds: 60,
        intervals: [
            { label: '30 sec', seconds: 30 },
            { label: '1 min', seconds: 60 },
            { label: '2 min', seconds: 120 },
            { label: '5 min', seconds: 300 }
        ],
        cacheTtlMs: 12000,
        requestTimeoutMs: 18000
    };
    const BUSINESS_TIMEZONE = 'America/Chicago';
    const BUSINESS_TIMEZONE_LABEL = 'San Antonio time';
    const SHEET_TIMESTAMP_TIMEZONE = 'Asia/Ho_Chi_Minh';
    const BUSINESS_DAY_START_HOUR = 4;
    const VALID_RANGES = new Set(['today', 'week', 'month', 'all']);
    const RANGE_STORAGE_KEY = 'brothTemperatureRangesV1';
    const RANGE_API = '/api/broth-log/ranges';

    const READING_FIELDS = [
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
        ['porkCold', 'Pork Cold', 'cold'],
        ['tapasReachInBelow', 'Tapas Reach-In Below', 'cold'],
        ['walkInProduceRecheck', 'Walk-In Produce Recheck', 'cold'],
        ['fryerLeft', 'Fryer Left', 'fryer'],
        ['fryerRight', 'Fryer Right', 'fryer'],
        ['pastaBoilerLeft', 'Pasta Boiler Left', 'boiler'],
        ['pastaBoilerRight', 'Pasta Boiler Right', 'boiler']
    ];

    const HEADER_ALIASES = {
        submittedAt: ['timestamp'],
        employeeName: ['employee name / nombre del empleado'],
        notes: ['notes / notas'],
        correctiveAction: ['corrective action / accion correctiva'],
        managerComment: ['manager comment / comentario del manager'],
        branch: ['store code'],
        businessDate: ['business date'],
        businessTime: ['business time'],
        shift: ['shift'],
        responseId: ['response id'],
        walkInCoolerProduce: ['walk-in cooler (produce)'],
        walkInFreezer: ['walk-in freezer', 'congelador trasero / back freezer'],
        prepAreaCooler: ['prep area cooler'],
        bowlWarmer: ['bowl warmer'],
        ramenReachInTop: ['ramen reach-in top'],
        ramenReachInBelow: ['ramen reach-in below'],
        lineFreezer: ['line freezer'],
        seasonedEggs: ['seasoned eggs'],
        slicedPorkHot: ['sliced pork hot'],
        dicedPorkHot: ['diced pork hot'],
        tapasReachInTop: ['tapas reach-in top'],
        chickenCold: ['chicken cold'],
        porkCold: ['pork cold'],
        tapasReachInBelow: ['tapas reach-in below'],
        walkInProduceRecheck: ['walk-in produce recheck'],
        fryerLeft: ['fryer left'],
        fryerRight: ['fryer right'],
        pastaBoilerLeft: ['pasta boiler left'],
        pastaBoilerRight: ['pasta boiler right']
    };

    const DEFAULT_TEMPERATURE_SOP = {
        walkInCoolerProduce: {
            category: 'Cold Holding',
            item: 'Walk-in Cooler',
            min: 30,
            max: 45,
            correctiveAction: 'Close door, re-temp in 10 min, alert MOD if still high'
        },
        walkInFreezer: {
            category: 'Cold Holding',
            item: 'Walk-in Freezer',
            min: -20,
            max: 5,
            correctiveAction: 'Close door, alert MOD if above 0F'
        },
        prepAreaCooler: {
            category: 'Cold Holding',
            item: 'Prep Area Cooler',
            min: 30,
            max: 45,
            correctiveAction: 'Alert MOD; move product if above 40F'
        },
        bowlWarmer: {
            category: 'Hot Holding',
            item: 'Bowl Warmers',
            min: 100,
            max: 125,
            correctiveAction: 'Adjust warmer and re-temp'
        },
        ramenReachInTop: {
            category: 'Cold Holding',
            item: 'Ramen Refrigeration Top',
            min: 30,
            max: 45,
            correctiveAction: 'Do not serve exposed product if high; cool/replace'
        },
        ramenReachInBelow: {
            category: 'Cold Holding',
            item: 'Ramen Refrigeration Below',
            min: 30,
            max: 45,
            correctiveAction: 'Cover/cool/replace and alert MOD if high'
        },
        lineFreezer: {
            category: 'Cold Holding',
            item: 'Line Freezer',
            min: -20,
            max: 0,
            correctiveAction: 'Alert MOD; verify product condition'
        },
        seasonedEggs: {
            category: 'Hot Holding',
            item: 'Seasoned Eggs',
            min: 95,
            max: 105,
            correctiveAction: 'Must have designated timer; verify 4-hour holding'
        },
        slicedPorkHot: {
            category: 'Hot Holding',
            item: 'Pork Chashu',
            min: 95,
            max: 105,
            correctiveAction: 'Verify SOP; if below hot holding standard, do not serve'
        },
        dicedPorkHot: {
            category: 'Hot Holding',
            item: 'Pork Chashu',
            min: 95,
            max: 105,
            correctiveAction: 'Verify SOP; if below hot holding standard, do not serve'
        },
        tapasReachInTop: {
            category: 'Cold Holding',
            item: 'Tapas Refrigeration Top',
            min: 30,
            max: 45,
            correctiveAction: 'Do not serve exposed product if high; cool/replace'
        },
        chickenCold: {
            category: 'Cold Holding',
            item: 'Chicken Chashu',
            min: 30,
            max: 40,
            correctiveAction: 'If above 40F, cover/cool/replace and alert MOD'
        },
        porkCold: {
            category: 'Cold Holding',
            item: 'Pork Cold Holding',
            min: 30,
            max: 40,
            correctiveAction: 'If above 40F, cover/cool/replace and alert MOD'
        },
        tapasReachInBelow: {
            category: 'Cold Holding',
            item: 'Tapas Refrigeration Below',
            min: 30,
            max: 45,
            correctiveAction: 'Cover/cool/replace and alert MOD if high'
        },
        walkInProduceRecheck: {
            category: 'Cold Holding',
            item: 'Walk-in Cooler',
            min: 30,
            max: 45,
            correctiveAction: 'Close door, re-temp in 10 min, alert MOD if still high'
        },
        fryerLeft: {
            category: 'Cooking Equipment',
            item: 'Fryer 1',
            min: 350,
            max: 360,
            correctiveAction: 'Adjust temperature dial and alert MOD'
        },
        fryerRight: {
            category: 'Cooking Equipment',
            item: 'Fryer 2',
            min: 350,
            max: 360,
            correctiveAction: 'Adjust temperature dial and alert MOD'
        },
        pastaBoilerLeft: {
            category: 'Cooking Equipment',
            item: 'Pasta Boiler 1',
            min: 200,
            max: 220,
            correctiveAction: 'Adjust temp and re-temp in 10 min'
        },
        pastaBoilerRight: {
            category: 'Cooking Equipment',
            item: 'Pasta Boiler 2',
            min: 200,
            max: 220,
            correctiveAction: 'Adjust temp and re-temp in 10 min'
        }
    };
    let TEMPERATURE_SOP = loadTemperatureSop();

    const SEVERITY_RULES = {
        warningMax: 2,
        highMax: 5
    };

    const STATION_GROUPS = {
        walkInCoolerProduce: 'Cold Storage',
        walkInFreezer: 'Freezers',
        prepAreaCooler: 'Prep and Service Line',
        bowlWarmer: 'Hot Holding',
        ramenReachInTop: 'Prep and Service Line',
        ramenReachInBelow: 'Prep and Service Line',
        lineFreezer: 'Freezers',
        seasonedEggs: 'Hot Holding',
        slicedPorkHot: 'Cooked Food',
        dicedPorkHot: 'Cooked Food',
        tapasReachInTop: 'Prep and Service Line',
        chickenCold: 'Cooked Food',
        porkCold: 'Cooked Food',
        tapasReachInBelow: 'Prep and Service Line',
        walkInProduceRecheck: 'Cold Storage',
        fryerLeft: 'Cooking Equipment',
        fryerRight: 'Cooking Equipment',
        pastaBoilerLeft: 'Cooking Equipment',
        pastaBoilerRight: 'Cooking Equipment'
    };

    const routeBranch = getRouteBranch();

    const state = {
        activeBranch: routeBranch.branch || getInitialBranch(),
        activeView: 'home',
        records: [],
        recordsByBranch: {},
        recordIndexByBranch: {},
        errors: [],
        lastSync: null,
        lastAttempt: null,
        syncStatus: 'idle',
        syncMessage: 'Waiting for first synchronization',
        consecutiveFailures: 0,
        lastChanges: { new: 0, updated: 0, deleted: 0, duplicates: 0, unchanged: 0 },
        loading: false,
        selectedRecordId: '',
        filters: {
            query: '',
            dateRange: getInitialRange(),
            selectedDate: getInitialSelectedDate(),
            branch: 'current',
            employee: 'all',
            issue: 'all',
            shift: 'all',
            temp: 'all',
            tempMin: '',
            tempMax: ''
        },
        refreshSeconds: getStoredRefreshSeconds(),
        theme: localStorage.getItem('brothTheme') || 'dark',
        timer: null,
        syncInFlight: null,
        showStoreSelector: false,
        routeUnsupported: routeBranch.unsupported,
        rangeEditorOpen: false,
        rangeEditorMessage: ''
    };

    const root = document.getElementById('broth-dashboard');
    if (!root) return;
    state.showStoreSelector = root.dataset.storeSelector === 'true';
    state.activeBranch = routeBranch.branch || (state.showStoreSelector ? getInitialBranch() : root.dataset.branch || getInitialBranch());
    document.documentElement.dataset.theme = state.theme;

    function getRouteBranch() {
        const path = window.location.pathname.replace(/\/+$/, '').replace(/\.html$/i, '').toLowerCase() || '/';
        if (PATH_BRANCHES[path]) return { branch: PATH_BRANCHES[path], unsupported: false };
        if (path === '/broth-log') return { branch: '', unsupported: false };
        if (path.includes('broth-log')) return { branch: '', unsupported: true };
        return { branch: '', unsupported: false };
    }

    function getInitialBranch() {
        const params = new URLSearchParams(window.location.search);
        const requested = String(params.get('store') || params.get('branch') || '').toUpperCase();
        const stored = String(localStorage.getItem('brothSelectedStore') || '').toUpperCase();
        if (SHEETS[requested]) return requested;
        if (SHEETS[stored]) return stored;
        return 'B1';
    }

    function getInitialRange() {
        const params = new URLSearchParams(window.location.search);
        if (isValidDateKey(params.get('date'))) return 'today';
        const requested = String(params.get('range') || 'today').toLowerCase();
        return VALID_RANGES.has(requested) ? requested : 'today';
    }

    function getInitialSelectedDate() {
        const params = new URLSearchParams(window.location.search);
        const requested = params.get('date');
        return isValidDateKey(requested) ? requested : businessToday();
    }

    function getStoredRefreshSeconds() {
        const stored = Number(localStorage.getItem('brothRefreshSeconds') || localStorage.getItem('brothRefreshMinutes') * 60);
        return SYNC_CONFIG.intervals.some(interval => interval.seconds === stored) ? stored : SYNC_CONFIG.defaultIntervalSeconds;
    }

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, ch => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        }[ch]));
    }

    function norm(value) {
        return String(value || '').trim().toLowerCase();
    }

    function valueOf(cell) {
        if (!cell) return '';
        if (cell.f != null) return cell.f;
        if (cell.v != null) return cell.v;
        return '';
    }

    function cloneSopConfig(config) {
        return Object.fromEntries(Object.entries(config).map(([key, sop]) => [key, { ...sop }]));
    }

    function loadStoredRanges() {
        try {
            const parsed = JSON.parse(localStorage.getItem(RANGE_STORAGE_KEY) || '{}');
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (error) {
            return {};
        }
    }

    function normalizeRange(min, max) {
        const minValue = toNumber(min);
        const maxValue = toNumber(max);
        if (!Number.isFinite(minValue) || !Number.isFinite(maxValue) || minValue > maxValue) return null;
        return { min: minValue, max: maxValue };
    }

    function loadTemperatureSop() {
        const next = cloneSopConfig(DEFAULT_TEMPERATURE_SOP);
        const stored = loadStoredRanges();
        applyRangesToSop(next, stored);
        return next;
    }

    function applyRangesToSop(sopConfig, ranges) {
        const stored = ranges && typeof ranges === 'object' ? ranges : {};
        Object.entries(stored).forEach(([key, range]) => {
            if (!sopConfig[key]) return;
            const normalized = normalizeRange(range && range.min, range && range.max);
            if (normalized) Object.assign(sopConfig[key], normalized);
        });
    }

    function saveTemperatureSop() {
        localStorage.setItem(RANGE_STORAGE_KEY, JSON.stringify(customRangesFromConfig(TEMPERATURE_SOP)));
    }

    function customRangesFromConfig(config) {
        const ranges = {};
        Object.entries(config).forEach(([key, sop]) => {
            const defaults = DEFAULT_TEMPERATURE_SOP[key] || {};
            if (sop.min !== defaults.min || sop.max !== defaults.max) ranges[key] = { min: sop.min, max: sop.max };
        });
        return ranges;
    }

    function hasCustomRanges() {
        return Object.values(loadStoredRanges()).some(Boolean);
    }

    function authHeaders() {
        const token = localStorage.getItem('bkdn_token') || '';
        return token ? { Authorization: `Bearer ${token}` } : {};
    }

    async function loadSharedRanges() {
        try {
            const response = await fetch(RANGE_API, { cache: 'no-store' });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const payload = await response.json();
            if (!payload || typeof payload !== 'object' || !payload.ranges) return false;
            localStorage.setItem(RANGE_STORAGE_KEY, JSON.stringify(payload.ranges));
            TEMPERATURE_SOP = loadTemperatureSop();
            state.rangeEditorMessage = payload.updated_at ? `Shared ranges loaded: ${payload.updated_at}` : '';
            return true;
        } catch (error) {
            state.rangeEditorMessage = 'Using saved browser ranges; shared range API was unavailable.';
            return false;
        }
    }

    async function saveSharedRanges(ranges) {
        const response = await fetch(RANGE_API, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', ...authHeaders() },
            body: JSON.stringify({ ranges })
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.ok === false) {
            throw new Error(payload.message || payload.error || `HTTP ${response.status}`);
        }
        return payload;
    }

    function parseDateCell(cell) {
        const raw = cell && cell.v ? String(cell.v) : '';
        const formatted = cell && cell.f ? String(cell.f) : '';
        const match = raw.match(/Date\((\d+),(\d+),(\d+),(\d+),(\d+),(\d+)/);
        if (match) {
            return dateFromZonedTimeParts(SHEET_TIMESTAMP_TIMEZONE, Number(match[1]), Number(match[2]), Number(match[3]), Number(match[4]), Number(match[5]), Number(match[6]));
        }
        return parseSheetTimestampString(formatted || raw);
    }

    function toNumber(value) {
        if (value === null || value === undefined || value === '') return null;
        const n = Number(String(value).replace(/[^\d.-]/g, ''));
        return Number.isFinite(n) ? n : null;
    }

    function sopLabel(sop) {
        if (sop && Number.isFinite(sop.min) && Number.isFinite(sop.max)) return `${fmtNumber(sop.min)}F - ${fmtNumber(sop.max)}F`;
        return sop ? `${sop.operator} ${sop.target}F` : 'No SOP target';
    }

    function isSopSafe(sop, temp) {
        if (!sop || temp === null) return false;
        if (Number.isFinite(sop.min) && Number.isFinite(sop.max)) return temp >= sop.min && temp <= sop.max;
        if (sop.operator === '<=') return temp <= sop.target;
        if (sop.operator === '>=') return temp >= sop.target;
        return true;
    }

    function varianceFromTarget(sop, temp) {
        if (!sop || temp === null) return 0;
        if (Number.isFinite(sop.min) && Number.isFinite(sop.max)) {
            if (temp < sop.min) return sop.min - temp;
            if (temp > sop.max) return temp - sop.max;
            return 0;
        }
        if (sop.operator === '<=') return temp - sop.target;
        if (sop.operator === '>=') return sop.target - temp;
        return 0;
    }

    function classifySeverity(variance) {
        if (variance === null) return 'missing';
        if (variance <= 0) return 'safe';
        if (variance <= SEVERITY_RULES.warningMax) return 'warning';
        if (variance <= SEVERITY_RULES.highMax) return 'high';
        return 'critical';
    }

    function severityToIssueSeverity(severity) {
        if (severity === 'critical') return 'critical';
        if (severity === 'high') return 'high';
        return severity === 'safe' ? 'ok' : 'warn';
    }

    function severityLabel(severity) {
        return ({ safe: 'Safe', warning: 'Warning', high: 'High', critical: 'Critical', missing: 'Missing' }[severity] || 'Missing');
    }

    function statusFor(key, category, temp) {
        const sop = TEMPERATURE_SOP[key];
        if (temp === null) return ['Missing Reading', 'warn', 'missing', 'Missing Reading'];
        if (Math.abs(temp) > 500) return ['Invalid Reading', 'critical', 'critical', 'Sensor Error'];
        if (!sop) return ['Safe', 'ok', 'safe', null];

        const variance = varianceFromTarget(sop, temp);
        const severityKey = classifySeverity(variance);
        const issueSeverity = severityToIssueSeverity(severityKey);
        if (severityKey === 'safe') return ['Safe', issueSeverity, severityKey, null];

        const issueType = Number.isFinite(sop.min) && Number.isFinite(sop.max)
            ? (temp < sop.min ? 'Temperature Too Low' : 'Temperature Too High')
            : sop.operator === '<=' ? 'Temperature Too High' : 'Temperature Too Low';
        return [`${severityLabel(severityKey)}: target ${sopLabel(sop)}`, issueSeverity, severityKey, issueType];
    }

    function signedDeviation(reading) {
        if (reading.temperature === null) return null;
        if (Number.isFinite(reading.targetMin) && Number.isFinite(reading.targetMax)) {
            if (reading.temperature < reading.targetMin) return reading.temperature - reading.targetMin;
            if (reading.temperature > reading.targetMax) return reading.temperature - reading.targetMax;
            return 0;
        }
        if (reading.targetTemperature === null) return null;
        return reading.temperature - reading.targetTemperature;
    }

    function deviationText(reading) {
        const diff = signedDeviation(reading);
        if (diff === null) return 'Not recorded';
        if (Number.isFinite(reading.targetMin) && Number.isFinite(reading.targetMax)) {
            if (diff === 0) return 'Within target range';
            const sign = diff > 0 ? '+' : '';
            return `${sign}${fmtNumber(diff)}F ${diff > 0 ? 'above range' : 'below range'}`;
        }
        const sign = diff > 0 ? '+' : '';
        if (reading.targetOperator === '<=') {
            return `${sign}${fmtNumber(diff)}F ${diff <= 0 ? 'below/equal limit' : 'above limit'}`;
        }
        return `${sign}${fmtNumber(diff)}F ${diff >= 0 ? 'above minimum' : 'below minimum'}`;
    }

    function currentMarker(reading) {
        const diff = signedDeviation(reading);
        if (diff === null) return 50;
        const reference = Number.isFinite(reading.targetMax) ? Math.max(Math.abs(reading.targetMax), Math.abs(reading.targetMin || 0)) : Math.abs(reading.targetTemperature || 0);
        const span = Math.max(8, reference * 0.08, 6);
        return Math.max(4, Math.min(96, 50 + (diff / span) * 38));
    }

    function trendFor(row, reading) {
        const currentTime = row.submittedAt ? row.submittedAt.getTime() : null;
        const previous = state.records
            .filter(record => record.branch === row.branch && record.id !== row.id && record.submittedAt && (!currentTime || record.submittedAt.getTime() < currentTime))
            .sort((a, b) => b.submittedAt - a.submittedAt)
            .map(record => ({
                record,
                reading: record.readings.find(item => item.key === reading.key)
            }))
            .find(item => item.reading && item.reading.temperature !== null);
        if (!previous || reading.temperature === null || !row.submittedAt) return 'No prior reading';
        const change = reading.temperature - previous.reading.temperature;
        const elapsedMs = row.submittedAt - previous.record.submittedAt;
        if (Math.abs(change) < 0.5) return `Stable · ${elapsedLabel(elapsedMs)}`;
        const arrow = change > 0 ? 'Up' : 'Down';
        const riskDirection = reading.targetOperator === '<='
            ? change > 0 ? 'risk increasing' : 'risk decreasing'
            : change < 0 ? 'risk increasing' : 'risk decreasing';
        return `${arrow} ${change > 0 ? '+' : ''}${fmtNumber(change)}F in ${elapsedLabel(elapsedMs)} · ${riskDirection}`;
    }

    function elapsedLabel(ms) {
        if (!Number.isFinite(ms) || ms <= 0) return 'unknown time';
        const minutes = Math.round(ms / 60000);
        if (minutes < 60) return `${minutes} min`;
        const hours = Math.round(minutes / 60);
        return `${hours} hr`;
    }

    function fmtNumber(n) {
        if (!Number.isFinite(n)) return '-';
        return Number.isInteger(n) ? String(n) : String(Math.round(n * 10) / 10);
    }

    function zonedDateParts(date = new Date(), timeZone = BUSINESS_TIMEZONE) {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        }).formatToParts(date).reduce((out, part) => {
            out[part.type] = part.value;
            return out;
        }, {});
        return {
            year: Number(parts.year),
            month: Number(parts.month),
            day: Number(parts.day),
            iso: `${parts.year}-${parts.month}-${parts.day}`
        };
    }

    function businessToday() {
        const now = new Date();
        const parts = businessTimeParts(now);
        if (parts && parts.hour < BUSINESS_DAY_START_HOUR) {
            return utcDateKey(addUtcDays(dateKeyToUtc(zonedDateParts(now).iso), -1));
        }
        return zonedDateParts(now).iso;
    }

    function businessDateKey(date) {
        return date ? zonedDateParts(date).iso : '';
    }

    function businessTimeParts(date, timeZone = BUSINESS_TIMEZONE) {
        if (!date) return null;
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        }).formatToParts(date).reduce((out, part) => {
            out[part.type] = part.value;
            return out;
        }, {});
        return {
            year: Number(parts.year),
            month: Number(parts.month),
            day: Number(parts.day),
            hour: Number(parts.hour) % 24,
            minute: Number(parts.minute),
            second: Number(parts.second)
        };
    }

    function zonedTimeOffsetMs(date, timeZone) {
        const parts = businessTimeParts(date, timeZone);
        if (!parts) return 0;
        return Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second) - date.getTime();
    }

    function dateFromZonedTimeParts(timeZone, year, monthIndex, day, hour, minute, second) {
        const wallTimeUtc = Date.UTC(year, monthIndex, day, hour, minute, second || 0);
        let date = new Date(wallTimeUtc);
        for (let i = 0; i < 3; i += 1) {
            date = new Date(wallTimeUtc - zonedTimeOffsetMs(date, timeZone));
        }
        return date;
    }

    function dateFromBusinessTimeParts(year, monthIndex, day, hour, minute, second) {
        return dateFromZonedTimeParts(BUSINESS_TIMEZONE, year, monthIndex, day, hour, minute, second);
    }

    function parseSheetTimestampString(value) {
        const match = String(value || '').trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
        if (!match) return null;
        const [, month, day, year, hour, minute, second] = match;
        return dateFromZonedTimeParts(SHEET_TIMESTAMP_TIMEZONE, Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute), Number(second || 0));
    }

    function alignDateToBusinessDate(date, dateKey) {
        if (!date || !isValidDateKey(dateKey) || businessDateKey(date) === dateKey) return date;
        const parts = businessTimeParts(date);
        const m = dateKey.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!parts || !m) return date;
        return dateFromBusinessTimeParts(Number(m[1]), Number(m[2]) - 1, Number(m[3]), parts.hour, parts.minute, parts.second);
    }

    function inferShift(value, date) {
        const explicit = String(value || '').trim().toUpperCase();
        if (/\bAM\b/.test(explicit)) return 'AM';
        if (/\bPM\b/.test(explicit)) return 'PM';
        const parts = businessTimeParts(date);
        return parts ? (parts.hour < 12 ? 'AM' : 'PM') : '';
    }

    function displayShift(shift, date) {
        const inferred = inferShift(shift, date);
        return inferred ? `${inferred} shift` : 'No shift';
    }

    function isValidDateKey(value) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value || ''))) return false;
        const date = dateKeyToUtc(value);
        return Boolean(date && utcDateKey(date) === value);
    }

    function isFutureBusinessDate(dateKey) {
        return isValidDateKey(dateKey) && dateKey > businessToday();
    }

    function dateKeyToUtc(dateKey) {
        const m = String(dateKey || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) return null;
        return new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
    }

    function addUtcDays(date, days) {
        const next = new Date(date.getTime());
        next.setUTCDate(next.getUTCDate() + days);
        return next;
    }

    function utcDateKey(date) {
        return date.toISOString().slice(0, 10);
    }

    function startOfBusinessWeek(dateKey) {
        const date = dateKeyToUtc(dateKey);
        if (!date) return dateKey;
        const day = date.getUTCDay();
        return utcDateKey(addUtcDays(date, -day));
    }

    function startOfBusinessMonth(dateKey) {
        return String(dateKey || '').slice(0, 7) + '-01';
    }

    function businessDateLabel(dateKey) {
        const date = dateKeyToUtc(dateKey);
        if (!date) return dateKey || 'Unknown date';
        return new Intl.DateTimeFormat([], { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }).format(date);
    }

    function rangeLabel() {
        const today = businessToday();
        if (state.filters.dateRange === 'today') {
            return state.filters.selectedDate === today
                ? `Today · ${businessDateLabel(today)}`
                : `Selected date · ${businessDateLabel(state.filters.selectedDate)}`;
        }
        if (state.filters.dateRange === 'week') return `This week · since ${businessDateLabel(startOfBusinessWeek(today))}`;
        if (state.filters.dateRange === 'month') return `This month · since ${businessDateLabel(startOfBusinessMonth(today))}`;
        return 'All dates';
    }

    function stats(nums) {
        const values = nums.filter(n => Number.isFinite(n));
        if (!values.length) return { avg: 0, min: 0, max: 0, std: 0 };
        const avg = values.reduce((a, b) => a + b, 0) / values.length;
        const variance = values.reduce((sum, n) => sum + Math.pow(n - avg, 2), 0) / values.length;
        return { avg, min: Math.min(...values), max: Math.max(...values), std: Math.sqrt(variance) };
    }

    function buildIndex(cols) {
        const labels = cols.map(col => norm(col.label));
        const index = {};
        Object.entries(HEADER_ALIASES).forEach(([field, aliases]) => {
            index[field] = labels.findIndex(label => aliases.includes(label));
        });
        return index;
    }

    function requiredWarnings(record) {
        const missing = [];
        if (!record.branch) missing.push('branch');
        if (!record.businessDate) missing.push('businessDate');
        if (!record.businessTime) missing.push('businessTime');
        if (!record.submittedAt) missing.push('submittedAt');
        if (!record.employeeName || record.employeeName === 'Unassigned') missing.push('employeeName');
        return missing;
    }

    function fingerprint(record) {
        return JSON.stringify({
            branch: record.branch,
            submittedAt: record.submittedAt ? record.submittedAt.toISOString() : '',
            businessDate: record.businessDate,
            businessTime: record.businessTime,
            shift: record.shift,
            employeeName: record.employeeName,
            notes: record.notes,
            correctiveAction: record.correctiveAction,
            managerComment: record.managerComment,
            responseId: record.responseId,
            readings: record.readings.map(reading => [reading.key, reading.temperature])
        });
    }

    function normalizeRow(row, cols, sheetBranch) {
        const idx = buildIndex(cols);
        const cell = field => idx[field] >= 0 ? row.c[idx[field]] : null;
        const text = field => String(valueOf(cell(field)) || '').trim();
        const rawSubmittedDate = parseDateCell(cell('submittedAt'));
        const explicitBusinessDate = text('businessDate');
        const explicitBusinessTime = text('businessTime');
        const explicitShift = text('shift');
        const branch = text('branch') || sheetBranch;
        const businessDate = explicitBusinessDate || (rawSubmittedDate ? businessDateKey(rawSubmittedDate) : '');
        const submittedDate = alignDateToBusinessDate(rawSubmittedDate, businessDate);
        const businessTime = explicitBusinessTime || fmtTime(submittedDate);
        const shift = explicitShift || inferShift(businessTime, submittedDate);
        const readings = READING_FIELDS.map(([key, label, category]) => {
            const temp = toNumber(valueOf(cell(key)));
            const sop = TEMPERATURE_SOP[key] || null;
            const [status, severity, severityKey, issueType] = statusFor(key, category, temp);
            return {
                key,
                label,
                category,
                group: STATION_GROUPS[key] || 'Other',
                temperature: temp,
                status,
                severity,
                severityKey,
                issueType,
                sopCategory: sop ? sop.category : '',
                sopItem: sop ? sop.item : label,
                targetOperator: sop ? sop.operator : '',
                targetTemperature: sop ? sop.target : null,
                targetMin: sop ? sop.min : null,
                targetMax: sop ? sop.max : null,
                targetLabel: sopLabel(sop),
                correctiveActionSop: sop ? sop.correctiveAction : ''
            };
        });
        const issues = readings
            .filter(reading => reading.issueType)
            .map(reading => ({
                type: reading.issueType,
                severity: reading.severity,
                severityKey: reading.severityKey,
                station: reading.label,
                target: reading.targetLabel,
                sopItem: reading.sopItem,
                owner: text('employeeName') || 'Unassigned',
                status: text('correctiveAction') ? 'Closed' : reading.severity === 'critical' ? 'Escalated' : 'Open',
                createdAt: submittedDate,
                closedAt: text('correctiveAction') ? submittedDate : null,
                resolution: text('correctiveAction') || reading.correctiveActionSop,
                preventiveAction: text('managerComment')
            }));
        if (text('notes')) {
            issues.push({
                type: 'Manager Note',
                severity: 'warn',
                station: 'Log',
                owner: text('employeeName') || 'Unassigned',
                status: text('correctiveAction') ? 'Closed' : 'Pending',
                createdAt: submittedDate,
                closedAt: text('correctiveAction') ? submittedDate : null,
                resolution: text('correctiveAction'),
                preventiveAction: text('managerComment')
            });
        }
        const measured = readings.filter(r => r.temperature !== null);
        const compliant = readings.filter(r => r.severity === 'ok').length;
        const s = stats(measured.map(r => r.temperature));
        const idParts = [text('responseId'), branch, businessDate, businessTime, text('employeeName'), submittedDate ? submittedDate.toISOString() : ''];
        const record = {
            id: idParts.filter(Boolean).join('|'),
            sourceSheetId: SHEETS[sheetBranch].id,
            sourceTab: SHEETS[sheetBranch].tab,
            branch,
            submittedAt: submittedDate,
            businessDate,
            businessTime,
            shift,
            employeeName: text('employeeName') || 'Unassigned',
            notes: text('notes'),
            correctiveAction: text('correctiveAction'),
            managerComment: text('managerComment'),
            responseId: text('responseId'),
            readings,
            issues,
            metrics: {
                averageTemperature: s.avg,
                highestTemperature: s.max,
                lowestTemperature: s.min,
                standardDeviation: s.std,
                temperatureDrift: s.max - s.min,
                complianceRate: readings.length ? compliant / readings.length : 0,
                missingReadings: readings.length - measured.length,
                riskScore: Math.min(100, issues.reduce((n, issue) => n + (issue.severity === 'critical' ? 18 : 8), 0))
            },
            validation: { duplicate: false, missingRequired: [], warnings: [] }
        };
        record.validation.missingRequired = requiredWarnings(record);
        record.validation.warnings = record.validation.missingRequired.map(field => `Missing required field: ${field}`);
        record.revisionHash = fingerprint(record);
        return record;
    }

    function rebuildRecordWithCurrentRanges(record) {
        const readings = record.readings.map(reading => {
            const sop = TEMPERATURE_SOP[reading.key] || null;
            const [status, severity, severityKey, issueType] = statusFor(reading.key, reading.category, reading.temperature);
            return {
                ...reading,
                status,
                severity,
                severityKey,
                issueType,
                sopCategory: sop ? sop.category : '',
                sopItem: sop ? sop.item : reading.label,
                targetOperator: sop ? sop.operator : '',
                targetTemperature: sop ? sop.target : null,
                targetMin: sop ? sop.min : null,
                targetMax: sop ? sop.max : null,
                targetLabel: sopLabel(sop),
                correctiveActionSop: sop ? sop.correctiveAction : ''
            };
        });
        const issues = readings
            .filter(reading => reading.issueType)
            .map(reading => ({
                type: reading.issueType,
                severity: reading.severity,
                severityKey: reading.severityKey,
                station: reading.label,
                target: reading.targetLabel,
                sopItem: reading.sopItem,
                owner: record.employeeName || 'Unassigned',
                status: record.correctiveAction ? 'Closed' : reading.severity === 'critical' ? 'Escalated' : 'Open',
                createdAt: record.submittedAt,
                closedAt: record.correctiveAction ? record.submittedAt : null,
                resolution: record.correctiveAction || reading.correctiveActionSop,
                preventiveAction: record.managerComment
            }));
        if (record.notes) {
            issues.push({
                type: 'Manager Note',
                severity: 'warn',
                station: 'Log',
                owner: record.employeeName || 'Unassigned',
                status: record.correctiveAction ? 'Closed' : 'Pending',
                createdAt: record.submittedAt,
                closedAt: record.correctiveAction ? record.submittedAt : null,
                resolution: record.correctiveAction,
                preventiveAction: record.managerComment
            });
        }
        const measured = readings.filter(r => r.temperature !== null);
        const compliant = readings.filter(r => r.severity === 'ok').length;
        const s = stats(measured.map(r => r.temperature));
        const next = {
            ...record,
            readings,
            issues,
            metrics: {
                averageTemperature: s.avg,
                highestTemperature: s.max,
                lowestTemperature: s.min,
                standardDeviation: s.std,
                temperatureDrift: s.max - s.min,
                complianceRate: readings.length ? compliant / readings.length : 0,
                missingReadings: readings.length - measured.length,
                riskScore: Math.min(100, issues.reduce((n, issue) => n + (issue.severity === 'critical' ? 18 : 8), 0))
            }
        };
        next.revisionHash = fingerprint(next);
        return next;
    }

    function rebuildRecordsWithCurrentRanges() {
        Object.keys(state.recordsByBranch).forEach(branch => {
            const rows = (state.recordsByBranch[branch] || []).map(rebuildRecordWithCurrentRanges);
            state.recordsByBranch[branch] = rows;
            state.recordIndexByBranch[branch] = Object.fromEntries(rows.map(row => [row.id, row]));
        });
        rebuildVisibleRecords();
    }

    const googleSheetsDataSource = {
        name: 'Google Sheets Visualization API',
        cache: {},
        getBranches(branches, options = {}) {
            return Promise.allSettled(branches.map(branch => this.getBranch(branch, options)));
        },
        getBranch(branch, options = {}) {
            const cached = this.cache[branch];
            if (!options.force && cached && Date.now() - cached.loadedAt < SYNC_CONFIG.cacheTtlMs) {
                return Promise.resolve({ branch, rows: cached.rows, fromCache: true });
            }
            return fetchSheet(branch).then(rows => {
                this.cache[branch] = { rows, loadedAt: Date.now() };
                return { branch, rows, fromCache: false };
            });
        }
    };

    const dataSource = googleSheetsDataSource;

    function fetchSheet(branch) {
        const cfg = SHEETS[branch];
        const callback = `brothSheet_${branch}_${Date.now()}_${Math.floor(Math.random() * 10000)}`;
        const url = `https://docs.google.com/spreadsheets/d/${cfg.id}/gviz/tq?tqx=out:json;responseHandler:${callback}&sheet=${encodeURIComponent(cfg.tab)}`;
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            const timeout = window.setTimeout(() => {
                cleanup();
                reject(new Error(`${branch} sync timed out`));
            }, SYNC_CONFIG.requestTimeoutMs);
            function cleanup() {
                window.clearTimeout(timeout);
                delete window[callback];
                script.remove();
            }
            window[callback] = payload => {
                cleanup();
                if (!payload || payload.status !== 'ok') {
                    reject(new Error(`${branch} export failed`));
                    return;
                }
                const rows = (payload.table.rows || []).map(row => normalizeRow(row, payload.table.cols || [], branch));
                resolve(rows);
            };
            script.onerror = () => {
                cleanup();
                reject(new Error(`${branch} sheet could not be loaded`));
            };
            script.src = url;
            document.head.appendChild(script);
        });
    }

    function applyBranchRecords(branch, incomingRows) {
        const previous = state.recordIndexByBranch[branch] || {};
        const next = {};
        const rows = [];
        const changes = { new: 0, updated: 0, deleted: 0, duplicates: 0, unchanged: 0 };
        incomingRows.forEach(row => {
            if (!row.id) {
                row.validation.warnings.push('Missing stable record identifier');
                row.id = `${branch}|row-${rows.length}`;
            }
            if (next[row.id]) {
                row.validation.duplicate = true;
                changes.duplicates += 1;
                return;
            }
            const prev = previous[row.id];
            if (!prev) changes.new += 1;
            else if (prev.revisionHash !== row.revisionHash) changes.updated += 1;
            else changes.unchanged += 1;
            next[row.id] = row;
            rows.push(row);
        });
        Object.keys(previous).forEach(id => {
            if (!next[id]) changes.deleted += 1;
        });
        state.recordsByBranch[branch] = rows;
        state.recordIndexByBranch[branch] = next;
        return changes;
    }

    function mergeChanges(list) {
        return list.reduce((sum, item) => {
            sum.new += item.new;
            sum.updated += item.updated;
            sum.deleted += item.deleted;
            sum.duplicates += item.duplicates;
            sum.unchanged += item.unchanged;
            return sum;
        }, { new: 0, updated: 0, deleted: 0, duplicates: 0, unchanged: 0 });
    }

    function visibleBranches() {
        return state.filters.branch === 'current' ? [state.activeBranch] : Object.keys(SHEETS);
    }

    function rebuildVisibleRecords() {
        state.records = visibleBranches()
            .flatMap(branch => state.recordsByBranch[branch] || [])
            .sort((a, b) => (b.submittedAt || 0) - (a.submittedAt || 0));
    }

    async function syncSheets(options = {}) {
        if (state.routeUnsupported) {
            state.loading = false;
            state.syncStatus = 'error';
            state.syncMessage = 'Unsupported Broth Log route';
            render();
            return;
        }
        if (state.syncInFlight) return state.syncInFlight;
        state.syncInFlight = runSync(options).finally(() => {
            state.syncInFlight = null;
        });
        return state.syncInFlight;
    }

    async function runSync(options = {}) {
        state.loading = true;
        state.lastAttempt = new Date();
        state.syncStatus = 'syncing';
        state.syncMessage = 'Refreshing Google Sheets data';
        render();
        const branches = visibleBranches();
        const results = await dataSource.getBranches(branches, { force: Boolean(options.force) });
        const changeList = [];
        const errors = [];
        results.forEach(result => {
            if (result.status === 'fulfilled') {
                changeList.push(applyBranchRecords(result.value.branch, result.value.rows));
            } else {
                errors.push(result.reason.message);
            }
        });
        rebuildVisibleRecords();
        state.lastChanges = mergeChanges(changeList);
        state.errors = errors;
        if (errors.length && !changeList.length) {
            state.consecutiveFailures += 1;
            state.syncStatus = 'error';
            state.syncMessage = 'Google Sheets sync failed; showing last successful data';
        } else if (errors.length) {
            state.consecutiveFailures += 1;
            state.syncStatus = 'warning';
            state.syncMessage = 'Partial sync completed; some branches may be outdated';
            state.lastSync = new Date();
        } else {
            state.consecutiveFailures = 0;
            state.syncStatus = 'ok';
            state.syncMessage = state.lastChanges.new || state.lastChanges.updated || state.lastChanges.deleted
                ? 'Synchronized with latest sheet changes'
                : 'Synchronized; no sheet changes detected';
            state.lastSync = new Date();
        }
        state.loading = false;
        render();
    }

    function filteredRecords() {
        const today = businessToday();
        const startOfWeek = startOfBusinessWeek(today);
        const startOfMonth = startOfBusinessMonth(today);
        return state.records.filter(row => {
            const haystack = [row.branch, row.employeeName, row.businessDate, row.businessTime, row.shift, row.notes, row.correctiveAction, row.managerComment, ...row.issues.map(i => i.type)].join(' ').toLowerCase();
            if (state.filters.query && !haystack.includes(state.filters.query.toLowerCase())) return false;
            if (state.filters.branch !== 'all' && state.filters.branch !== 'current' && row.branch !== state.filters.branch) return false;
            if (state.filters.employee !== 'all' && row.employeeName !== state.filters.employee) return false;
            if (state.filters.shift !== 'all' && inferShift(row.shift, row.submittedAt) !== state.filters.shift) return false;
            if (state.filters.issue === 'open' && !row.issues.some(i => i.status !== 'Closed')) return false;
            if (state.filters.issue === 'critical' && !row.issues.some(i => i.severity === 'critical')) return false;
            if (state.filters.issue === 'closed' && !row.issues.some(i => i.status === 'Closed')) return false;
            if (state.filters.temp === 'noncompliant' && row.metrics.complianceRate >= 1) return false;
            const minTemp = toNumber(state.filters.tempMin);
            const maxTemp = toNumber(state.filters.tempMax);
            if (minTemp !== null && !row.readings.some(reading => reading.temperature !== null && reading.temperature >= minTemp)) return false;
            if (maxTemp !== null && !row.readings.some(reading => reading.temperature !== null && reading.temperature <= maxTemp)) return false;
            if (state.filters.dateRange !== 'all') {
                const dateKey = row.businessDate;
                if (!dateKey) return false;
                if (state.filters.dateRange === 'today' && dateKey !== state.filters.selectedDate) return false;
                if (state.filters.dateRange === 'week' && dateKey < startOfWeek) return false;
                if (state.filters.dateRange === 'month' && dateKey < startOfMonth) return false;
            }
            return true;
        }).sort((a, b) => {
            if (state.filters.dateRange === 'today') {
                const issueDiff = recordPriority(b) - recordPriority(a);
                if (issueDiff) return issueDiff;
            }
            return (b.submittedAt || 0) - (a.submittedAt || 0);
        });
    }

    function recordPriority(row) {
        if (row.issues.some(issue => issue.severity === 'critical')) return 4;
        if (row.issues.some(issue => issue.severity === 'high')) return 3;
        if (row.issues.some(issue => issue.status !== 'Closed')) return 2;
        if (row.metrics.missingReadings) return 1;
        return 0;
    }

    function selectedRecord(records) {
        if (!records.length) return null;
        const current = records.find(row => row.id === state.selectedRecordId);
        if (current) return current;
        state.selectedRecordId = records[0].id;
        return records[0];
    }

    function aggregate(records) {
        const allReadings = records.flatMap(r => r.readings);
        const measured = allReadings.filter(r => r.temperature !== null);
        const issues = records.flatMap(r => r.issues);
        const s = stats(measured.map(r => r.temperature));
        return {
            totalLogs: records.length,
            todayLogs: records.filter(r => r.businessDate === businessToday()).length,
            openIssues: issues.filter(i => i.status !== 'Closed').length,
            criticalAlerts: issues.filter(i => i.severity === 'critical').length,
            compliance: allReadings.length ? allReadings.filter(r => r.severity === 'ok').length / allReadings.length : 0,
            avgTemp: s.avg,
            activeEmployees: new Set(records.map(r => r.employeeName)).size,
            missing: records.reduce((sum, r) => sum + r.metrics.missingReadings, 0),
            issues
        };
    }

    function groupBy(records, keyFn) {
        return records.reduce((map, row) => {
            const key = keyFn(row) || 'Unknown';
            if (!map[key]) map[key] = [];
            map[key].push(row);
            return map;
        }, {});
    }

    function percent(n) {
        return `${Math.round(n * 100)}%`;
    }

    function fmtTemp(n) {
        return Number.isFinite(n) ? `${fmtNumber(n)}F` : 'Not recorded';
    }

    function branchLabel(branch) {
        return SHEETS[branch] ? `${branch} · ${SHEETS[branch].name.replace(/^B\d+\s*/, '')}` : branch;
    }

    function fmtDate(d) {
        return d ? new Intl.DateTimeFormat('en-US', {
            timeZone: BUSINESS_TIMEZONE,
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
            timeZoneName: 'short'
        }).format(d) : '-';
    }

    function fmtTime(d) {
        return d ? new Intl.DateTimeFormat('en-US', {
            timeZone: BUSINESS_TIMEZONE,
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
            timeZoneName: 'short'
        }).format(d) : '';
    }

    function statusPill(row) {
        const cls = row.issues.some(i => i.severity === 'critical') ? 'critical' : row.issues.length ? 'warn' : 'ok';
        const text = row.issues.some(i => i.severity === 'critical') ? 'Critical' : row.issues.length ? 'Issue' : 'Compliant';
        return `<span class="bd-pill ${cls}">${text}</span>`;
    }

    function nav() {
        const items = [
            ['home', 'Home'], ['journal', 'Daily Journal'], ['timeline', 'Temperature'], ['issues', 'Issues'],
            ['employees', 'Employees'], ['daily', 'Daily'], ['weekly', 'Weekly'], ['monthly', 'Monthly'],
            ['branches', 'Branches'], ['compliance', 'Compliance'], ['notifications', 'Notifications']
        ];
        return items.map(([id, label]) => `<button class="${state.activeView === id ? 'active' : ''}" data-view="${id}">${label}</button>`).join('');
    }

    function rangeEditor() {
        return `<section class="bd-card bd-section bd-range-tool" aria-label="Temperature range settings">
            <div class="bd-section-head">
                <h2>Range settings</h2>
                <span>${hasCustomRanges() ? 'Custom ranges active' : 'Using default paper ranges'}</span>
            </div>
            ${state.rangeEditorMessage ? `<div class="bd-range-message">${esc(state.rangeEditorMessage)}</div>` : ''}
            <form id="rangeEditorForm">
                <div class="bd-range-grid" role="table" aria-label="Editable temperature target ranges">
                    <div class="bd-range-row bd-range-header" role="row">
                        <span>Item / Station</span><span>Default</span><span>Min F</span><span>Max F</span>
                    </div>
                    ${READING_FIELDS.map(([key, label]) => {
                        const sop = TEMPERATURE_SOP[key] || {};
                        const defaults = DEFAULT_TEMPERATURE_SOP[key] || {};
                        const changed = sop.min !== defaults.min || sop.max !== defaults.max;
                        return `<div class="bd-range-row ${changed ? 'changed' : ''}" role="row">
                            <strong>${esc(label)}</strong>
                            <span>${esc(sopLabel(defaults))}</span>
                            <input data-range-min="${esc(key)}" type="number" step="0.1" value="${esc(sop.min)}" aria-label="${esc(label)} minimum temperature">
                            <input data-range-max="${esc(key)}" type="number" step="0.1" value="${esc(sop.max)}" aria-label="${esc(label)} maximum temperature">
                        </div>`;
                    }).join('')}
                </div>
                <div class="bd-range-actions">
                    <button class="bd-btn primary" type="submit">Save ranges</button>
                    <button class="bd-btn" type="button" data-action="resetRanges">Reset to default</button>
                    <button class="bd-btn" type="button" data-action="toggleRanges">Close</button>
                </div>
            </form>
        </section>`;
    }

    function filters(records) {
        const employees = [...new Set(state.records.map(r => r.employeeName))].sort();
        return `
            <div class="bd-primary-filters">
                <button class="bd-btn bd-today-btn ${isViewingToday() ? 'active' : ''}" data-action="today" aria-pressed="${isViewingToday()}">Today</button>
                <label class="bd-date-picker"><span>Business date</span><input data-filter="selectedDate" type="date" max="${esc(businessToday())}" value="${esc(state.filters.selectedDate)}" aria-label="Selected business date"></label>
                <label class="bd-shift-filter"><span>Shift</span><select data-filter="shift" aria-label="Shift">
                    ${option('all', 'All shifts', state.filters.shift)}
                    ${option('AM', 'AM shift', state.filters.shift)}
                    ${option('PM', 'PM shift', state.filters.shift)}
                </select></label>
                <button class="bd-btn bd-issues-only ${state.filters.issue === 'open' || state.filters.issue === 'critical' ? 'active' : ''}" data-action="issuesOnly" aria-pressed="${state.filters.issue === 'open' || state.filters.issue === 'critical'}">Issues only</button>
            </div>
            <details class="bd-advanced-filters">
                <summary>More filters</summary>
                <div class="bd-advanced-grid">
            <input data-filter="query" type="search" placeholder="Search employee, station, issue..." value="${esc(state.filters.query)}">
            <select data-filter="dateRange" aria-label="Date range">
                ${option('today', 'Selected date', state.filters.dateRange)}
                ${option('week', 'This week', state.filters.dateRange)}
                ${option('month', 'This month', state.filters.dateRange)}
                ${option('all', 'All dates', state.filters.dateRange)}
            </select>
            <select data-filter="branch" aria-label="Store scope">
                ${option('current', `Current (${state.activeBranch})`, state.filters.branch)}
                ${option('all', 'All branches', state.filters.branch)}
                ${Object.keys(SHEETS).map(b => option(b, b, state.filters.branch)).join('')}
            </select>
            <select data-filter="issue" aria-label="Status">
                ${option('all', 'All statuses', state.filters.issue)}
                ${option('open', 'Open/Pending', state.filters.issue)}
                ${option('critical', 'Critical', state.filters.issue)}
                ${option('closed', 'Closed', state.filters.issue)}
            </select>
            <select data-filter="employee">
                ${option('all', 'All employees', state.filters.employee)}
                ${employees.map(e => option(e, e, state.filters.employee)).join('')}
            </select>
            <select data-filter="temp">
                ${option('all', 'All readings', state.filters.temp)}
                ${option('noncompliant', 'Non-compliant only', state.filters.temp)}
            </select>
            <input data-filter="tempMin" type="number" inputmode="decimal" placeholder="Min F" value="${esc(state.filters.tempMin)}">
            <input data-filter="tempMax" type="number" inputmode="decimal" placeholder="Max F" value="${esc(state.filters.tempMax)}">
            <button class="bd-btn" data-action="reset">Reset filters</button>
                </div>
            </details>
        `;
    }

    function option(value, label, selected) {
        return `<option value="${esc(value)}" ${value === selected ? 'selected' : ''}>${esc(label)}</option>`;
    }

    function kpis(summary) {
        return `
            <div class="bd-grid bd-kpis">
                ${kpi('Viewing', rangeLabel(), `${SHEETS[state.activeBranch].name}`)}
                ${kpi("Today's Logs", summary.todayLogs, `Business date ${businessToday()}`)}
                ${kpi('Open Issues', summary.openIssues, `${summary.criticalAlerts} critical`)}
                ${kpi('Compliance', percent(summary.compliance), `${summary.missing} missing readings`)}
                ${kpi('Last Sync', state.lastSync ? state.lastSync.toLocaleTimeString() : 'Waiting', state.syncMessage)}
            </div>
        `;
    }

    function todayOperations(records, summary) {
        if (state.filters.dateRange !== 'today') return '';
        const selectedDate = state.filters.selectedDate;
        const branchText = state.filters.branch === 'all' ? 'All branches' : branchLabel(state.filters.branch === 'current' ? state.activeBranch : state.filters.branch);
        const allReadings = dailyReadingRows(records, { includeSafe: true });
        const problemReadings = allReadings.filter(item => item.reading.severityKey !== 'safe');
        const critical = problemReadings.filter(item => item.reading.severityKey === 'critical');
        const warnings = problemReadings.filter(item => item.reading.severityKey !== 'critical');
        const missing = problemReadings.filter(item => item.reading.severityKey === 'missing');
        const latest = latestRecordLabel(records);
        const statusClass = critical.length ? 'critical' : problemReadings.length ? 'warning' : records.length ? 'ok' : isFutureBusinessDate(selectedDate) ? 'future' : 'empty';
        const statusText = critical.length
            ? `${critical.length} critical reading${critical.length === 1 ? '' : 's'} need action`
            : problemReadings.length
                ? `${problemReadings.length} reading${problemReadings.length === 1 ? '' : 's'} need attention`
                : records.length
                    ? `${allReadings.length} readings recorded · No issues`
                    : isFutureBusinessDate(selectedDate)
                        ? `Future date selected: ${businessDateLabel(selectedDate)}`
                        : `No Broth Log records for ${businessDateLabel(selectedDate)}`;
        return `<section class="bd-today-ops ${statusClass}" aria-label="Daily operations status">
            <div class="bd-today-hero">
                <div>
                    <span class="bd-eyebrow">${isViewingToday() ? 'Today' : 'Selected date'} · ${esc(BUSINESS_TIMEZONE_LABEL)}</span>
                    <h2>${esc(statusText)}</h2>
                    <p>${esc(branchText)} · ${esc(businessDateLabel(selectedDate))} (${esc(selectedDate)})${latest ? ` · Latest log ${esc(latest)}` : ''}</p>
                </div>
            </div>
            ${records.length ? `<p class="bd-reviewed-count">${allReadings.length} readings recorded · ${warnings.length} warning/open · ${missing.length} missing/incomplete</p>` : ''}
            ${problemReadings.length ? `<div class="bd-today-issue-grid">${topProblemReadingCards(problemReadings).join('')}</div>` : todayEmptyMessage(records)}
        </section>`;
    }

    function selectedDateUrl() {
        const url = new URL(window.location.href);
        url.pathname = '/broth-log';
        url.searchParams.set('date', state.filters.selectedDate);
        url.searchParams.delete('range');
        if (state.showStoreSelector) url.searchParams.set('store', state.activeBranch);
        return url.pathname + url.search;
    }

    function currentDayUrl() {
        const url = new URL(window.location.href);
        url.pathname = '/broth-log';
        url.searchParams.set('range', 'today');
        url.searchParams.delete('date');
        if (state.showStoreSelector) url.searchParams.set('store', state.activeBranch);
        return url.pathname + url.search;
    }

    function isViewingToday() {
        return state.filters.dateRange === 'today' && state.filters.selectedDate === businessToday();
    }

    function latestRecordLabel(records) {
        if (!records.length) return '';
        const latest = records.slice().sort((a, b) => (b.submittedAt || 0) - (a.submittedAt || 0))[0];
        return `${latest.branch} ${fmtDate(latest.submittedAt)} · ${displayShift(latest.shift, latest.submittedAt)} by ${latest.employeeName}`;
    }

    function todayCount(label, value, key) {
        return `<div class="bd-today-count ${key}"><span>${esc(label)}</span><strong>${esc(value)}</strong></div>`;
    }

    function topIssueCards(records, issues) {
        return issues
            .slice()
            .sort((a, b) => issuePriority(b) - issuePriority(a))
            .slice(0, 6)
            .map(issue => {
                const row = records.find(record => record.issues.includes(issue));
                const reading = row ? row.readings.find(item => item.label === issue.station || item.sopItem === issue.sopItem) : null;
                return `<article class="bd-today-issue ${esc(issue.severityKey || issue.severity)}">
                    <div class="bd-today-issue-head">
                        <strong>${esc(row ? row.branch : '')} · ${esc(issue.station)}</strong>
                        <span class="bd-pill ${esc(issue.severityKey || issue.severity)}">${esc(issueSeverityLabel(issue))}</span>
                    </div>
                    <dl>
                        <div><dt>Recorded</dt><dd>${reading ? fmtTemp(reading.temperature) : 'Not recorded'}</dd></div>
                        <div><dt>Required</dt><dd>${esc(issue.target || (reading ? reading.targetLabel : 'No SOP target'))}</dd></div>
                        <div><dt>Status</dt><dd>${esc(issue.status)}</dd></div>
                        <div><dt>Time</dt><dd>${esc(row ? fmtDate(row.submittedAt) : '-')}</dd></div>
                        <div><dt>Shift</dt><dd>${esc(row ? displayShift(row.shift, row.submittedAt) : '-')}</dd></div>
                        <div><dt>Employee</dt><dd>${esc(row ? row.employeeName : '-')}</dd></div>
                    </dl>
                    <p><b>Corrective action:</b> ${esc(issue.resolution || (reading ? reading.correctiveActionSop : '') || 'Follow SOP and notify MOD.')}</p>
                    ${row ? `<button class="bd-btn" data-select-record="${esc(row.id)}">View log</button>` : ''}
                </article>`;
            });
    }

    function topProblemReadingCards(readings) {
        return readings
            .slice()
            .sort((a, b) => readingPriority(b.reading) - readingPriority(a.reading) || (a.record.submittedAt || 0) - (b.record.submittedAt || 0))
            .slice(0, 2)
            .map(({ record, reading }) => `<article class="bd-today-issue ${esc(reading.severityKey)}">
                <div class="bd-today-issue-head">
                    <strong>${esc(reading.label)}</strong>
                    <span class="bd-pill ${esc(reading.severityKey)}">${esc(severityLabel(reading.severityKey))}</span>
                </div>
                <dl>
                    <div><dt>Entered</dt><dd>${fmtTemp(reading.temperature)}</dd></div>
                    <div><dt>Target</dt><dd>${esc(reading.targetLabel)}</dd></div>
                    <div><dt>Time</dt><dd>${esc(fmtDate(record.submittedAt))}</dd></div>
                    <div><dt>Shift</dt><dd>${esc(displayShift(record.shift, record.submittedAt))}</dd></div>
                    <div><dt>Employee</dt><dd>${esc(record.employeeName)}</dd></div>
                </dl>
                <p><b>Corrective action:</b> ${esc(record.correctiveAction || reading.correctiveActionSop || 'Follow SOP and notify MOD.')}</p>
            </article>`);
    }

    function readingPriority(reading) {
        const rank = { critical: 4, high: 3, warning: 2, missing: 1, safe: 0 };
        return rank[reading.severityKey] || 0;
    }

    function issuePriority(issue) {
        const rank = { critical: 4, high: 3, warn: 2, warning: 2, missing: 1, ok: 0 };
        return rank[issue.severityKey || issue.severity] || 0;
    }

    function issueSeverityLabel(issue) {
        if (issue.severityKey) return severityLabel(issue.severityKey);
        if (issue.severity === 'warn') return 'Warning';
        if (issue.severity === 'ok') return 'Safe';
        return severityLabel(issue.severity);
    }

    function todayEmptyMessage(records) {
        if (!records.length) {
            return `<div class="bd-today-empty">
                <strong>${isFutureBusinessDate(state.filters.selectedDate) ? 'Future date selected.' : `No Broth Log records for ${esc(businessDateLabel(state.filters.selectedDate))}`}</strong>
                <span>${isFutureBusinessDate(state.filters.selectedDate) ? 'Choose today or a past date to review submitted logs.' : 'No daily log was found for the selected store and business date.'}</span>
            </div>`;
        }
        return `<div class="bd-today-empty ok">
            <strong>No issues found for this day.</strong>
            <span>${records.length} log${records.length === 1 ? '' : 's'} checked for ${esc(businessDateLabel(state.filters.selectedDate))}.</span>
        </div>`;
    }

    function kpi(label, value, sub) {
        return `<div class="bd-card bd-kpi"><span>${esc(label)}</span><strong>${esc(value)}</strong><small>${esc(sub)}</small></div>`;
    }

    function storeSelector() {
        if (!state.showStoreSelector) return '';
        return `<label class="bd-store-select"><span>Store</span><select id="storeSelect" aria-label="Select store">${Object.entries(SHEETS).map(([key, cfg]) => option(key, `${key} · ${cfg.name.replace(/^B\d+\s*/, '')}`, state.activeBranch)).join('')}</select></label>`;
    }

    function refreshLabel(seconds) {
        const interval = SYNC_CONFIG.intervals.find(item => item.seconds === seconds);
        return interval ? interval.label : `${seconds}s`;
    }

    function journal(records) {
        if (!records.length) return emptyState();
        const selected = selectedRecord(records);
        return `
            <div class="bd-card bd-section bd-journal-section">
                <h2>Daily Log Journal</h2>
                <div class="bd-journal-list" role="listbox" aria-label="Daily log journal">
                    ${records.map(row => journalItem(row, selected && row.id === selected.id)).join('')}
                </div>
            </div>
        `;
    }

    function journalItem(row, selected) {
        return `<button class="bd-journal-item ${selected ? 'selected' : ''}" data-select-record="${esc(row.id)}" role="option" aria-selected="${selected ? 'true' : 'false'}">
            <span class="bd-journal-date">${esc(row.businessDate)} <b>${esc(fmtDate(row.submittedAt))}</b></span>
            <strong>${esc(row.employeeName)}</strong>
            <span>${esc(displayShift(row.shift, row.submittedAt))} · Avg ${fmtTemp(row.metrics.averageTemperature)}</span>
            <span>${statusPill(row)} <small>${row.issues.length} issue${row.issues.length === 1 ? '' : 's'}</small></span>
        </button>`;
    }

    function home(records, summary) {
        if (state.filters.dateRange !== 'today') return `${kpis(summary)}${masterDetail(records, summary)}`;
        return `${todayOperations(records, summary)}${dailyRecords(records)}`;
    }

    function dailyRecords(records) {
        if (!records.length) return '';
        const rows = dailyReadingRows(records, { includeSafe: state.filters.issue !== 'open' && state.filters.issue !== 'critical' });
        if (!rows.length) {
            return `<section class="bd-card bd-section bd-daily-records">
                <div class="bd-section-head">
                    <h2>Daily recorded items</h2>
                    <span>${esc(businessDateLabel(state.filters.selectedDate))}</span>
                </div>
                <div class="bd-empty">No issue readings match the current Issues only filter.</div>
            </section>`;
        }
        return `<section class="bd-card bd-section bd-daily-records">
            <div class="bd-section-head">
                <h2>${state.filters.issue === 'open' || state.filters.issue === 'critical' ? 'Issue readings' : 'All daily readings'}</h2>
                <span>${rows.length} item${rows.length === 1 ? '' : 's'} · ${esc(businessDateLabel(state.filters.selectedDate))}</span>
            </div>
            <div class="bd-daily-table" role="table" aria-label="Daily recorded Broth Log items">
                <div role="row" class="bd-daily-row bd-daily-header">
                    <span>Time</span><span>Item / Station</span><span>Employee</span><span>Shift</span><span>Entered Temp</span><span>Target</span><span>Status</span><span>Corrective Action</span>
                </div>
                ${rows.map(dailyReadingRow).join('')}
            </div>
        </section>`;
    }

    function dailyReadingRows(records, options = {}) {
        const includeSafe = options.includeSafe !== false;
        return records
            .flatMap(record => record.readings.map(reading => ({ record, reading })))
            .filter(item => includeSafe || item.reading.severityKey !== 'safe')
            .sort((a, b) => readingPriority(b.reading) - readingPriority(a.reading) || (a.record.submittedAt || 0) - (b.record.submittedAt || 0));
    }

    function dailyReadingRow({ record, reading }) {
        const needsAction = reading.severityKey !== 'safe';
        return `<div role="row" class="bd-daily-row ${esc(reading.severityKey)}">
            <span>${esc(fmtDate(record.submittedAt))}</span>
            <strong>${esc(reading.label)}</strong>
            <span>${esc(record.employeeName)}</span>
            <span>${esc(displayShift(record.shift, record.submittedAt))}</span>
            <b>${fmtTemp(reading.temperature)}</b>
            <span>${esc(reading.targetLabel)}</span>
            <span class="bd-pill ${esc(reading.severityKey)}">${esc(severityLabel(reading.severityKey))}</span>
            <span>${esc(needsAction ? record.correctiveAction || reading.correctiveActionSop || 'Follow SOP' : 'None')}</span>
        </div>`;
    }

    function masterDetail(records, summary) {
        const selected = selectedRecord(records);
        return `<div class="bd-master-detail">
            <section class="bd-master-panel">${journal(records)}</section>
            <section class="bd-detail-panel" aria-live="polite">
                ${selected ? selectedLogDetail(selected, summary, records) : emptyState()}
            </section>
        </div>`;
    }

    function emptyState() {
        if (state.filters.dateRange === 'today') {
            return `<div class="bd-empty bd-empty-today">
                <h2>No logs for today</h2>
                <p>No broth log has business date ${esc(businessToday())} for ${esc(state.filters.branch === 'current' ? state.activeBranch : state.filters.branch)}.</p>
                <p>Use All dates to review older history.</p>
                <button class="bd-btn" data-action="allDates">All dates</button>
            </div>`;
        }
        return `<div class="bd-empty">No matching logs for ${esc(rangeLabel())}.</div>`;
    }

    function lineChart(records, metric) {
        const values = records.map(r => metric === 'complianceRate' ? r.metrics[metric] * 100 : r.metrics[metric]).filter(Number.isFinite);
        if (values.length < 2) return `<div class="bd-empty">Need at least two records for a timeline.</div>`;
        const min = Math.min(...values);
        const max = Math.max(...values);
        const points = values.map((value, i) => {
            const x = (i / Math.max(values.length - 1, 1)) * 100;
            const y = 92 - ((value - min) / Math.max(max - min, 1)) * 76;
            return `${x},${y}`;
        }).join(' ');
        const area = `0,96 ${points} 100,96`;
        return `<svg class="bd-chart" viewBox="0 0 100 100" preserveAspectRatio="none" role="img" aria-label="Temperature timeline">
            <polyline points="${area}" fill="rgba(98,166,223,.18)" stroke="none"></polyline>
            <polyline points="${points}" fill="none" stroke="#62a6df" stroke-width="2.2" vector-effect="non-scaling-stroke"></polyline>
            ${values.map((value, i) => {
                const x = (i / Math.max(values.length - 1, 1)) * 100;
                const y = 92 - ((value - min) / Math.max(max - min, 1)) * 76;
                return `<circle cx="${x}" cy="${y}" r="1.5" fill="#d7af57"></circle>`;
            }).join('')}
        </svg><div class="bd-muted">Low ${fmtTemp(min)} · High ${fmtTemp(max)} · ${values.length} points</div>`;
    }

    function issueList(issues) {
        if (!issues.length) return `<div class="bd-empty">No issues in the current filter.</div>`;
        return `<div class="bd-list">${issues.map(issue => `
            <div class="bd-list-item">
                <strong>${esc(issue.type)} · ${esc(issue.station)}</strong>
                <span>${esc(issue.owner)} · ${esc(issue.status)} · ${esc(issue.severity)}${issue.target ? ` · target ${esc(issue.target)}` : ''}</span>
                ${issue.resolution ? `<div>${esc(issue.resolution)}</div>` : ''}
            </div>`).join('')}</div>`;
    }

    function employeeBars(records) {
        const groups = groupBy(records, r => r.employeeName);
        const rows = Object.entries(groups).map(([name, list]) => {
            const compliance = aggregate(list).compliance;
            const score = Math.round((compliance * .55 + Math.min(list.length / 10, 1) * .2 + (1 - aggregate(list).openIssues / Math.max(aggregate(list).issues.length, 1)) * .25) * 100);
            return { name, score, logs: list.length, compliance };
        }).sort((a, b) => b.score - a.score);
        if (!rows.length) return `<div class="bd-empty">No employee records yet.</div>`;
        return `<div class="bd-bars">${rows.map(row => `
            <div class="bd-bar"><span>${esc(row.name)}</span><div class="bd-bar-track"><div class="bd-bar-fill" style="width:${row.score}%"></div></div><strong>${row.score}</strong></div>
            <div class="bd-muted">${row.logs} logs · ${percent(row.compliance)} compliance · Grade ${grade(row.score)}</div>`).join('')}</div>`;
    }

    function grade(score) {
        if (score >= 97) return 'A+';
        if (score >= 90) return 'A';
        if (score >= 80) return 'B';
        if (score >= 70) return 'C';
        return 'Needs Improvement';
    }

    function timeline(records) {
        return `
            <div class="bd-grid bd-two">
                <div class="bd-card bd-section"><h2>Average Temperature Line Chart</h2>${lineChart(records.slice().reverse(), 'averageTemperature')}</div>
                <div class="bd-card bd-section"><h2>Compliance Area Chart</h2>${lineChart(records.slice().reverse(), 'complianceRate')}</div>
            </div>
            <div class="bd-card bd-section" style="margin-top:14px"><h2>Station Heat Map</h2>${heatmap(records)}</div>
        `;
    }

    function heatmap(records) {
        const latest = records.slice(0, 14);
        if (!latest.length) return `<div class="bd-empty">No readings available.</div>`;
        return `<div class="bd-heatmap">${latest.map(row => {
            const score = Math.round(row.metrics.complianceRate * 100);
            const color = score > 90 ? 'rgba(87,183,122,.35)' : score > 75 ? 'rgba(233,166,58,.35)' : 'rgba(255,91,91,.35)';
            return `<div class="bd-heat-cell" style="background:${color}"><strong>${esc(row.businessDate.slice(5))}</strong><br>${esc(row.branch)}<br>${score}%</div>`;
        }).join('')}</div>`;
    }

    function analytics(records, mode) {
        const groups = groupBy(records, row => {
            if (mode === 'daily') return row.businessDate;
            if (mode === 'weekly') {
                const d = row.submittedAt || new Date(row.businessDate);
                const week = new Date(d);
                week.setDate(d.getDate() - d.getDay());
                return week.toISOString().slice(0, 10);
            }
            if (mode === 'monthly') return String(row.businessDate).slice(0, 7);
            return row.branch;
        });
        const rows = Object.entries(groups).map(([label, list]) => ({ label, list, s: aggregate(list) })).sort((a, b) => b.label.localeCompare(a.label));
        return `<div class="bd-card bd-section">
            <h2>${mode[0].toUpperCase() + mode.slice(1)} Analysis</h2>
            <div class="bd-table-wrap"><table class="bd-table">
                <thead><tr><th>Period</th><th>Logs</th><th>Employees</th><th>Average Temp</th><th>Compliance</th><th>Issues</th><th>Issue Rate</th></tr></thead>
                <tbody>${rows.map(row => `<tr><td>${esc(row.label)}</td><td>${row.s.totalLogs}</td><td>${row.s.activeEmployees}</td><td>${fmtTemp(row.s.avgTemp)}</td><td>${percent(row.s.compliance)}</td><td>${row.s.issues.length}</td><td>${percent(row.s.issues.length / Math.max(row.s.totalLogs, 1))}</td></tr>`).join('')}</tbody>
            </table></div>
        </div>`;
    }

    function compliance(records) {
        const byCategory = READING_FIELDS.map(([key, label]) => {
            const readings = records.flatMap(r => r.readings).filter(r => r.key === key);
            const rate = readings.length ? readings.filter(r => r.severity === 'ok').length / readings.length : 0;
            const sop = TEMPERATURE_SOP[key];
            return { label: `${label} (${sopLabel(sop)})`, rate, count: readings.length };
        });
        return `<div class="bd-grid bd-two">
            <div class="bd-card bd-section"><h2>Compliance Dashboard</h2>${barRows(byCategory)}</div>
            <div class="bd-card bd-section"><h2>Temperature Intelligence</h2>${recommendations(records)}</div>
        </div>`;
    }

    function barRows(rows) {
        return `<div class="bd-bars">${rows.map(row => `<div class="bd-bar"><span>${esc(row.label)}</span><div class="bd-bar-track"><div class="bd-bar-fill" style="width:${Math.round(row.rate * 100)}%"></div></div><strong>${percent(row.rate)}</strong></div>`).join('')}</div>`;
    }

    function recommendations(records) {
        const summary = aggregate(records);
        const recs = [];
        if (summary.criticalAlerts) recs.push('Escalate critical temperature alerts before next service period.');
        if (summary.missing) recs.push('Coach shift teams on completing every station field before submission.');
        if (summary.compliance < .9) recs.push('Review cold holding and hot holding stations with repeated variance.');
        if (!recs.length) recs.push('Current logs are stable. Continue standard QA spot checks.');
        return `<div class="bd-list">${recs.map((r, i) => `<div class="bd-list-item"><strong>Insight ${i + 1}</strong><span>${esc(r)}</span></div>`).join('')}</div>`;
    }

    function branches(records) {
        return `${analytics(records, 'branch')}<div class="bd-card bd-section" style="margin-top:14px"><h2>Branch Ranking</h2>${barRows(Object.entries(groupBy(records, r => r.branch)).map(([label, list]) => ({ label, rate: aggregate(list).compliance, count: list.length })).sort((a, b) => b.rate - a.rate))}</div>`;
    }

    function renderView(records, summary) {
        if (state.activeView === 'home') return home(records, summary);
        if (state.activeView === 'journal') return masterDetail(records, summary);
        if (state.activeView === 'timeline') return timeline(records);
        if (state.activeView === 'issues') return `<div class="bd-card bd-section"><h2>Issue Management</h2>${issueList(summary.issues)}</div>`;
        if (state.activeView === 'employees') return `<div class="bd-card bd-section"><h2>Employee Performance</h2>${employeeBars(records)}</div>`;
        if (state.activeView === 'daily') return analytics(records, 'daily');
        if (state.activeView === 'weekly') return analytics(records, 'weekly');
        if (state.activeView === 'monthly') return analytics(records, 'monthly');
        if (state.activeView === 'branches') return branches(records);
        if (state.activeView === 'compliance') return compliance(records);
        if (state.activeView === 'notifications') return `<div class="bd-card bd-section"><h2>Notification Center</h2>${issueList(summary.issues.filter(i => i.status !== 'Closed'))}</div>`;
        return home(records, summary);
    }

    function render() {
        if (state.routeUnsupported) {
            root.innerHTML = `
            <div class="bd-shell">
                <aside class="bd-sidebar">
                    <a class="bd-brand" href="index.html"><span class="bd-brand-mark">B</span><span class="bd-brand-text"><strong>Bakudan</strong><span>Broth Log Ops</span></span></a>
                </aside>
                <main class="bd-main">
                    <div class="bd-card bd-empty">
                        <h1>Unsupported Broth Log route</h1>
                        <p>This dashboard supports /broth-log, /broth-log-b1, /broth-log-b2, and /broth-log-b3.</p>
                    </div>
                </main>
            </div>`;
            return;
        }
        const records = filteredRecords();
        const summary = aggregate(records);
        root.innerHTML = `
            <div class="bd-shell">
                <main class="bd-main">
                    <div class="bd-topbar">
                        <div class="bd-title">
                            <div class="bd-title-row">
                                <h1>Broth Log</h1>
                                ${storeSelector()}
                            </div>
                            <p>${esc(SHEETS[state.activeBranch].name)} · Daily manager review</p>
                        </div>
                        <div class="bd-actions">
                            <button class="bd-btn ${state.rangeEditorOpen ? 'primary' : ''}" data-action="toggleRanges" aria-pressed="${state.rangeEditorOpen}">Ranges</button>
                            <details class="bd-action-menu">
                                <summary>Export</summary>
                                <div>
                                    <button class="bd-btn" data-action="csv">CSV</button>
                                    <button class="bd-btn" data-action="excel">Excel</button>
                                    <button class="bd-btn" data-action="print">Print/PDF</button>
                                </div>
                            </details>
                        </div>
                    </div>
                    ${state.errors.map(e => `<div class="bd-error">${esc(e)}</div>`).join('')}
                    <div class="bd-filters">${filters(records)}</div>
                    ${state.rangeEditorOpen ? rangeEditor() : ''}
                    ${renderView(records, summary)}
                    <details class="bd-system-info">
                        <summary>System info</summary>
                        <div class="bd-sync">
                            <span>Viewing: ${esc(rangeLabel())}</span>
                            <span class="bd-sync-state ${esc(state.syncStatus)}">${state.loading ? 'Syncing Google Sheets...' : esc(state.syncMessage)}</span>
                            <span>Last sync: ${state.lastSync ? state.lastSync.toLocaleTimeString() : 'not yet synced'}</span>
                            <span>Source rows: ${state.records.length}</span>
                            <span>Changes: +${state.lastChanges.new} / updated ${state.lastChanges.updated} / deleted ${state.lastChanges.deleted} / duplicates ignored ${state.lastChanges.duplicates}</span>
                            ${state.consecutiveFailures ? `<span>Retrying automatically (${state.consecutiveFailures} failed attempt${state.consecutiveFailures === 1 ? '' : 's'})</span>` : ''}
                            <select id="refreshSeconds" class="bd-btn" aria-label="Refresh interval">
                                ${SYNC_CONFIG.intervals.map(interval => option(interval.seconds, interval.label, state.refreshSeconds)).join('')}
                            </select>
                            <button class="bd-btn primary" data-action="sync">Sync now</button>
                        </div>
                    </details>
                </main>
            </div>
            <div class="bd-drawer" id="detailDrawer"></div>
        `;
        bindEvents();
    }

    function selectedLogDetail(row, summary, records) {
        return `<div class="bd-selected-detail" data-selected-log="${esc(row.id)}">
            ${detailBody(row, summary, records)}
        </div>`;
    }

    function detail(row) {
        return `<div class="bd-drawer-backdrop" data-close></div><div class="bd-drawer-panel">${detailBody(row, aggregate([row]), [row])}</div>`;
    }

    function detailBody(row) {
        const summary = temperatureSummary(row.readings);
        const grouped = groupBy(row.readings, reading => reading.group);
        const actionReadings = row.readings.filter(reading => reading.severityKey !== 'safe');
        return `
            <div class="bd-detail-head">
                <div>
                    <h2>${esc(row.branch)} · ${esc(row.businessDate)} ${esc(fmtDate(row.submittedAt))}</h2>
                    <div class="bd-muted">${esc(row.employeeName)} · ${esc(displayShift(row.shift, row.submittedAt))} · ${statusPill(row)}</div>
                </div>
                <button class="bd-icon-btn bd-drawer-close" data-close>X</button>
            </div>
            <section class="bd-card bd-section bd-overview-section">
                <h2>Overview</h2>
                <div class="bd-detail-grid">
                    ${field('Branch', row.branch)}${field('Kitchen/Station', 'All logged stations')}${field('Batch ID', row.responseId || row.id.slice(0, 42))}${field('Broth Name', 'Food safety temperature log')}${field('Supervisor', row.managerComment || 'Not recorded')}${field('Submitted', fmtDate(row.submittedAt))}
                </div>
            </section>
            <section class="bd-card bd-section"><h2>Temperature History</h2>
                <div class="bd-temp-summary" aria-label="Temperature status summary">
                    ${['safe', 'warning', 'high', 'critical', 'missing'].map(key => tempKpi(key, summary[key])).join('')}
                </div>
                ${summary.mostSevere ? `<div class="bd-temp-alert ${summary.mostSevere.severityKey}">Most severe: ${esc(summary.mostSevere.label)} · ${esc(severityLabel(summary.mostSevere.severityKey))} · ${esc(deviationText(summary.mostSevere))}</div>` : ''}
                ${actionReadings.length ? `<div class="bd-action-strip" aria-label="Stations requiring attention">
                    <strong>Action Required</strong>
                    <div>${actionReadings.map(reading => `<span class="${esc(reading.severityKey)}">${esc(reading.label)} · ${esc(severityLabel(reading.severityKey))} · ${esc(deviationText(reading))}</span>`).join('')}</div>
                </div>` : ''}
                <div class="bd-muted">Each row compares the original reading directly against that station's SOP target range. SOP and Current markers use a per-station deviation scale.</div>
                <div class="bd-temp-groups">${Object.entries(grouped).map(([group, readings]) => `
                    <section class="bd-temp-group">
                        <h3>${esc(group)}</h3>
                        <div class="bd-temp-list">${readings.map(reading => tempReadingCard(row, reading)).join('')}</div>
                    </section>`).join('')}
                </div>
            </section>
            <section class="bd-card bd-section"><h2>Issues</h2>${issueList(row.issues)}</section>
            <section class="bd-card bd-section"><h2>Timeline</h2>${selectedTimeline(row)}</section>
            <section class="bd-card bd-section"><h2>Compliance</h2>${selectedCompliance(row)}</section>
            <section class="bd-card bd-section"><h2>Employee / Metadata</h2><div class="bd-detail-grid">${field('Employee', row.employeeName)}${field('Shift', displayShift(row.shift, row.submittedAt))}${field('Record ID', row.responseId || row.id.slice(0, 42))}${field('Source', row.sourceTab)}</div></section>
            <section class="bd-card bd-section"><h2>Notes</h2><p>${esc(row.notes || 'No notes')}</p><p>${esc(row.correctiveAction || 'No corrective action')}</p><p class="bd-muted">${esc(row.managerComment || 'No manager comment')}</p></section>
        `;
    }

    function tempKpi(key, value) {
        return `<span class="bd-temp-kpi ${key} ${value ? '' : 'zero'}"><small>${esc(severityLabel(key))}</small><strong>${value}</strong></span>`;
    }

    function temperatureSummary(readings) {
        const summary = { safe: 0, warning: 0, high: 0, critical: 0, missing: 0, mostSevere: null };
        const rank = { safe: 0, warning: 1, high: 2, critical: 3, missing: 1 };
        readings.forEach(reading => {
            const key = reading.severityKey || 'missing';
            summary[key] += 1;
            if (key !== 'safe' && (!summary.mostSevere || rank[key] > rank[summary.mostSevere.severityKey])) summary.mostSevere = reading;
        });
        return summary;
    }

    function tempReadingCard(row, reading) {
        return `<article class="bd-temp-card ${esc(reading.severityKey)}" aria-label="${esc(reading.label)} ${esc(severityLabel(reading.severityKey))}">
            <div class="bd-temp-card-head">
                <div class="bd-temp-main">
                    <strong>${esc(reading.label)}</strong>
                    <span>${esc(reading.sopItem)} · ${esc(reading.sopCategory || reading.group)}</span>
                </div>
                <span class="bd-pill ${esc(reading.severityKey)}">${esc(severityLabel(reading.severityKey))}</span>
            </div>
            <div class="bd-current-temp"><span>Current</span><strong>${fmtTemp(reading.temperature)}</strong></div>
            <div class="bd-temp-values">
                ${tempMetric('Deviation', deviationText(reading))}
                ${tempMetric('Target', reading.targetLabel)}
                ${tempMetric('Trend', trendFor(row, reading))}
                ${tempMetric('Recorded', fmtDate(row.submittedAt))}
                ${tempMetric('By', row.employeeName || 'Unassigned')}
            </div>
            <div class="bd-deviation-wrap">
                <div class="bd-deviation-track" role="img" aria-label="Current reading compared with SOP target">
                    <span class="bd-limit-band"></span>
                    <span class="bd-sop-marker" style="left:50%"><b>SOP</b></span>
                    <span class="bd-current-marker ${esc(reading.severityKey)}" style="left:${currentMarker(reading)}%"><b>Current</b></span>
                </div>
            </div>
            <div class="bd-temp-status"><span>${esc(reading.status)}</span></div>
        </article>`;
    }

    function tempMetric(label, value) {
        return `<div><span>${esc(label)}</span><strong>${esc(value || '-')}</strong></div>`;
    }

    function selectedTimeline(row) {
        const measured = row.readings.filter(reading => reading.temperature !== null);
        if (!measured.length) return `<div class="bd-empty">No numeric readings in this log.</div>`;
        return `<div class="bd-selected-timeline">${measured.map(reading => `
            <div>
                <strong>${esc(reading.label)}</strong>
                <span>${fmtTemp(reading.temperature)} · ${esc(severityLabel(reading.severityKey))}</span>
            </div>`).join('')}</div>`;
    }

    function selectedCompliance(row) {
        const summary = temperatureSummary(row.readings);
        const total = row.readings.length || 1;
        const safeRate = Math.round((summary.safe / total) * 100);
        return `<div class="bd-compliance-detail">
            ${field('Compliance', `${safeRate}%`)}
            ${field('Safe Stations', summary.safe)}
            ${field('Action Needed', summary.warning + summary.high + summary.critical + summary.missing)}
            ${field('Critical', summary.critical)}
        </div>`;
    }

    function field(label, value) {
        return `<div class="bd-field"><span>${esc(label)}</span><strong>${esc(value || '-')}</strong></div>`;
    }

    function bindEvents() {
        root.querySelectorAll('[data-view]').forEach(btn => btn.addEventListener('click', () => {
            state.activeView = btn.dataset.view;
            render();
        }));
        root.querySelectorAll('[data-filter]').forEach(input => input.addEventListener('input', () => {
            if (input.dataset.filter === 'selectedDate') {
                state.filters.selectedDate = isValidDateKey(input.value) ? input.value : businessToday();
                state.filters.dateRange = 'today';
                state.selectedRecordId = '';
                updateDateUrl();
                render();
                return;
            }
            state.filters[input.dataset.filter] = input.value;
            if (input.dataset.filter === 'dateRange') updateRangeUrl(input.value);
            if (input.dataset.filter === 'branch') syncSheets();
            else render();
        }));
        root.querySelectorAll('[data-action]').forEach(btn => btn.addEventListener('click', () => actions(btn.dataset.action)));
        root.querySelectorAll('[data-select-record]').forEach(btn => btn.addEventListener('click', () => {
            state.selectedRecordId = btn.dataset.selectRecord;
            render();
        }));
        const refresh = root.querySelector('#refreshSeconds');
        if (refresh) refresh.addEventListener('change', () => {
            state.refreshSeconds = Number(refresh.value);
            localStorage.setItem('brothRefreshSeconds', String(state.refreshSeconds));
            localStorage.removeItem('brothRefreshMinutes');
            scheduleSync();
            render();
        });
        const storeSelect = root.querySelector('#storeSelect');
        if (storeSelect) storeSelect.addEventListener('change', () => {
            state.activeBranch = storeSelect.value;
            state.filters.branch = 'current';
            state.selectedRecordId = '';
            localStorage.setItem('brothSelectedStore', state.activeBranch);
            const url = new URL(window.location.href);
            url.searchParams.set('store', state.activeBranch);
            if (state.filters.dateRange === 'today') {
                url.searchParams.set('date', state.filters.selectedDate);
                url.searchParams.delete('range');
            } else {
                url.searchParams.set('range', state.filters.dateRange);
                url.searchParams.delete('date');
            }
            window.history.replaceState(null, '', url);
            syncSheets({ force: true });
        });
        const rangeForm = root.querySelector('#rangeEditorForm');
        if (rangeForm) rangeForm.addEventListener('submit', event => {
            event.preventDefault();
            saveRangesFromForm(rangeForm);
        });
        root.querySelectorAll('[data-detail]').forEach(btn => btn.addEventListener('click', () => {
            const row = state.records.find(r => r.id === btn.dataset.detail);
            const drawer = root.querySelector('#detailDrawer');
            if (row && drawer) {
                drawer.innerHTML = detail(row);
                drawer.classList.add('open');
                drawer.querySelectorAll('[data-close]').forEach(close => close.addEventListener('click', () => drawer.classList.remove('open')));
            }
        }));
    }

    function actions(action) {
        if (action === 'sync') syncSheets({ force: true });
        if (action === 'toggleRanges') {
            state.rangeEditorOpen = !state.rangeEditorOpen;
            state.rangeEditorMessage = '';
            render();
        }
        if (action === 'resetRanges') {
            resetSharedRanges();
        }
        if (action === 'today') {
            state.filters.dateRange = 'today';
            state.filters.selectedDate = businessToday();
            state.selectedRecordId = '';
            updateRangeUrl('today');
            render();
        }
        if (action === 'allDates') {
            state.filters.dateRange = 'all';
            updateRangeUrl('all');
            render();
        }
        if (action === 'issuesOnly') {
            state.filters.issue = state.filters.issue === 'open' ? 'all' : 'open';
            state.selectedRecordId = '';
            render();
        }
        if (action === 'reset') {
            state.filters = { query: '', dateRange: 'today', selectedDate: businessToday(), branch: 'current', employee: 'all', issue: 'all', shift: 'all', temp: 'all', tempMin: '', tempMax: '' };
            updateRangeUrl('today');
            syncSheets();
        }
        if (action === 'theme') {
            state.theme = state.theme === 'dark' ? 'light' : 'dark';
            localStorage.setItem('brothTheme', state.theme);
            document.documentElement.dataset.theme = state.theme;
            render();
        }
        if (action === 'print') window.print();
        if (action === 'csv') download('broth-log.csv', csv(filteredRecords()), 'text/csv');
        if (action === 'excel') download('broth-log.xls', excel(filteredRecords()), 'application/vnd.ms-excel');
    }

    async function saveRangesFromForm(form) {
        const next = cloneSopConfig(TEMPERATURE_SOP);
        const invalid = [];
        READING_FIELDS.forEach(([key, label]) => {
            const minInput = form.querySelector(`[data-range-min="${key}"]`);
            const maxInput = form.querySelector(`[data-range-max="${key}"]`);
            const normalized = normalizeRange(minInput ? minInput.value : '', maxInput ? maxInput.value : '');
            if (!normalized) {
                invalid.push(label);
                return;
            }
            Object.assign(next[key], normalized);
        });
        if (invalid.length) {
            state.rangeEditorOpen = true;
            state.rangeEditorMessage = `Check min/max for: ${invalid.join(', ')}`;
            render();
            return;
        }
        const ranges = customRangesFromConfig(next);
        try {
            const payload = await saveSharedRanges(ranges);
            localStorage.setItem(RANGE_STORAGE_KEY, JSON.stringify(payload.ranges || ranges));
            TEMPERATURE_SOP = loadTemperatureSop();
            rebuildRecordsWithCurrentRanges();
            state.rangeEditorOpen = true;
            state.rangeEditorMessage = 'Shared ranges saved. Current readings recalculated for all managers.';
            render();
        } catch (error) {
            state.rangeEditorOpen = true;
            state.rangeEditorMessage = `Could not save shared ranges: ${error.message}. Sign in as a manager/admin and try again.`;
            render();
        }
    }

    async function resetSharedRanges() {
        try {
            const payload = await saveSharedRanges({});
            localStorage.setItem(RANGE_STORAGE_KEY, JSON.stringify(payload.ranges || {}));
            TEMPERATURE_SOP = loadTemperatureSop();
            rebuildRecordsWithCurrentRanges();
            state.rangeEditorOpen = true;
            state.rangeEditorMessage = 'Shared ranges reset to the default paper ranges.';
            render();
        } catch (error) {
            state.rangeEditorOpen = true;
            state.rangeEditorMessage = `Could not reset shared ranges: ${error.message}. Sign in as a manager/admin and try again.`;
            render();
        }
    }

    function updateRangeUrl(range) {
        const url = new URL(window.location.href);
        if (range === 'today') {
            if (state.filters.selectedDate === businessToday()) {
                url.searchParams.set('range', 'today');
                url.searchParams.delete('date');
            } else {
                url.searchParams.set('date', state.filters.selectedDate);
                url.searchParams.delete('range');
            }
        } else {
            url.searchParams.set('range', range);
            url.searchParams.delete('date');
        }
        if (state.showStoreSelector) url.searchParams.set('store', state.activeBranch);
        window.history.replaceState(null, '', url);
    }

    function updateDateUrl() {
        const url = new URL(window.location.href);
        url.searchParams.set('date', state.filters.selectedDate);
        url.searchParams.delete('range');
        if (state.showStoreSelector) url.searchParams.set('store', state.activeBranch);
        window.history.pushState(null, '', url);
    }

    function applyUrlState() {
        const nextBranch = getInitialBranch();
        const branchChanged = nextBranch !== state.activeBranch;
        state.activeBranch = nextBranch;
        state.filters.dateRange = getInitialRange();
        state.filters.selectedDate = getInitialSelectedDate();
        state.filters.branch = 'current';
        state.selectedRecordId = '';
        if (branchChanged) syncSheets({ force: true });
        else render();
    }

    function csv(records) {
        const headers = ['Date', 'Time', 'Branch', 'Employee', 'Shift', 'Average Temp', 'Compliance', 'Issues', 'Notes'];
        const rows = records.map(r => [r.businessDate, r.businessTime, r.branch, r.employeeName, r.shift, Math.round(r.metrics.averageTemperature), percent(r.metrics.complianceRate), r.issues.map(i => i.type).join('; '), r.notes]);
        return [headers, ...rows].map(row => row.map(value => `"${String(value || '').replace(/"/g, '""')}"`).join(',')).join('\n');
    }

    function excel(records) {
        return `<table>${csv(records).split('\n').map(line => `<tr>${line.split(',').map(cell => `<td>${esc(cell.replace(/^"|"$/g, ''))}</td>`).join('')}</tr>`).join('')}</table>`;
    }

    function download(filename, content, type) {
        const blob = new Blob([content], { type });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        a.click();
        URL.revokeObjectURL(url);
    }

    function scheduleSync() {
        if (state.timer) window.clearInterval(state.timer);
        state.timer = window.setInterval(() => syncSheets(), state.refreshSeconds * 1000);
    }

    async function initDashboard() {
        render();
        window.addEventListener('popstate', applyUrlState);
        const loadedSharedRanges = await loadSharedRanges();
        if (loadedSharedRanges) render();
        syncSheets();
        scheduleSync();
    }

    initDashboard();
}());
