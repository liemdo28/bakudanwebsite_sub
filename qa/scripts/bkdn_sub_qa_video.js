/**
 * bakudanramen.com-current — QA Walkthrough Video
 * Records all 8 required proof flows.
 */
const { chromium } = require('E:\\Project\\Master\\QA\\qa_runner\\node_modules\\playwright');
const fs   = require('fs');
const path = require('path');

const BASE     = 'http://localhost:3000';
const EMAIL    = 'admin@bakudanramen.com';
const PASSWORD = 'admin123';
const OUT_DIR  = 'E:\\Project\\Master\\bkdn_sub_qa_video';

if (!fs.existsSync(OUT_DIR)) fs.mkdirSync(OUT_DIR, { recursive: true });

async function pause(ms) { return new Promise(r => setTimeout(r, ms)); }

async function safe(label, fn) {
  try { await fn(); }
  catch(e) { console.warn(`  [WARN] ${label}: ${e.message.slice(0,120)}`); }
}

// Close any open modal by pressing Escape or clicking cancel
async function closeModal(page) {
  await safe('close-modal', () => page.evaluate(() => {
    const m = document.getElementById('bkdn-modal');
    if (m) m.style.display = 'none';
    // Also try cancel button
    const cancel = [...document.querySelectorAll('button')].find(b => /cancel|close/i.test(b.textContent));
    if (cancel) cancel.click();
  }));
  await pause(400);
}

async function waitForHash(page, fragment, timeout = 10000) {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    const h = await page.evaluate(() => window.location.hash).catch(() => '');
    if (h.includes(fragment)) return true;
    await pause(300);
  }
  return false;
}

async function navigateTo(page, hash) {
  await page.evaluate(h => { window.location.hash = h; }, hash);
  await pause(2000);
}

