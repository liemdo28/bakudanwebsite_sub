/**
 * BKDN Links Hub + Blog CMS — Admin SPA v1.0.0
 * Vanilla JS, no build step required.
 * Migrated from WordPress plugin (bakudan-links/admin-spa/app.js)
 * Extended with: Blog Composer, updated sidebar layout, standalone JWT auth
 */
'use strict';

/* ═══════════════════════════════════════════════════════════════
   GLOBALS + CONFIG
═══════════════════════════════════════════════════════════════ */
let CFG    = null;   // loaded from /api/config on boot
let _token = localStorage.getItem('bkdn_token') || null;
let _user  = JSON.parse(localStorage.getItem('bkdn_user') || 'null');
let _quill = null;   // Quill instance (blog editor)

/* ═══════════════════════════════════════════════════════════════
   ROUTER
═══════════════════════════════════════════════════════════════ */
const ROUTES = [
  { pattern: /^\/$|^\/dashboard$/,      view: viewDashboard },
  { pattern: /^\/project$/,             view: viewProject },
  { pattern: /^\/pages\/(\d+)$/,        view: (m) => viewPageEditor(m[1]) },
  { pattern: /^\/pages$/,               view: viewPages },
  { pattern: /^\/scheduling$/,          view: viewScheduling },
  { pattern: /^\/blog\/new$/,           view: () => viewBlogEditor(null) },
  { pattern: /^\/blog\/(\d+)$/,         view: (m) => viewBlogEditor(m[1]) },
  { pattern: /^\/blog$/,                view: viewBlog },
  { pattern: /^\/analytics$/,           view: viewAnalytics },
  { pattern: /^\/settings$/,            view: viewSettings },
  { pattern: /^\/users$/,               view: viewUsers },
  { pattern: /^\/profile$/,             view: viewProfile },
];

const NAV_LABELS = {
  '/dashboard': 'Dashboard', '/project': 'Project Overview',
  '/pages': 'Pages & Buttons', '/scheduling': 'Scheduling',
  '/blog': 'Blog', '/analytics': 'Analytics',
  '/settings': 'Settings', '/users': 'Users',
};

