/**
 * SQLite database — schema + seed
 * Uses Node.js built-in node:sqlite (Node >= 22.5, stable in Node 24)
 * No native compilation required.
 */
'use strict';

const { DatabaseSync } = require('node:sqlite');
const bcrypt = require('bcryptjs');
const path   = require('path');
const fs     = require('fs');

const DATA_DIR = path.join(__dirname, '..', 'data');
if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });

const db = new DatabaseSync(path.join(DATA_DIR, 'bkdn.db'));

// WAL mode + foreign keys
db.exec('PRAGMA journal_mode = WAL');
db.exec('PRAGMA foreign_keys = ON');

/* ── helpers to match better-sqlite3 interface ─── */
// node:sqlite StatementSync already has .get(), .all(), .run() with spread args
// We add a tiny transaction helper
db.transaction = function(fn) {
  return function(...args) {
    db.exec('BEGIN');
    try { const result = fn(...args); db.exec('COMMIT'); return result; }
    catch (e) { try { db.exec('ROLLBACK'); } catch {} throw e; }
  };
};

/* ═══════════════════════════════════════════════════
   SCHEMA
═══════════════════════════════════════════════════ */
db.exec(`
  CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT    NOT NULL,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    role          TEXT    NOT NULL DEFAULT 'viewer',
    is_active     INTEGER NOT NULL DEFAULT 1,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
  );

  CREATE TABLE IF NOT EXISTS settings (
    key        TEXT PRIMARY KEY,
    value      TEXT,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
  );

  CREATE TABLE IF NOT EXISTS link_pages (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    slug              TEXT    NOT NULL UNIQUE,
    title             TEXT    NOT NULL,
    headline          TEXT,
    subheadline       TEXT,
    hero_image_url    TEXT,
    seo_title         TEXT,
    seo_description   TEXT,
    theme_json        TEXT    NOT NULL DEFAULT '{}',
    is_active         INTEGER NOT NULL DEFAULT 1,
    last_published_at TEXT,
    created_at        TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at        TEXT    NOT NULL DEFAULT (datetime('now'))
  );

  CREATE TABLE IF NOT EXISTS link_buttons (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id          INTEGER NOT NULL REFERENCES link_pages(id) ON DELETE CASCADE,
    kind             TEXT    NOT NULL DEFAULT 'link',
    platform         TEXT,
    title            TEXT    NOT NULL,
    subtitle         TEXT,
    url              TEXT,
    icon_key         TEXT,
    custom_icon_svg  TEXT,
    style_variant    TEXT    NOT NULL DEFAULT 'secondary',
    sort_order       INTEGER NOT NULL DEFAULT 0,
    visible          INTEGER NOT NULL DEFAULT 1,
    enabled          INTEGER NOT NULL DEFAULT 1,
    is_featured      INTEGER NOT NULL DEFAULT 0,
    opens_in_new_tab INTEGER NOT NULL DEFAULT 0,
    start_at         TEXT,
    end_at           TEXT,
    created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    deleted_at       TEXT
  );

  CREATE TABLE IF NOT EXISTS link_publish_snapshots (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id        INTEGER NOT NULL REFERENCES link_pages(id),
    version_number INTEGER NOT NULL DEFAULT 1,
    payload_json   TEXT    NOT NULL,
    published_by   INTEGER,
    published_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    checksum       TEXT,
    status         TEXT    NOT NULL DEFAULT 'published'
  );

  CREATE TABLE IF NOT EXISTS blog_posts (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    title              TEXT    NOT NULL,
    slug               TEXT    UNIQUE,
    status             TEXT    NOT NULL DEFAULT 'draft',
    excerpt            TEXT,
    content_html       TEXT,
    content_json       TEXT,
    caption            TEXT,
    hashtags           TEXT,
    featured_image_url TEXT,
    scheduled_at       TEXT,
    published_at       TEXT,
    author_id          INTEGER REFERENCES users(id),
    cta_label          TEXT,
    cta_url            TEXT,
    template_type      TEXT,
    created_at         TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at         TEXT    NOT NULL DEFAULT (datetime('now')),
    archived_at        TEXT
  );

  CREATE TABLE IF NOT EXISTS blog_media (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id       INTEGER NOT NULL REFERENCES blog_posts(id) ON DELETE CASCADE,
    media_type    TEXT    NOT NULL DEFAULT 'image',
    url           TEXT    NOT NULL,
    thumbnail_url TEXT,
    alt_text      TEXT,
    sort_order    INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
  );

  CREATE TABLE IF NOT EXISTS analytics_events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type   TEXT    NOT NULL,
    page_id      INTEGER,
    button_id    INTEGER,
    post_id      INTEGER,
    ip_hash      TEXT,
    user_agent   TEXT,
    referer      TEXT,
    device_type  TEXT,
    created_at   TEXT    NOT NULL DEFAULT (datetime('now'))
  );
`);