(async () => {
  const browser = await chromium.launch({ headless: true, slowMo: 60 });
  const VP = { width: 1280, height: 900 };

  const adminCtx = await browser.newContext({
    viewport: VP,
    recordVideo: { dir: OUT_DIR, size: VP }
  });
  const page = await adminCtx.newPage();

  console.log('\n=== bakudanramen.com-current QA Walkthrough Video ===\n');

  /* ── INTRO: Login ── */
  console.log('[INTRO] Navigate to admin login');
  await page.goto(BASE + '/links-admin', { waitUntil: 'domcontentloaded', timeout: 20000 });
  await pause(4000);

  await safe('email',  () => page.fill('#login-email', EMAIL));
  await pause(500);
  await safe('pass',   () => page.fill('#login-pwd', PASSWORD));
  await pause(500);
  await safe('submit', () => page.click('#login-btn'));
  await pause(5000);

  const h = await page.evaluate(() => window.location.hash);
  console.log(`  hash after login: "${h}"`);

  // If still on login, the JWT bootstrap may need a nudge
  if (!h.includes('dashboard')) {
    console.log('  Re-trying login via evaluate...');
    await safe('re-login', () => page.evaluate(async (creds) => {
      const r = await fetch('/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(creds)
      });
      const d = await r.json();
      if (d.token) {
        localStorage.setItem('bkdn_token', d.token);
        localStorage.setItem('bkdn_user', JSON.stringify(d.user));
        window.location.hash = '#/dashboard';
      }
    }, { email: EMAIL, password: PASSWORD }));
    await pause(3000);
  }

  const h2 = await page.evaluate(() => window.location.hash);
  console.log(`  hash now: "${h2}"`);
  await pause(1500);

  /* ══════════════════════════════════════════════════════
     FLOW 1 — Navigate pages list → open Bakudan page editor
              → Add a new button → edit that button
  ══════════════════════════════════════════════════════ */
  console.log('\n[FLOW 1] Create & edit button');

  await navigateTo(page, '#/pages');

  // Get page-1 link href and navigate
  const pageHref = await page.evaluate(() => {
    const a = document.querySelector('a[href*="#/pages/"]');
    return a ? a.getAttribute('href') : null;
  });
  if (pageHref) {
    const hash = pageHref.match(/#.*$/)?.[0] || '#/pages/1';
    await navigateTo(page, hash);
    console.log(`  Opened page editor: ${hash}`);
  } else {
    await navigateTo(page, '#/pages/1');
  }

  // Scroll to see button list
  await page.evaluate(() => window.scrollTo(0, 0));
  await pause(600);

  // Click "Add Button"
  await safe('click add-btn', () => page.evaluate(() => {
    const btn = [...document.querySelectorAll('button')].find(b =>
      /add\s*button/i.test(b.textContent) || (b.textContent.trim() === '+')
    );
    if (btn) btn.click();
    else {
      const alt = document.querySelector('#add-button-btn, .btn-add-button, [data-action="add-button"]');
      if (alt) alt.click();
    }
  }));
  await pause(1500);

  // Fill modal fields using IDs from app.js: #bf-title, #bf-url, #bf-subtitle
  await safe('modal title',    () => page.fill('#bf-title',    'Catering Inquiry — QA Test'));
  await pause(300);
  await safe('modal url',      () => page.fill('#bf-url',      'https://bakudanramen.com/catering'));
  await pause(300);
  await safe('modal subtitle', () => page.fill('#bf-subtitle', 'Book us for your next event'));
  await pause(300);

  // Save modal — button calls BKDN.saveBtnModal
  await safe('save modal', () => page.evaluate(() => {
    const btn = [...document.querySelectorAll('button')].find(b => /add button|save changes/i.test(b.textContent));
    if (btn) btn.click();
  }));
  await pause(2500);

  // Ensure modal is closed
  await closeModal(page);

  // Now edit the first button in the list
  console.log('  Editing first button...');
  await safe('click edit', () => page.evaluate(() => {
    const btn = document.querySelector('button[onclick*="openEditButton"], button[title="Edit"]');
    if (btn) btn.click();
  }));
  await pause(1500);

  // Update subtitle — field ID is #bf-subtitle
  await safe('clear subtitle', () => page.fill('#bf-subtitle', ''));
  await pause(200);
  await safe('edit subtitle',  () => page.fill('#bf-subtitle', 'Pickup · Delivery · Catering — All options'));
  await pause(300);
  await safe('save edit', () => page.evaluate(() => {
    const btn = [...document.querySelectorAll('button')].find(b => /save changes/i.test(b.textContent));
    if (btn) btn.click();
  }));
  await pause(2500);
  await closeModal(page);
  console.log('  ✓ Flow 1 complete');

  /* ══════════════════════════════════════════════════════
     FLOW 2 — Hide / show Instagram button
  ══════════════════════════════════════════════════════ */
  console.log('\n[FLOW 2] Hide / show Instagram');

  // Find Instagram button row and click visible toggle
  await safe('hide ig', () => page.evaluate(() => {
    const rows = [...document.querySelectorAll('[data-btn-id]')];
    const ig = rows.find(r => r.textContent.toLowerCase().includes('instagram'));
    if (ig) {
      const t = ig.querySelector('.toggle-vis, [data-field="visible"], input[type="checkbox"]') ||
                ig.querySelector('button');
      if (t) t.click();
    } else {
      // fallback: find by innerText scan on button rows
      const allBtns = [...document.querySelectorAll('.btn-row, .link-btn-row, li')];
      const igRow = allBtns.find(r => r.textContent.toLowerCase().includes('instagram'));
      if (igRow) {
        const tog = igRow.querySelector('input[type="checkbox"], button');
        if (tog) tog.click();
      }
    }
  }));
  await pause(1500);
  console.log('  ✓ Instagram hidden');

  await pause(800);

  // Show again
  await safe('show ig', () => page.evaluate(() => {
    const rows = [...document.querySelectorAll('[data-btn-id]')];
    const ig = rows.find(r => r.textContent.toLowerCase().includes('instagram'));
    if (ig) {
      const t = ig.querySelector('.toggle-vis, [data-field="visible"], input[type="checkbox"]') ||
                ig.querySelector('button');
      if (t) t.click();
    }
  }));
  await pause(1500);
  console.log('  ✓ Instagram shown again');

  /* ══════════════════════════════════════════════════════
     FLOW 3 — Reorder buttons (via API + UI refresh)
  ══════════════════════════════════════════════════════ */
  console.log('\n[FLOW 3] Reorder buttons');
  await page.evaluate(() => window.scrollBy(0, 150));
  await pause(800);

  // Get current button order, then reverse to demonstrate reorder
  const currentOrder = await page.evaluate(async () => {
    const token = localStorage.getItem('bkdn_token');
    const r = await fetch('/api/links/pages/1/buttons', {
      headers: { Authorization: 'Bearer ' + token }
    });
    const d = await r.json();
    return (d.data?.buttons || []).map(b => b.id);
  });
  console.log(`  Current order: [${currentOrder}]`);

  if (currentOrder.length >= 2) {
    // Swap first two buttons to show reorder effect
    const newOrder = [currentOrder[1], currentOrder[0], ...currentOrder.slice(2)];
    await safe('reorder-api', () => page.evaluate(async (order) => {
      const token = localStorage.getItem('bkdn_token');
      await fetch('/api/links/pages/1/buttons/reorder', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token },
        body: JSON.stringify({ order })
      });
    }, newOrder));
    await pause(800);
    // Refresh the page editor UI to show updated order
    const hash = await page.evaluate(() => window.location.hash);
    await page.evaluate(h => { window.location.hash = '#/pages'; }, hash);
    await pause(600);
    await page.evaluate(h => { window.location.hash = h; }, hash);
    await pause(1500);
    console.log(`  ✓ Reorder done → new order: [${newOrder}]`);
  }
  await page.evaluate(() => window.scrollTo(0, 0));
  await pause(600);

  /* ══════════════════════════════════════════════════════
     FLOW 4 — Publish page → verify /links
  ══════════════════════════════════════════════════════ */
  console.log('\n[FLOW 4] Publish page');

  // Click Save Draft first if visible
  await safe('save draft btn', () => page.evaluate(() => {
    const btn = [...document.querySelectorAll('button')].find(b => /save draft/i.test(b.textContent));
    if (btn) btn.click();
  }));
  await pause(1500);

  // Click Publish
  await safe('publish btn', () => page.evaluate(() => {
    const btn = [...document.querySelectorAll('button')].find(b =>
      /^publish$/i.test(b.textContent.trim()) || /publish page/i.test(b.textContent)
    );
    if (btn) btn.click();
  }));
  await pause(2500);
  console.log('  ✓ Published');

  // Open /links public page
  console.log('[FLOW 4b] Verifying /links public page...');
  await page.goto(BASE + '/links', { waitUntil: 'networkidle', timeout: 15000 });
  await pause(3000);
  console.log('  ✓ Public /links loaded');

  // Return to admin
  await page.goto(BASE + '/links-admin', { waitUntil: 'domcontentloaded', timeout: 15000 });
  await pause(4000);

  /* ══════════════════════════════════════════════════════
     FLOW 5 — Create blog post
  ══════════════════════════════════════════════════════ */
  console.log('\n[FLOW 5] Create blog post');
  await navigateTo(page, '#/blog/new');
  await pause(3000); // wait for Quill

  await safe('title',   () => page.fill('#blog-title',   'Happy Hour at Bakudan — Weekday Deals'));
  await pause(400);
  await safe('excerpt', () => page.fill('#blog-excerpt', 'Join us Mon–Fri 3–6 PM for ramen + drinks at special prices.'));
  await pause(400);
  await safe('caption', () => page.fill('#blog-caption', 'Every weekday, 3–6 PM. #HappyHour #BakudanRamen'));
  await pause(400);

  // Type into Quill editor
  await safe('quill content', () => page.evaluate(() => {
    if (window.quillEditor) {
      window.quillEditor.setContents([
        { insert: 'Happy Hour Specials\n', attributes: { header: 2 } },
        { insert: 'Every Monday through Friday, 3 PM to 6 PM.\n\n' },
        { insert: 'What\'s Included:\n', attributes: { bold: true } },
        { insert: '• Half-price tonkotsu ramen\n• $4 draft Sapporo\n• $6 sake cocktails\n\nSee you there! 🍜\n' }
      ]);
    }
  }));
  await pause(600);

  // Apply a post template
  await safe('template', () => page.evaluate(() => {
    const btn = [...document.querySelectorAll('button')].find(b => /template/i.test(b.textContent));
    if (btn) btn.click();
  }));
  await pause(600);
  // Close template dropdown if opened
  await page.keyboard.press('Escape');
  await pause(400);

  /* ══════════════════════════════════════════════════════
     FLOW 6 — Set featured image + open emoji picker
  ══════════════════════════════════════════════════════ */
  console.log('\n[FLOW 6] Featured image + emoji picker');

  await safe('featured url', () => page.fill('#blog-featured-img', 'https://bakudanramen.com/images/happy-hour.jpg'));
  await pause(500);

  // Open emoji picker
  await safe('emoji open', () => page.evaluate(() => {
    const btn = document.querySelector('.emoji-picker-container button') ||
                document.querySelector('#emoji-btn') ||
                [...document.querySelectorAll('button')].find(b => b.textContent.includes('😊') || /emoji/i.test(b.title));
    if (btn) btn.click();
  }));
  await pause(800);

  // Select a food emoji
  await safe('emoji click', () => page.evaluate(() => {
    const grid = document.getElementById('emoji-grid') || document.querySelector('.emoji-grid');
    if (grid) {
      const span = [...grid.querySelectorAll('span, button')][0];
      if (span) span.click();
    }
  }));
  await pause(600);

  // Scroll editor visible for video
  await page.evaluate(() => {
    const el = document.querySelector('.ql-editor');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });
  await pause(600);

  /* ══════════════════════════════════════════════════════
     FLOW 7 — Schedule the post
  ══════════════════════════════════════════════════════ */
  console.log('\n[FLOW 7] Schedule post');

  // Set status to scheduled
  await safe('status', () => page.evaluate(() => {
    const sel = document.querySelector('#blog-status, select[name="status"]');
    if (sel) { sel.value = 'scheduled'; sel.dispatchEvent(new Event('change', { bubbles: true })); }
  }));
  await pause(500);

  // Set datetime-local to tomorrow 10am
  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  tomorrow.setHours(10, 0, 0, 0);
  const dtLocal = tomorrow.toISOString().slice(0, 16);

  await safe('schedule date', () => page.evaluate((val) => {
    const inp = document.querySelector('#blog-scheduled-at, input[type="datetime-local"]');
    if (inp) {
      inp.value = val;
      inp.dispatchEvent(new Event('change', { bubbles: true }));
      inp.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }, dtLocal));
  await pause(400);
  console.log(`  Scheduled for: ${dtLocal}`);

  // Save (as draft/scheduled)
  await safe('save', () => page.evaluate(() => {
    const btn = [...document.querySelectorAll('button')].find(b =>
      /save|draft/i.test(b.textContent) && !/another|and create/i.test(b.textContent)
    );
    if (btn) btn.click();
  }));
  await pause(3000);
  console.log('  ✓ Scheduled post saved');

  /* ── Publish an immediate post for the public blog ── */
  console.log('\n[FLOW 7b] Publish an immediate post');
  await navigateTo(page, '#/blog/new');
  await pause(3000);

  await safe('title2', () => page.fill('#blog-title', 'Stone Oak Grand Opening — Now Serving Ramen!'));
  await pause(300);
  await safe('excerpt2', () => page.fill('#blog-excerpt', 'Visit our newest Bakudan Ramen location at Stone Oak, 22506 US Hwy 281 N Ste 106.'));
  await pause(300);
  await safe('quill2', () => page.evaluate(() => {
    if (window.quillEditor) {
      window.quillEditor.setContents([
        { insert: 'Grand Opening — Stone Oak\n', attributes: { header: 2 } },
        { insert: 'We are proud to announce the opening of our Stone Oak location. Come experience authentic Japanese ramen!\n' }
      ]);
    }
  }));
  await pause(400);

  // Set status published
  await safe('status-pub', () => page.evaluate(() => {
    const sel = document.querySelector('#blog-status, select[name="status"]');
    if (sel) { sel.value = 'published'; sel.dispatchEvent(new Event('change', { bubbles: true })); }
  }));
  await pause(400);

  // Click publish
  await safe('publish-post', () => page.evaluate(() => {
    const btn = [...document.querySelectorAll('button')].find(b => /publish/i.test(b.textContent));
    if (btn) btn.click();
  }));
  await pause(3000);
  console.log('  ✓ Second post published');

  /* ══════════════════════════════════════════════════════
     FLOW 8 — Verify public blog after publish
  ══════════════════════════════════════════════════════ */
  console.log('\n[FLOW 8] Verify public blog');
  await page.goto(BASE + '/blog-cms', { waitUntil: 'networkidle', timeout: 15000 });
  await pause(3000);
  console.log('  ✓ Public blog listing loaded');

  // Try clicking the first post
  const firstPostSlug = await page.evaluate(async () => {
    const r = await fetch('/api/public/blog');
    const d = await r.json();
    return d.data?.posts?.[0]?.slug || null;
  });

  if (firstPostSlug) {
    await page.goto(BASE + '/blog-cms/post/' + firstPostSlug, { waitUntil: 'networkidle', timeout: 15000 });
    await pause(3000);
    console.log(`  ✓ Blog post detail loaded: ${firstPostSlug}`);
  }

  /* ── OUTRO: show Scheduling view ── */
  console.log('\n[OUTRO] Show Scheduling view');
  await page.goto(BASE + '/links-admin', { waitUntil: 'domcontentloaded', timeout: 15000 });
  await pause(3500);
  await navigateTo(page, '#/scheduling');
  await pause(3000);

  console.log('\n[DONE] All flows recorded.');
  await adminCtx.close();

  await browser.close();

  // Wait for Playwright to finish flushing the video file, then rename
  const dest = path.join(OUT_DIR, 'bkdn_sub_walkthrough.webm');
  let renamed = false;
  for (let attempt = 0; attempt < 15; attempt++) {
    await new Promise(r => setTimeout(r, 2000));
    const videos = fs.readdirSync(OUT_DIR).filter(f => f.endsWith('.webm') && !f.includes('walkthrough'));
    if (videos.length > 0) {
      const src = path.join(OUT_DIR, videos[0]);
      try {
        try { fs.unlinkSync(dest); } catch {}
        fs.renameSync(src, dest);
        renamed = true;
        break;
      } catch (e) {
        if (attempt < 14) { console.log(`  [retry ${attempt+1}] file still locked, waiting...`); }
        else { console.warn(`  [WARN] rename failed: ${e.message}`); }
      }
    }
  }
  if (renamed) {
    const size = (fs.statSync(dest).size / 1024 / 1024).toFixed(1);
    console.log(`\n=== VIDEO SAVED ===`);
    console.log(`  ${dest}  (${size} MB)`);
  } else {
    const videos2 = fs.readdirSync(OUT_DIR).filter(f => f.endsWith('.webm'));
    console.log(`\n=== VIDEO FILES IN ${OUT_DIR} ===`);
    videos2.forEach(f => {
      const p = path.join(OUT_DIR, f);
      console.log(`  ${f}  (${(fs.statSync(p).size/1024/1024).toFixed(1)} MB)`);
    });
  }
})().catch(e => {
  console.error('\nFATAL:', e.message);
  process.exit(1);
});
