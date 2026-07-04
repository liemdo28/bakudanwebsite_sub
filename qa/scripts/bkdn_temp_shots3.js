/**
 * QA screenshots for /links-temp v2 — live + local
 */
const { chromium } = require('E:\\Project\\Master\\QA\\qa_runner\\node_modules\\playwright');
const fs   = require('fs');
const path = require('path');

const OUT = 'E:\\Project\\Master\\bkdn_temp_shots';
if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });

async function pause(ms) { return new Promise(r => setTimeout(r, ms)); }

(async () => {
  const br = await chromium.launch({ headless: true });

  /* ── Mobile: live production ── */
  const mCtx = await br.newContext({ viewport: { width: 390, height: 844 } });
  const mp   = await mCtx.newPage();
  await mp.goto('https://bakudanramen.com/links-temp/', { waitUntil: 'networkidle', timeout: 20000 });
  await pause(1500);
  await mp.screenshot({ path: path.join(OUT, 'temp_v2_mobile.png'), fullPage: true });
  console.log('Mobile shot: temp_v2_mobile.png');
  await mCtx.close();

  /* ── Desktop: live production ── */
  const dCtx = await br.newContext({ viewport: { width: 1280, height: 900 } });
  const dp   = await dCtx.newPage();
  await dp.goto('https://bakudanramen.com/links-temp/', { waitUntil: 'networkidle', timeout: 20000 });
  await pause(1500);
  await dp.screenshot({ path: path.join(OUT, 'temp_v2_desktop.png'), fullPage: false });

  // QA assertions
  const btnCount = await dp.locator('.links-btn').count();
  const noindex  = await dp.locator('meta[name="robots"]').getAttribute('content');
  const titles   = await dp.locator('.links-btn-title').allTextContents();

  console.log('Desktop shot: temp_v2_desktop.png');
  console.log('Button count:', btnCount);
  console.log('Noindex meta:', noindex);
  console.log('Titles:', JSON.stringify(titles, null, 2));
  await dCtx.close();

  await br.close();

  const files = fs.readdirSync(OUT).filter(f => f.startsWith('temp_v2')).sort();
  console.log('\n=== Screenshots ===');
  files.forEach(f => {
    const kb = Math.round(fs.statSync(path.join(OUT, f)).size / 1024);
    console.log(`  ${f}  (${kb} KB)`);
  });
})().catch(e => { console.error('FATAL:', e.message); process.exit(1); });
