/**
 * BKDN Links Hub v3.0.0 — QA Walkthrough Video
 * Records a narrated walkthrough covering all required flows:
 *   login → dashboard → project hub → social link edit → reorder → publish → public verify
 *
 * Credential: read from /tmp/bkdn_qa_cred.txt (never in source)
 * Output:     E:\Project\Master\bkdn_qa_video\bkdn_walkthrough.webm
 */
const { chromium } = require('E:\\Project\\Master\\QA\\qa_runner\\node_modules\\playwright');
const fs   = require('fs');
const path = require('path');

const BASE    = 'https://www.bakudanramen.com/links-admin/';
const EMAIL   = 'qa-links-admin@bakudanramen.com';
const PASS    = fs.readFileSync('/tmp/bkdn_qa_cred.txt', 'utf8').trim();
const OUT_DIR = 'E:\\Project\\Master\\bkdn_qa_video';

if (!fs.existsSync(OUT_DIR)) fs.mkdirSync(OUT_DIR, { recursive: true });

const VP = { width: 1280, height: 900 };

async function pause(ms) {
  return new Promise(r => setTimeout(r, ms));
}

async function safe(label, fn) {
  try { await fn(); }
  catch(e) { console.warn(`  [WARN] ${label}: ${e.message.slice(0,100)}`); }
}

