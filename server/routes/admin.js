'use strict';
/**
 * admin.js — /api/admin/* routes
 *
 * The admin SPA (app.js) calls /api/admin/... but the original server only
 * mounted routes at /api/links/... and /api/auth/...  This router provides
 * the /api/admin namespace the SPA expects, wiring each path to the existing
 * business logic.
 */
const express = require('express');
const db      = require('../db');
const { requireAuth } = require('../middleware/auth');

const router = express.Router();
router.use(requireAuth);

/* ── helpers ──────────────────────────────────────── */
function getButtons(pageId) {
  return db.prepare(`
    SELECT * FROM link_buttons
    WHERE page_id = ? AND deleted_at IS NULL
    ORDER BY sort_order ASC, id ASC
  `).all(pageId);
}

/* ── Dashboard ────────────────────────────────────── */
// GET /api/admin/dashboard
router.get('/dashboard', (req, res) => {
  const now = new Date().toISOString();

  const views24  = db.prepare(`SELECT COUNT(*) as c FROM analytics_events WHERE event_type='view'  AND created_at > datetime('now', '-24 hours')`).get().c;
  const clicks24 = db.prepare(`SELECT COUNT(*) as c FROM analytics_events WHERE event_type='click' AND created_at > datetime('now', '-24 hours')`).get().c;

  const pages = db.prepare(`
    SELECT p.*,
      (SELECT COUNT(*) FROM link_buttons b WHERE b.page_id = p.id AND b.deleted_at IS NULL) AS button_count
    FROM link_pages p ORDER BY p.id ASC
  `).all();

  // page_button_counts: { [slug]: count }
  const page_button_counts = {};
  pages.forEach(p => { page_button_counts[p.slug] = p.button_count || 0; });

  // top_pages: pages sorted by button_count desc (proxy for activity)
  const top_pages = [...pages]
    .sort((a, b) => (b.button_count || 0) - (a.button_count || 0))
    .slice(0, 5)
    .map(p => ({ slug: p.slug, title: p.title, button_count: p.button_count || 0 }));

  // Button status summary
  const buttons = db.prepare('SELECT * FROM link_buttons WHERE deleted_at IS NULL').all();
  let live = 0, hidden = 0, scheduled = 0, expired = 0, featured = 0;
  buttons.forEach(b => {
    if (!b.visible) { hidden++; return; }
    if (b.end_at && now > b.end_at) { expired++; return; }
    if (b.start_at && now < b.start_at) { scheduled++; return; }
    if (b.is_featured) featured++;
    live++;
  });

  res.json({
    ok: true,
    data: {
      dashboard: {
        views_24h:          views24,
        clicks_24h:         clicks24,
        total_buttons:      buttons.length,
        live, hidden, scheduled, expired, featured,
        page_button_counts,
        top_pages,
      },
    },
  });
});

/* ── Pages ────────────────────────────────────────── */
// GET /api/admin/pages
router.get('/pages', (req, res) => {
  const pages = db.prepare(`
    SELECT p.*,
      (SELECT COUNT(*) FROM link_buttons b WHERE b.page_id = p.id AND b.deleted_at IS NULL) AS button_count
    FROM link_pages p ORDER BY p.id ASC
  `).all();
  res.json({ ok: true, data: { pages } });
});

// POST /api/admin/pages
router.post('/pages', (req, res) => {
  const { slug, title, headline, subheadline } = req.body || {};
  if (!slug || !title) return res.status(400).json({ ok: false, error: 'slug and title required' });
  try {
    const r = db.prepare(`INSERT INTO link_pages (slug, title, headline, subheadline) VALUES (?, ?, ?, ?)`).run(slug, title, headline || null, subheadline || null);
    const page = db.prepare('SELECT * FROM link_pages WHERE id = ?').get(r.lastInsertRowid);
    res.status(201).json({ ok: true, data: { page } });
  } catch (e) {
    if (e.code === 'SQLITE_CONSTRAINT_UNIQUE') return res.status(409).json({ ok: false, error: 'Slug already exists' });
    throw e;
  }
});

// GET /api/admin/pages/:id
router.get('/pages/:id', (req, res) => {
  const page = db.prepare('SELECT * FROM link_pages WHERE id = ?').get(req.params.id);
  if (!page) return res.status(404).json({ ok: false, error: 'Page not found' });
  res.json({ ok: true, data: { page } });
});

