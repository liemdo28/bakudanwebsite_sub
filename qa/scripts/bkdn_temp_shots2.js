/**
 * Admin screenshots for Temp Linktree — with proper login timing
 */
const { chromium } = require('E:\\Project\\Master\\QA\\qa_runner\\node_modules\\playwright');
const fs   = require('fs');
const path = require('path');

const BASE = 'http://localhost:3000';
const OUT  = 'E:\\Project\\Master\\bkdn_temp_shots';

async function pause(ms) { return new Promise(r => setTimeout(r, ms)); }

(async () => {
  const br = await chromium.launch({ headless: true });
  const ctx = await br.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();

  await page.goto(BASE + '/links-admin', { waitUntil: 'domcontentloaded', timeout: 15000 });
  await pause(4000);

  // Set token in localStorage, then reload so SPA picks it up
  await page.evaluate(async () => {
    const r = await fetch('/api/auth/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: 'admin@bakudanramen.com', password: 'admin123' })
    });
    const d = await r.json();
    if (d.token) {
      localStorage.setItem('bkdn_token', d.token);
      localStorage.setItem('bkdn_user', JSON.stringify(d.user));
      window.location.hash = '#/pages';
      window.location.reload();
    }
  });
  await pause(5000); // wait for reload + SPA boot
  await page.screenshot({ path: path.join(OUT, '03_admin_pages_draft_badge.png'), fullPage: false });
  console.log('Shot 3: Pages list with Draft badge');

  // Get the page ID of bakudan-temp
  const tempId = await page.evaluate(async () => {
    const token = localStorage.getItem('bkdn_token');
    const r = await fetch('/api/links/pages', { headers: { Authorization: 'Bearer ' + token } });
    const d = await r.json();
    const temp = (d.data?.pages || []).find(p => p.slug === 'bakudan-temp');
    return temp?.id || null;
  });

  console.log('Temp page ID:', tempId);

  if (tempId) {
    // Open editor
    await page.evaluate(id => { window.location.hash = '#/pages/' + id; }, tempId);
    await pause(3000);
    await page.screenshot({ path: path.join(OUT, '04_temp_editor_buttons.png'), fullPage: false });
    console.log('Shot 4: Temp editor — Buttons tab');

    // Settings tab
    await page.evaluate(() => {
      const tabs = [...document.querySelectorAll('.tab')];
      const t = tabs.find(t => /settings/i.test(t.textContent));
      if (t) t.click();
    });
    await pause(800);
    await page.screenshot({ path: path.join(OUT, '05_temp_editor_settings.png'), fullPage: false });
    console.log('Shot 5: Temp editor — Settings (handle, tagline)');

    // Publish & Preview tab
    await page.evaluate(() => {
      const tabs = [...document.querySelectorAll('.tab')];
      const t = tabs.find(t => /publish/i.test(t.textContent));
      if (t) t.click();
    });
    await pause(800);
    await page.screenshot({ path: path.join(OUT, '06_temp_editor_publish_preview.png'), fullPage: false });
    console.log('Shot 6: Temp editor — Publish & Preview tab');
  }

  await ctx.close();
  await br.close();

  const files = fs.readdirSync(OUT).filter(f => f.endsWith('.png')).sort();
  console.log(`\n=== ${files.length} screenshots in ${OUT} ===`);
  files.forEach(f => {
    const kb = Math.round(fs.statSync(path.join(OUT, f)).size / 1024);
    console.log(`  ${f}  (${kb} KB)`);
  });
})().catch(e => { console.error('FATAL:', e.message); process.exit(1); });