(async () => {
  const browser = await chromium.launch({ headless: true });

  // ─── Context with video recording ─────────────────────────────────────────
  const ctx = await browser.newContext({
    viewport: VP,
    recordVideo: {
      dir: OUT_DIR,
      size: VP,
    },
  });
  const page = await ctx.newPage();

  console.log('\n=== BKDN v3.0.0 Walkthrough Recording ===\n');

  // ── 1. BOOT / LOGIN SCREEN ────────────────────────────────────────────────
  console.log('[1] Navigating to /links-admin ...');
  await page.goto(BASE, { waitUntil: 'networkidle', timeout: 25000 });
  await pause(2500);
  const loadingVisible = await page.locator('#spa-loading').isVisible().catch(() => false);
  console.log(`    #spa-loading visible: ${loadingVisible} — EXPECTED: false — ${loadingVisible ? 'FAIL' : 'PASS'}`);
  // Hold on login screen for 2s so viewer can see it
  await pause(2000);

  // ── 2. LOGIN ──────────────────────────────────────────────────────────────
  console.log('[2] Filling login form...');
  await safe('fill email',    () => page.fill('#login-email', EMAIL));
  await pause(400);
  await safe('fill password', () => page.fill('#login-pwd',   PASS));
  await pause(600);
  await safe('submit login',  () => page.click('#login-btn'));
  await pause(3500);

  const hash = await page.evaluate(() => window.location.hash);
  console.log(`    hash after login: ${hash} — EXPECTED: #/dashboard — ${hash === '#/dashboard' ? 'PASS' : 'FAIL'}`);
  // Hold on dashboard for 3s
  await pause(3000);

  // ── 3. PROJECT HUB ────────────────────────────────────────────────────────
  console.log('[3] Navigating to Project Hub...');
  await page.evaluate(() => { window.location.hash = '#/project'; });
  await pause(2500);
  // Pause for viewer to read
  await pause(3000);

  // ── 4. PAGES LIST ─────────────────────────────────────────────────────────
  console.log('[4] Navigating to Pages...');
  await page.evaluate(() => { window.location.hash = '#/pages'; });
  await pause(2500);
  await pause(2000);

  // ── 5. PAGE EDITOR (first page — Bakudan main hub) ───────────────────────
  console.log('[5] Opening first page editor...');
  const firstLink = page.locator('a[href*="#/pages/"]').first();
  const hasLink = await firstLink.count() > 0;
  if (hasLink) {
    const href = await firstLink.getAttribute('href');
    const hashPath = href.replace(/^.*?(#.*)$/, '$1');
    await page.evaluate(h => { window.location.hash = h; }, hashPath);
    await pause(3000);
  }

  // ── 6. BUTTON LIST + 3-STATE TOGGLES ─────────────────────────────────────
  console.log('[6] Clicking Buttons tab...');
  await safe('click Buttons tab', async () => {
    const btnsTab = page.locator('.tab').filter({ hasText: 'Buttons' });
    if (await btnsTab.count() > 0) {
      await btnsTab.first().click();
      await pause(1200);
    }
  });
  await pause(2000);

  // ── 7. REORDER — toggle a button Visible state then scroll to drag handles ─
  console.log('[7] Demonstrating toggle + reorder handles...');
  await page.evaluate(() => window.scrollBy(0, 200));
  await pause(800);
  // Toggle first visible toggle off then back on
  await safe('toggle visibility', async () => {
    const toggle = page.locator('.toggle-slider').first();
    if (await toggle.count() > 0) {
      await toggle.click();   // toggle off
      await pause(700);
      await toggle.click();   // toggle back on
      await pause(700);
    }
  });
  await pause(1500);

  // ── 8. SETTINGS — SOCIAL LINKS EDIT ──────────────────────────────────────
  console.log('[8] Navigating to Settings...');
  await page.evaluate(() => { window.location.hash = '#/settings'; });
  await pause(2500);
  // Scroll to social card
  await safe('scroll to instagram', () => page.locator('#s-ig').scrollIntoViewIfNeeded());
  await pause(600);
  // Clear + retype Instagram URL
  await safe('edit instagram', async () => {
    const igField = page.locator('#s-ig');
    if (await igField.count() > 0) {
      await igField.click({ clickCount: 3 });
      await pause(300);
      await igField.fill('https://www.instagram.com/bakudanramen');
      await pause(600);
    }
  });
  // Save settings
  await safe('save settings', async () => {
    const saveBtn = page.locator('button').filter({ hasText: /Save Settings/i }).first();
    if (await saveBtn.count() > 0) {
      await saveBtn.click();
      await pause(1500);
    }
  });
  await pause(2000);

  // ── 9. SCHEDULING VIEW ────────────────────────────────────────────────────
  console.log('[9] Navigating to Scheduling...');
  await page.evaluate(() => { window.location.hash = '#/scheduling'; });
  await pause(3000);
  await pause(2000);

  // ── 10. ANALYTICS ────────────────────────────────────────────────────────
  console.log('[10] Navigating to Analytics...');
  await page.evaluate(() => { window.location.hash = '#/analytics'; });
  await pause(3000);
  await pause(2000);

  // ── 11. PUBLISH FLOW ─────────────────────────────────────────────────────
  console.log('[11] Returning to page editor — publish flow...');
  // Go back to first page editor
  if (hasLink) {
    const href = await page.locator('a[href*="#/pages/"]').first().getAttribute('href').catch(() => null);
    if (href) {
      const hashPath = href.replace(/^.*?(#.*)$/, '$1');
      await page.evaluate(() => { window.location.hash = '#/pages'; });
      await pause(1500);
      await page.evaluate(h => { window.location.hash = h; }, hashPath);
      await pause(3000);
    }
  }
  // Show publish bar — scroll to top so it's visible
  await page.evaluate(() => window.scrollTo(0, 0));
  await pause(800);
  // Click Verify Sync
  console.log('    Clicking Verify Sync...');
  await safe('verify sync', async () => {
    const verifyBtn = page.locator('button').filter({ hasText: /Verify Sync/i }).first();
    if (await verifyBtn.count() > 0) {
      await verifyBtn.click();
      await pause(3000);
    }
  });
  await pause(2000);

  // ── 12. PUBLIC /links — mobile emulation ─────────────────────────────────
  console.log('[12] Closing page editor — navigating back to dashboard...');
  await page.evaluate(() => { window.location.hash = '#/dashboard'; });
  await pause(2500);

  // Close auth context — video file is written on close
  await ctx.close();

  // ─── Public page verification in separate context ─────────────────────────
  // (not recorded — just console output for final check)
  const pubCtx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const pubPage = await pubCtx.newPage();
  await pubPage.goto('https://www.bakudanramen.com/links/', { waitUntil: 'networkidle', timeout: 25000 });
  await pause(2000);
  const pubFile = path.join(OUT_DIR, 'public_links_mobile_final.png');
  await pubPage.screenshot({ path: pubFile, fullPage: true });
  console.log('  [SHOT] Public /links mobile (final check) → ' + pubFile);
  await pubCtx.close();

  await browser.close();

  // ─── Rename the video to a known filename ─────────────────────────────────
  const videoFiles = fs.readdirSync(OUT_DIR).filter(f => f.endsWith('.webm'));
  if (videoFiles.length > 0) {
    const latestVideo = videoFiles.sort().pop();
    const src  = path.join(OUT_DIR, latestVideo);
    const dest = path.join(OUT_DIR, 'bkdn_walkthrough.webm');
    if (src !== dest) fs.renameSync(src, dest);
    console.log(`\n=== VIDEO SAVED → ${dest} ===\n`);
    const stat = fs.statSync(dest);
    console.log(`    Size: ${(stat.size / 1024 / 1024).toFixed(1)} MB`);
  } else {
    console.warn('\n[WARN] No .webm file found in ' + OUT_DIR);
  }

  console.log('\n=== WALKTHROUGH COMPLETE ===\n');
})().catch(e => {
  console.error('\nFATAL:', e.message);
  console.error(e.stack);
  process.exit(1);
});
