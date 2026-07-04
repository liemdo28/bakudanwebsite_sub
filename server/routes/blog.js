'use strict';
const express = require('express');
const path    = require('path');
const fs      = require('fs');
const multer  = require('multer');
const db      = require('../db');
const { requireAuth } = require('../middleware/auth');

const router = express.Router();
router.use(requireAuth);

/* ── File upload config ──────────────────────────── */
const UPLOADS_DIR = path.join(__dirname, '..', '..', 'uploads');
if (!fs.existsSync(UPLOADS_DIR)) fs.mkdirSync(UPLOADS_DIR, { recursive: true });

const storage = multer.diskStorage({
  destination: (req, file, cb) => cb(null, UPLOADS_DIR),
  filename:    (req, file, cb) => {
    const ext  = path.extname(file.originalname).toLowerCase();
    const safe = path.basename(file.originalname, ext).replace(/[^a-z0-9]/gi, '-').toLowerCase();
    cb(null, `${Date.now()}-${safe}${ext}`);
  },
});
const ALLOWED_TYPES = /^(image\/(jpeg|jpg|png|webp|gif)|video\/(mp4|mov|quicktime))$/;
const upload = multer({
  storage,
  limits: { fileSize: 100 * 1024 * 1024 }, // 100 MB
  fileFilter: (req, file, cb) => {
    if (ALLOWED_TYPES.test(file.mimetype)) return cb(null, true);
    cb(new Error('Invalid file type. Allowed: JPG, PNG, WEBP, MP4, MOV'));
  },
});

/* ── helpers ─────────────────────────────────────── */
function slugify(str) {
  return str.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 80);
}

function getPost(id) {
  const post  = db.prepare('SELECT * FROM blog_posts WHERE id = ?').get(id);
  if (!post) return null;
  const media = db.prepare('SELECT * FROM blog_media WHERE post_id = ? ORDER BY sort_order, id').all(id);
  return { ...post, media };
}

/* ── Posts ───────────────────────────────────────── */

// GET /api/blog/posts
router.get('/posts', (req, res) => {
  const { status, search } = req.query;
  let sql = 'SELECT p.*, u.name as author_name FROM blog_posts p LEFT JOIN users u ON u.id = p.author_id WHERE p.archived_at IS NULL';
  const params = [];
  if (status) { sql += ' AND p.status = ?'; params.push(status); }
  if (search) { sql += ' AND (p.title LIKE ? OR p.excerpt LIKE ?)'; params.push(`%${search}%`, `%${search}%`); }
  sql += ' ORDER BY COALESCE(p.scheduled_at, p.created_at) DESC';
  const posts = db.prepare(sql).all(...params);
  res.json({ ok: true, data: { posts } });
});

// GET /api/blog/posts/:id
router.get('/posts/:id', (req, res) => {
  const post = getPost(req.params.id);
  if (!post) return res.status(404).json({ ok: false, error: 'Post not found' });
  res.json({ ok: true, data: { post } });
});

