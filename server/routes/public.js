'use strict';
const express = require('express');
const db      = require('../db');

const router = express.Router();

/* ── Analytics tracking (fire-and-forget POSTs) ── */

router.post('/analytics/view', (req, res) => {
  const { page_id, ip_hash, user_agent, referer } = req.body || {};
  try {
    db.prepare(`
      INSERT INTO analytics_events (event_type, page_id, ip_hash, user_agent, referer, device_type)
      VALUES ('view', ?, ?, ?, ?, ?)
    `).run(page_id || null, ip_hash || null, (user_agent||'').slice(0,200), (referer||'').slice(0,200),
           /mobile|android|iphone|ipad/i.test(user_agent||'') ? 'mobile' : 'desktop');
  } catch {}
  res.json({ ok: true });
});

router.post('/analytics/click', (req, res) => {
  const { button_id, page_id, ip_hash } = req.body || {};
  try {
    db.prepare(`
      INSERT INTO analytics_events (event_type, page_id, button_id, ip_hash)
      VALUES ('click', ?, ?, ?)
    `).run(page_id || null, button_id || null, ip_hash || null);
  } catch {}
  res.json({ ok: true });
});

/* ── Public Links ─────────────────────────────────── */

// GET /api/public/links/preview/:slug?token=TOKEN  — MUST be before /links/:slug wildcard
// Private preview: shows all buttons regardless of visible/enabled state
router.get('/links/preview/:slug', (req, res) => {
  const { token } = req.query;
  if (!token) return res.status(401).json({ ok: false, error: 'Preview token required' });

  const page = db.prepare('SELECT * FROM link_pages WHERE slug = ? AND preview_token = ?').get(req.params.slug, token);
  if (!page) return res.status(403).json({ ok: false, error: 'Invalid preview token' });

  const buttons = db.prepare(`
    SELECT * FROM link_buttons
    WHERE page_id = ? AND deleted_at IS NULL
    ORDER BY sort_order ASC, id ASC
  `).all(page.id);

  const settings = {};
  db.prepare('SELECT key, value FROM settings').all().forEach(r => { settings[r.key] = r.value; });

  res.set('X-Robots-Tag', 'noindex, nofollow');
  res.json({ ok: true, preview: true, data: { page, buttons, settings } });
});

// GET /api/public/links/:slug  — public (respects page status)
router.get('/links/:slug', (req, res) => {
  const page = db.prepare('SELECT * FROM link_pages WHERE slug = ?').get(req.params.slug);
  if (!page) return res.status(404).json({ ok: false, error: 'Page not found' });

  const now = new Date().toISOString();
  const status = page.status || (page.is_active ? 'published' : 'draft');

  // Gate: only published pages are publicly accessible
  if (status === 'draft' || status === 'private') {
    return res.status(404).json({ ok: false, error: 'Page not found' });
  }
  if (status === 'scheduled') {
    if (!page.scheduled_publish_at || now < page.scheduled_publish_at) {
      return res.status(404).json({ ok: false, error: 'Page not found' });
    }
    // Auto-upgrade to published now that time has passed
    db.prepare(`UPDATE link_pages SET status='published', is_active=1, updated_at=datetime('now') WHERE id=?`).run(page.id);
  }

  const allButtons = db.prepare(`
    SELECT * FROM link_buttons
    WHERE page_id = ? AND deleted_at IS NULL AND visible = 1
    ORDER BY sort_order ASC, id ASC
  `).all(page.id);

  // Server-side scheduling enforcement on buttons
  const buttons = allButtons.filter(b => {
    if (!b.enabled) return false;
    if (b.end_at && now > b.end_at) return false;
    if (b.start_at && now < b.start_at) return false;
    return true;
  });

  const settings = {};
  db.prepare('SELECT key, value FROM settings').all().forEach(r => { settings[r.key] = r.value; });

  res.json({ ok: true, data: { page, buttons, settings } });
});

/* ── Public Blog ─────────────────────────────────── */

// GET /api/public/blog
router.get('/blog', (req, res) => {
  const { limit = 20, offset = 0 } = req.query;
  const posts = db.prepare(`
    SELECT id, title, slug, excerpt, featured_image_url, published_at, cta_label, cta_url
    FROM blog_posts
    WHERE status = 'published' AND archived_at IS NULL
    ORDER BY published_at DESC
    LIMIT ? OFFSET ?
  `).all(parseInt(limit), parseInt(offset));
  const total = db.prepare(`SELECT COUNT(*) as c FROM blog_posts WHERE status='published' AND archived_at IS NULL`).get().c;
  res.json({ ok: true, data: { posts, total } });
});

// GET /api/public/blog/:slug
router.get('/blog/:slug', (req, res) => {
  const post = db.prepare(`SELECT * FROM blog_posts WHERE slug = ? AND status = 'published' AND archived_at IS NULL`).get(req.params.slug);
  if (!post) return res.status(404).json({ ok: false, error: 'Post not found' });
  const media = db.prepare('SELECT * FROM blog_media WHERE post_id = ? ORDER BY sort_order, id').all(post.id);
  // Track view
  try { db.prepare(`INSERT INTO analytics_events (event_type, post_id) VALUES ('blog_view', ?)`).run(post.id); } catch {}
  res.json({ ok: true, data: { post: { ...post, media } } });
});

module.exports = router;