// PUT /api/admin/pages/:id
router.put('/pages/:id', (req, res) => {
  const page = db.prepare('SELECT id FROM link_pages WHERE id = ?').get(req.params.id);
  if (!page) return res.status(404).json({ ok: false, error: 'Page not found' });
  const fields = ['title','headline','subheadline','hero_image_url','seo_title','seo_description','theme_json','is_active','status','scheduled_publish_at'];
  const sets = [], vals = [];
  fields.forEach(f => { if (req.body[f] !== undefined) { sets.push(`${f} = ?`); vals.push(req.body[f]); } });
  if (!sets.length) return res.status(400).json({ ok: false, error: 'No fields to update' });
  sets.push(`updated_at = datetime('now')`);
  vals.push(req.params.id);
  db.prepare(`UPDATE link_pages SET ${sets.join(', ')} WHERE id = ?`).run(...vals);
  res.json({ ok: true, data: { page: db.prepare('SELECT * FROM link_pages WHERE id = ?').get(req.params.id) } });
});

// DELETE /api/admin/pages/:id  (soft: unpublish + deactivate)
router.delete('/pages/:id', (req, res) => {
  const page = db.prepare('SELECT id FROM link_pages WHERE id = ?').get(req.params.id);
  if (!page) return res.status(404).json({ ok: false, error: 'Page not found' });
  db.prepare(`UPDATE link_pages SET is_active = 0, status = 'draft', updated_at = datetime('now') WHERE id = ?`).run(req.params.id);
  res.json({ ok: true });
});

// POST /api/admin/pages/:id/duplicate
router.post('/pages/:id/duplicate', (req, res) => {
  const page = db.prepare('SELECT * FROM link_pages WHERE id = ?').get(req.params.id);
  if (!page) return res.status(404).json({ ok: false, error: 'Page not found' });
  const newSlug = page.slug + '-copy-' + Date.now().toString(36);
  const r = db.prepare(`INSERT INTO link_pages (slug, title, headline, subheadline, hero_image_url, seo_title, seo_description, theme_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)`).run(newSlug, page.title + ' (copy)', page.headline, page.subheadline, page.hero_image_url, page.seo_title, page.seo_description, page.theme_json || '{}');
  const newPage = db.prepare('SELECT * FROM link_pages WHERE id = ?').get(r.lastInsertRowid);
  // duplicate buttons
  const buttons = getButtons(page.id);
  const insertBtn = db.prepare(`INSERT INTO link_buttons (page_id,kind,platform,title,subtitle,url,icon_key,custom_icon_svg,style_variant,sort_order,visible,enabled,is_featured,opens_in_new_tab,start_at,end_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`);
  buttons.forEach(b => insertBtn.run(newPage.id,b.kind,b.platform,b.title,b.subtitle,b.url,b.icon_key,b.custom_icon_svg,b.style_variant,b.sort_order,b.visible,b.enabled,b.is_featured,b.opens_in_new_tab,b.start_at,b.end_at));
  res.status(201).json({ ok: true, data: { page: newPage } });
});

/* ── Page publish/unpublish/schedule ──────────────── */
router.post('/pages/:id/publish', (req, res) => {
  const page = db.prepare('SELECT * FROM link_pages WHERE id = ?').get(req.params.id);
  if (!page) return res.status(404).json({ ok: false, error: 'Page not found' });
  const buttons = getButtons(page.id);
  const payload = JSON.stringify({ page, buttons });
  const checksum = Buffer.from(payload).length.toString(16);
  const lastV = db.prepare('SELECT COALESCE(MAX(version_number),0) as v FROM link_publish_snapshots WHERE page_id = ?').get(page.id).v;
  db.prepare(`INSERT INTO link_publish_snapshots (page_id,version_number,payload_json,published_by,checksum) VALUES (?,?,?,?,?)`).run(page.id, lastV + 1, payload, req.user?.id || null, checksum);
  db.prepare(`UPDATE link_pages SET status='published', is_active=1, last_published_at=datetime('now'), scheduled_publish_at=NULL, updated_at=datetime('now') WHERE id=?`).run(page.id);
  res.json({ ok: true, data: { version: lastV + 1, published_at: new Date().toISOString() } });
});

router.post('/pages/:id/unpublish', (req, res) => {
  const page = db.prepare('SELECT id FROM link_pages WHERE id = ?').get(req.params.id);
  if (!page) return res.status(404).json({ ok: false, error: 'Page not found' });
  db.prepare(`UPDATE link_pages SET status='draft', is_active=0, updated_at=datetime('now') WHERE id=?`).run(page.id);
  res.json({ ok: true });
});

router.post('/pages/:id/schedule', (req, res) => {
  const page = db.prepare('SELECT id FROM link_pages WHERE id = ?').get(req.params.id);
  if (!page) return res.status(404).json({ ok: false, error: 'Page not found' });
  const { scheduled_publish_at } = req.body || {};
  if (!scheduled_publish_at) return res.status(400).json({ ok: false, error: 'scheduled_publish_at required' });
  db.prepare(`UPDATE link_pages SET status='scheduled', is_active=0, scheduled_publish_at=?, updated_at=datetime('now') WHERE id=?`).run(scheduled_publish_at, page.id);
  res.json({ ok: true, data: { scheduled_publish_at } });
});

