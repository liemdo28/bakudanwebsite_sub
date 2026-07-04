'use strict';
const express = require('express');
const crypto  = require('crypto');
const db      = require('../db');
const { requireAuth } = require('../middleware/auth');

const router = express.Router();
router.use(requireAuth);

/* ── helpers ─────────────────────────────────────── */
function getButtons(pageId) {
  return db.prepare(`
    SELECT * FROM link_buttons
    WHERE page_id = ? AND deleted_at IS NULL
    ORDER BY sort_order ASC, id ASC
  `).all(pageId);
}

/* ── Pages ───────────────────────────────────────── */

// GET /api/links/pages
router.get('/pages', (req, res) => {
  const pages = db.prepare(`
    SELECT p.*,
      (SELECT COUNT(*) FROM link_buttons b WHERE b.page_id = p.id AND b.deleted_at IS NULL) AS button_count
    FROM link_pages p ORDER BY p.id ASC
  `).all();
  res.json({ ok: true, data: { pages } });
});

// GET /api/links/pages/:id
router.get('/pages/:id', (req, res) => {
  const page = db.prepare('SELECT * FROM link_pages WHERE id = ?').get(req.params.id);
  if (!page) return res.status(404).json({ ok: false, error: 'Page not found' });
  const buttons = getButtons(page.id);
  res.json({ ok: true, data: { page, buttons } });
});

// POST /api/links/pages
router.post('/pages', (req, res) => {
  const { slug, title, headline, subheadline } = req.body || {};
  if (!slug || !title) return res.status(400).json({ ok: false, error: 'slug and title required' });
  try {
    const r = db.prepare(`
      INSERT INTO link_pages (slug, title, headline, subheadline)
      VALUES (?, ?, ?, ?)
    `).run(slug, title, headline || null, subheadline || null);
    const page = db.prepare('SELECT * FROM link_pages WHERE id = ?').get(r.lastInsertRowid);
    res.status(201).json({ ok: true, data: { page } });
  } catch (e) {
    if (e.code === 'SQLITE_CONSTRAINT_UNIQUE') return res.status(409).json({ ok: false, error: 'Slug already exists' });
    throw e;
  }
});

// PUT /api/links/pages/:id
router.put('/pages/:id', (req, res) => {
  const page = db.prepare('SELECT id FROM link_pages WHERE id = ?').get(req.params.id);
  if (!page) return res.status(404).json({ ok: false, error: 'Page not found' });

  const fields = [
    'title', 'handle', 'headline', 'subheadline', 'hero_image_url',
    'seo_title', 'seo_description', 'theme_json', 'is_active',
    'status', 'scheduled_publish_at',
  ];
  const sets = [], vals = [];
  fields.forEach(f => {
    if (req.body[f] !== undefined) { sets.push(`${f} = ?`); vals.push(req.body[f]); }
  });
  if (!sets.length) return res.status(400).json({ ok: false, error: 'No fields to update' });
  sets.push(`updated_at = datetime('now')`);
  vals.push(req.params.id);
  db.prepare(`UPDATE link_pages SET ${sets.join(', ')} WHERE id = ?`).run(...vals);
  res.json({ ok: true, data: { page: db.prepare('SELECT * FROM link_pages WHERE id = ?').get(req.params.id) } });
});

/* ── Buttons ─────────────────────────────────────── */

// GET /api/links/pages/:id/buttons
router.get('/pages/:id/buttons', (req, res) => {
  const page = db.prepare('SELECT id FROM link_pages WHERE id = ?').get(req.params.id);
  if (!page) return res.status(404).json({ ok: false, error: 'Page not found' });
  res.json({ ok: true, data: { buttons: getButtons(page.id) } });
});

