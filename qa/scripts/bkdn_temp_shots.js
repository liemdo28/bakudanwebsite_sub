/**
 * QA screenshots for the Temporary Linktree pages
 */
const { chromium } = require('E:\\Project\\Master\\QA\\qa_runner\\node_modules\\playwright');
const fs   = require('fs');
const path = require('path');

const BASE = 'http://localhost:3000';
const OUT  = 'E:\\Project\\Master\\bkdn_temp_shots';
if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });

async function pause(ms) { return new Promise(r => setTimeout(r, ms)); }

(async () => {
  const br = await chromium.launch({ headless: true });

  /* ── Mobile: /links-temp ── */
  const mCtx = await br.newContext({ viewport: { width: 390, height: 844 } });
  const mp   = await mCtx.newPage();
  await mp.goto(BASE + '/links-temp', { waitUntil: 'networkidle', timeout: 15000 });
  await pause(2000);
  await mp.screenshot({ path: path.join(OUT, '01_links_temp_mobile.png'), fullPage: true });
  console.log('Shot 1: /links-temp mobile (390px)');
  await mCtx.close();

  /* ── Desktop: /links-temp ── */
  const dCtx = await br.newContext({ viewport: { width: 1280, height: 900 } });
  const dp   = await dCtx.newPage();
  await dp.goto(BASE + '/links-temp', { waitUntil: 'networkidle', timeout: 15000 });
  await pause(2000);
  await dp.screenshot({ path: path.join(OUT, '02_links_temp_desktop.png'), fullPage: false });
  console.log('Shot 2: /links-temp desktop (1280px)');
  await dCtx.close();

  /* ── Admin: Pages list with Draft badge ── */
  const aCtx = await br.newContext({ viewport: { width: 1280, height: 900 } });
  const ap   = await aCtx.newPage();
  await ap.goto(BASE + '/links-admin', { waitUntil: 'domcontentloaded', timeout: 15000 });
  await pause(4000);

  // Login via evaluate
  await ap.evaluate(async () => {
    const r = await fetch('/api/auth/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: 'admin@bakudanramen.com', password: 'admin123' })
    });
    const d = await r.json();
    if (d.token) {
      localStorage.setItem('bkdn_token', d.token);
      localStorage.setItem('bkdn_user', JSON.stringify(d.user));
      window.location.hash = '#/pages';
    }
  });
  await pause(2500);
  await ap.screenshot({ path: path.join(OUT, '03_admin_pages_with_draft.png'), fullPage: false });
  console.log('Shot 3: Admin pages list — Draft badge visible');

  /* ── Admin: Temp Linktree Editor (Buttons tab) ── */
  const tempLink = await ap.evaluate(() => {
    const cards = [...document.querySelectorAll('.page-card')];
    const tempCard = cards.find(c => c.textContent.includes('bakudan-temp'));
    if (tempCard) {
      const a = tempCard.querySelector('a[href*="#/pages/"]');
      return a ? a.getAttribute('href') : null;
    }
    return null;
  });

  if (tempLink) {
    const hash = tempLink.match(/#.*/)?.[0] || '#/pages';
    await ap.evaluate(h => { window.location.hash = h; }, hash);
    await pause(2500);
    await ap.screenshot({ path: path.join(OUT, '04_temp_editor_buttons.png'), fullPage: false });
    console.log('Shot 4: Temp Linktree editor — Buttons tab');

    // Click Settings tab
    await ap.evaluate(() => {
      const tabs = [...document.querySelectorAll('.tab')];
      const t = tabs.find(t => t.textContent.trim() === 'Page Settings');
      if (t) t.click();
    });
    await pause(600);
    await ap.screenshot({ path: path.join(OUT, '05_temp_editor_settings.png'), fullPage: false });
    console.log('Shot 5: Temp Linktree — Settings tab (handle, tagline)');

    // Click Publish & Preview tab
    await ap.evaluate(() => {
      const tabs = [...document.querySelectorAll('.tab')];
      const t = tabs.find(t => t.textContent.includes('Publish'));
      if (t) t.click();
    });
    await pause(600);
    await ap.screenshot({ path: path.join(OUT, '06_temp_editor_publish_preview.png'), fullPage: false });
    console.log('Shot 6: Temp Linktree — Publish & Preview tab (schedule + token)');
  }

  await aCtx.close();
  await br.close();

  const files = fs.readdirSync(OUT).filter(f => f.endsWith('.png')).sort();
  console.log(`\n=== ${files.length} screenshots in ${OUT} ===`);
  files.forEach(f => {
    const kb = Math.round(fs.statSync(path.join(OUT, f)).size / 1024);
    console.log(`  ${f}  (${kb} KB)`);
  });
})().catch(e => { console.error('FATAL:', e.message); process.exit(1); });
