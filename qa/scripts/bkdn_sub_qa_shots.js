/**
 * bakudanramen.com-current — QA Screenshot Suite
 * Captures all 14 required proof screenshots
 */
const { chromium } = require('E:\\Project\\Master\\QA\\qa_runner\\node_modules\\playwright');
const fs   = require('fs');
const path = require('path');

const BASE     = 'http://localhost:3000';
const EMAIL    = 'admin@bakudanramen.com';
const PASSWORD = 'admin123';
const OUT_DIR  = 'E:\\Project\\Master\\bkdn_sub_qa_shots';

if (!fs.existsSync(OUT_DIR)) fs.mkdirSync(OUT_DIR, { recursive: true });

let idx = 1;
async function shot(page, name, desc) {
  const file = path.join(OUT_DIR, `${String(idx).padStart(2,'0')}_${name}.png`);
  idx++;
  await page.screenshot({ path: file, fullPage: false }).catch(e => console.warn('  shot fail:', e.message));
  console.log(`  [SHOT ${idx-1}] ${desc}`);
  return file;
}

async function safe(label, fn) {
  try { await fn(); }
  catch(e) { console.warn(`  [WARN] ${label}: ${e.message.slice(0,100)}`); }
}

async function pause(ms) { return new Promise(r => setTimeout(r, ms)); }

(async () => {
  const browser = await chromium.launch({ headless: true });
  const VP      = { width: 1280, height: 900 };
  const ctx     = await browser.newContext({ viewport: VP });
  const page    = await ctx.newPage();

  console.log('\n=== bakudanramen.com-current QA Screenshots ===\n');

  // ─ 1. Public /links ─────────────────────────────────────
  console.log('[1] Public /links page');
  await page.goto(BASE + '/links', { waitUntil: 'networkidle', timeout: 15000 });
  await pause(2000);
  await shot(page, 'public_links', '/links — public page with buttons');

  // ─ Login ────────────────────────────────────────────────
  console.log('[2] Admin login');
  await page.goto(BASE + '/links-admin', { waitUntil: 'domcontentloaded', timeout: 15000 });
  await pause(3000); // wait for SPA boot
  await shot(page, 'login_screen', 'Admin login screen');

  await safe('fill email', () => page.fill('#login-email', EMAIL));
  await safe('fill pwd',   () => page.fill('#login-pwd', PASSWORD));
  await safe('submit',     () => page.click('#login-btn'));
  await pause(3500);

  const hash = await page.evaluate(() => window.location.hash);
  console.log(`    hash after login: ${hash} — ${hash.includes('dashboard') ? 'PASS' : 'FAIL'}`);

  // ─ 2. Dashboard ──────────────────────────────────────────
  console.log('[3] Dashboard');
  await pause(1000);
  await shot(page, 'dashboard', 'Dashboard — KPI cards + quick actions');

  // ─ 3. Sidebar user + version metadata ─────────────────
  console.log('[4] Sidebar user section with version');
  await shot(page, 'sidebar_version', 'Sidebar — user section with version/deploy metadata');

  // ─ 4. Project Overview ───────────────────────────────────
  console.log('[5] Project Overview');
  await page.evaluate(() => { window.location.hash = '#/project'; });
  await pause(1500);
  await shot(page, 'project_hub', 'Project Overview — ecosystem, resources, stores');

  // ─ 5. Pages & Buttons list ──────────────────────────────
  console.log('[6] Pages & Buttons');
  await page.evaluate(() => { window.location.hash = '#/pages'; });
  await pause(2000);
  await shot(page, 'pages_list', 'Pages & Buttons — all 4 store pages');

  // ─ 6. Page editor with publish bar + button list ─────────
  console.log('[7] Page editor + publish bar');
  const firstPage = await page.locator('a[href*="#/pages/"]').first();
  if (await firstPage.count() > 0) {
    const href = await firstPage.getAttribute('href');
    await page.evaluate(h => { window.location.hash = h; }, href.replace(/.*?(#.*)/, '$1'));
    await pause(2500);
    await shot(page, 'page_editor_publish_bar', 'Page editor — publish bar (Save Draft / Publish / Verify Sync)');

    // Scroll to show reorder handles
    await page.evaluate(() => window.scrollBy(0, 150));
    await pause(400);
    await shot(page, 'button_list_3state', 'Button list — 3-state toggles (Visible / Enabled / Featured) + drag handles');
  }

  // ─ 7. Scheduling ────────────────────────────────────────
  console.log('[8] Scheduling');
  await page.evaluate(() => { window.location.hash = '#/scheduling'; });
  await pause(2500);
  await shot(page, 'scheduling_view', 'Scheduling — grouped by state');

  // ─ 8. Blog list ─────────────────────────────────────────
  console.log('[9] Blog list');
  await page.evaluate(() => { window.location.hash = '#/blog'; });
  await pause(2000);
  await shot(page, 'blog_list', 'Blog — post list with status badges');

  // ─ 9. Blog editor (new post) ────────────────────────────
  console.log('[10] Blog editor');
  await page.evaluate(() => { window.location.hash = '#/blog/new'; });
  await pause(3000); // wait for Quill to init
  await shot(page, 'blog_editor', 'Blog Composer — new post editor');

  // ─ 10. Rich text toolbar ────────────────────────────────
  console.log('[11] Rich text toolbar');
  await page.evaluate(() => window.scrollTo(0, 300));
  await pause(500);
  await shot(page, 'rich_text_toolbar', 'Rich text editor — Quill toolbar visible');

  // ─ 11. Emoji picker ─────────────────────────────────────
  console.log('[12] Emoji picker');
  await safe('open emoji', () => page.evaluate(() => { const btn = document.querySelector('.emoji-picker-container button'); if (btn) btn.click(); }));
  await pause(500);
  await shot(page, 'emoji_picker', 'Emoji picker — food/celebration/business categories');
  await safe('close emoji', () => page.evaluate(() => { const grid = document.getElementById('emoji-grid'); if (grid) grid.classList.remove('open'); }));

  // ─ 12. Settings / Social Links ──────────────────────────
  console.log('[13] Settings — Social Links');
  await page.evaluate(() => { window.location.hash = '#/settings'; });
  await pause(2000);
  await shot(page, 'settings_social_links', 'Settings — Social Links card (Instagram + Facebook)');

  // ─ 13. Version / deploy metadata (zoom into sidebar) ────
  console.log('[14] Version/deploy metadata');
  await page.evaluate(() => { window.location.hash = '#/dashboard'; });
  await pause(1000);
  // Scroll sidebar to show user section
  await shot(page, 'version_deploy_metadata', 'Version + deploy metadata under user section in sidebar');

  await ctx.close();

  // ─ Public blog ──────────────────────────────────────────
  console.log('[15] Public blog page');
  const pubCtx  = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const pubPage = await pubCtx.newPage();
  await pubPage.goto(BASE + '/blog-cms', { waitUntil: 'networkidle', timeout: 15000 });
  await pause(2000);
  await pubPage.screenshot({ path: path.join(OUT_DIR, `${String(idx).padStart(2,'0')}_public_blog_mobile.png`), fullPage: true });
  console.log(`  [SHOT ${idx}] Public blog — mobile`);
  idx++;
  await pubCtx.close();

  await browser.close();

  const files = fs.readdirSync(OUT_DIR).filter(f => f.endsWith('.png')).sort();
  console.log(`\n=== COMPLETE — ${files.length} screenshots saved to ${OUT_DIR} ===\n`);
  files.forEach(f => console.log('  ' + f));
})().catch(e => {
  console.error('\nFATAL:', e.message);
  process.exit(1);
});