/* ═══════════════════════════════════════════════════
   MIGRATIONS — safe additions to existing schema
   Each ALTER TABLE is wrapped in try/catch; SQLite
   throws if the column already exists, which we ignore.
═══════════════════════════════════════════════════ */
[
  `ALTER TABLE link_pages ADD COLUMN status TEXT NOT NULL DEFAULT 'published'`,
  `ALTER TABLE link_pages ADD COLUMN scheduled_publish_at TEXT`,
  `ALTER TABLE link_pages ADD COLUMN handle TEXT`,
  `ALTER TABLE link_pages ADD COLUMN preview_token TEXT`,
].forEach(sql => { try { db.exec(sql); } catch {} });

/* ═══════════════════════════════════════════════════
   SEED
═══════════════════════════════════════════════════ */
function seed() {
  const userCount = db.prepare('SELECT COUNT(*) as c FROM users').get().c;
  if (userCount > 0) return;

  console.log('[db] Seeding initial data...');

  const hash = bcrypt.hashSync('admin123', 10);
  db.prepare(`INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'super_admin')`).run('Admin', 'admin@bakudanramen.com', hash);
  db.prepare(`INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'marketing_manager')`).run('Maria', 'maria@bakudanramen.com', bcrypt.hashSync('marketing123', 10));

  const settingsDefaults = {
    instagram_url: 'https://www.instagram.com/bakudanramen', instagram_visible: '1', instagram_label: '@bakudanramen',
    facebook_url:  'https://www.facebook.com/bakudanSA/',    facebook_visible:  '1', facebook_label:  'Bakudan Ramen',
    site_url:      'https://bakudanramen.com',
    links_headline:    'BAKUDAN RAMEN',
    links_subheadline: 'AUTHENTIC JAPANESE RAMEN · SAN ANTONIO',
    links_logo_url:    '',
  };
  const insertSetting = db.prepare(`INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)`);
  Object.entries(settingsDefaults).forEach(([k, v]) => insertSetting.run(k, v));

  const insertPage = db.prepare(`INSERT INTO link_pages (slug, title, headline, subheadline, is_active) VALUES (?, ?, ?, ?, 1)`);
  const bakudanId  = insertPage.run('bakudan',  'Bakudan Ramen — Links',     'BAKUDAN RAMEN',             'AUTHENTIC JAPANESE RAMEN · SAN ANTONIO').lastInsertRowid;
  const banderaId  = insertPage.run('bandera',  'Bakudan Ramen — Bandera',   'BAKUDAN RAMEN · BANDERA',   '11309 Bandera Rd Ste 111').lastInsertRowid;
  const rimId      = insertPage.run('rim',      'Bakudan Ramen — The Rim',   'BAKUDAN RAMEN · THE RIM',   '17619 La Cantera Pkwy Ste 208').lastInsertRowid;
  const stoneOakId = insertPage.run('stone-oak','Bakudan Ramen — Stone Oak', 'BAKUDAN RAMEN · STONE OAK', '22506 US Hwy 281 N Ste 106').lastInsertRowid;

  const insertBtn = db.prepare(`
    INSERT INTO link_buttons
      (page_id, kind, platform, title, subtitle, url, icon_key, style_variant, sort_order, visible, enabled, is_featured)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`);

  // Main hub buttons (migrated from live /links page)
  insertBtn.run(bakudanId,'link',  null,       'Order Online','Pickup · Delivery · Catering — tap to choose location','https://order.toasttab.com/online/bakudan-ramen','order','primary',1,1,1,1);
  insertBtn.run(bakudanId,'link',  null,       'Visit Main Website','Menu, locations, story','https://bakudanramen.com','website','secondary',2,1,1,0);
  insertBtn.run(bakudanId,'social','instagram','Instagram','@bakudanramen','https://www.instagram.com/bakudanramen','instagram','secondary',3,1,1,0);
  insertBtn.run(bakudanId,'social','facebook', 'Facebook','Bakudan Ramen','https://www.facebook.com/bakudanSA/','facebook','secondary',4,1,1,0);
  insertBtn.run(bakudanId,'link',  null,       'Merchandise','Coming Soon','','gift','secondary',5,1,0,0);

  [banderaId, rimId, stoneOakId].forEach(pid => {
    insertBtn.run(pid,'link',null,'Order Online','Pickup · Delivery · Catering','https://order.toasttab.com/online/bakudan-ramen','order','primary',1,1,1,1);
    insertBtn.run(pid,'link',null,'View Full Menu','Ramen, appetizers, drinks','https://bakudanramen.com/menu.html','menu','secondary',2,1,1,0);
    insertBtn.run(pid,'link',null,'Happy Hour Deals','Mon–Fri 3–6 PM','https://bakudanramen.com/happy-hour.html','gift','secondary',3,1,1,0);
    insertBtn.run(pid,'social','instagram','Instagram','@bakudanramen','https://www.instagram.com/bakudanramen','instagram','secondary',4,1,1,0);
    insertBtn.run(pid,'social','facebook','Facebook','Bakudan Ramen','https://www.facebook.com/bakudanSA/','facebook','secondary',5,1,1,0);
  });

  db.prepare(`UPDATE link_pages SET last_published_at = datetime('now') WHERE id = ?`).run(bakudanId);
  console.log('[db] Seed complete. Default login: admin@bakudanramen.com / admin123');
}