/* ── Page analytics ───────────────────────────────── */
// GET /api/admin/pages/:id/analytics
router.get('/pages/:id/analytics', (req, res) => {
  const period = req.query.period || '7d';
  const days = parseInt(period) || 7;
  const views  = db.prepare(`SELECT COUNT(*) as c FROM analytics_events WHERE event_type='view'  AND page_id=? AND created_at > datetime('now', '-${days} days')`).get(req.params.id)?.c || 0;
  const clicks = db.prepare(`SELECT COUNT(*) as c FROM analytics_events WHERE event_type='click' AND page_id=? AND created_at > datetime('now', '-${days} days')`).get(req.params.id)?.c || 0;
  res.json({ ok: true, data: { views, clicks, period } });
});

/* ── Page redirects (stub — table not yet created) ── */
router.get('/pages/:id/redirects', (req, res) => res.json({ ok: true, data: { redirects: [] } }));
router.post('/pages/:id/redirects', (req, res) => res.status(501).json({ ok: false, error: 'Redirects not yet implemented' }));
router.delete('/redirects/:id', (req, res) => res.status(501).json({ ok: false, error: 'Redirects not yet implemented' }));

/* ── Buttons ──────────────────────────────────────── */
// GET /api/admin/pages/:id/buttons
router.get('/pages/:id/buttons', (req, res) => {
  const page = db.prepare('SELECT id FROM link_pages WHERE id = ?').get(req.params.id);
  if (!page) return res.status(404).json({ ok: false, error: 'Page not found' });
  res.json({ ok: true, data: { buttons: getButtons(page.id) } });
});

