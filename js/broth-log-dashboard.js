(function () {
    'use strict';

    const SHEETS = {
        B1: { id: '1-T9WLdHI1MWp0kX7U2SNPOnc7nDBnrrc0njFxBUKnqo', tab: 'Form Responses 1', name: 'B1 Bandera' },
        B2: { id: '1qk78Spg8GmyP4RCjQYwU8Nm0bXdoyl240iUDcSkK3MQ', tab: 'Form Responses 1', name: 'B2 Stone Oak' },
        B3: { id: '1odx4Xq94kz50aJBuE2Q-WcZbvXdfeVFOksOeAxn4Kxw', tab: 'Form Responses 1', name: 'B3 The Rim' }
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

    const state = {
        activeBranch: 'B1',
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
        filters: {
            query: '',
            dateRange: 'all',
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
        syncInFlight: null
    };

    const root = document.getElementById('broth-dashboard');
    if (!root) return;
    state.activeBranch = root.dataset.branch || 'B1';
    document.documentElement.dataset.theme = state.theme;

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

    function parseDateCell(cell) {
        const raw = cell && cell.v ? String(cell.v) : '';
        const formatted = cell && cell.f ? String(cell.f) : '';
        const match = raw.match(/Date\((\d+),(\d+),(\d+),(\d+),(\d+),(\d+)/);
        if (match) {
            return new Date(Number(match[1]), Number(match[2]), Number(match[3]), Number(match[4]), Number(match[5]), Number(match[6]));
        }
        const parsed = new Date(formatted || raw);
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    }

    function toNumber(value) {
        if (value === null || value === undefined || value === '') return null;
        const n = Number(String(value).replace(/[^\d.-]/g, ''));
        return Number.isFinite(n) ? n : null;
    }

    function statusFor(category, temp) {
        if (temp === null) return ['Missing Reading', 'warn', 'Missing Reading'];
        if (category === 'cold' && temp > 41) return ['Temperature Too High', temp >= 45 ? 'critical' : 'warn', 'Temperature Too High'];
        if (category === 'freezer' && temp > 10) return ['Temperature Too High', temp >= 20 ? 'critical' : 'warn', 'Temperature Too High'];
        if (category === 'hot' && temp < 135) return ['Temperature Too Low', temp < 100 ? 'critical' : 'warn', 'Temperature Too Low'];
        if (category === 'fryer' && (temp < 325 || temp > 375)) return ['Out of Range', 'warn', temp < 325 ? 'Temperature Too Low' : 'Temperature Too High'];
        if (category === 'boiler' && (temp < 200 || temp > 214)) return ['Out of Range', 'warn', temp < 200 ? 'Temperature Too Low' : 'Temperature Too High'];
        if (Math.abs(temp) > 500) return ['Sensor Error', 'critical', 'Sensor Error'];
        return ['Compliant', 'ok', null];
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
        const submittedDate = parseDateCell(cell('submittedAt'));
        const branch = text('branch') || sheetBranch;
        const readings = READING_FIELDS.map(([key, label, category]) => {
            const temp = toNumber(valueOf(cell(key)));
            const [status, severity, issueType] = statusFor(category, temp);
            return { key, label, category, temperature: temp, status, severity, issueType };
        });
        const issues = readings
            .filter(reading => reading.issueType)
            .map(reading => ({
                type: reading.issueType,
                severity: reading.severity,
                station: reading.label,
                owner: text('employeeName') || 'Unassigned',
                status: text('correctiveAction') ? 'Closed' : reading.severity === 'critical' ? 'Escalated' : 'Open',
                createdAt: submittedDate,
                closedAt: text('correctiveAction') ? submittedDate : null,
                resolution: text('correctiveAction'),
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
        const businessDate = text('businessDate') || (submittedDate ? submittedDate.toISOString().slice(0, 10) : '');
        const idParts = [text('responseId'), branch, businessDate, text('businessTime'), text('employeeName'), submittedDate ? submittedDate.toISOString() : ''];
        const record = {
            id: idParts.filter(Boolean).join('|'),
            sourceSheetId: SHEETS[sheetBranch].id,
            sourceTab: SHEETS[sheetBranch].tab,
            branch,
            submittedAt: submittedDate,
            businessDate,
            businessTime: text('businessTime'),
            shift: text('shift'),
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
        const now = new Date();
        const startOfDay = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const startOfWeek = new Date(startOfDay);
        startOfWeek.setDate(startOfDay.getDate() - startOfDay.getDay());
        const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);
        return state.records.filter(row => {
            const haystack = [row.branch, row.employeeName, row.businessDate, row.businessTime, row.shift, row.notes, row.correctiveAction, row.managerComment, ...row.issues.map(i => i.type)].join(' ').toLowerCase();
            if (state.filters.query && !haystack.includes(state.filters.query.toLowerCase())) return false;
            if (state.filters.branch !== 'all' && state.filters.branch !== 'current' && row.branch !== state.filters.branch) return false;
            if (state.filters.employee !== 'all' && row.employeeName !== state.filters.employee) return false;
            if (state.filters.shift !== 'all' && row.shift !== state.filters.shift) return false;
            if (state.filters.issue === 'open' && !row.issues.some(i => i.status !== 'Closed')) return false;
            if (state.filters.issue === 'critical' && !row.issues.some(i => i.severity === 'critical')) return false;
            if (state.filters.issue === 'closed' && !row.issues.some(i => i.status === 'Closed')) return false;
            if (state.filters.temp === 'noncompliant' && row.metrics.complianceRate >= 1) return false;
            const minTemp = toNumber(state.filters.tempMin);
            const maxTemp = toNumber(state.filters.tempMax);
            if (minTemp !== null && !row.readings.some(reading => reading.temperature !== null && reading.temperature >= minTemp)) return false;
            if (maxTemp !== null && !row.readings.some(reading => reading.temperature !== null && reading.temperature <= maxTemp)) return false;
            if (state.filters.dateRange !== 'all') {
                const d = row.submittedAt;
                if (!d) return false;
                if (state.filters.dateRange === 'today' && d < startOfDay) return false;
                if (state.filters.dateRange === 'week' && d < startOfWeek) return false;
                if (state.filters.dateRange === 'month' && d < startOfMonth) return false;
            }
            return true;
        });
    }

    function aggregate(records) {
        const allReadings = records.flatMap(r => r.readings);
        const measured = allReadings.filter(r => r.temperature !== null);
        const issues = records.flatMap(r => r.issues);
        const s = stats(measured.map(r => r.temperature));
        return {
            totalLogs: records.length,
            todayLogs: records.filter(r => r.businessDate === new Date().toISOString().slice(0, 10)).length,
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
        return Number.isFinite(n) ? `${Math.round(n)}F` : '-';
    }

    function fmtDate(d) {
        return d ? d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-';
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

    function filters(records) {
        const employees = [...new Set(state.records.map(r => r.employeeName))].sort();
        const shifts = [...new Set(state.records.map(r => r.shift).filter(Boolean))].sort();
        return `
            <input data-filter="query" type="search" placeholder="Search employee, batch, issue, status..." value="${esc(state.filters.query)}">
            <select data-filter="dateRange">
                ${option('all', 'All dates', state.filters.dateRange)}
                ${option('today', 'Today', state.filters.dateRange)}
                ${option('week', 'This week', state.filters.dateRange)}
                ${option('month', 'This month', state.filters.dateRange)}
            </select>
            <select data-filter="branch">
                ${option('current', `Current (${state.activeBranch})`, state.filters.branch)}
                ${option('all', 'All branches', state.filters.branch)}
                ${Object.keys(SHEETS).map(b => option(b, b, state.filters.branch)).join('')}
            </select>
            <select data-filter="employee">
                ${option('all', 'All employees', state.filters.employee)}
                ${employees.map(e => option(e, e, state.filters.employee)).join('')}
            </select>
            <select data-filter="issue">
                ${option('all', 'All statuses', state.filters.issue)}
                ${option('open', 'Open/Pending', state.filters.issue)}
                ${option('critical', 'Critical', state.filters.issue)}
                ${option('closed', 'Closed', state.filters.issue)}
            </select>
            <select data-filter="shift">
                ${option('all', 'All shifts', state.filters.shift)}
                ${shifts.map(s => option(s, s, state.filters.shift)).join('')}
            </select>
            <select data-filter="temp">
                ${option('all', 'All readings', state.filters.temp)}
                ${option('noncompliant', 'Non-compliant only', state.filters.temp)}
            </select>
            <input data-filter="tempMin" type="number" inputmode="decimal" placeholder="Min F" value="${esc(state.filters.tempMin)}">
            <input data-filter="tempMax" type="number" inputmode="decimal" placeholder="Max F" value="${esc(state.filters.tempMax)}">
            <button class="bd-btn" data-action="reset">Reset filters</button>
        `;
    }

    function option(value, label, selected) {
        return `<option value="${esc(value)}" ${value === selected ? 'selected' : ''}>${esc(label)}</option>`;
    }

    function kpis(summary) {
        return `
            <div class="bd-grid bd-kpis">
                ${kpi('Total Logs', summary.totalLogs, 'Deduplicated submissions')}
                ${kpi("Today's Logs", summary.todayLogs, 'Based on business date')}
                ${kpi('Open Issues', summary.openIssues, `${summary.criticalAlerts} critical alerts`)}
                ${kpi('Compliance', percent(summary.compliance), `${summary.missing} missing readings`)}
                ${kpi('Average Temp', fmtTemp(summary.avgTemp), 'All station readings')}
                ${kpi('Active Employees', summary.activeEmployees, 'Unique submitters')}
                ${kpi('Refresh', refreshLabel(state.refreshSeconds), 'Configured in SYNC_CONFIG')}
                ${kpi('Last Sync', state.lastSync ? state.lastSync.toLocaleTimeString() : 'Waiting', state.syncMessage)}
            </div>
        `;
    }

    function kpi(label, value, sub) {
        return `<div class="bd-card bd-kpi"><span>${esc(label)}</span><strong>${esc(value)}</strong><small>${esc(sub)}</small></div>`;
    }

    function refreshLabel(seconds) {
        const interval = SYNC_CONFIG.intervals.find(item => item.seconds === seconds);
        return interval ? interval.label : `${seconds}s`;
    }

    function journal(records) {
        if (!records.length) return `<div class="bd-empty">No matching logs yet.</div>`;
        return `
            <div class="bd-card bd-section">
                <h2>Daily Log Journal</h2>
                <div class="bd-table-wrap">
                    <table class="bd-table">
                        <thead><tr><th>Date</th><th>Time</th><th>Branch</th><th>Employee</th><th>Shift</th><th>Avg</th><th>Low</th><th>High</th><th>Status</th><th>Notes</th><th></th></tr></thead>
                        <tbody>${records.map(row => `
                            <tr>
                                <td>${esc(row.businessDate)}</td>
                                <td>${esc(row.businessTime || fmtDate(row.submittedAt))}</td>
                                <td>${esc(row.branch)}</td>
                                <td>${esc(row.employeeName)}</td>
                                <td>${esc(row.shift)}</td>
                                <td>${fmtTemp(row.metrics.averageTemperature)}</td>
                                <td>${fmtTemp(row.metrics.lowestTemperature)}</td>
                                <td>${fmtTemp(row.metrics.highestTemperature)}</td>
                                <td>${statusPill(row)}</td>
                                <td>${esc(row.notes || row.correctiveAction || '')}</td>
                                <td><button class="bd-row-btn" data-detail="${esc(row.id)}">Quick view</button></td>
                            </tr>`).join('')}</tbody>
                    </table>
                </div>
            </div>
        `;
    }

    function home(records, summary) {
        return `
            ${kpis(summary)}
            <div class="bd-grid bd-two" style="margin-top:14px">
                <div class="bd-card bd-section">
                    <h2>Today's Temperature Trend</h2>
                    ${lineChart(records.slice().reverse(), 'averageTemperature')}
                </div>
                <div class="bd-card bd-section">
                    <h2>Latest Issues</h2>
                    ${issueList(summary.issues.slice(0, 6))}
                </div>
            </div>
            <div class="bd-grid bd-two" style="margin-top:14px">
                <div>${journal(records.slice(0, 12))}</div>
                <div class="bd-card bd-section">
                    <h2>Employee Leaderboard</h2>
                    ${employeeBars(records)}
                </div>
            </div>
        `;
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
                <span>${esc(issue.owner)} · ${esc(issue.status)} · ${esc(issue.severity)}</span>
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
            return { label, rate, count: readings.length };
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
        if (state.activeView === 'journal') return journal(records);
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
        const records = filteredRecords();
        const summary = aggregate(records);
        root.innerHTML = `
            <div class="bd-shell">
                <aside class="bd-sidebar">
                    <a class="bd-brand" href="index.html"><span class="bd-brand-mark">B</span><span class="bd-brand-text"><strong>Bakudan</strong><span>Broth Log Ops</span></span></a>
                    <nav class="bd-nav">${nav()}</nav>
                </aside>
                <main class="bd-main">
                    <div class="bd-topbar">
                        <div class="bd-title">
                            <h1>${esc(SHEETS[state.activeBranch].name)}</h1>
                            <p>Centralized food-safety and broth temperature intelligence from Google Sheets.</p>
                        </div>
                        <div class="bd-actions">
                            <select id="refreshSeconds" class="bd-btn" aria-label="Refresh interval">
                                ${SYNC_CONFIG.intervals.map(interval => option(interval.seconds, interval.label, state.refreshSeconds)).join('')}
                            </select>
                            <button class="bd-btn" data-action="csv">CSV</button>
                            <button class="bd-btn" data-action="excel">Excel</button>
                            <button class="bd-btn" data-action="print">Print/PDF</button>
                            <button class="bd-icon-btn" data-action="theme" title="Toggle dark/light mode">${state.theme === 'dark' ? 'L' : 'D'}</button>
                            <button class="bd-btn primary" data-action="sync">Sync</button>
                        </div>
                    </div>
                    <div class="bd-sync">
                        <span class="bd-sync-state ${esc(state.syncStatus)}">${state.loading ? 'Syncing Google Sheets...' : esc(state.syncMessage)}</span>
                        <span>Last successful sync: ${state.lastSync ? state.lastSync.toLocaleString() : 'not yet synced'}</span>
                        <span>Last attempt: ${state.lastAttempt ? state.lastAttempt.toLocaleTimeString() : 'not yet attempted'}</span>
                        <span>Rows: ${state.records.length}</span>
                        <span>Changes: +${state.lastChanges.new} / updated ${state.lastChanges.updated} / deleted ${state.lastChanges.deleted} / duplicates ignored ${state.lastChanges.duplicates}</span>
                        ${state.consecutiveFailures ? `<span>Retrying automatically (${state.consecutiveFailures} failed attempt${state.consecutiveFailures === 1 ? '' : 's'})</span>` : ''}
                    </div>
                    ${state.errors.map(e => `<div class="bd-error">${esc(e)}</div>`).join('')}
                    <div class="bd-filters">${filters(records)}</div>
                    ${renderView(records, summary)}
                </main>
            </div>
            <div class="bd-drawer" id="detailDrawer"></div>
        `;
        bindEvents();
    }

    function detail(row) {
        const max = Math.max(...row.readings.map(r => r.temperature || 0), 1);
        return `<div class="bd-drawer-backdrop" data-close></div><div class="bd-drawer-panel">
            <div class="bd-detail-head"><div><h2>${esc(row.branch)} · ${esc(row.businessDate)} ${esc(row.businessTime)}</h2><div class="bd-muted">${esc(row.employeeName)} · ${esc(row.shift)} · ${statusPill(row)}</div></div><button class="bd-icon-btn" data-close>X</button></div>
            <div class="bd-detail-grid">
                ${field('Branch', row.branch)}${field('Kitchen/Station', 'All logged stations')}${field('Batch ID', row.responseId || row.id.slice(0, 42))}${field('Broth Name', 'Food safety temperature log')}${field('Supervisor', row.managerComment || 'Not recorded')}${field('Submitted', fmtDate(row.submittedAt))}
            </div>
            <div class="bd-card bd-section" style="margin-top:14px"><h2>Temperature History</h2>
                <div class="bd-timeline">${row.readings.map(reading => `<div class="bd-time-row"><strong>${esc(reading.label)}</strong><div class="bd-bar-track"><div class="bd-bar-fill" style="width:${Math.max(3, Math.min(100, ((reading.temperature || 0) / max) * 100))}%"></div></div><span class="bd-pill ${reading.severity}">${fmtTemp(reading.temperature)}</span></div>`).join('')}</div>
            </div>
            <div class="bd-grid bd-two" style="margin-top:14px">
                <div class="bd-card bd-section"><h2>Issues</h2>${issueList(row.issues)}</div>
                <div class="bd-card bd-section"><h2>Notes & Actions</h2><p>${esc(row.notes || 'No notes')}</p><p>${esc(row.correctiveAction || 'No corrective action')}</p><p class="bd-muted">${esc(row.managerComment || 'No manager comment')}</p></div>
            </div>
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
            state.filters[input.dataset.filter] = input.value;
            if (input.dataset.filter === 'branch') syncSheets();
            else render();
        }));
        root.querySelectorAll('[data-action]').forEach(btn => btn.addEventListener('click', () => actions(btn.dataset.action)));
        const refresh = root.querySelector('#refreshSeconds');
        if (refresh) refresh.addEventListener('change', () => {
            state.refreshSeconds = Number(refresh.value);
            localStorage.setItem('brothRefreshSeconds', String(state.refreshSeconds));
            localStorage.removeItem('brothRefreshMinutes');
            scheduleSync();
            render();
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
        if (action === 'reset') {
            state.filters = { query: '', dateRange: 'all', branch: 'current', employee: 'all', issue: 'all', shift: 'all', temp: 'all', tempMin: '', tempMax: '' };
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

    render();
    syncSheets();
    scheduleSync();
}());