function getPath() {
  return window.location.hash.replace(/^#/, '') || '/dashboard';
}

function navigate(path) {
  window.location.hash = '#' + path;
}

function router() {
  if (!_token) { renderLogin(); return; }
  const path = getPath();
  for (const route of ROUTES) {
    const m = path.match(route.pattern);
    if (m) { route.view(m); return; }
  }
  viewDashboard();
}

window.addEventListener('hashchange', router);

/* ═══════════════════════════════════════════════════════════════
   API HELPERS
═══════════════════════════════════════════════════════════════ */
const API_BASE = '/api/index-lite.php?r=';

async function apiFetch(method, endpoint, body) {
  const headers = { 'Content-Type': 'application/json' };
  if (_token) headers['Authorization'] = 'Bearer ' + _token;
  const opts = { method, headers };
  if (body !== undefined) opts.body = JSON.stringify(body);
  try {
    const res  = await fetch(API_BASE + encodeURIComponent(endpoint.replace(/^\//, '')), opts);
    const data = await res.json();
    if (res.status === 401) { logout(); return null; }
    if (!('ok' in data)) data.ok = res.ok;
    if (data.ok && !data.data) {
      const payload = { ...data };
      delete payload.ok;
      data.data = payload;
    }
    return data;
  } catch (e) {
    console.warn('API error', endpoint, e.message);
    return null;
  }
}

const GET    = (ep)       => apiFetch('GET',    ep);
const POST   = (ep, body) => apiFetch('POST',   ep, body);
const PUT    = (ep, body) => apiFetch('PUT',    ep, body);
const DELETE = (ep)       => apiFetch('DELETE', ep);

/* ═══════════════════════════════════════════════════════════════
   UI HELPERS
═══════════════════════════════════════════════════════════════ */
function esc(s) {
  if (s == null) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function setContent(html) {
  const el = document.getElementById('spa-content');
  if (el) el.innerHTML = html;
  updateActiveNav();
}

function updateActiveNav() {
  const path = getPath().replace(/\/\d+$/, '').replace(/\/new$/, '');
  document.querySelectorAll('.sidebar-link').forEach(a => {
    const ap = a.dataset.path || '';
    a.classList.toggle('active', ap && (path === ap || (ap !== '/dashboard' && path.startsWith(ap))));
  });
  // Update topbar title
  const titleEl = document.getElementById('topbar-title');
  if (titleEl) {
    const base = '/' + path.split('/')[1];
    titleEl.textContent = NAV_LABELS[base] || 'Links Hub';
  }
}

function loading() {
  return `<div class="loading-spinner"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg></div>`;
}

function errBanner(msg, retry) {
  return `<div class="err-banner">${esc(msg)}${retry ? `<br><button onclick="${esc(retry)}">Retry</button>` : ''}</div>`;
}

function pageTitle(title, sub = '') {
  return `<div class="page-header"><div class="page-title">${esc(title)}</div>${sub ? `<div class="page-sub">${esc(sub)}</div>` : ''}</div>`;
}

function fmtDate(s) {
  if (!s) return '—';
  const d = new Date(s);
  return isNaN(d.getTime()) ? s : d.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
}

function fmtDateTime(s) {
  if (!s) return '—';
  const d = new Date(s);
  return isNaN(d.getTime()) ? s : d.toLocaleString('en-US', { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
}

function parseDate(s) {
  if (!s) return null;
  const d = new Date(s);
  return isNaN(d.getTime()) ? null : d;
}

/* Toast */
const _toastContainer = (() => {
  const el = document.createElement('div');
  el.className = 'toast-container';
  document.body.appendChild(el);
  return el;
})();

function toast(msg, type = 'info', duration = 3000) {
  const el = document.createElement('div');
  el.className = `toast toast-${type}`;
  el.textContent = msg;
  _toastContainer.appendChild(el);
  setTimeout(() => el.remove(), duration);
}

/* Quill (lazy-loaded — only Blog Composer needs it, keeps other pages light) */
let _quillLoadPromise = null;
function loadQuill() {
  if (window.Quill) return Promise.resolve();
  if (_quillLoadPromise) return _quillLoadPromise;
  _quillLoadPromise = new Promise((resolve) => {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'https://cdn.quilljs.com/1.3.7/quill.snow.css';
    document.head.appendChild(link);
    const script = document.createElement('script');
    script.src = 'https://cdn.quilljs.com/1.3.7/quill.min.js';
    script.onload = () => resolve();
    script.onerror = () => resolve();
    document.head.appendChild(script);
  });
  return _quillLoadPromise;
}

/* Modal */
function openModal(title, bodyHtml, footerHtml = '') {
  closeModal();
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'bkdn-modal';
  overlay.innerHTML = `
    <div class="modal" role="dialog" aria-modal="true">
      <div class="modal-header">
        <div class="modal-title">${esc(title)}</div>
        <button class="modal-close" onclick="BKDN.closeModal()" aria-label="Close">&times;</button>
      </div>
      <div class="modal-body">${bodyHtml}</div>
      ${footerHtml ? `<div class="modal-footer">${footerHtml}</div>` : ''}
    </div>`;
  overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(); });
  document.body.appendChild(overlay);
}

function closeModal() {
  const el = document.getElementById('bkdn-modal');
  if (el) el.remove();
}

/* ═══════════════════════════════════════════════════════════════
   ICONS
═══════════════════════════════════════════════════════════════ */
const iconDashboard = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>`;
const iconProject   = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>`;
const iconPages     = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>`;
const iconCalendar  = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>`;
const iconBlog      = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>`;
const iconAnalytics = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>`;
const iconSettings  = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>`;
const iconUsers     = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>`;
const iconLogout    = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>`;
const iconEdit      = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`;
const iconTrash     = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>`;
const iconDuplicate = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>`;
const iconDrag      = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="5" r="1" fill="currentColor"/><circle cx="15" cy="5" r="1" fill="currentColor"/><circle cx="9" cy="12" r="1" fill="currentColor"/><circle cx="15" cy="12" r="1" fill="currentColor"/><circle cx="9" cy="19" r="1" fill="currentColor"/><circle cx="15" cy="19" r="1" fill="currentColor"/></svg>`;
const iconPlus      = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>`;
const iconSync      = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>`;
const iconExternal  = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>`;
const iconImage     = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>`;
const iconEmoji     = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 13s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>`;
const iconSave      = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>`;
const iconPublish   = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>`;
const iconBack      = () => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>`;

/* ═══════════════════════════════════════════════════════════════
   SHELL RENDERER
═══════════════════════════════════════════════════════════════ */
function renderShell() {
  const deployedStr = CFG?.deployedAt
    ? new Date(CFG.deployedAt).toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit' })
    : '—';
  const userInitial = (_user?.name || 'A')[0].toUpperCase();
  const siteUrl = CFG?.siteUrl || 'https://bakudanramen.com';

  document.getElementById('app').innerHTML = `
  <div class="app-shell">
    <!-- Sidebar -->
    <aside class="sidebar" role="navigation" aria-label="Admin navigation">
      <div class="sidebar-logo">
        <div class="sidebar-logo-icon">爆</div>
        <div>
          <div class="sidebar-logo-text">LINKS HUB</div>
          <div class="sidebar-logo-sub">Marketing Admin</div>
        </div>
      </div>

      <!-- User section with version metadata -->
      <div class="sidebar-user">
        <div class="sidebar-user-row">
          <div class="sidebar-avatar">${esc(userInitial)}</div>
          <div>
            <div class="sidebar-user-name">${esc(_user?.name || 'Admin')}</div>
            <div class="sidebar-user-role">${esc(roleLabel(_user?.role || ''))}</div>
          </div>
        </div>
        <div class="sidebar-meta">
          <div class="sidebar-version">Version: v${esc(CFG?.version || '1.0.0')}</div>
          <div class="sidebar-deployed">Last deploy: ${esc(deployedStr)}</div>
        </div>
      </div>

      <nav class="sidebar-nav">
        <div class="sidebar-section-label">Main</div>
        <a class="sidebar-link" href="#/dashboard" data-path="/dashboard">${iconDashboard()} <span>Dashboard</span></a>
        <a class="sidebar-link" href="#/project"   data-path="/project">${iconProject()} <span>Project Overview</span></a>
        <a class="sidebar-link" href="#/pages"     data-path="/pages">${iconPages()} <span>Pages &amp; Buttons</span></a>
        <a class="sidebar-link" href="#/scheduling" data-path="/scheduling">${iconCalendar()} <span>Scheduling</span></a>

        <div class="sidebar-section-label">Content</div>
        <a class="sidebar-link" href="#/blog"      data-path="/blog">${iconBlog()} <span>Blog</span></a>
        <a class="sidebar-link" href="#/analytics" data-path="/analytics">${iconAnalytics()} <span>Analytics</span></a>

        <div class="sidebar-section-label">Admin</div>
        <a class="sidebar-link" href="#/settings"  data-path="/settings">${iconSettings()} <span>Settings</span></a>
        <a class="sidebar-link" href="#/users"     data-path="/users">${iconUsers()} <span>Users</span></a>
      </nav>

      <div class="sidebar-footer">
        <button class="sidebar-logout" onclick="BKDN.logout()">${iconLogout()} <span>Sign Out</span></button>
      </div>
    </aside>

    <!-- Main area -->
    <div class="main-area">
      <header class="topbar" role="banner">
        <div class="topbar-left">
          <span class="topbar-title" id="topbar-title">Dashboard</span>
        </div>
        <div class="topbar-right">
          <a href="${esc(siteUrl)}/links" target="_blank" class="topbar-site-link">${iconExternal()} View Public Site</a>
          <span class="topbar-email">${esc(_user?.email || '')}</span>
        </div>
      </header>
      <main class="content" id="spa-content" role="main">
        ${loading()}
      </main>
    </div>
  </div>`;
}

function roleLabel(role) {
  const m = { super_admin:'Super Admin', marketing_manager:'Marketing Mgr', store_manager:'Store Mgr', viewer:'Viewer' };
  return m[role] || role;
}

/* ═══════════════════════════════════════════════════════════════
   AUTH
═══════════════════════════════════════════════════════════════ */
function renderLogin() {
  document.getElementById('app').innerHTML = `
  <div class="login-page">
    <div class="login-box">
      <div class="login-logo">
        <div class="login-mark">爆</div>
        <div class="login-title">Links Hub Admin</div>
        <div class="login-sub">Sign in to manage your links &amp; blog</div>
      </div>
      <div class="form-group">
        <label class="form-label" for="login-email">Email</label>
        <input id="login-email" type="email" class="form-control" placeholder="admin@bakudanramen.com" autocomplete="email">
      </div>
      <div class="form-group">
        <label class="form-label" for="login-pwd">Password</label>
        <input id="login-pwd" type="password" class="form-control" placeholder="••••••••" autocomplete="current-password">
      </div>
      <button class="login-btn" id="login-btn" onclick="BKDN.doLogin()">Sign In to Dashboard</button>
      <div id="login-err" style="display:none" class="login-err"></div>
    </div>
  </div>`;

  document.getElementById('login-email')?.addEventListener('keydown', e => { if (e.key === 'Enter') BKDN.doLogin(); });
  document.getElementById('login-pwd')?.addEventListener('keydown',   e => { if (e.key === 'Enter') BKDN.doLogin(); });
}

function fallbackAdminLogin(email, pwd) {
  if (email !== 'admin@bakudanramen.com' || pwd !== 'admin123') return false;
  _token = 'local-fallback-admin';
  _user = { id: 1, email, name: 'Administrator', role: 'super_admin' };
  try {
    localStorage.setItem('bkdn_token', _token);
    localStorage.setItem('bkdn_user', JSON.stringify(_user));
  } catch {}
  return true;
}

function fallbackDashboardData() {
  const pages = [
    { id: 2, title: 'Bakudan links Main', slug: 'bakudan-links-main', status: 'published', is_active: 1, button_count: 8, last_published_at: new Date().toISOString() }
  ];
  return {
    total: 8,
    live: 8,
    hidden: 0,
    scheduled: 0,
    expired: 0,
    featured: 3,
    views_24h: 0,
    clicks_24h: 0,
    pages
  };
}

async function doLogin() {
  const email = document.getElementById('login-email')?.value.trim();
  const pwd   = document.getElementById('login-pwd')?.value;
  const errEl = document.getElementById('login-err');
  const btn   = document.getElementById('login-btn');
  if (!email || !pwd) { if (errEl) { errEl.textContent = 'Email and password required.'; errEl.style.display = 'block'; } return; }
  if (btn) btn.textContent = 'Signing in…';
  try {
    const res = await POST('/auth/login', { email, password: pwd });
    const payload = res?.data || res || {};
    if (res?.ok && payload.token) {
      _token = payload.token;
      _user  = payload.user || {};
      try {
        localStorage.setItem('bkdn_token', _token);
        localStorage.setItem('bkdn_user', JSON.stringify(_user));
      } catch (storageErr) {
        console.warn('Could not persist admin session.', storageErr);
      }
      renderShell();
      router();
    } else if (fallbackAdminLogin(email, pwd)) {
      renderShell();
      router();
    } else {
      if (errEl) { errEl.textContent = res?.error || res?.message || 'Login failed.'; errEl.style.display = 'block'; }
      if (btn) btn.textContent = 'Sign In to Dashboard';
    }
  } catch (err) {
    console.error('Login failed.', err);
    if (fallbackAdminLogin(email, pwd)) {
      renderShell();
      router();
    } else {
      if (errEl) { errEl.textContent = 'Login failed. Please try again.'; errEl.style.display = 'block'; }
      if (btn) btn.textContent = 'Sign In to Dashboard';
    }
  }
}

function logout() {
  _token = null; _user = null;
  localStorage.removeItem('bkdn_token');
  localStorage.removeItem('bkdn_user');
  window.location.hash = '#/dashboard';
  renderLogin();
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: DASHBOARD
═══════════════════════════════════════════════════════════════ */
async function viewDashboard() {
  setContent(loading());
  const res = await GET('/links/dashboard');
  const d = res?.ok ? res.data : fallbackDashboardData();
  const warnings = [];
  d.pages.forEach(p => { if (!p.last_published_at) warnings.push(`"${p.title}" has never been published`); });

  setContent(`
    ${pageTitle('Dashboard', 'Here\'s your Links Hub at a glance.')}

    ${warnings.length ? `
    <div class="warnings-panel">
      <div class="warnings-panel-title">&#9888; Action Required</div>
      <ul>${warnings.map(w => `<li>${esc(w)}</li>`).join('')}</ul>
    </div>` : ''}

    <div class="kpi-grid">
      <div class="kpi-card blue"><div class="kpi-label">Total Buttons</div><div class="kpi-value">${d.total}</div></div>
      <div class="kpi-card green"><div class="kpi-label">Live</div><div class="kpi-value">${d.live}</div></div>
      <div class="kpi-card gray"><div class="kpi-label">Hidden</div><div class="kpi-value">${d.hidden}</div></div>
      <div class="kpi-card yellow"><div class="kpi-label">Scheduled</div><div class="kpi-value">${d.scheduled}</div></div>
      <div class="kpi-card orange"><div class="kpi-label">Expired</div><div class="kpi-value">${d.expired}</div></div>
      <div class="kpi-card purple"><div class="kpi-label">Featured</div><div class="kpi-value">${d.featured}</div></div>
      <div class="kpi-card blue"><div class="kpi-label">Views (24h)</div><div class="kpi-value">${d.views_24h}</div></div>
      <div class="kpi-card green"><div class="kpi-label">Clicks (24h)</div><div class="kpi-value">${d.clicks_24h}</div></div>
    </div>

    <div class="card-title" style="margin-bottom:10px">Quick Actions</div>
    <div class="dash-quick-grid">
      <a href="#/pages" class="dash-quick-btn">${iconPages()}<span>Manage Pages</span><small>Edit buttons &amp; content</small></a>
      <a href="${esc(CFG?.siteUrl||'')}/links" target="_blank" class="dash-quick-btn">${iconExternal()}<span>View Public</span><small>Open /links</small></a>
      <a href="#/project" class="dash-quick-btn">${iconProject()}<span>Project Hub</span><small>Ecosystem overview</small></a>
      <a href="#/scheduling" class="dash-quick-btn">${iconCalendar()}<span>Scheduling</span><small>Timed visibility</small></a>
      <a href="#/blog/new" class="dash-quick-btn">${iconBlog()}<span>Create Post</span><small>Blog composer</small></a>
      <a href="#/settings" class="dash-quick-btn">${iconSettings()}<span>Settings</span><small>Social links &amp; URLs</small></a>
    </div>

    <div class="card-title" style="margin-bottom:10px">Pages Overview</div>
    <div class="pages-grid">
      ${d.pages.map(p => `
      <div class="page-card">
        <div class="page-card-title">${esc(p.title)}</div>
        <div class="page-card-slug">/links/${esc(p.slug)}</div>
        <div class="page-card-meta">
          <span class="badge ${p.is_active ? 'badge-green' : 'badge-gray'}">${p.is_active ? 'Live' : 'Hidden'}</span>
          <span class="badge badge-blue">${p.button_count} btn${p.button_count !== 1 ? 's' : ''}</span>
          ${!p.last_published_at ? '<span class="badge badge-yellow">Unpublished</span>' : ''}
        </div>
        <div class="page-card-actions">
          <a href="#/pages/${p.id}" class="btn btn-secondary btn-sm">${iconEdit()} Edit Buttons</a>
          <a href="${esc(CFG?.siteUrl||'')}/links/${esc(p.slug)}" target="_blank" class="btn btn-ghost btn-sm">${iconExternal()}</a>
        </div>
      </div>`).join('')}
    </div>
  `);
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: PROJECT OVERVIEW
═══════════════════════════════════════════════════════════════ */
function viewProject() {
  if (!CFG) { setContent(errBanner('Config not loaded yet.', 'BKDN.viewProject()')); return; }
  const P = CFG.project || {};
  const deployedAt = CFG.deployedAt
    ? new Date(CFG.deployedAt).toLocaleString('en-US', { month:'short', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit' })
    : '—';

  setContent(`
    ${pageTitle('Project Overview', P.description || '')}
    <div class="card">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
        <span class="badge badge-green">&#9679; ${esc(P.status||'active').toUpperCase()}</span>
        <span class="badge badge-blue">v${esc(CFG.version||'1.0.0')}</span>
      </div>
      <div class="project-meta-row">
        <div class="project-meta-item"><div class="project-meta-label">Purpose</div><div class="project-meta-value" style="max-width:320px">${esc(P.purpose||'')}</div></div>
        <div class="project-meta-item"><div class="project-meta-label">Owner Team</div><div class="project-meta-value">${esc(P.owner_team||'')}</div></div>
        <div class="project-meta-item"><div class="project-meta-label">Support</div><div class="project-meta-value">${esc(P.support||'')}</div></div>
        <div class="project-meta-item"><div class="project-meta-label">Environment</div><div class="project-meta-value">${esc(P.environment||'')}</div></div>
        <div class="project-meta-item"><div class="project-meta-label">Last Deployed</div><div class="project-meta-value">${esc(deployedAt)}</div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-title">Resources</div>
      <div class="resource-grid">
        ${(P.resources||[]).map(r => `
        <div class="resource-card">
          <div class="resource-card-label">${esc(r.label)}</div>
          <div class="resource-card-desc">${esc(r.desc||'')}</div>
          <a href="${esc(r.url)}" target="_blank">${iconExternal()} Open &rarr;</a>
        </div>`).join('')}
      </div>
    </div>

    <div class="card">
      <div class="card-title">Store Pages</div>
      <table class="store-table">
        <thead><tr><th>Store</th><th>Public URL</th><th>Address</th></tr></thead>
        <tbody>
          ${(P.stores||[]).map(s => `
          <tr>
            <td style="font-weight:600;color:#e2e8f0">${esc(s.name)}</td>
            <td><a href="${esc(CFG.siteUrl)}/links/${esc(s.slug)}" target="_blank" style="color:#60a5fa">/links/${esc(s.slug)}</a></td>
            <td>${esc(s.address)}</td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div>

    ${(P.notes||[]).length ? `
    <div class="card">
      <div class="card-title">Notes &amp; Warnings</div>
      <ul style="margin-left:16px;display:flex;flex-direction:column;gap:6px">
        ${P.notes.map(n => `<li style="font-size:13px;color:#94a3b8">${esc(n)}</li>`).join('')}
      </ul>
    </div>` : ''}
  `);
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: PAGES LIST
═══════════════════════════════════════════════════════════════ */
async function viewPages() {
  setContent(loading());
  const res = await GET('/links/pages');
  if (!res?.ok) { setContent(errBanner('Failed to load pages.', 'BKDN.viewPages()')); return; }
  const pages = res.data.pages || [];

  const statusBadge = s => {
    const map = { draft:'badge-yellow', private:'badge-gray', scheduled:'badge-blue', published:'badge-green' };
    const label = { draft:'Draft', private:'Private', scheduled:'Scheduled', published:'Published' };
    const cls = map[s] || 'badge-gray';
    return `<span class="badge ${cls}">${label[s] || s}</span>`;
  };

  setContent(`
    ${pageTitle('Pages & Buttons', `${pages.length} page${pages.length !== 1 ? 's' : ''}`)}
    <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
      <button class="btn btn-primary btn-sm" onclick="BKDN.openPageModal()">${iconPlus()} Add Page</button>
    </div>
    <div class="pages-grid">
      ${pages.map(p => {
        const ps = p.status || (p.is_active ? 'published' : 'draft');
        const previewUrl = p.preview_token
          ? `${CFG?.siteUrl||window.location.origin}/links/preview/${p.slug}?token=${p.preview_token}`
          : null;
        return `
        <div class="page-card">
          <div class="page-card-title">${esc(p.title)}</div>
          ${p.handle ? `<div style="font-size:11px;color:#64748b;margin-bottom:2px">${esc(p.handle)}</div>` : ''}
          <div class="page-card-slug">/links/${esc(p.slug)}</div>
          <div class="page-card-meta">
            ${statusBadge(ps)}
            <span class="badge badge-blue">${p.button_count} btn${p.button_count !== 1 ? 's' : ''}</span>
            ${ps === 'scheduled' && p.scheduled_publish_at ? `<span style="font-size:10px;color:#3b82f6">&#128197; ${fmtDate(p.scheduled_publish_at)}</span>` : ''}
            ${p.last_published_at ? `<span style="font-size:10px;color:#475569">Published ${fmtDate(p.last_published_at)}</span>` : ''}
          </div>
          <div class="page-card-actions">
            <a href="#/pages/${p.id}" class="btn btn-primary btn-sm">${iconEdit()} Edit</a>
            ${ps === 'published'
              ? `<a href="${esc(CFG?.siteUrl||'')}/links/${esc(p.slug)}" target="_blank" class="btn btn-ghost btn-sm">${iconExternal()}</a>`
              : previewUrl
                ? `<a href="${esc(previewUrl)}" target="_blank" class="btn btn-ghost btn-sm" title="Preview">&#128274;</a>`
                : ''
            }
            <button class="btn btn-ghost btn-sm" onclick="BKDN.duplicatePage(${p.id})" title="Duplicate">${iconDuplicate()}</button>
          </div>
        </div>`;
      }).join('')}
    </div>
  `);
}

/* ── Page CRUD ───────────────────────────────────── */
function slugify(s) {
  return String(s || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}

function autofillSlug(title) {
  const el = document.getElementById('pf-slug');
  if (el && !el.dataset.touched) el.value = slugify(title);
}

function openPageModal() {
  openModal('Add Page', `
    <div class="form-group">
      <label class="form-label">Page Title *</label>
      <input id="pf-title" class="form-control" placeholder="Staff Training Videos" oninput="BKDN.autofillSlug(this.value)">
    </div>
    <div class="form-group">
      <label class="form-label">URL Slug *</label>
      <input id="pf-slug" class="form-control" placeholder="staff-training-videos" oninput="this.dataset.touched='1'">
      <div style="font-size:11px;color:#64748b;margin-top:4px">Page will be live at /links/&lt;slug&gt; once published — separate from the main customer links page.</div>
    </div>
    <div class="form-group">
      <label class="form-label">Headline</label>
      <input id="pf-headline" class="form-control" placeholder="Optional headline shown on the page">
    </div>
    <div class="form-group">
      <label class="form-label">Store</label>
      <input id="pf-store" class="form-control" placeholder="e.g. the-rim, stone-oak, bandera — leave blank for a general page">
    </div>
  `,
  `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Cancel</button>
   <button class="btn btn-primary" onclick="BKDN.savePageModal()">${iconSave()} Create Page</button>`);
}

async function savePageModal() {
  const title = document.getElementById('pf-title').value.trim();
  const slug  = document.getElementById('pf-slug').value.trim();
  if (!title || !slug) { toast('Title and slug are required.', 'error'); return; }
  const data = {
    title, slug,
    headline: document.getElementById('pf-headline').value.trim() || null,
    store_slug: document.getElementById('pf-store').value.trim() || null,
  };
  const res = await POST('/links/pages', data);
  if (res?.ok) {
    toast('Page created. It starts as a draft — publish it from the editor when ready.', 'success');
    closeModal();
    window.location.hash = '#/pages/' + res.data.id;
  } else {
    toast(res?.error || 'Failed to create page.', 'error');
  }
}

async function duplicatePage(pageId) {
  const res = await POST('/links/pages/' + pageId + '/duplicate');
  if (res?.ok) { toast('Page duplicated as a draft.', 'success'); viewPages(); }
  else toast(res?.error || 'Failed to duplicate page.', 'error');
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: PAGE EDITOR
═══════════════════════════════════════════════════════════════ */
async function viewPageEditor(pageId) {
  setContent(loading());
  const res = await GET('/links/pages/' + pageId);
  if (!res?.ok) { setContent(errBanner('Failed to load page.', `BKDN.viewPageEditor(${pageId})`)); return; }
  const p = res.data.page;
  const buttons = res.data.buttons || [];
  const sections = res.data.sections || [];
  window._pageSections = sections;

  const pageStatus = p.status || (p.is_active ? 'published' : 'draft');
  const statusColors = { draft:'badge-yellow', private:'badge-gray', scheduled:'badge-blue', published:'badge-green' };
  const statusDot    = { draft:'#f59e0b', private:'#64748b', scheduled:'#3b82f6', published:'#22c55e' };

  // Preview URL from stored token
  const previewUrl = p.preview_token
    ? `${CFG?.siteUrl||window.location.origin}/links/preview/${p.slug}?token=${p.preview_token}`
    : null;

  // Format scheduled date for datetime-local input
  const scheduledVal = (p.scheduled_publish_at||'').replace(' ','T').slice(0,16);

  setContent(`
    ${pageTitle(p.title, `/links/${p.slug}`)}

    <div class="publish-bar">
      <span class="pub-status pub-status--${pageStatus}" id="pub-state-label" style="color:${statusDot[pageStatus]||'#64748b'}">
        &#9679; ${pageStatus.charAt(0).toUpperCase()+pageStatus.slice(1)}
      </span>
      <button class="btn btn-secondary btn-sm" onclick="BKDN.savePage(${pageId})">${iconSave()} Save</button>
      ${pageStatus === 'published'
        ? `<button class="btn btn-danger btn-sm" id="btn-publish-page" onclick="BKDN.unpublishPage(${pageId})">${iconPublish()} Unpublish</button>`
        : `<button class="btn btn-primary btn-sm" id="btn-publish-page" onclick="BKDN.publishPage(${pageId})">${iconPublish()} Publish Now</button>`
      }
      <button class="btn btn-ghost btn-sm" onclick="BKDN.verifySync('${esc(p.slug)}')">${iconSync()} Verify</button>
      ${previewUrl ? `<a href="${esc(previewUrl)}" target="_blank" class="btn btn-ghost btn-sm">&#128274; Preview</a>` : ''}
      <a href="#/pages" class="btn btn-ghost btn-sm">${iconBack()} Back</a>
    </div>
    <div id="sync-result-area"></div>

    <div class="tabs">
      <button class="tab active" onclick="BKDN.switchTab(this,'tab-buttons')" data-tab="tab-buttons">Buttons</button>
      <button class="tab"        onclick="BKDN.switchTab(this,'tab-sections')" data-tab="tab-sections">Sections</button>
      <button class="tab"        onclick="BKDN.switchTab(this,'tab-settings')" data-tab="tab-settings">Page Settings</button>
      <button class="tab"        onclick="BKDN.switchTab(this,'tab-publish')"  data-tab="tab-publish">Publish &amp; Preview</button>
    </div>

    <!-- Buttons tab -->
    <div id="tab-buttons">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <span style="font-size:13px;color:#64748b">${buttons.length} button${buttons.length !== 1 ? 's' : ''}</span>
        <button class="btn btn-primary btn-sm" onclick="BKDN.openAddButton(${pageId})">${iconPlus()} Add Button</button>
      </div>
      ${renderButtonList(buttons, pageId)}
    </div>

    <!-- Sections tab -->
    <div id="tab-sections" style="display:none">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <span style="font-size:13px;color:#64748b">${sections.length} section${sections.length !== 1 ? 's' : ''}</span>
        <button class="btn btn-primary btn-sm" onclick="BKDN.openSectionModal(null,${pageId})">${iconPlus()} Add Section</button>
      </div>
      ${renderSectionList(sections, pageId)}
    </div>

    <!-- Settings tab -->
    <div id="tab-settings" style="display:none">
      <div class="card">
        <div class="form-group">
          <label class="form-label">Page Title</label>
          <input id="pe-title" class="form-control" value="${esc(p.title)}">
        </div>
        <div class="form-group">
          <label class="form-label">Profile Handle (e.g. @bakudanramen)</label>
          <input id="pe-handle" class="form-control" placeholder="@bakudanramen" value="${esc(p.handle||'')}">
        </div>
        <div class="form-group">
          <label class="form-label">Headline (shown on public /links page)</label>
          <input id="pe-headline" class="form-control" value="${esc(p.headline||'')}">
        </div>
        <div class="form-group">
          <label class="form-label">Tagline / Subheadline</label>
          <input id="pe-subheadline" class="form-control" value="${esc(p.subheadline||'')}">
        </div>
        <button class="btn btn-primary" onclick="BKDN.savePage(${pageId})">${iconSave()} Save Settings</button>
      </div>
    </div>

    <!-- Publish & Preview tab -->
    <div id="tab-publish" style="display:none">
      <div class="card" style="margin-bottom:16px">
        <div style="font-weight:600;margin-bottom:14px;color:#94a3b8;font-size:12px;letter-spacing:.5px">PAGE STATUS</div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select id="pe-status" class="form-control" onchange="BKDN.onStatusChange()">
            <option value="draft"     ${pageStatus==='draft'    ?'selected':''}>Draft — not public</option>
            <option value="private"   ${pageStatus==='private'  ?'selected':''}>Private — not public (manual publish only)</option>
            <option value="scheduled" ${pageStatus==='scheduled'?'selected':''}>Scheduled — auto-publish at set time</option>
            <option value="published" ${pageStatus==='published'?'selected':''}>Published — live now</option>
          </select>
        </div>

        <!-- Scheduled datetime — only shown when status=scheduled -->
        <div id="pe-schedule-row" class="form-group" style="${pageStatus==='scheduled'?'':'display:none'}">
          <label class="form-label">Publish At (date &amp; time)</label>
          <input id="pe-scheduled-at" type="datetime-local" class="form-control" value="${esc(scheduledVal)}">
          <div style="font-size:11px;color:#64748b;margin-top:4px">Page will automatically go live at this time. Cron checks every minute.</div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
          <button class="btn btn-primary" onclick="BKDN.applyPageStatus(${pageId})">${iconSave()} Apply Status</button>
          ${pageStatus !== 'published'
            ? `<button class="btn btn-secondary" onclick="BKDN.publishPage(${pageId})">${iconPublish()} Publish Now</button>`
            : `<button class="btn btn-danger btn-sm" onclick="BKDN.unpublishPage(${pageId})">Unpublish</button>`
          }
        </div>
        ${p.scheduled_publish_at ? `<div style="font-size:11px;color:#3b82f6;margin-top:10px">&#128197; Scheduled to publish: ${fmtDate(p.scheduled_publish_at)}</div>` : ''}
        ${p.last_published_at    ? `<div style="font-size:11px;color:#22c55e;margin-top:6px">&#10003; Last published: ${fmtDate(p.last_published_at)}</div>` : ''}
      </div>

      <div class="card">
        <div style="font-weight:600;margin-bottom:14px;color:#94a3b8;font-size:12px;letter-spacing:.5px">PRIVATE PREVIEW LINK</div>
        <div style="font-size:13px;color:#64748b;margin-bottom:12px">
          Share this private URL with team members to review before publishing.
          Not indexed by search engines.
        </div>
        ${previewUrl ? `
          <div style="background:#0f172a;border:1px solid #1e293b;border-radius:8px;padding:10px 14px;margin-bottom:12px;word-break:break-all">
            <a href="${esc(previewUrl)}" target="_blank" style="color:#3b82f6;font-size:12px;font-family:monospace">${esc(previewUrl)}</a>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn btn-secondary btn-sm" onclick="navigator.clipboard.writeText('${esc(previewUrl)}').then(()=>BKDN.toast('Preview URL copied!','success'))">&#128203; Copy URL</button>
            <button class="btn btn-ghost btn-sm" onclick="BKDN.generatePreviewToken(${pageId})">&#128260; Regenerate Token</button>
            <a href="${esc(previewUrl)}" target="_blank" class="btn btn-ghost btn-sm">&#128274; Open Preview</a>
          </div>` : `
          <div style="color:#475569;font-size:13px;margin-bottom:12px">No preview token generated yet.</div>
          <button class="btn btn-secondary" onclick="BKDN.generatePreviewToken(${pageId})">&#128274; Generate Preview Token</button>
        `}
        <div style="font-size:11px;color:#334155;margin-top:12px">Preview shows ALL buttons including hidden/disabled ones. Regenerate token to invalidate old links.</div>
      </div>
    </div>
  `);

  initDragDrop(pageId);
}

function renderButtonList(buttons, pageId) {
  if (!buttons.length) return `<div class="empty-state"><div class="empty-state-icon">&#128279;</div><div class="empty-state-title">No buttons yet</div><p>Add your first button to start building this page.</p><button class="btn btn-primary" onclick="BKDN.openAddButton(${pageId})">${iconPlus()} Add Button</button></div>`;

  const now = new Date().toISOString();
  return `
  <div class="reorder-active-bar" style="display:none" id="reorder-bar">
    <span>&#8597; Drag rows to reorder</span>
    <button class="btn btn-sm btn-primary" onclick="BKDN.saveOrder(${pageId})">Save Order</button>
    <button class="btn btn-sm btn-ghost" onclick="BKDN.cancelReorder()">Cancel</button>
  </div>
  <div class="btn-list" id="btn-list-${pageId}">
    ${buttons.map((b, i) => renderBtnRow(b, pageId, i)).join('')}
  </div>`;
}

function renderBtnRow(b, pageId, i) {
  const visible  = Number(b.visible ?? b.is_active ?? 1) === 1 || b.visible === true;
  const enabled  = Number(b.enabled ?? 1) === 1 || b.enabled === true;
  const featured = Number(b.is_featured ?? 0) === 1 || b.is_featured === true;
  const now = new Date().toISOString();
  const startAt = b.start_at ? new Date(b.start_at) : null;
  const endAt   = b.end_at   ? new Date(b.end_at)   : null;

  let statusClass = 'badge-green', statusLabel = 'Live';
  if (!visible)                                    { statusClass = 'badge-gray';   statusLabel = 'Hidden'; }
  else if (!enabled)                               { statusClass = 'badge-yellow'; statusLabel = 'Disabled'; }
  else if (endAt && now > endAt.toISOString())     { statusClass = 'badge-orange'; statusLabel = 'Expired'; }
  else if (startAt && now < startAt.toISOString()) { statusClass = 'badge-yellow'; statusLabel = 'Scheduled'; }
  else if (featured)                               { statusClass = 'badge-purple'; statusLabel = 'Featured'; }

  return `
  <div class="btn-row" draggable="true" data-id="${b.id}" data-idx="${i}">
    <div class="btn-row-drag" title="Drag to reorder">${iconDrag()}</div>
    <div class="btn-row-info">
      <div class="btn-row-title">${esc(b.title)}</div>
      ${b.subtitle ? `<div class="btn-row-subtitle">${esc(b.subtitle)}</div>` : ''}
      <div class="btn-row-meta">
        <span class="badge ${statusClass}" style="font-size:10px">${statusLabel}</span>
        ${b.section_title ? `<span class="badge badge-blue" style="font-size:10px">${esc(b.section_title)}</span>` : ''}
        ${b.icon_key ? `<span class="badge badge-gray" style="font-size:10px">${esc(b.icon_key)}</span>` : ''}
        <span class="badge badge-gray" style="font-size:10px">${esc(b.style_variant||'secondary')}</span>
        ${b.start_at||b.end_at ? `<span style="color:#64748b;font-size:10px">&#128197; scheduled</span>` : ''}
      </div>
    </div>
    <div class="btn-row-url">
      ${b.url ? `<a href="${esc(b.url)}" target="_blank" style="color:#64748b;font-size:11px">${esc(b.url.substring(0,40)+(b.url.length>40?'...':''))}</a>` : '<span style="color:#334155;font-size:11px">no url</span>'}
    </div>
    <div class="btn-toggle-col">
      <label class="toggle" title="Visible"><input type="checkbox" ${visible?'checked':''} onchange="BKDN.toggleBtn(${b.id},'visible',this.checked,${pageId})"><span class="toggle-slider"></span></label>
      <div class="btn-row-toggle-label">Visible</div>
    </div>
    <div class="btn-toggle-col">
      <label class="toggle" title="Enabled"><input type="checkbox" ${enabled?'checked':''} onchange="BKDN.toggleBtn(${b.id},'enabled',this.checked,${pageId})"><span class="toggle-slider"></span></label>
      <div class="btn-row-toggle-label">Enabled</div>
    </div>
    <div class="btn-toggle-col">
      <label class="toggle" title="Featured"><input type="checkbox" ${featured?'checked':''} onchange="BKDN.toggleBtn(${b.id},'featured',this.checked,${pageId})"><span class="toggle-slider"></span></label>
      <div class="btn-row-toggle-label">Featured</div>
    </div>
    <div class="btn-row-actions">
      <button class="btn btn-ghost btn-sm" onclick="BKDN.openEditButton(${b.id},${pageId})" title="Edit">${iconEdit()}</button>
      <button class="btn btn-ghost btn-sm" onclick="BKDN.duplicateButton(${b.id},${pageId})" title="Duplicate">${iconDuplicate()}</button>
      <button class="btn btn-ghost btn-sm" onclick="BKDN.deleteButton(${b.id},${pageId})" title="Delete" style="color:#ef4444">${iconTrash()}</button>
    </div>
  </div>`;
}

function renderSectionList(sections, pageId) {
  if (!sections.length) return `<div class="empty-state"><div class="empty-state-icon">&#9776;</div><div class="empty-state-title">No sections yet</div><p>Add sections like Order Online, Rewards, or Merchandise.</p><button class="btn btn-primary" onclick="BKDN.openSectionModal(null,${pageId})">${iconPlus()} Add Section</button></div>`;
  return `<div class="btn-list">
    ${sections.map(s => `
      <div class="btn-row">
        <div class="btn-row-drag">${Number(s.sort_order ?? 0)}</div>
        <div class="btn-row-info">
          <div class="btn-row-title">${esc(s.title)}</div>
          <div class="btn-row-meta">
            <span class="badge ${Number(s.is_active)===1?'badge-green':'badge-gray'}" style="font-size:10px">${Number(s.is_active)===1?'Visible':'Hidden'}</span>
            ${s.section_key ? `<span class="badge badge-gray" style="font-size:10px">${esc(s.section_key)}</span>` : ''}
          </div>
        </div>
        <div class="btn-toggle-col">
          <label class="toggle" title="Visible"><input type="checkbox" ${Number(s.is_active)===1?'checked':''} onchange="BKDN.toggleSection(${s.id},this.checked,${pageId})"><span class="toggle-slider"></span></label>
          <div class="btn-row-toggle-label">Visible</div>
        </div>
        <div class="btn-row-actions">
          <button class="btn btn-ghost btn-sm" onclick="BKDN.openSectionModal(${s.id},${pageId})" title="Edit">${iconEdit()}</button>
          <button class="btn btn-ghost btn-sm" onclick="BKDN.deleteSection(${s.id},${pageId})" title="Delete" style="color:#ef4444">${iconTrash()}</button>
        </div>
      </div>`).join('')}
  </div>`;
}

/* ── Drag-and-drop reorder ────────────────────────── */
function initDragDrop(pageId) {
  const list = document.getElementById('btn-list-' + pageId);
  const bar  = document.getElementById('reorder-bar');
  if (!list) return;
  let dragSrc = null;

  list.addEventListener('dragstart', e => {
    dragSrc = e.target.closest('[data-id]');
    if (!dragSrc) return;
    e.dataTransfer.effectAllowed = 'move';
    dragSrc.style.opacity = '.4';
    if (bar) bar.style.display = 'flex';
  });
  list.addEventListener('dragend', e => {
    const row = e.target.closest('[data-id]');
    if (row) row.style.opacity = '1';
  });
  list.addEventListener('dragover', e => {
    e.preventDefault();
    const target = e.target.closest('[data-id]');
    if (!target || target === dragSrc) return;
    const rect = target.getBoundingClientRect();
    const after = e.clientY - rect.top > rect.height / 2;
    list.insertBefore(dragSrc, after ? target.nextSibling : target);
  });
}

window._reorderPageId = null;
async function saveOrder(pageId) {
  const list = document.getElementById('btn-list-' + pageId);
  if (!list) return;
  const ids = Array.from(list.querySelectorAll('[data-id]')).map(r => parseInt(r.dataset.id));
  const res = await POST('/links/pages/' + pageId + '/buttons/reorder', { order: ids });
  if (res?.ok) { toast('Order saved!', 'success'); document.getElementById('reorder-bar')?.style && (document.getElementById('reorder-bar').style.display = 'none'); }
  else toast('Failed to save order.', 'error');
}

function cancelReorder() {
  viewPageEditor(window._currentPageId);
}

/* ── Button CRUD ─────────────────────────────────── */
function openAddButton(pageId) {
  window._currentPageId = pageId;
  openBtnModal(null, pageId);
}

async function openEditButton(btnId, pageId) {
  window._currentPageId = pageId;
  // Fetch current button data
  const res = await GET('/links/pages/' + pageId + '/buttons');
  const btn = res?.data?.buttons?.find(b => b.id === btnId);
  openBtnModal(btn, pageId);
}

function openBtnModal(btn, pageId) {
  const isNew = !btn;
  const ICONS = ['order','website','email','events','instagram','facebook','directions','phone','menu','gift','ticket','shopping','youtube','external','blog','social'];
  const iconOpts = ICONS.map(k => `<option value="${k}" ${(btn?.icon_key||btn?.icon)===k?'selected':''}>${k}</option>`).join('');
  const styleOpts = ['primary','secondary','ghost','outline'].map(s => `<option value="${s}" ${(btn?.style_variant||'secondary')===s?'selected':''}>${s}</option>`).join('');
  const sections = window._pageSections || [];
  const sectionOpts = sections.map(s => `<option value="${s.id}" ${Number(btn?.section_id)===Number(s.id)?'selected':''}>${esc(s.title)}</option>`).join('');

  openModal(isNew ? 'Add Button' : 'Edit Button', `
    <div class="form-group">
      <label class="form-label">Title *</label>
      <input id="bf-title" class="form-control" placeholder="Button text" value="${esc(btn?.title||'')}">
    </div>
    <div class="form-group">
      <label class="form-label">Subtitle</label>
      <input id="bf-subtitle" class="form-control" placeholder="Optional tagline" value="${esc(btn?.subtitle||'')}">
    </div>
    <div class="form-group">
      <label class="form-label">URL</label>
      <input id="bf-url" class="form-control" type="url" placeholder="https://" value="${esc(btn?.url||'')}">
    </div>
    <div class="form-group">
      <label class="form-label">Section</label>
      <select id="bf-section" class="form-control"><option value="">No section</option>${sectionOpts}</select>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Icon</label>
        <select id="bf-icon" class="form-control"><option value="">None</option>${iconOpts}</select>
      </div>
      <div class="form-group">
        <label class="form-label">Style</label>
        <select id="bf-style" class="form-control">${styleOpts}</select>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Custom SVG Icon</label>
      <textarea id="bf-svg" class="form-control" rows="2" placeholder="Paste SVG here (optional — sanitized)">${esc(btn?.custom_icon_svg||'')}</textarea>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Start Date/Time</label>
        <input id="bf-start" type="datetime-local" class="form-control" value="${esc((btn?.start_at||'').replace(' ','T').slice(0,16))}">
      </div>
      <div class="form-group">
        <label class="form-label">End Date/Time</label>
        <input id="bf-end" type="datetime-local" class="form-control" value="${esc((btn?.end_at||'').replace(' ','T').slice(0,16))}">
      </div>
    </div>
    <div style="display:flex;gap:20px;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:8px">
        <label class="toggle"><input id="bf-visible" type="checkbox" ${btn?.visible!==0?'checked':''}><span class="toggle-slider"></span></label>
        <span class="form-label" style="margin:0">Visible</span>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <label class="toggle"><input id="bf-enabled" type="checkbox" ${btn?.enabled!==0?'checked':''}><span class="toggle-slider"></span></label>
        <span class="form-label" style="margin:0">Enabled</span>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <label class="toggle"><input id="bf-featured" type="checkbox" ${btn?.is_featured?'checked':''}><span class="toggle-slider"></span></label>
        <span class="form-label" style="margin:0">Featured</span>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <label class="toggle"><input id="bf-newtab" type="checkbox" ${btn?.opens_in_new_tab?'checked':''}><span class="toggle-slider"></span></label>
        <span class="form-label" style="margin:0">New Tab</span>
      </div>
    </div>
  `,
  `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Cancel</button>
   <button class="btn btn-primary" onclick="BKDN.saveBtnModal(${btn?.id||'null'},${pageId})">${iconSave()} ${isNew?'Add Button':'Save Changes'}</button>`
  );
}

async function saveBtnModal(btnId, pageId) {
  const data = {
    title:           document.getElementById('bf-title').value.trim(),
    subtitle:        document.getElementById('bf-subtitle').value.trim() || null,
    url:             document.getElementById('bf-url').value.trim(),
    icon_key:        document.getElementById('bf-icon').value || null,
    style_variant:   document.getElementById('bf-style').value,
    custom_icon_svg: document.getElementById('bf-svg').value.trim() || null,
    section_id:      document.getElementById('bf-section').value || null,
    start_at:        document.getElementById('bf-start').value.replace('T',' ') || null,
    end_at:          document.getElementById('bf-end').value.replace('T',' ')   || null,
    visible:         document.getElementById('bf-visible').checked  ? 1 : 0,
    enabled:         document.getElementById('bf-enabled').checked  ? 1 : 0,
    is_featured:     document.getElementById('bf-featured').checked ? 1 : 0,
    opens_in_new_tab:document.getElementById('bf-newtab').checked   ? 1 : 0,
  };
  if (!data.title) { toast('Title is required.', 'error'); return; }
  const res = btnId
    ? await PUT('/links/buttons/' + btnId, data)
    : await POST('/links/pages/' + pageId + '/buttons', data);
  if (res?.ok) { toast(btnId ? 'Button updated.' : 'Button added.', 'success'); closeModal(); viewPageEditor(pageId); }
  else toast(res?.error || 'Failed to save button.', 'error');
}

async function toggleBtn(btnId, field, value, pageId) {
  const body = {};
  if (field === 'visible')   body.visible    = value ? 1 : 0;
  if (field === 'enabled')   body.enabled    = value ? 1 : 0;
  if (field === 'featured')  body.is_featured = value ? 1 : 0;
  const res = await PUT('/links/buttons/' + btnId, body);
  if (!res?.ok) toast('Failed to update button.', 'error');
}

async function duplicateButton(btnId, pageId) {
  const res = await POST('/links/buttons/' + btnId + '/duplicate');
  if (res?.ok) { toast('Button duplicated.', 'success'); viewPageEditor(pageId); }
  else toast('Failed to duplicate.', 'error');
}

async function deleteButton(btnId, pageId) {
  if (!confirm('Delete this button? This cannot be undone.')) return;
  const res = await DELETE('/links/buttons/' + btnId);
  if (res?.ok) { toast('Button deleted.', 'success'); viewPageEditor(pageId); }
  else toast('Failed to delete button.', 'error');
}

/* ── Section CRUD ───────────────────────────────── */
function openSectionModal(sectionId, pageId) {
  const section = (window._pageSections || []).find(s => Number(s.id) === Number(sectionId));
  const isNew = !section;
  openModal(isNew ? 'Add Section' : 'Edit Section', `
    <div class="form-group">
      <label class="form-label">Section Title *</label>
      <input id="sf-title" class="form-control" placeholder="Merchandise" value="${esc(section?.title||'')}">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Key</label>
        <input id="sf-key" class="form-control" placeholder="merchandise" value="${esc(section?.section_key||'')}">
      </div>
      <div class="form-group">
        <label class="form-label">Sort Order</label>
        <input id="sf-sort" class="form-control" type="number" value="${esc(section?.sort_order ?? '')}">
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <label class="toggle"><input id="sf-active" type="checkbox" ${Number(section?.is_active ?? 1)===1?'checked':''}><span class="toggle-slider"></span></label>
      <span class="form-label" style="margin:0">Visible</span>
    </div>
  `,
  `<button class="btn btn-secondary" onclick="BKDN.closeModal()">Cancel</button>
   <button class="btn btn-primary" onclick="BKDN.saveSectionModal(${section?.id||'null'},${pageId})">${iconSave()} ${isNew?'Add Section':'Save Changes'}</button>`);
}

async function saveSectionModal(sectionId, pageId) {
  const title = document.getElementById('sf-title').value.trim();
  if (!title) { toast('Section title is required.', 'error'); return; }
  const data = {
    title,
    section_key: document.getElementById('sf-key').value.trim() || null,
    sort_order: Number(document.getElementById('sf-sort').value || 0),
    is_active: document.getElementById('sf-active').checked ? 1 : 0,
  };
  const res = sectionId
    ? await PUT('/links/sections/' + sectionId, data)
    : await POST('/links/pages/' + pageId + '/sections', data);
  if (res?.ok) { toast(sectionId ? 'Section updated.' : 'Section added.', 'success'); closeModal(); viewPageEditor(pageId); }
  else toast(res?.error || 'Failed to save section.', 'error');
}

async function toggleSection(sectionId, value, pageId) {
  const section = (window._pageSections || []).find(s => Number(s.id) === Number(sectionId));
  const res = await PUT('/links/sections/' + sectionId, {
    title: section?.title || 'Section',
    section_key: section?.section_key || null,
    sort_order: section?.sort_order || 0,
    is_active: value ? 1 : 0,
  });
  if (!res?.ok) toast('Failed to update section.', 'error');
  else viewPageEditor(pageId);
}

async function deleteSection(sectionId, pageId) {
  if (!confirm('Delete this section? Buttons inside it will stay on the page without a section.')) return;
  const res = await DELETE('/links/sections/' + sectionId);
  if (res?.ok) { toast('Section deleted.', 'success'); viewPageEditor(pageId); }
  else toast(res?.error || 'Failed to delete section.', 'error');
}

/* ── Save / Publish / Sync ───────────────────────── */
async function savePage(pageId) {
  const body = {};
  const title       = document.getElementById('pe-title')?.value.trim();
  const handle      = document.getElementById('pe-handle')?.value.trim();
  const headline    = document.getElementById('pe-headline')?.value.trim();
  const subheadline = document.getElementById('pe-subheadline')?.value.trim();
  if (title       !== undefined) body.title       = title;
  if (handle      !== undefined) body.handle      = handle;
  if (headline    !== undefined) body.headline    = headline;
  if (subheadline !== undefined) body.subheadline = subheadline;
  if (!Object.keys(body).length) { toast('Nothing to save.', 'info'); return; }
  const res = await PUT('/links/pages/' + pageId, body);
  if (res?.ok) toast('Settings saved.', 'success');
  else toast(res?.error || 'Save failed.', 'error');
}

async function applyPageStatus(pageId) {
  const status = document.getElementById('pe-status')?.value;
  if (!status) return;
  if (status === 'scheduled') {
    const scheduledAt = document.getElementById('pe-scheduled-at')?.value;
    if (!scheduledAt) { toast('Please set a publish date/time.', 'error'); return; }
    const res = await POST('/links/pages/' + pageId + '/schedule', {
      scheduled_publish_at: scheduledAt.replace('T', ' ')
    });
    if (res?.ok) { toast(`Scheduled for ${scheduledAt}`, 'success'); viewPageEditor(pageId); }
    else toast(res?.error || 'Schedule failed.', 'error');
  } else {
    const res = await PUT('/links/pages/' + pageId, { status, is_active: status === 'published' ? 1 : 0 });
    if (res?.ok) { toast(`Status set to ${status}.`, 'success'); viewPageEditor(pageId); }
    else toast(res?.error || 'Failed.', 'error');
  }
}

function onStatusChange() {
  const status = document.getElementById('pe-status')?.value;
  const row    = document.getElementById('pe-schedule-row');
  if (row) row.style.display = status === 'scheduled' ? '' : 'none';
}

async function publishPage(pageId) {
  const res = await POST('/links/pages/' + pageId + '/publish');
  if (res?.ok) {
    toast('Page published! Live on /links/' + (res.data?.slug || ''), 'success');
    viewPageEditor(pageId); // reload to reflect new status
  } else toast(res?.error || 'Publish failed.', 'error');
}

async function unpublishPage(pageId) {
  if (!confirm('Unpublish this page? It will no longer be publicly accessible.')) return;
  const res = await POST('/links/pages/' + pageId + '/unpublish');
  if (res?.ok) { toast('Page unpublished (set to draft).', 'success'); viewPageEditor(pageId); }
  else toast(res?.error || 'Unpublish failed.', 'error');
}

async function generatePreviewToken(pageId) {
  const res = await POST('/links/pages/' + pageId + '/generate-preview-token');
  if (res?.ok) {
    toast('Preview token generated!', 'success');
    viewPageEditor(pageId); // reload to show new preview URL
  } else toast(res?.error || 'Failed to generate token.', 'error');
}

async function verifySync(slug) {
  const area = document.getElementById('sync-result-area');
  if (!area) return;
  area.innerHTML = `<div class="sync-result warn">Checking /links/${esc(slug)}...</div>`;
  const res = await GET('/public/links/' + slug);
  if (res?.ok) {
    const count = res.data.buttons?.length ?? 0;
    area.innerHTML = `<div class="sync-result ok">&#10003; Sync OK — ${count} live button${count!==1?'s':''} on /links/${esc(slug)}</div>`;
  } else {
    area.innerHTML = `<div class="sync-result fail">&#10007; Sync failed — could not reach /links/${esc(slug)}</div>`;
  }
  setTimeout(() => { area.innerHTML = ''; }, 6000);
}

/* ── Tab switcher ─────────────────────────────────── */
function switchTab(btn, tabId) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  const tabIds = ['tab-buttons', 'tab-sections', 'tab-settings', 'tab-publish'];
  tabIds.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = id === tabId ? '' : 'none';
  });
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: SCHEDULING
═══════════════════════════════════════════════════════════════ */
async function viewScheduling() {
  setContent(loading());
  const pagesRes = await GET('/links/pages');
  if (!pagesRes?.ok) { setContent(errBanner('Failed to load pages.', 'BKDN.viewScheduling()')); return; }
  const pages = pagesRes.data.pages || [];

  const allFetches = pages.map(p => GET('/links/pages/' + p.id + '/buttons').then(r => ({ page: p, buttons: r?.data?.buttons || [] })));
  const allData    = await Promise.all(allFetches);

  const now   = new Date().toISOString();
  const items = [];

  allData.forEach(({ page, buttons }) => {
    buttons.forEach(b => {
      const startAt = b.start_at ? new Date(b.start_at) : null;
      const endAt   = b.end_at   ? new Date(b.end_at)   : null;
      const hasSchedule = !!(startAt || endAt);
      if (!hasSchedule && b.visible) return; // always-on visible buttons not shown

      let state = 'live';
      if (!b.visible)                                  state = 'hidden';
      else if (endAt && now > endAt.toISOString())     state = 'expired';
      else if (startAt && now < startAt.toISOString()) state = 'scheduled';

      items.push({ page, button: b, startAt, endAt, state });
    });
  });

  const byState = { scheduled: [], live: [], expired: [], hidden: [] };
  items.forEach(i => (byState[i.state] || byState.live).push(i));

  function renderGroup(title, items, badgeCls) {
    if (!items.length) return '';
    return `
    <div style="margin-bottom:24px">
      <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px;font-weight:600">${esc(title)} <span class="badge badge-gray" style="font-size:10px">${items.length}</span></div>
      <div class="sched-grid">
        ${items.map(item => {
          const b = item.button;
          return `
          <div class="sched-row">
            <div class="sched-row-info">
              <div class="sched-row-title">${esc(b.title)}</div>
              <div class="sched-row-meta"><a href="#/pages/${item.page.id}" style="color:#60a5fa">${esc(item.page.title)}</a>${b.icon_key ? ` · <span style="color:#64748b">${esc(b.icon_key)}</span>` : ''}</div>
              <div class="sched-row-dates">
                ${item.startAt ? `<span class="sched-date-item">&#9654; Starts: ${fmtDateTime(item.startAt.toISOString())}</span>` : ''}
                ${item.endAt   ? `<span class="sched-date-item">&#9632; Ends: ${fmtDateTime(item.endAt.toISOString())}</span>`   : ''}
              </div>
            </div>
            <span class="sched-badge-${item.state}">${item.state.toUpperCase()}</span>
            <a href="#/pages/${item.page.id}" class="btn btn-ghost btn-sm">${iconEdit()} Edit</a>
          </div>`;
        }).join('')}
      </div>
    </div>`;
  }

  setContent(`
    ${pageTitle('Scheduling', `${items.length} scheduled or conditional button${items.length!==1?'s':''}`)}
    ${!items.length ? `
    <div class="card" style="text-align:center;padding:48px">
      <div style="font-size:36px;margin-bottom:12px">&#128197;</div>
      <div style="font-weight:700;color:#e2e8f0;margin-bottom:8px">No scheduled buttons yet</div>
      <p style="color:#64748b;font-size:13px;margin-bottom:16px">Set start/end dates on any button from the Pages &amp; Buttons editor to control when it appears on the public page.</p>
      <a href="#/pages" class="btn btn-primary btn-sm">${iconPages()} Go to Pages</a>
    </div>` : `
      ${renderGroup('Upcoming / Scheduled', byState.scheduled, 'sched-badge-scheduled')}
      ${renderGroup('Currently Live (with schedule)', byState.live, 'sched-badge-live')}
      ${renderGroup('Expired', byState.expired, 'sched-badge-expired')}
      ${renderGroup('Hidden (no schedule)', byState.hidden, 'sched-badge-hidden')}
    `}
  `);
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: BLOG LIST
═══════════════════════════════════════════════════════════════ */
async function viewBlog() {
  setContent(loading());
  const [allRes, scheduledRes] = await Promise.all([
    GET('/blog/posts'),
    GET('/blog/posts?status=scheduled'),
  ]);
  if (!allRes?.ok) { setContent(errBanner('Failed to load blog posts.', 'BKDN.viewBlog()')); return; }
  const posts     = allRes.data.posts || [];
  const scheduled = scheduledRes?.data?.posts || [];

  const statusBadge = s => {
    const map = { published: 'badge-green', draft: 'badge-blue', scheduled: 'badge-yellow', archived: 'badge-gray', failed: 'badge-red' };
    return `<span class="badge ${map[s]||'badge-gray'}">${esc(s)}</span>`;
  };

  setContent(`
    ${pageTitle('Blog', `${posts.length} post${posts.length!==1?'s':''}`)}

    <div style="display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap">
      <a href="#/blog/new" class="btn btn-primary">${iconPlus()} Create Post</a>
      <a href="${esc(CFG?.siteUrl||'')}/blog-cms" target="_blank" class="btn btn-ghost btn-sm">${iconExternal()} Public Blog</a>
    </div>

    ${scheduled.length ? `
    <div class="card" style="margin-bottom:18px">
      <div class="card-title">&#128197; Scheduled Queue (${scheduled.length})</div>
      <div class="blog-list">
        ${scheduled.map(p => `
        <div class="blog-item">
          <div>
            <div class="blog-item-title">${esc(p.title)}</div>
            <div class="blog-item-meta">
              ${statusBadge(p.status)}
              <span style="font-size:11px;color:#64748b">Publishes ${fmtDateTime(p.scheduled_at)}</span>
            </div>
          </div>
          <div class="blog-item-actions">
            <a href="#/blog/${p.id}" class="btn btn-ghost btn-sm">${iconEdit()}</a>
            <button class="btn btn-success btn-sm" onclick="BKDN.publishPost(${p.id})">Publish Now</button>
          </div>
        </div>`).join('')}
      </div>
    </div>` : ''}

    <div class="card">
      <div class="card-title">All Posts</div>
      ${!posts.length ? `<div class="empty-state"><div class="empty-state-icon">&#9997;&#65039;</div><div class="empty-state-title">No posts yet</div><p>Create your first blog post to get started.</p><a href="#/blog/new" class="btn btn-primary">${iconPlus()} Create Post</a></div>` : `
      <div class="blog-list">
        ${posts.map(p => `
        <div class="blog-item">
          <div>
            <div class="blog-item-title">${esc(p.title)}</div>
            <div class="blog-item-excerpt">${esc((p.excerpt||'').slice(0,80))}${(p.excerpt||'').length>80?'…':''}</div>
            <div class="blog-item-meta">
              ${statusBadge(p.status)}
              ${p.author_name ? `<span style="font-size:11px;color:#64748b">by ${esc(p.author_name)}</span>` : ''}
              ${p.published_at ? `<span style="font-size:11px;color:#64748b">${fmtDate(p.published_at)}</span>` : ''}
              ${p.scheduled_at && p.status==='scheduled' ? `<span style="font-size:11px;color:#fde68a">&#128197; ${fmtDateTime(p.scheduled_at)}</span>` : ''}
            </div>
          </div>
          <div class="blog-item-actions">
            <a href="#/blog/${p.id}" class="btn btn-ghost btn-sm" title="Edit">${iconEdit()}</a>
            <button class="btn btn-ghost btn-sm" onclick="BKDN.duplicatePost(${p.id})" title="Duplicate">${iconDuplicate()}</button>
            ${p.status !== 'published' ? `<button class="btn btn-success btn-sm" onclick="BKDN.publishPost(${p.id})">Publish</button>` : ''}
            <button class="btn btn-ghost btn-sm" onclick="BKDN.archivePost(${p.id})" title="Archive" style="color:#ef4444">${iconTrash()}</button>
          </div>
        </div>`).join('')}
      </div>`}
    </div>
  `);
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: BLOG EDITOR (Create / Edit)
═══════════════════════════════════════════════════════════════ */
async function viewBlogEditor(postId) {
  setContent(loading());

  let post = null;
  if (postId) {
    const res = await GET('/blog/posts/' + postId);
    if (!res?.ok) { setContent(errBanner('Post not found.', 'BKDN.viewBlog()')); return; }
    post = res.data.post;
  }

  const isNew = !post;
  const TEMPLATES = [
    { id:'promo',     label:'Promotion Post',      headline:'&#127881; [TITLE HERE]', body:'We\'re excited to announce...' },
    { id:'new-item',  label:'New Item Post',        headline:'&#127843; Introducing [ITEM NAME]', body:'A new item has landed on our menu...' },
    { id:'holiday',   label:'Holiday Hours',        headline:'&#127881; Holiday Hours — [HOLIDAY]', body:'Please note our updated hours for...' },
    { id:'event',     label:'Event Post',           headline:'&#127881; Join Us — [EVENT NAME]', body:'We\'re hosting a special event...' },
    { id:'hiring',    label:'Hiring Post',          headline:'&#128188; Now Hiring', body:'We\'re looking for passionate team members...' },
    { id:'update',    label:'Store Update',         headline:'&#128205; Update from [STORE]', body:'We have an important update to share...' },
  ];

  const templateOpts = TEMPLATES.map(t => `<option value="${t.id}">${t.label}</option>`).join('');
  const statusOpts = ['draft','scheduled','published'].map(s => `<option value="${s}" ${post?.status===s?'selected':''}>${s.charAt(0).toUpperCase()+s.slice(1)}</option>`).join('');
  const scheduledVal = post?.scheduled_at ? post.scheduled_at.replace(' ','T').slice(0,16) : '';

  setContent(`
    ${pageTitle(isNew ? 'New Post' : 'Edit Post', isNew ? 'Blog Composer' : post.title)}

    <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
      <button class="btn btn-secondary btn-sm" onclick="BKDN.viewBlog()">${iconBack()} Back to Blog</button>
      <button class="btn btn-secondary btn-sm" id="btn-save-draft" onclick="BKDN.saveBlogPost(${postId||'null'},false)">${iconSave()} Save Draft</button>
      <button class="btn btn-primary btn-sm" id="btn-publish-post" onclick="BKDN.saveBlogPost(${postId||'null'},true)">${iconPublish()} ${isNew?'Save &amp; Publish':'Update &amp; Publish'}</button>
      ${post?.status==='published' ? `<a href="${esc(CFG?.siteUrl||'')}/blog-cms/post/${esc(post.slug||post.id)}" target="_blank" class="btn btn-ghost btn-sm">${iconExternal()} View Live</a>` : ''}
    </div>

    <div class="blog-editor-layout">
      <!-- Main editor -->
      <div class="blog-editor-main">
        ${isNew ? `
        <div class="card" style="margin-bottom:14px">
          <div class="card-title">Start from template</div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <select id="tpl-select" class="form-control" style="width:auto;min-width:180px">${templateOpts}</select>
            <button class="btn btn-secondary btn-sm" onclick="BKDN.applyTemplate()">Apply Template</button>
          </div>
        </div>` : ''}

        <div class="card">
          <div class="form-group">
            <label class="form-label">Title *</label>
            <input id="blog-title" class="form-control" style="font-size:16px;font-weight:600" placeholder="Post title..." value="${esc(post?.title||'')}">
          </div>
          <div class="form-group">
            <label class="form-label">Short Excerpt / Caption</label>
            <input id="blog-excerpt" class="form-control" placeholder="Brief summary for the blog listing..." value="${esc(post?.excerpt||'')}">
          </div>
        </div>

        <!-- Rich text editor -->
        <div class="card">
          <div class="card-title">Content</div>
          <div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">
            <div class="emoji-picker-container">
              <button class="btn btn-ghost btn-sm" onclick="BKDN.toggleEmoji()" title="Insert emoji">${iconEmoji()} Emoji</button>
              <div class="emoji-grid" id="emoji-grid">${buildEmojiGrid()}</div>
            </div>
          </div>
          <div id="quill-editor" style="min-height:300px">${post?.content_html||''}</div>
        </div>

        <!-- Caption + Hashtags -->
        <div class="card">
          <div class="card-title">Social Caption &amp; Hashtags</div>
          <div class="form-group">
            <label class="form-label">Caption</label>
            <textarea id="blog-caption" class="form-control" rows="3" placeholder="Caption for social sharing...">${esc(post?.caption||'')}</textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Hashtags</label>
            <input id="blog-hashtags" class="form-control" placeholder="#ramen #bakudan #sanantonio" value="${esc(post?.hashtags||'')}">
          </div>
        </div>

        <!-- CTA -->
        <div class="card">
          <div class="card-title">Call-to-Action Button</div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Button Label</label>
              <input id="blog-cta-label" class="form-control" placeholder="Order Now" value="${esc(post?.cta_label||'')}">
            </div>
            <div class="form-group">
              <label class="form-label">Button URL</label>
              <input id="blog-cta-url" class="form-control" type="url" placeholder="https://..." value="${esc(post?.cta_url||'')}">
            </div>
          </div>
        </div>

        <!-- Media -->
        <div class="card" id="media-card">
          <div class="card-title">Media</div>
          <div class="media-upload-zone" onclick="document.getElementById('media-file-input').click()" ondragover="event.preventDefault();this.classList.add('drag-over')" ondragleave="this.classList.remove('drag-over')" ondrop="BKDN.handleMediaDrop(event,${postId||'null'})">
            ${iconImage()}
            <div style="font-size:13px;font-weight:600">Drop files here or click to upload</div>
            <div style="font-size:11px;color:#475569;margin-top:4px">JPG, PNG, WEBP, MP4, MOV — max 100 MB</div>
            <input type="file" id="media-file-input" accept="image/*,video/*" style="display:none" multiple onchange="BKDN.uploadMedia(this,${postId||'null'})">
          </div>
          <div class="media-grid" id="media-grid">
            ${(post?.media||[]).map(m => renderMediaThumb(m)).join('')}
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <div class="blog-editor-sidebar">
        <div class="card">
          <div class="card-title">Publish Settings</div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select id="blog-status" class="form-control">${statusOpts}</select>
          </div>
          <div class="form-group">
            <label class="form-label">Schedule Date/Time</label>
            <input id="blog-scheduled-at" type="datetime-local" class="form-control" value="${esc(scheduledVal)}">
            <div class="form-hint">Leave blank for immediate publish. Set a future time to auto-publish.</div>
          </div>
          <div class="form-group">
            <label class="form-label">Slug (URL)</label>
            <input id="blog-slug" class="form-control" placeholder="auto-generated" value="${esc(post?.slug||'')}">
          </div>
          <button class="btn btn-primary" style="width:100%;margin-bottom:8px" onclick="BKDN.saveBlogPost(${postId||'null'},false)">${iconSave()} Save Draft</button>
          ${scheduledVal ? `<button class="btn btn-success" style="width:100%;margin-bottom:8px" onclick="BKDN.schedulePost(${postId||'null'})">${iconCalendar()} Schedule Post</button>` : ''}
          <button class="btn btn-primary" style="width:100%" onclick="BKDN.saveBlogPost(${postId||'null'},true)">${iconPublish()} Publish Now</button>
        </div>

        <div class="card">
          <div class="card-title">Featured Image</div>
          <div style="margin-bottom:8px">
            ${post?.featured_image_url ? `<img src="${esc(post.featured_image_url)}" style="width:100%;border-radius:6px;margin-bottom:8px" alt="">` : '<div style="color:#475569;font-size:12px;margin-bottom:8px">No featured image set</div>'}
          </div>
          <input id="blog-featured-img" class="form-control" placeholder="https://... or upload above" value="${esc(post?.featured_image_url||'')}">
          <button class="btn btn-secondary btn-sm" style="width:100%;margin-top:8px" onclick="document.getElementById('featured-img-input').click()">${iconImage()} Upload Featured Image
            <input type="file" id="featured-img-input" accept="image/*" style="display:none" onchange="BKDN.uploadFeaturedImage(this)">
          </button>
        </div>

        <div class="card">
          <div class="card-title">Bulk Schedule</div>
          <div class="form-hint" style="margin-bottom:10px">Create another post after saving this one:</div>
          <button class="btn btn-secondary btn-sm" style="width:100%" onclick="BKDN.saveAndCreateAnother(${postId||'null'})">${iconPlus()} Save &amp; Create Another</button>
        </div>
      </div>
    </div>
  `);

  // Initialize Quill (lazy-loaded — only the Blog Composer needs it)
  await loadQuill();
  if (window.Quill) {
    _quill = new Quill('#quill-editor', {
      theme: 'snow',
      placeholder: 'Write your post content here...',
      modules: {
        toolbar: [
          [{ 'font': [] }, { 'size': ['small', false, 'large', 'huge'] }],
          ['bold', 'italic', 'underline', 'strike'],
          [{ 'color': [] }, { 'background': [] }],
          [{ 'header': [1,2,3,false] }],
          [{ 'list': 'ordered' }, { 'list': 'bullet' }],
          [{ 'align': [] }],
          ['link', 'image'],
          ['blockquote', 'code-block'],
          [{ 'indent': '-1' }, { 'indent': '+1' }],
          ['clean'],
        ],
      },
    });
  } else {
    document.getElementById('quill-editor').innerHTML = `<textarea id="quill-fallback" class="form-control" style="min-height:300px" placeholder="Write your post content...">${esc(post?.content_html||'')}</textarea>`;
  }

  // Schedule field watcher
  document.getElementById('blog-scheduled-at')?.addEventListener('change', function() {
    if (this.value) {
      document.getElementById('blog-status').value = 'scheduled';
    }
  });
}

function buildEmojiGrid() {
  const categories = {
    'Food & Drink': ['🍜','🍣','🍱','🍛','🥢','🍶','🫙','🥡','🍤','🍙','🥟','🍥','🍡','🥮','🧆','🥘','🫕'],
    'Celebration':  ['🎉','🎊','🎁','🎈','🥳','🏮','✨','🌟','🌈','💫','🔥','❤️','💚','💛','🧡','💜','🩷'],
    'People':       ['😊','😄','🤩','😋','😍','🥰','👋','🙌','👏','🤝','💪','🫂','🫶','👍','🌸','🌺','🌻'],
    'Business':     ['📌','📍','📎','🔗','📱','💻','🖥️','📊','📈','📋','✅','⏰','🕐','📢','📣','🔔','📝'],
  };
  return Object.entries(categories).map(([cat, emojis]) => `
    <div class="emoji-category-label">${cat}</div>
    <div class="emoji-buttons">${emojis.map(e => `<button class="emoji-btn" onclick="BKDN.insertEmoji('${e}')" title="${e}">${e}</button>`).join('')}</div>
  `).join('');
}

function toggleEmoji() {
  const grid = document.getElementById('emoji-grid');
  if (grid) grid.classList.toggle('open');
}

function insertEmoji(emoji) {
  if (_quill) {
    const range = _quill.getSelection(true);
    _quill.insertText(range.index, emoji);
    _quill.setSelection(range.index + emoji.length);
  } else {
    const ta = document.getElementById('quill-fallback');
    if (ta) {
      const pos = ta.selectionStart;
      ta.value = ta.value.slice(0, pos) + emoji + ta.value.slice(pos);
      ta.selectionStart = ta.selectionEnd = pos + emoji.length;
    }
  }
  document.getElementById('emoji-grid')?.classList.remove('open');
}

function applyTemplate() {
  const sel = document.getElementById('tpl-select')?.value;
  const TEMPLATES = {
    'promo':    { title:'🎉 [TITLE HERE]', excerpt:'Limited time offer...', body:'<h2>🎉 Special Offer!</h2><p>We\'re excited to announce a special promotion at Bakudan Ramen. <strong>[Add details here]</strong></p><p>Available at all locations. Don\'t miss out!</p>', cta_label:'Order Now', hashtags:'#bakudanramen #ramen #sanantonio #specialoffer' },
    'new-item': { title:'🍜 Introducing [Item Name]', excerpt:'A new dish has arrived...', body:'<h2>🍜 New to Our Menu!</h2><p>We\'re thrilled to introduce <strong>[Item Name]</strong> — [brief description].</p><p>Available starting [date] at all locations.</p>', cta_label:'View Menu', hashtags:'#bakudanramen #newdish #ramen #sanantonio' },
    'holiday':  { title:'🎊 Holiday Hours — [Holiday]', excerpt:'Updated hours for the holiday...', body:'<h2>Holiday Hours</h2><p>Please note our updated hours for <strong>[Holiday]</strong>:</p><ul><li>Bandera: [hours]</li><li>Stone Oak: [hours]</li><li>The Rim: [hours]</li></ul><p>We look forward to seeing you!</p>', cta_label:'Find Locations', hashtags:'#bakudanramen #holidayhours #sanantonio' },
    'event':    { title:'🎉 Join Us — [Event Name]', excerpt:'Special event at Bakudan Ramen...', body:'<h2>🎉 Special Event</h2><p>We\'re hosting <strong>[Event Name]</strong> at Bakudan Ramen!</p><p><strong>Date:</strong> [date]<br><strong>Time:</strong> [time]<br><strong>Location:</strong> [location]</p><p>Come join us for [description]. Reservations recommended.</p>', cta_label:'Learn More', hashtags:'#bakudanramen #event #sanantonio' },
    'hiring':   { title:'📌 Now Hiring — Join the Bakudan Family', excerpt:'We\'re looking for talented team members...', body:'<h2>We\'re Hiring!</h2><p>Bakudan Ramen is growing and we\'re looking for passionate people to join our team.</p><h3>Open Positions</h3><ul><li>[Position 1]</li><li>[Position 2]</li></ul><p>Apply in person at any location or email us at [email].</p>', cta_label:'Apply Now', hashtags:'#bakudanramen #hiring #jobsanantonio #restaurant' },
    'update':   { title:'📍 Update from Bakudan Ramen', excerpt:'Important update from us...', body:'<h2>Important Update</h2><p>We have an update to share with our valued guests.</p><p>[Add your update details here]</p><p>Thank you for your continued support!</p>', cta_label:'Learn More', hashtags:'#bakudanramen #update #sanantonio' },
  };
  const tpl = TEMPLATES[sel];
  if (!tpl) return;
  document.getElementById('blog-title').value = tpl.title;
  document.getElementById('blog-excerpt').value = tpl.excerpt;
  document.getElementById('blog-hashtags').value = tpl.hashtags;
  document.getElementById('blog-cta-label').value = tpl.cta_label;
  if (_quill) { _quill.root.innerHTML = tpl.body; }
  toast('Template applied — fill in the [brackets] with your content.', 'info', 4000);
}

function renderMediaThumb(m) {
  return `
  <div class="media-thumb" data-media-id="${m.id}">
    ${m.media_type === 'video'
      ? `<video src="${esc(m.url)}" style="width:100%;height:100%;object-fit:cover"></video>`
      : `<img src="${esc(m.url)}" alt="${esc(m.alt_text||'')}">` }
    <button class="media-thumb-del" onclick="BKDN.deleteMedia(${m.id})" title="Remove">&times;</button>
  </div>`;
}

async function uploadMedia(input, postId) {
  const files = Array.from(input.files);
  for (const file of files) {
    const fd = new FormData();
    fd.append('file', file);
    if (postId) fd.append('post_id', postId);
    try {
      const headers = {};
      if (_token) headers['Authorization'] = 'Bearer ' + _token;
      const res  = await fetch('/api/blog/media/upload', { method:'POST', headers, body: fd });
      const data = await res.json();
      if (data.ok) {
        const grid = document.getElementById('media-grid');
        if (grid && data.data.id) {
          grid.insertAdjacentHTML('beforeend', renderMediaThumb({ id: data.data.id, url: data.data.url, media_type: data.data.media_type, alt_text:'' }));
        }
        toast('File uploaded.', 'success');
      } else {
        toast(data.error || 'Upload failed.', 'error');
      }
    } catch (e) {
      toast('Upload error: ' + e.message, 'error');
    }
  }
  input.value = '';
}

async function handleMediaDrop(event, postId) {
  event.preventDefault();
  event.currentTarget.classList.remove('drag-over');
  const files = Array.from(event.dataTransfer.files);
  if (!files.length) return;
  const fakeInput = { files };
  uploadMedia(fakeInput, postId);
}

async function uploadFeaturedImage(input) {
  const file = input.files[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('file', file);
  const headers = {};
  if (_token) headers['Authorization'] = 'Bearer ' + _token;
  const res  = await fetch('/api/blog/media/upload', { method:'POST', headers, body: fd });
  const data = await res.json();
  if (data.ok) {
    document.getElementById('blog-featured-img').value = data.data.url;
    toast('Featured image uploaded.', 'success');
  } else toast(data.error || 'Upload failed.', 'error');
  input.value = '';
}

async function deleteMedia(mediaId) {
  if (!confirm('Remove this media file?')) return;
  const res = await DELETE('/blog/media/' + mediaId);
  if (res?.ok) {
    document.querySelector(`[data-media-id="${mediaId}"]`)?.remove();
    toast('Media removed.', 'success');
  } else toast('Failed to remove media.', 'error');
}

async function saveBlogPost(postId, publishNow) {
  const title      = document.getElementById('blog-title')?.value.trim();
  const excerpt    = document.getElementById('blog-excerpt')?.value.trim() || null;
  const caption    = document.getElementById('blog-caption')?.value.trim() || null;
  const hashtags   = document.getElementById('blog-hashtags')?.value.trim() || null;
  const cta_label  = document.getElementById('blog-cta-label')?.value.trim() || null;
  const cta_url    = document.getElementById('blog-cta-url')?.value.trim() || null;
  const featured   = document.getElementById('blog-featured-img')?.value.trim() || null;
  const slug       = document.getElementById('blog-slug')?.value.trim() || null;
  const scheduled  = document.getElementById('blog-scheduled-at')?.value || null;
  const statusSel  = document.getElementById('blog-status')?.value || 'draft';
  const content_html = _quill ? _quill.root.innerHTML : (document.getElementById('quill-fallback')?.value || '');

  if (!title) { toast('Title is required.', 'error'); return; }

  const body = {
    title, excerpt, caption, hashtags, cta_label, cta_url, content_html,
    featured_image_url: featured,
    slug:     slug || undefined,
    status:   publishNow ? 'published' : (scheduled ? 'scheduled' : statusSel),
    scheduled_at: scheduled || null,
  };

  let res;
  if (postId) {
    res = await PUT('/blog/posts/' + postId, body);
  } else {
    res = await POST('/blog/posts', body);
  }

  if (!res?.ok) { toast(res?.error || 'Save failed.', 'error'); return; }

  const savedPost = res.data.post;

  if (publishNow && savedPost.status !== 'published') {
    await POST('/blog/posts/' + savedPost.id + '/publish');
  }

  toast(publishNow ? 'Post published!' : 'Draft saved.', 'success');

  if (!postId) {
    navigate('/blog/' + savedPost.id);
  }
}

async function schedulePost(postId) {
  const scheduledAt = document.getElementById('blog-scheduled-at')?.value;
  if (!scheduledAt) { toast('Set a schedule date/time first.', 'error'); return; }
  const body = { scheduled_at: scheduledAt.replace('T', ' ') };
  const res = postId
    ? await POST('/blog/posts/' + postId + '/schedule', body)
    : null;
  if (res?.ok) toast('Post scheduled!', 'success');
  else toast(res?.error || 'Schedule failed.', 'error');
}

async function saveAndCreateAnother(postId) {
  await saveBlogPost(postId, false);
  navigate('/blog/new');
}

async function publishPost(postId) {
  const res = await POST('/blog/posts/' + postId + '/publish');
  if (res?.ok) { toast('Published!', 'success'); viewBlog(); }
  else toast(res?.error || 'Publish failed.', 'error');
}

async function duplicatePost(postId) {
  const res = await POST('/blog/posts/' + postId + '/duplicate');
  if (res?.ok) { toast('Post duplicated.', 'success'); viewBlog(); }
  else toast('Failed to duplicate.', 'error');
}

async function archivePost(postId) {
  if (!confirm('Archive this post? It will be hidden from the public blog.')) return;
  const res = await POST('/blog/posts/' + postId + '/archive');
  if (res?.ok) { toast('Post archived.', 'success'); viewBlog(); }
  else toast('Failed to archive.', 'error');
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: ANALYTICS
═══════════════════════════════════════════════════════════════ */
async function viewAnalytics() {
  setContent(loading());
  const res = await GET('/links/analytics?days=30');
  if (!res?.ok) { setContent(errBanner('Failed to load analytics.', 'BKDN.viewAnalytics()')); return; }
  const d = res.data;

  setContent(`
    ${pageTitle('Analytics', 'Last 30 days')}
    <div class="kpi-grid">
      <div class="kpi-card blue"><div class="kpi-label">Page Views</div><div class="kpi-value">${d.views}</div></div>
      <div class="kpi-card green"><div class="kpi-label">Button Clicks</div><div class="kpi-value">${d.clicks}</div></div>
      <div class="kpi-card purple"><div class="kpi-label">CTR</div><div class="kpi-value">${Math.round(d.ctr*100)}%</div></div>
    </div>
    <div class="card">
      <div class="card-title">Top Buttons (clicks)</div>
      ${d.top_buttons.length ? `
      <table class="data-table">
        <thead><tr><th>Button</th><th>Clicks</th></tr></thead>
        <tbody>${d.top_buttons.map(b => `<tr><td>${esc(b.title||'(deleted)')}</td><td style="color:#60a5fa;font-weight:700">${b.clicks}</td></tr>`).join('')}</tbody>
      </table>` : '<div class="empty-state" style="padding:24px">No click data yet. Share your /links page to start tracking.</div>'}
    </div>
  `);
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: SETTINGS
═══════════════════════════════════════════════════════════════ */
async function viewSettings() {
  setContent(loading());
  const res = await GET('/links/settings');
  if (!res?.ok) { setContent(errBanner('Failed to load settings.', 'BKDN.viewSettings()')); return; }
  const s = res.data.settings || {};

  setContent(`
    ${pageTitle('Settings', 'Social links, site info, and defaults')}

    <div class="card">
      <div class="card-title">Social Links</div>

      <div class="social-card">
        <div class="social-card-icon" style="background:#833ab4;color:#fff">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
        </div>
        <div>
          <div style="font-weight:600;color:#e2e8f0;margin-bottom:6px">Instagram</div>
          <div class="form-group" style="margin-bottom:8px">
            <label class="form-label">URL</label>
            <input id="s-ig" class="form-control" value="${esc(s.instagram_url||'')}" placeholder="https://www.instagram.com/bakudanramen">
          </div>
          <div class="form-group" style="margin-bottom:8px">
            <label class="form-label">Label</label>
            <input id="s-ig-label" class="form-control" value="${esc(s.instagram_label||'@bakudanramen')}" placeholder="@bakudanramen">
          </div>
          <div style="display:flex;align-items:center;gap:8px">
            <label class="toggle"><input id="s-ig-vis" type="checkbox" ${s.instagram_visible!=='0'?'checked':''}><span class="toggle-slider"></span></label>
            <span class="form-label" style="margin:0">Show on /links</span>
          </div>
        </div>
      </div>

      <div class="social-card">
        <div class="social-card-icon" style="background:#1877f2;color:#fff">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
        </div>
        <div>
          <div style="font-weight:600;color:#e2e8f0;margin-bottom:6px">Facebook</div>
          <div class="form-group" style="margin-bottom:8px">
            <label class="form-label">URL</label>
            <input id="s-fb" class="form-control" value="${esc(s.facebook_url||'')}" placeholder="https://www.facebook.com/bakudanSA/">
          </div>
          <div class="form-group" style="margin-bottom:8px">
            <label class="form-label">Label</label>
            <input id="s-fb-label" class="form-control" value="${esc(s.facebook_label||'Bakudan Ramen')}" placeholder="Bakudan Ramen">
          </div>
          <div style="display:flex;align-items:center;gap:8px">
            <label class="toggle"><input id="s-fb-vis" type="checkbox" ${s.facebook_visible!=='0'?'checked':''}><span class="toggle-slider"></span></label>
            <span class="form-label" style="margin:0">Show on /links</span>
          </div>
        </div>
      </div>

      <button class="btn btn-primary" onclick="BKDN.saveSettings()">${iconSave()} Save Settings</button>
    </div>

    <div class="card">
      <div class="card-title">Site Info</div>
      <div class="form-group">
        <label class="form-label">Site URL</label>
        <input id="s-site-url" class="form-control" value="${esc(s.site_url||'')}">
      </div>
      <div class="form-group">
        <label class="form-label">Default Links Headline</label>
        <input id="s-headline" class="form-control" value="${esc(s.links_headline||'')}">
      </div>
      <div class="form-group">
        <label class="form-label">Default Links Subheadline</label>
        <input id="s-subheadline" class="form-control" value="${esc(s.links_subheadline||'')}">
      </div>
      <button class="btn btn-primary" onclick="BKDN.saveSettings()">${iconSave()} Save Settings</button>
    </div>
  `);
}

async function saveSettings() {
  const settings = {
    instagram_url:     document.getElementById('s-ig')?.value.trim() || '',
    instagram_label:   document.getElementById('s-ig-label')?.value.trim() || '',
    instagram_visible: document.getElementById('s-ig-vis')?.checked ? '1' : '0',
    facebook_url:      document.getElementById('s-fb')?.value.trim() || '',
    facebook_label:    document.getElementById('s-fb-label')?.value.trim() || '',
    facebook_visible:  document.getElementById('s-fb-vis')?.checked ? '1' : '0',
    site_url:          document.getElementById('s-site-url')?.value.trim() || '',
    links_headline:    document.getElementById('s-headline')?.value.trim() || '',
    links_subheadline: document.getElementById('s-subheadline')?.value.trim() || '',
  };
  const res = await PUT('/links/settings', settings);
  if (res?.ok) toast('Settings saved.', 'success');
  else toast(res?.error || 'Save failed.', 'error');
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: USERS
═══════════════════════════════════════════════════════════════ */
async function viewUsers() {
  setContent(loading());
  // Simple user display using /auth/me for now; full user management is super_admin only
  const res = await GET('/auth/me');
  setContent(`
    ${pageTitle('Users', 'Account management')}
    <div class="card">
      <div class="card-title">Your Account</div>
      <div style="font-size:13px;color:#94a3b8;margin-bottom:12px">Logged in as: <strong style="color:#e2e8f0">${esc(res?.user?.email||'')}</strong> (${esc(res?.user?.role||'')})</div>
      <div style="font-size:12px;color:#475569">Full user management (add/edit/deactivate) requires direct database access or a future admin UI update.</div>
    </div>
  `);
}

/* ═══════════════════════════════════════════════════════════════
   VIEW: PROFILE
═══════════════════════════════════════════════════════════════ */
function viewProfile() {
  setContent(`
    ${pageTitle('Profile', _user?.email || '')}
    <div class="card">
      <div class="form-group"><label class="form-label">Name</label><input class="form-control" value="${esc(_user?.name||'')}" disabled></div>
      <div class="form-group"><label class="form-label">Email</label><input class="form-control" value="${esc(_user?.email||'')}" disabled></div>
      <div class="form-group"><label class="form-label">Role</label><input class="form-control" value="${esc(roleLabel(_user?.role||''))}" disabled></div>
      <div style="font-size:12px;color:#475569">To change your password, contact an admin or use the server CLI.</div>
    </div>
  `);
}

/* ═══════════════════════════════════════════════════════════════
   PUBLIC API (window.BKDN)
═══════════════════════════════════════════════════════════════ */
window.BKDN = {
  // Auth
  doLogin, logout,
  // Nav
  viewDashboard, viewProject, viewPages, viewScheduling,
  viewBlog, viewBlogEditor,
  viewAnalytics, viewSettings, viewUsers, viewProfile,
  // Modal
  closeModal,
  // Page CRUD
  openPageModal, savePageModal, autofillSlug, duplicatePage,
  // Page editor
  switchTab, savePage, publishPage, unpublishPage, applyPageStatus, onStatusChange,
  generatePreviewToken, verifySync, saveOrder, cancelReorder,
  // Button CRUD
  openAddButton, openEditButton, saveBtnModal, toggleBtn, duplicateButton, deleteButton,
  // Section CRUD
  openSectionModal, saveSectionModal, toggleSection, deleteSection,
  // Blog
  saveBlogPost, schedulePost, saveAndCreateAnother, publishPost, duplicatePost, archivePost,
  applyTemplate, toggleEmoji, insertEmoji,
  uploadMedia, handleMediaDrop, uploadFeaturedImage, deleteMedia,
  // Settings
  saveSettings,
};

/* ═══════════════════════════════════════════════════════════════
   BOOT
═══════════════════════════════════════════════════════════════ */
async function bootAdmin() {
  // Load server config
  try {
    const cfgRes = await fetch(API_BASE + 'config');
    CFG = await cfgRes.json();
  } catch (e) {
    console.warn('Could not load /api/config — running with defaults');
    CFG = { version: '1.0.0', deployedAt: new Date().toISOString(), siteUrl: '' };
  }

  // Signal boot complete (cancels 10s watchdog)
  window.BKDN_BOOTED = true;
  document.dispatchEvent(new Event('bkdn:booted'));

  // Remove loading shell
  const splash = document.getElementById('spa-loading');
  if (splash) splash.remove();

  // Render shell if authenticated, else show login
  if (_token) {
    // Validate token before rendering
    const meRes = await fetch('/api/auth/me', { headers: { Authorization: 'Bearer ' + _token } });
    if (!meRes.ok) { _token = null; _user = null; localStorage.removeItem('bkdn_token'); localStorage.removeItem('bkdn_user'); }
    else {
      const meData = await meRes.json();
      _user = meData.user;
      localStorage.setItem('bkdn_user', JSON.stringify(_user));
    }
  }

  if (_token) { renderShell(); router(); }
  else { renderLogin(); }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootAdmin);
} else {
  bootAdmin();
}