// POST /api/links/pages/:id/buttons
router.post('/pages/:id/buttons', (req, res) => {
  const page = db.prepare('SELECT id FROM link_pages WHERE id = ?').get(req.params.id);
  if (!page) return res.status(404).json({ ok: false, error: 'Page not found' });

  const { kind='link', platform=null, title, subtitle=null, url='', icon_key=null,
          custom_icon_svg=null, style_variant='secondary', visible=1, enabled=1,
          is_featured=0, opens_in_new_tab=0, start_at=null, end_at=null } = req.body || {};
  if (!title) return res.status(400).json({ ok: false, error: 'title required' });

  const maxOrder = db.prepare(`SELECT COALESCE(MAX(sort_order),0) as m FROM link_buttons WHERE page_id = ? AND deleted_at IS NULL`).get(page.id).m;
  const r = db.prepare(`
    INSERT INTO link_buttons
      (page_id, kind, platform, title, subtitle, url, icon_key, custom_icon_svg,
       style_variant, sort_order, visible, enabled, is_featured, opens_in_new_tab, start_at, end_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(page.id, kind, platform, title, subtitle, url, icon_key, custom_icon_svg,
         style_variant, maxOrder + 1, visible ? 1 : 0, enabled ? 1 : 0,
         is_featured ? 1 : 0, opens_in_new_tab ? 1 : 0, start_at || null, end_at || null);
  const btn = db.prepare('SELECT * FROM link_buttons WHERE id = ?').get(r.lastInsertRowid);
  res.status(201).json({ ok: true, data: { button: btn } });
});

// PUT /api/links/buttons/:id
router.put('/buttons/:id', (req, res) => {
  const btn = db.prepare('SELECT id FROM link_buttons WHERE id = ? AND deleted_at IS NULL').get(req.params.id);
  if (!btn) return res.status(404).json({ ok: false, error: 'Button not found' });

  const fields = ['kind','platform','title','subtitle','url','icon_key','custom_icon_svg',
                  'style_variant','sort_order','visible','enabled','is_featured','opens_in_new_tab','start_at','end_at'];
  const sets = [], vals = [];
  fields.forEach(f => {
    if (req.body[f] !== undefined) { sets.push(`${f} = ?`); vals.push(req.body[f]); }
  });
  if (!sets.length) return res.status(400).json({ ok: false, error: 'No fields to update' });
  sets.push(`updated_at = datetime('now')`);
  vals.push(req.params.id);
  db.prepare(`UPDATE link_buttons SET ${sets.join(', ')} WHERE id = ?`).run(...vals);
  res.json({ ok: true, data: { button: db.prepare('SELECT * FROM link_buttons WHERE id = ?').get(req.params.id) } });
});

// DELETE /api/links/buttons/:id  (soft delete)
router.delete('/buttons/:id', (req, res) => {
  const r = db.prepare(`UPDATE link_buttons SET deleted_at = datetime('now') WHERE id = ? AND deleted_at IS NULL`).run(req.params.id);
  if (!r.changes) return res.status(404).json({ ok: false, error: 'Button not found' });
  res.json({ ok: true });
});

// POST /api/links/buttons/:id/duplicate
router.post('/buttons/:id/duplicate', (req, res) => {
  const btn = db.prepare('SELECT * FROM link_buttons WHERE id = ? AND deleted_at IS NULL').get(req.params.id);
  if (!btn) return res.status(404).json({ ok: false, error: 'Button not found' });
  const maxOrder = db.prepare(`SELECT COALESCE(MAX(sort_order),0) as m FROM link_buttons WHERE page_id = ? AND deleted_at IS NULL`).get(btn.page_id).m;
  const r = db.prepare(`
    INSERT INTO link_buttons
      (page_id, kind, platform, title, subtitle, url, icon_key, custom_icon_svg,
       style_variant, sort_order, visible, enabled, is_featured, opens_in_new_tab, start_at, end_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(btn.page_id, btn.kind, btn.platform, btn.title + ' (copy)', btn.subtitle, btn.url,
         btn.icon_key, btn.custom_icon_svg, btn.style_variant, maxOrder + 1,
         btn.visible, btn.enabled, btn.is_featured, btn.opens_in_new_tab, btn.start_at, btn.end_at);
  res.status(201).json({ ok: true, data: { button: db.prepare('SELECT * FROM link_buttons WHERE id = ?').get(r.lastInsertRowid) } });
});

// PATCH /api/links/pages/:id/buttons/reorder (alias — both PATCH and POST accepted)
router.patch('/pages/:id/buttons/reorder', (req, res) => {
  const { order } = req.body || {};
  if (!Array.isArray(order)) return res.status(400).json({ ok: false, error: 'order must be array of ids' });
  const updateOrder = db.prepare('UPDATE link_buttons SET sort_order = ?, updated_at = datetime(\'now\') WHERE id = ? AND page_id = ?');
  const reorderAll = db.transaction((pageId, ids) => {
    ids.forEach((id, idx) => updateOrder.run(idx + 1, id, pageId));
  });
  reorderAll(req.params.id, order);
  res.json({ ok: true });
});

// POST /api/links/pages/:id/buttons/reorder
router.post('/pages/:id/buttons/reorder', (req, res) => {
  const { order } = req.body || {}; // array of button ids in new order
  if (!Array.isArray(order)) return res.status(400).json({ ok: false, error: 'order must be array of ids' });
  const updateOrder = db.prepare('UPDATE link_buttons SET sort_order = ?, updated_at = datetime(\'now\') WHERE id = ? AND page_id = ?');
  const reorderAll = db.transaction((pageId, ids) => {
    ids.forEach((id, idx) => updateOrder.run(idx + 1, id, pageId));
  });
  reorderAll(req.params.id, order);
  res.json({ ok: true });
});

// POST /api/links/pages/:id/publish
router.post('/pages/:id/publish', requireAuth, (req, res) => {
  const page    = db.prepare('SELECT * FROM link_pages WHERE id = ?').get(req.params.id);
  if (!page) return res.status(404).json({ ok: false, error: 'Page not found' });
  const buttons = getButtons(page.id);
  const payload = JSON.stringify({ page, buttons });
  const checksum = Buffer.from(payload).length.toString(16);
  const lastVersion = db.prepare('SELECT COALESCE(MAX(version_number),0) as v FROM link_publish_snapshots WHERE page_id = ?').get(page.id).v;
  db.prepare(`
    INSERT INTO link_publish_snapshots (page_id, version_number, payload_json, published_by, checksum)
    VALUES (?, ?, ?, ?, ?)
  `).run(page.id, lastVersion + 1, payload, req.user?.id || null, checksum);
  db.prepare(`
    UPDATE link_pages
    SET status = 'published', is_active = 1,
        last_published_at = datetime('now'), scheduled_publish_at = NULL, updated_at = datetime('now')
    WHERE id = ?
  `).run(page.id);
  res.json({ ok: true, data: { version: lastVersion + 1, published_at: new Date().toISOString() } });
});

// POST /api/links/pages/:id/unpublish  — revert to draft
router.post('/pages/:id/unpublish', requireAuth, (req, res) => {
  const page = db.prepare('SELECT id FROM link_pages WHERE id = ?').get(req.params.id);
  if (!page) return res.status(404).json({ ok: false, error: 'Page not found' });
  db.prepare(`UPDATE link_pages SET status = 'draft', is_active = 0, updated_at = datetime('now') WHERE id = ?`).run(page.id);
  res.json({ ok: true });
});

// POST /api/links/pages/:id/schedule  — set scheduled publish date
router.post('/pages/:id/schedule', requireAuth, (req, res) => {
  const page = db.prepare('SELECT id FROM link_pages WHERE id = ?').get(req.params.id);
  if (!page) return res.status(404).json({ ok: false, error: 'Page not found' });
  const { scheduled_publish_at } = req.body || {};
  if (!scheduled_publish_at) return res.status(400).json({ ok: false, error: 'scheduled_publish_at required' });
  db.prepare(`
    UPDATE link_pages
    SET status = 'scheduled', is_active = 0, scheduled_publish_at = ?, updated_at = datetime('now')
    WHERE id = ?
  `).run(scheduled_publish_at, page.id);
  res.json({ ok: true, data: { scheduled_publish_at } });
});

// POST /api/links/pages/:id/generate-preview-token
router.post('/pages/:id/generate-preview-token', requireAuth, (req, res) => {
  const page = db.prepare('SELECT * FROM link_pages WHERE id = ?').get(req.params.id);
  if (!page) return res.status(404).json({ ok: false, error: 'Page not found' });
  const token = crypto.randomBytes(24).toString('hex');
  db.prepare(`UPDATE link_pages SET preview_token = ?, updated_at = datetime('now') WHERE id = ?`).run(token, page.id);
  const siteUrl = process.env.SITE_URL || 'http://localhost:3000';
  res.json({ ok: true, data: { token, preview_url: `${siteUrl}/links/preview/${page.slug}?token=${token}` } });
});

/* ── Settings ─────────────────────────────────────── */

// GET /api/links/settings
router.get('/settings', (req, res) => {
  const rows = db.prepare('SELECT key, value FROM settings').all();
  const settings = Object.fromEntries(rows.map(r => [r.key, r.value]));
  res.json({ ok: true, data: { settings } });
});

// PUT /api/links/settings
router.put('/settings', (req, res) => {
  const upsert = db.prepare(`INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
    ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at`);
  const update = db.transaction(obj => { Object.entries(obj).forEach(([k, v]) => upsert.run(k, v)); });
  update(req.body || {});
  res.json({ ok: true });
});

/* ── Dashboard ────────────────────────────────────── */

// GET /api/links/dashboard
router.get('/dashboard', (req, res) => {
  const now = new Date().toISOString();
  const buttons = db.prepare('SELECT * FROM link_buttons WHERE deleted_at IS NULL').all();
  let live = 0, hidden = 0, scheduled = 0, expired = 0, featured = 0;
  buttons.forEach(b => {
    if (!b.visible) { hidden++; return; }
    if (b.end_at && now > b.end_at) { expired++; return; }
    if (b.start_at && now < b.start_at) { scheduled++; return; }
    if (b.is_featured) featured++;
    live++;
  });
  const views24 = db.prepare(`SELECT COUNT(*) as c FROM analytics_events WHERE event_type='view' AND created_at > datetime('now', '-24 hours')`).get().c;
  const clicks24 = db.prepare(`SELECT COUNT(*) as c FROM analytics_events WHERE event_type='click' AND created_at > datetime('now', '-24 hours')`).get().c;
  const pages = db.prepare(`
    SELECT p.*, (SELECT COUNT(*) FROM link_buttons b WHERE b.page_id=p.id AND b.deleted_at IS NULL) as button_count
    FROM link_pages p ORDER BY p.id ASC
  `).all();

  // Page status summary
  let pDraft = 0, pPrivate = 0, pScheduled = 0, pPublished = 0;
  pages.forEach(p => {
    const s = p.status || (p.is_active ? 'published' : 'draft');
    if (s === 'draft')     pDraft++;
    else if (s === 'private')    pPrivate++;
    else if (s === 'scheduled')  pScheduled++;
    else                         pPublished++;
  });

  res.json({ ok: true, data: {
    total: buttons.length, live, hidden, scheduled, expired, featured,
    views_24h: views24, clicks_24h: clicks24,
    pages,
    page_counts: { draft: pDraft, private: pPrivate, scheduled: pScheduled, published: pPublished },
  }});
});

/* ── Analytics ────────────────────────────────────── */

router.get('/analytics', (req, res) => {
  const days = parseInt(req.query.days) || 30;
  const views = db.prepare(`SELECT COUNT(*) as c FROM analytics_events WHERE event_type='view' AND created_at > datetime('now', '-${days} days')`).get().c;
  const clicks = db.prepare(`SELECT COUNT(*) as c FROM analytics_events WHERE event_type='click' AND created_at > datetime('now', '-${days} days')`).get().c;
  const topButtons = db.prepare(`
    SELECT b.title, COUNT(*) as clicks
    FROM analytics_events e LEFT JOIN link_buttons b ON b.id = e.button_id
    WHERE e.event_type='click' AND e.created_at > datetime('now', '-${days} days')
    GROUP BY e.button_id ORDER BY clicks DESC LIMIT 10
  `).all();
  res.json({ ok: true, data: { views, clicks, ctr: views > 0 ? Math.round(clicks/views*100)/100 : 0, top_buttons: topButtons, days } });
});

module.exports = router;