seed();
seedTempLinktree();

/* ═══════════════════════════════════════════════════
   SEED — Bakudan Temporary Linktree (Draft / Private)
   Created once; idempotent on re-runs.
═══════════════════════════════════════════════════ */
function seedTempLinktree() {
  const exists = db.prepare(`SELECT id FROM link_pages WHERE slug = 'bakudan-temp'`).get();
  if (exists) return;

  console.log('[db] Seeding Bakudan Temporary Linktree...');

  const pageId = db.prepare(`
    INSERT INTO link_pages
      (slug, title, handle, headline, subheadline, status, is_active)
    VALUES (?, ?, ?, ?, ?, 'draft', 0)
  `).run(
    'bakudan-temp',
    'Bakudan Temporary Linktree',
    '@bakudanramen',
    'Bakudan Ramen',
    'Bold Japanese. Texas soul. 3 locations.'
  ).lastInsertRowid;

  // icon stored in custom_icon_svg as emoji text (admin can replace with SVG later)
  const b = db.prepare(`
    INSERT INTO link_buttons
      (page_id, title, subtitle, url, custom_icon_svg, style_variant,
       sort_order, visible, enabled, is_featured)
    VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)`);

  // Required order from spec (Section 8)
  b.run(pageId, 'MIDWEEK RAMEN NIGHT — $22 Mon/Tue/Wed', 'Limited-time weekly special',           '',                                           '🍜', 'primary',    1, 0, 1);
  b.run(pageId, 'View Menu',                              'Full ramen, appetizers & drinks menu',  'https://bakudanramen.com/menu.html',          '📖', 'secondary',  2, 0, 0);
  b.run(pageId, 'Order Delivery',                         'Choose the best location near you',     '',                                           '🛵', 'secondary',  3, 0, 0);
  b.run(pageId, 'Find Your Bakudan',                      'RIM · Stone Oak · Bandera',             '',                                           '📍', 'secondary',  4, 0, 0);
  b.run(pageId, 'Make a Reservation',                     'Book your table',                       '',                                           '📅', 'secondary',  5, 0, 0);
  b.run(pageId, 'Join Bakudan Rewards',                   'Earn Double on Midweek',                'https://www.toasttab.com/bakudanramen/rewardsSignup', '🎟️','secondary',  6, 1, 1);
  b.run(pageId, 'Gift Cards',                             'Give the gift of Bakudan',              '',                                           '🎁', 'secondary',  7, 0, 0);
  b.run(pageId, 'Current Specials',                       'See our latest deals & promotions',     '',                                           '🔥', 'secondary',  8, 0, 0);
  b.run(pageId, 'Catering & Group Orders',                'Office lunches, events, parties',       '',                                           '🍱', 'secondary',  9, 0, 0);
  b.run(pageId, 'Tag Us — @bakudanramen',                 'Share your Bakudan moment',             'https://www.instagram.com/bakudanramen',      '📸', 'secondary', 10, 1, 1);
  b.run(pageId, "We're Hiring",                           'Join our team',                         '',                                           '👥', 'secondary', 11, 0, 0);
  b.run(pageId, 'Contact Us / Feedback',                  'We want to hear from you',              '',                                           '💬', 'secondary', 12, 0, 0);

  console.log('[db] Temporary Linktree seeded (status: draft, 12 buttons). Slug: bakudan-temp');
}

module.exports = db;
