'use strict';

const express = require('express');
const fs = require('fs');
const path = require('path');

const app = express();
const ROOT = path.join(__dirname, '..');
const PORT = process.env.PORT || 3000;

const linkButtons = [
  { id: 2, section_id: 1, title: 'Order Bandera', url: 'https://order.toasttab.com/online/bakudan-bandera', is_featured: 1, visible: 1, enabled: 1, opens_in_new_tab: 1 },
  { id: 4, section_id: 1, title: 'Order Stone Oak', url: 'https://order.toasttab.com/online/bakudan-ramen-stone-oak', is_featured: 1, visible: 1, enabled: 1, opens_in_new_tab: 1 },
  { id: 5, section_id: 1, title: 'Order The Rim', url: 'https://order.toasttab.com/online/bakudanramen/', is_featured: 1, visible: 1, enabled: 1, opens_in_new_tab: 1 },
  { id: 10, section_id: 2, title: 'Stone Oak Rewards', url: 'https://www.toasttab.com/bakudan-ramen-stone-oak/rewardsSignup', visible: 1, enabled: 1, opens_in_new_tab: 1 },
  { id: 12, section_id: 2, title: 'The Rim Rewards', url: 'https://www.toasttab.com/bakudanramen/rewardsSignup', visible: 1, enabled: 1, opens_in_new_tab: 1 },
  { id: 18, section_id: 2, title: 'Bandera Rewards', url: 'https://www.toasttab.com/bakudan-bandera/rewardsSignup', visible: 1, enabled: 1, opens_in_new_tab: 1 },
  { id: 1, section_id: 3, title: 'Follow on Instagram', url: 'https://www.instagram.com/bakudanramen/', icon_key: 'instagram', visible: 1, enabled: 1, opens_in_new_tab: 1 },
  { id: 19, section_id: 3, title: 'Visit Website', url: 'https://www.bakudanramen.com/', icon_key: 'website', visible: 1, enabled: 1, opens_in_new_tab: 0 }
];

const page = {
  id: 2,
  title: 'Bakudan links Main',
  slug: 'bakudan-links-main',
  headline: 'BAKUDAN RAMEN',
  subheadline: 'AUTHENTIC JAPANESE RAMEN · SAN ANTONIO',
  is_active: 1,
  last_published_at: new Date().toISOString()
};

const sections = [
  { id: 1, title: 'Order Online' },
  { id: 2, title: 'Rewards & Loyalty' },
  { id: 3, title: 'Social' }
];

app.use(express.json({ limit: '1mb' }));
app.use(express.urlencoded({ extended: true }));

function configPayload() {
  return {
    ok: true,
    version: '3.0.2-lite',
    siteUrl: 'https://www.bakudanramen.com',
    iconKeys: ['order','website','email','events','instagram','facebook','directions','phone','menu','gift','ticket','external','blog','social']
  };
}

function publicLinksPayload() {
  return { ok: true, data: { page, sections, buttons: linkButtons, settings: {
    links_headline: 'BAKUDAN RAMEN',
    links_subheadline: 'AUTHENTIC JAPANESE RAMEN · SAN ANTONIO'
  } } };
}

function dashboardPayload() {
  return {
    ok: true,
    total: linkButtons.length,
    live: linkButtons.length,
    hidden: 0,
    scheduled: 0,
    expired: 0,
    featured: linkButtons.filter(b => b.is_featured).length,
    views_24h: 0,
    clicks_24h: 0,
    pages: [{ ...page, button_count: linkButtons.length }]
  };
}

function loginResponse(req, res) {
  const email = String((req.body && req.body.email) || '').trim().toLowerCase();
  const password = String((req.body && req.body.password) || '');
  if (email === 'admin@bakudanramen.com' && password === 'admin123') {
    return res.json({
      ok: true,
      success: true,
      token: 'local-fallback-admin',
      user: { id: 1, email, name: 'Administrator', role: 'super_admin' }
    });
  }
  res.status(401).json({ ok: false, error: 'Invalid email or password.' });
}

function liteCompat(req, res) {
  const route = String(req.query.r || '').replace(/^\/+/, '');
  if (route === 'config') return res.json(configPayload());
  if (route === 'auth/login' && req.method === 'POST') return loginResponse(req, res);
  if (route === 'auth/me') return res.json({ ok: true, user: { id: 1, email: 'admin@bakudanramen.com', name: 'Administrator', role: 'super_admin' } });
  if (route === 'links/dashboard') return res.json(dashboardPayload());
  if (route.indexOf('public/links/') === 0) return res.json(publicLinksPayload());
  if (route.indexOf('public/analytics/') === 0) return res.json({ ok: true });
  res.status(404).json({ ok: false, error: 'Not found' });
}

app.get('/api/config', (req, res) => {
  res.json(configPayload());
});

app.get('/api/index-lite.php', liteCompat);
app.post('/api/index-lite.php', liteCompat);

app.post('/api/auth/login', loginResponse);

app.get('/api/auth/me', (req, res) => {
  res.json({
    ok: true,
    user: { id: 1, email: 'admin@bakudanramen.com', name: 'Administrator', role: 'super_admin' }
  });
});

app.get('/api/public/links/:slug', (req, res) => {
  res.json(publicLinksPayload());
});

app.post('/api/public/analytics/:kind', (req, res) => res.json({ ok: true }));

app.get('/api/links/dashboard', (req, res) => {
  res.json(dashboardPayload());
});

app.get('/links', (req, res) => res.sendFile(path.join(ROOT, 'links', 'index.html')));
app.get('/links/', (req, res) => res.sendFile(path.join(ROOT, 'links', 'index.html')));
app.get('/links/:slug', (req, res) => res.sendFile(path.join(ROOT, 'links', 'index.html')));

app.use('/links-admin', express.static(path.join(ROOT, 'links-admin')));
app.get('/links-admin', (req, res) => res.redirect('/links-admin/'));
app.get('/links-admin/*', (req, res) => res.sendFile(path.join(ROOT, 'links-admin', 'index.html')));

app.use(express.static(ROOT, { index: 'index.html' }));

app.use((req, res) => {
  if (req.path.indexOf('/api/') === 0) return res.status(404).json({ ok: false, error: 'Not found' });
  const indexPath = path.join(ROOT, 'index.html');
  if (fs.existsSync(indexPath)) return res.status(404).sendFile(indexPath);
  res.status(404).send('Not found');
});

app.listen(PORT, () => {
  console.log('Bakudan lite server listening on ' + PORT);
});
