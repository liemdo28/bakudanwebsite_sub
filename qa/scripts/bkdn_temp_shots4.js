/**
 * QA screenshots — links-temp v3 + all sub-pages
 */
const { chromium } = require('E:\\Project\\Master\\QA\\qa_runner\\node_modules\\playwright');
const fs   = require('fs');
const path = require('path');

const OUT = 'E:\\Project\\Master\\bkdn_temp_shots';
if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });
const pause = ms => new Promise(r => setTimeout(r, ms));

const PAGES = [
  { url: 'https://bakudanramen.com/links-temp/',       file: 'v3_linktree_mobile.png',      vp: { width: 390, height: 844 }, full: true  },
  { url: 'https://bakudanramen.com/links-temp/',       file: 'v3_linktree_desktop.png',     vp: { width: 1280, height: 900 }, full: false },
  { url: 'https://bakudanramen.com/store-locations/',  file: 'v3_store_locations.png',      vp: { width: 390, height: 844 }, full: true  },
  { url: 'https://bakudanramen.com/order-smart/',      file: 'v3_order_smart_loading.png',  vp: { width: 390, height: 844 }, full: false },
  { url: 'https://bakudanramen.com/reservations/',     file: 'v3_reservations.png',         vp: { width: 390, height: 844 }, full: true  },
];

(async () => {
  const br = await chromium.launch({ headless: true });

  for (const p of PAGES) {
    const ctx  = await br.newContext({ viewport: p.vp });
    const page = await ctx.newPage();
    await page.goto(p.url, { waitUntil: 'networkidle', timeout: 20000 });
    await pause(1200);
    await page.screenshot({ path: path.join(OUT, p.file), fullPage: p.full });
    console.log('Shot:', p.file);
    await ctx.close();
  }

  /* QA assertions on linktree page */
  const ctx  = await br.newContext({ viewport: { width: 390, height: 844 } });
  const page = await ctx.newPage();
  await page.goto('https://bakudanramen.com/links-temp/', { waitUntil: 'networkidle', timeout: 20000 });
  await pause(800);
  const btnCount  = await page.locator('.links-btn').count();
  const noindex   = await page.locator('meta[name="robots"]').getAttribute('content');
  const titles    = await page.locator('.links-btn-title').allTextContents();
  const item1href = await page.locator('.links-btn').first().getAttribute('href');
  const enabledCount = await page.locator('.links-btn[href="#"]').count();
  await ctx.close();

  await br.close();

  console.log('\n=== QA ===');
  console.log('Button count :', btnCount);
  console.log('Noindex      :', noindex);
  console.log('Item 1 href  :', item1href);
  console.log('Placeholder  :', enabledCount, '(should be 1 — Gift Cards only)');
  console.log('Titles:');
  titles.forEach((t, i) => console.log('  ' + (i + 1) + '.', t));

  const files = fs.readdirSync(OUT).filter(f => f.startsWith('v3_')).sort();
  console.log('\n=== Screenshots ===');
  files.forEach(f => {
    const kb = Math.round(fs.statSync(path.join(OUT, f)).size / 1024);
    console.log('  ' + f + '  (' + kb + ' KB)');
  });
})().catch(e => { console.error('FATAL:', e.message); process.exit(1); });