// POST /api/admin/pages/:id/buttons
router.post('/pages/:id/buttons', (req, res) => {
  const page = db.prepare('SELECT id FROM link_pages WHERE id = ?').get(req.params.id);
  if (!page) return res.status(404).json({ ok: false, error: 'Page not found' });
  const { kind='link', platform=null, title, subtitle=null, url='', icon_key=null,
          custom_icon_svg=null, style_variant='secondary', visible=1, enabled=1,
          is_featured=0, opens_in_new_tab=0, start_at=null, end_at=null } = req.body || {};
  if (!title) return res.status(400).json({ ok: false, error: 'title required' });
  const maxOrder = db.prepare(`SELECT COALESCE(MAX(sort_order),0) as m FROM link_buttons WHERE page_id=? AND deleted_at IS NULL`).get(page.id).m;
  const r = db.prepare(`INSERT INTO link_buttons (page_id,kind,platform,title,subtitle,url,icon_key,custom_icon_svg,style_variant,sort_order,visible,enabled,is_featured,opens_in_new_tab,start_at,end_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).run(page.id,kind,platform,title,subtitle,url,icon_key,custom_icon_svg,style_variant,maxOrder+1,visible?1:0,enabled?1:0,is_featured?1:0,opens_in_new_tab?1:0,start_at||null,end_at||null);
  res.status(201).json({ ok: true, data: { button: db.prepare('SELECT * FROM link_buttons WHERE id=?').get(r.lastInsertRowid) } });
});

// PATCH + POST /api/admin/pages/:id/buttons/reorder
function reorderHandler(req, res) {
  const { order } = req.body || {};
  if (!Array.isArray(order)) return res.status(400).json({ ok: false, error: 'order must be array of ids' });
  const upd = db.prepare(`UPDATE link_buttons SET sort_order=?, updated_at=datetime('now') WHERE id=? AND page_id=?`);
  db.transaction((pageId, ids) => ids.forEach((id, i) => upd.run(i + 1, id, pageId)))(req.params.id, order);
  res.json({ ok: true });
}
router.patch('/pages/:id/buttons/reorder', reorderHandler);
router.post('/pages/:id/buttons/reorder', reorderHandler);

// PUT /api/admin/buttons/:id
router.put('/buttons/:id', (req, res) => {
  const btn = db.prepare('SELECT id FROM link_buttons WHERE id=? AND deleted_at IS NULL').get(req.params.id);
  if (!btn) return res.status(404).json({ ok: false, error: 'Button not found' });
  const fields = ['kind','platform','title','subtitle','url','icon_key','custom_icon_svg','style_variant','sort_order','visible','enabled','is_featured','opens_in_new_tab','start_at','end_at'];
  const sets = [], vals = [];
  fields.forEach(f => { if (req.body[f] !== undefined) { sets.push(`${f}=?`); vals.push(req.body[f]); } });
  if (!sets.length) return res.status(400).json({ ok: false, error: 'No fields to update' });
  sets.push(`updated_at=datetime('now')`);
  vals.push(req.params.id);
  db.prepare(`UPDATE link_buttons SET ${sets.join(',')} WHERE id=?`).run(...vals);
  res.json({ ok: true, data: { button: db.prepare('SELECT * FROM link_buttons WHERE id=?').get(req.params.id) } });
});

// POST /api/admin/buttons/:id/duplicate
router.post('/buttons/:id/duplicate', (req, res) => {
  const b = db.prepare('SELECT * FROM link_buttons WHERE id=? AND deleted_at IS NULL').get(req.params.id);
  if (!b) return res.status(404).json({ ok: false, error: 'Button not found' });
  const maxOrder = db.prepare(`SELECT COALESCE(MAX(sort_order),0) as m FROM link_buttons WHERE page_id=? AND deleted_at IS NULL`).get(b.page_id).m;
  const r = db.prepare(`INSERT INTO link_buttons (page_id,kind,platform,title,subtitle,url,icon_key,custom_icon_svg,style_variant,sort_order,visible,enabled,is_featured,opens_in_new_tab,start_at,end_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).run(b.page_id,b.kind,b.platform,b.title+' (copy)',b.subtitle,b.url,b.icon_key,b.custom_icon_svg,b.style_variant,maxOrder+1,b.visible,b.enabled,b.is_featured,b.opens_in_new_tab,b.start_at,b.end_at);
  res.status(201).json({ ok: true, data: { button: db.prepare('SELECT * FROM link_buttons WHERE id=?').get(r.lastInsertRowid) } });
});

// DELETE /api/admin/buttons/:id  (soft delete)
router.delete('/buttons/:id', (req, res) => {
  const r = db.prepare(`UPDATE link_buttons SET deleted_at=datetime('now') WHERE id=? AND deleted_at IS NULL`).run(req.params.id);
  if (!r.changes) return res.status(404).json({ ok: false, error: 'Button not found' });
  res.json({ ok: true });
});

/* ── Analytics ────────────────────────────────────── */
// GET /api/admin/analytics?period=7d
router.get('/analytics', (req, res) => {
  const period = req.query.period || '7d';
  const days   = parseInt(period) || 7;
  const views  = db.prepare(`SELECT COUNT(*) as c FROM analytics_events WHERE event_type='view'  AND created_at > datetime('now', '-${days} days')`).get().c;
  const clicks = db.prepare(`SELECT COUNT(*) as c FROM analytics_events WHERE event_type='click' AND created_at > datetime('now', '-${days} days')`).get().c;
  const topButtons = db.prepare(`
    SELECT b.title, COUNT(*) as clicks
    FROM analytics_events e LEFT JOIN link_buttons b ON b.id = e.button_id
    WHERE e.event_type='click' AND e.created_at > datetime('now', '-${days} days')
    GROUP BY e.button_id ORDER BY clicks DESC LIMIT 10
  `).all();
  res.json({ ok: true, data: { views, clicks, ctr: views > 0 ? Math.round(clicks / views * 100) / 100 : 0, top_buttons: topButtons, days } });
});

/* ── Settings ─────────────────────────────────────── */
router.get('/settings', (req, res) => {
  const rows = db.prepare('SELECT key, value FROM settings').all();
  res.json({ ok: true, data: { settings: Object.fromEntries(rows.map(r => [r.key, r.value])) } });
});

router.put('/settings', (req, res) => {
  const upsert = db.prepare(`INSERT INTO settings (key,value,updated_at) VALUES (?,?,datetime('now')) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at`);
  db.transaction(obj => Object.entries(obj).forEach(([k, v]) => upsert.run(k, v)))(req.body || {});
  res.json({ ok: true });
});

/* ── Users ────────────────────────────────────────── */
router.get('/users', (req, res) => {
  const users = db.prepare('SELECT id, name, email, role, is_active, created_at FROM users ORDER BY id ASC').all();
  res.json({ ok: true, data: { users } });
});

/* ── Subscribers (stub) ───────────────────────────── */
router.get('/subscribers', (req, res) => res.json({ ok: true, data: { subscribers: [], total: 0 } }));
router.get('/subscribers/export', (req, res) => res.json({ ok: true, data: { url: null } }));

/* ── Shortlinks (stub) ────────────────────────────── */
router.get('/shortlinks',      (req, res) => res.json({ ok: true, data: { shortlinks: [] } }));
router.post('/shortlinks',     (req, res) => res.status(501).json({ ok: false, error: 'Shortlinks not yet implemented' }));
router.delete('/shortlinks/:id', (req, res) => res.status(501).json({ ok: false, error: 'Shortlinks not yet implemented' }));

module.exports = router;
