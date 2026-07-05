/**
 * BKDN Links Hub v3.0.0 — Full QA Screenshot Suite
 * Credentials read from /tmp/bkdn_qa_cred.txt (never in source)
 */
const { chromium } = require('E:\\Project\\Master\\QA\\qa_runner\\node_modules\\playwright');
const fs = require('fs');
const path = require('path');

const BASE    = 'https://www.bakudanramen.com/links-admin/';
const EMAIL   = 'qa-links-admin@bakudanramen.com';
const PASS    = fs.readFileSync('/tmp/bkdn_qa_cred.txt', 'utf8').trim();
const OUT_DIR = 'E:\\Project\\Master\\bkdn_qa_shots';

if (!fs.existsSync(OUT_DIR)) fs.mkdirSync(OUT_DIR, { recursive: true });

let shotIdx = 1;
async function shot(page, name, desc) {
  const file = path.join(OUT_DIR, `${String(shotIdx).padStart(2,'0')}_${name}.png`);
  shotIdx++;
  await page.screenshot({ path: file, fullPage: false }).catch(e => console.warn('  shot fail:', e.message));
  console.log(`  [SHOT] ${desc}`);
  return file;
}

async function safe(label, fn) {
  try { await fn(); }
  catch(e) { console.warn(`  [WARN] ${label}: ${e.message.slice(0,100)}`); }
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const VP = { width: 1280, height: 900 };

  /* ══════════════════════════════════════════════════════════════
     CONTEXT 1 — Authenticated session
  ══════════════════════════════════════════════════════════════ */
  const ctx = await browser.newContext({ viewport: VP });
  const page = await ctx.newPage();
  console.log('\n=== BKDN v3.0.0 QA Screenshot Suite ===\n');

  /* 1. Boot proof */
  console.log('[1] Boot / login screen');
  await page.goto(BASE, { waitUntil: 'networkidle', timeout: 25000 });
  await page.waitForTimeout(2500);
  const loadingVisible = await page.locator('#spa-loading').isVisible().catch(() => false);
  console.log(`    #spa-loading visible: ${loadingVisible} — EXPECTED: false — ${loadingVisible ? 'FAIL' : 'PASS'}`);
  await shot(page, 'login_screen', 'Login screen (not stuck on loading shell)');

  /* 2. Login */
  console.log('[2] Logging in');
  await safe('fill email',    () => page.fill('#login-email', EMAIL));
  await safe('fill password', () => page.fill('#login-pwd', PASS));
  await shot(page, 'login_form_filled', 'Login form — fields filled (password masked)');
  await safe('submit login',  () => page.click('#login-btn'));
  await page.waitForTimeout(3500);
  const currentHash = await page.evaluate(() => window.location.hash);
  console.log(`    hash after login: ${currentHash}`);
  await shot(page, 'dashboard', 'Dashboard — KPI cards, quick actions, page overview');

  /* 3. Sidebar + topbar */
  console.log('[3] Sidebar & topbar');
  await shot(page, 'sidebar_topbar', 'Sidebar nav + topbar (navy blue active state)');

  /* 4. Sidebar version strip — scroll sidebar to bottom */
  console.log('[4] Sidebar version strip');
  await safe('scroll sidebar', () =>
    page.evaluate(() => {
      const sb = document.querySelector('.sidebar');
      if (sb) sb.scrollTop = sb.scrollHeight;
    })
  );
  await page.waitForTimeout(400);
  await shot(page, 'sidebar_version_strip', 'Sidebar footer — v3.0.0 + deployed date');

  /* 5. Project Hub */
  console.log('[5] Project Hub (#/project)');
  await page.evaluate(() => { window.location.hash = '#/project'; });
  await page.waitForTimeout(2500);
  await shot(page, 'project_hub', 'Project Hub — ecosystem overview, resources, stores');

  /* 6. Pages list */
  console.log('[6] Pages & Buttons (#/pages)');
  await page.evaluate(() => { window.location.hash = '#/pages'; });
  await page.waitForTimeout(2500);
  await shot(page, 'pages_list', 'Pages list — all link pages with actions');

  /* 7. Page editor — first page */
  console.log('[7] Page editor + publish bar');
  const firstLink = await page.locator('a[href*="#/pages/"]').first();
  if (await firstLink.count() > 0) {
    const href = await firstLink.getAttribute('href');
    await page.evaluate(h => { window.location.hash = h; }, href.replace(/^.*?(#.*)$/, '$1'));
    await page.waitForTimeout(3000);
    await shot(page, 'page_editor_publish_bar', 'Page editor with publish bar (Save Draft / Publish / Verify Sync)');

    /* Buttons tab */
    const btnsTab = page.locator('.tab').filter({ hasText: 'Buttons' });
    if (await btnsTab.count() > 0) {
      await btnsTab.first().click();
      await page.waitForTimeout(1000);
      await shot(page, 'button_list_3state', 'Button list — 3-state toggles (Visible / Enabled / Featured)');
    }

    /* Scroll to show reorder handles */
    await page.evaluate(() => window.scrollBy(0, 200));
    await page.waitForTimeout(300);
    await shot(page, 'reorder_handles', 'Button rows with drag-reorder handles visible');
  }

  /* 8. Scheduling view */
  console.log('[8] Scheduling (#/scheduling)');
  await page.evaluate(() => { window.location.hash = '#/scheduling'; });
  await page.waitForTimeout(4000);
  await shot(page, 'scheduling_view', 'Scheduling view — grouped by state');

  /* 9. Settings — Social Links */
  console.log('[9] Settings / Social Links (#/settings)');
  await page.evaluate(() => { window.location.hash = '#/settings'; });
  await page.waitForTimeout(2500);
  await shot(page, 'settings_full', 'Settings — full view');
  /* Scroll to social section */
  await safe('scroll to social',
    () => page.locator('#s-ig').scrollIntoViewIfNeeded()
  );
  await page.waitForTimeout(400);
  await shot(page, 'settings_social_links', 'Settings — Social Links card (Instagram + Facebook)');

  /* 10. Analytics */
  console.log('[10] Analytics (#/analytics)');
  await page.evaluate(() => { window.location.hash = '#/analytics'; });
  await page.waitForTimeout(3000);
  await shot(page, 'analytics_view', 'Analytics — global traffic overview');

  /* 11. Sidebar nav — verify active state per route */
  console.log('[11] Sidebar nav active state');
  await page.evaluate(() => { window.location.hash = '#/scheduling'; });
  await page.waitForTimeout(800);
  await shot(page, 'sidebar_scheduling_active', 'Sidebar with Scheduling link active (navy blue)');

  await ctx.close();

  /* ══════════════════════════════════════════════════════════════
     CONTEXT 2 — Public /links (mobile viewport)
  ══════════════════════════════════════════════════════════════ */
  console.log('[12] Public /links (mobile)');
  const pubCtx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const pubPage = await pubCtx.newPage();
  await pubPage.goto('https://www.bakudanramen.com/links/', { waitUntil: 'networkidle', timeout: 25000 });
  await pubPage.waitForTimeout(2500);
  const pubFile = path.join(OUT_DIR, `${String(shotIdx).padStart(2,'0')}_public_links_mobile.png`);
  shotIdx++;
  await pubPage.screenshot({ path: pubFile, fullPage: true });
  console.log('  [SHOT] Public /links — mobile full-page');
  await pubCtx.close();

  /* ══════════════════════════════════════════════════════════════
     CONTEXT 3 — Error fallback (app.js blocked)
  ══════════════════════════════════════════════════════════════ */
  console.log('[13] Error fallback — blocking app.js (10-second watchdog test)');
  const errCtx = await browser.newContext({ viewport: VP });
  const errPage = await errCtx.newPage();
  await errPage.route('**/app.js**', route => route.abort());
  await errPage.goto(BASE, { waitUntil: 'domcontentloaded', timeout: 15000 });
  console.log('     Waiting 11 seconds for watchdog to fire…');
  await errPage.waitForTimeout(11000);
  const errFile = path.join(OUT_DIR, `${String(shotIdx).padStart(2,'0')}_error_fallback_watchdog.png`);
  shotIdx++;
  await errPage.screenshot({ path: errFile });
  const errVisible = await errPage.locator('#spa-loading-err').isVisible().catch(() => false);
  console.log(`  [SHOT] Error fallback state — #spa-loading-err visible: ${errVisible} — ${errVisible ? 'PASS' : 'FAIL'}`);
  await errCtx.close();

  await browser.close();

  /* ══════════════════════════════════════════════════════════════
     Summary
  ══════════════════════════════════════════════════════════════ */
  const files = fs.readdirSync(OUT_DIR).filter(f => f.endsWith('.png')).sort();
  console.log(`\n=== COMPLETE — ${files.length} screenshots saved to ${OUT_DIR} ===\n`);
  files.forEach(f => console.log('  ' + f));
})().catch(e => {
  console.error('\nFATAL:', e.message);
  console.error(e.stack);
  process.exit(1);
});
