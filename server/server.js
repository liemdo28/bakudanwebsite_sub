'use strict';
/**
 * Bakudan Ramen — Express server
 * Serves static website + Links Hub API + Blog CMS API
 */
require('dotenv').config();

const express = require('express');
const path    = require('path');
const fs      = require('fs');
const cron    = require('node-cron');
const db      = require('./db');

const app  = express();
const ROOT = path.join(__dirname, '..');
const PORT = process.env.PORT || 3000;
const pkg  = JSON.parse(fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8'));

/* ── Middleware ──────────────────────────────────── */
app.use(express.json({ limit: '10mb' }));
app.use(express.urlencoded({ extended: true }));

// Serve uploads
app.use('/uploads', express.static(path.join(ROOT, 'uploads')));

/* ── API Config endpoint ─────────────────────────── */
app.get('/api/config', (req, res) => {
  const serverStat = fs.statSync(path.join(__dirname, 'server.js'));
  res.json({
    ok: true,
    version:     pkg.version,
    deployedAt:  serverStat.mtime.toISOString(),
    siteUrl:     process.env.SITE_URL || 'https://bakudanramen.com',
    iconKeys:    ['order','website','email','events','instagram','facebook',
                  'directions','phone','menu','gift','ticket','external','blog','social'],
    project: {
      key:         'bakudan-links',
      name:        'Bakudan Ramen — Links Ecosystem',
      description: 'Self-hosted Link Hub + Blog CMS for bakudanramen.com',
      purpose:     'Replace Linktree subscription. Full control over links, analytics, scheduling, and content.',
      owner_team:  'Marketing / Agency',
      support:     'admin@bakudanramen.com',
      status:      'active',
      environment: 'production',
      resources: [
        { type:'website',       label:'Main Website',      url:'https://bakudanramen.com' },
        { type:'public_links',  label:'Public Links Page', url:'https://bakudanramen.com/links' },
        { type:'admin_console', label:'Links Admin',       url:'https://bakudanramen.com/links-admin' },
        { type:'blog',          label:'Blog',              url:'https://bakudanramen.com/blog-cms' },
      ],
      stores: [
        { slug:'rim',       name:'The Rim',    address:'17619 La Cantera Pkwy #208' },
        { slug:'stone-oak', name:'Stone Oak',  address:'22506 US Hwy 281 N #106' },
        { slug:'bandera',   name:'Bandera',    address:'11309 Bandera Rd #111' },
      ],
      notes: [
        'Changes to /links reflect immediately after Publish.',
        'QR codes point to /links/{slug}. Do NOT change page slugs after printing.',
        'To add new buttons: Pages & Buttons → select store → Add Button.',
        'Schedule a button using Start/End dates — it will auto show/hide.',
        'Blog posts can be scheduled for future publishing.',
      ],
    },
  });
});

/* ── API Routes ──────────────────────────────────── */
app.use('/api/auth',    require('./routes/auth'));
app.use('/api/links',   require('./routes/links'));
app.use('/api/blog',    require('./routes/blog'));
app.use('/api/public',  require('./routes/public'));

/* ── Admin SPA ───────────────────────────────────── */
// Serve the SPA shell for all /links-admin/* paths
app.use('/links-admin', (req, res, next) => {
  // Static assets for the admin SPA (app.js, app.css)
  const adminDir = path.join(ROOT, 'links-admin');
  const urlPath  = req.path === '/' ? '/index.html' : req.path;
  const filePath = path.join(adminDir, urlPath);
  if (fs.existsSync(filePath) && fs.statSync(filePath).isFile()) {
    return res.sendFile(filePath);
  }
  // Fall through to SPA index for all hash-routed paths
  res.sendFile(path.join(adminDir, 'index.html'));
});

/* ── Public links page ───────────────────────────── */
app.get('/links', (req, res) => {
  res.sendFile(path.join(ROOT, 'links', 'index.html'));
});
app.get('/links/:slug', (req, res) => {
  res.sendFile(path.join(ROOT, 'links', 'index.html'));
});

/* ── Blog CMS public pages ───────────────────────── */
app.get('/blog-cms', (req, res) => res.sendFile(path.join(ROOT, 'blog-cms', 'index.html')));
app.get('/blog-cms/*', (req, res) => res.sendFile(path.join(ROOT, 'blog-cms', 'index.html')));

/* ── Static website files (existing HTML pages) ── */
app.use(express.static(ROOT, {
  index: 'index.html',
  setHeaders: (res, filePath) => {
    if (filePath.endsWith('.html')) {
      res.setHeader('Cache-Control', 'no-cache');
    }
  },
}));

/* ── 404 handler ────────────────────────────────── */
app.use((req, res) => {
  if (req.path.startsWith('/api/')) return res.status(404).json({ ok: false, error: 'Not found' });
  res.status(404).sendFile(path.join(ROOT, 'index.html'));
});

/* ── Error handler ───────────────────────────────── */
app.use((err, req, res, _next) => {
  console.error('[ERROR]', err.message);
  if (req.path.startsWith('/api/')) return res.status(500).json({ ok: false, error: err.message });
  res.status(500).send('Server error');
});

/* ── Scheduled publishing cron ───────────────────── */
// Every minute: auto-publish blog posts whose scheduled_at has passed
cron.schedule('* * * * *', () => {
  const now = new Date().toISOString();
  const result = db.prepare(`
    UPDATE blog_posts
    SET status = 'published', published_at = datetime('now'), updated_at = datetime('now')
    WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= ? AND archived_at IS NULL
  `).run(now);
  if (result.changes > 0) {
    console.log(`[cron] Auto-published ${result.changes} scheduled blog post(s)`);
  }
});

/* ── Start ───────────────────────────────────────── */
app.listen(PORT, () => {
  console.log(`
╔══════════════════════════════════════════════╗
║  Bakudan Ramen — Links Hub + Blog CMS        ║
║  v${pkg.version.padEnd(42)}║
║  http://localhost:${String(PORT).padEnd(28)}║
╚══════════════════════════════════════════════╝

  Website:     http://localhost:${PORT}/
  Links Hub:   http://localhost:${PORT}/links
  Admin:       http://localhost:${PORT}/links-admin
  Blog:        http://localhost:${PORT}/blog-cms
  API:         http://localhost:${PORT}/api/

  Default login:  admin@bakudanramen.com / admin123
`);
});

module.exports = app;