// POST /api/blog/posts
router.post('/posts', (req, res) => {
  const { title, excerpt='', content_html='', content_json=null, caption='', hashtags='',
          featured_image_url=null, cta_label=null, cta_url=null, template_type=null,
          status='draft', scheduled_at=null } = req.body || {};
  if (!title) return res.status(400).json({ ok: false, error: 'title required' });

  let slug = slugify(title);
  // Ensure unique slug
  let counter = 0;
  while (db.prepare('SELECT id FROM blog_posts WHERE slug = ?').get(slug + (counter ? `-${counter}` : ''))) counter++;
  if (counter) slug = `${slug}-${counter}`;

  const r = db.prepare(`
    INSERT INTO blog_posts
      (title, slug, status, excerpt, content_html, content_json, caption, hashtags,
       featured_image_url, cta_label, cta_url, template_type, scheduled_at, author_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(title, slug, status, excerpt, content_html, content_json ? JSON.stringify(content_json) : null,
         caption, hashtags, featured_image_url, cta_label, cta_url, template_type,
         scheduled_at || null, req.user.id);
  res.status(201).json({ ok: true, data: { post: getPost(r.lastInsertRowid) } });
});

// PUT /api/blog/posts/:id
router.put('/posts/:id', (req, res) => {
  const post = db.prepare('SELECT id FROM blog_posts WHERE id = ?').get(req.params.id);
  if (!post) return res.status(404).json({ ok: false, error: 'Post not found' });

  const fields = ['title','slug','excerpt','content_html','content_json','caption','hashtags',
                  'featured_image_url','cta_label','cta_url','template_type','status','scheduled_at'];
  const sets = [], vals = [];
  fields.forEach(f => {
    if (req.body[f] !== undefined) {
      sets.push(`${f} = ?`);
      vals.push(f === 'content_json' && req.body[f] && typeof req.body[f] === 'object' ? JSON.stringify(req.body[f]) : req.body[f]);
    }
  });
  if (!sets.length) return res.status(400).json({ ok: false, error: 'No fields to update' });
  sets.push(`updated_at = datetime('now')`);
  vals.push(req.params.id);
  db.prepare(`UPDATE blog_posts SET ${sets.join(', ')} WHERE id = ?`).run(...vals);
  res.json({ ok: true, data: { post: getPost(req.params.id) } });
});

// DELETE /api/blog/posts/:id
router.delete('/posts/:id', (req, res) => {
  const r = db.prepare(`UPDATE blog_posts SET archived_at = datetime('now'), status = 'archived' WHERE id = ?`).run(req.params.id);
  if (!r.changes) return res.status(404).json({ ok: false, error: 'Post not found' });
  res.json({ ok: true });
});

// POST /api/blog/posts/:id/duplicate
router.post('/posts/:id/duplicate', (req, res) => {
  const post = getPost(req.params.id);
  if (!post) return res.status(404).json({ ok: false, error: 'Post not found' });
  let slug = slugify(post.title + '-copy');
  let c = 0;
  while (db.prepare('SELECT id FROM blog_posts WHERE slug = ?').get(slug + (c ? `-${c}` : ''))) c++;
  if (c) slug = `${slug}-${c}`;
  const r = db.prepare(`
    INSERT INTO blog_posts
      (title, slug, status, excerpt, content_html, content_json, caption, hashtags,
       featured_image_url, cta_label, cta_url, template_type, author_id)
    VALUES (?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(post.title + ' (copy)', slug, post.excerpt, post.content_html, post.content_json,
         post.caption, post.hashtags, post.featured_image_url,
         post.cta_label, post.cta_url, post.template_type, req.user.id);
  res.status(201).json({ ok: true, data: { post: getPost(r.lastInsertRowid) } });
});

// POST /api/blog/posts/:id/publish
router.post('/posts/:id/publish', (req, res) => {
  const r = db.prepare(`
    UPDATE blog_posts SET status = 'published', published_at = datetime('now'), updated_at = datetime('now')
    WHERE id = ? AND archived_at IS NULL
  `).run(req.params.id);
  if (!r.changes) return res.status(404).json({ ok: false, error: 'Post not found' });
  res.json({ ok: true, data: { post: getPost(req.params.id) } });
});

// POST /api/blog/posts/:id/schedule
router.post('/posts/:id/schedule', (req, res) => {
  const { scheduled_at } = req.body || {};
  if (!scheduled_at) return res.status(400).json({ ok: false, error: 'scheduled_at required' });
  const r = db.prepare(`
    UPDATE blog_posts SET status = 'scheduled', scheduled_at = ?, updated_at = datetime('now')
    WHERE id = ? AND archived_at IS NULL
  `).run(scheduled_at, req.params.id);
  if (!r.changes) return res.status(404).json({ ok: false, error: 'Post not found' });
  res.json({ ok: true, data: { post: getPost(req.params.id) } });
});

// POST /api/blog/posts/:id/archive
router.post('/posts/:id/archive', (req, res) => {
  const r = db.prepare(`
    UPDATE blog_posts SET status = 'archived', archived_at = datetime('now') WHERE id = ?
  `).run(req.params.id);
  if (!r.changes) return res.status(404).json({ ok: false, error: 'Post not found' });
  res.json({ ok: true });
});

/* ── Media ───────────────────────────────────────── */

// POST /api/blog/media/upload
router.post('/media/upload', upload.single('file'), (req, res) => {
  if (!req.file) return res.status(400).json({ ok: false, error: 'No file uploaded' });
  const { post_id, alt_text = '', sort_order = 0 } = req.body || {};
  const isVideo = req.file.mimetype.startsWith('video/');
  const url     = `/uploads/${req.file.filename}`;

  let mediaId = null;
  if (post_id) {
    const r = db.prepare(`
      INSERT INTO blog_media (post_id, media_type, url, alt_text, sort_order)
      VALUES (?, ?, ?, ?, ?)
    `).run(post_id, isVideo ? 'video' : 'image', url, alt_text, sort_order);
    mediaId = r.lastInsertRowid;
  }
  res.json({ ok: true, data: { url, media_type: isVideo ? 'video' : 'image', id: mediaId } });
});

// DELETE /api/blog/media/:id
router.delete('/media/:id', (req, res) => {
  const media = db.prepare('SELECT * FROM blog_media WHERE id = ?').get(req.params.id);
  if (!media) return res.status(404).json({ ok: false, error: 'Media not found' });
  // Delete file from disk
  const filePath = path.join(__dirname, '..', '..', media.url.replace(/^\//, ''));
  if (fs.existsSync(filePath)) fs.unlinkSync(filePath);
  db.prepare('DELETE FROM blog_media WHERE id = ?').run(req.params.id);
  res.json({ ok: true });
});

module.exports = router;
