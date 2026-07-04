/**
 * Smoke test — verifies all key API endpoints respond correctly.
 * Run: node server/test.js
 */
'use strict';
const http = require('http');
const { spawn } = require('child_process');
const path = require('path');

const PORT  = process.env.TEST_PORT || 3000;
const BASE  = `http://localhost:${PORT}`;
let _token  = null;
let _passed = 0;
let _failed = 0;
let _serverProcess = null;

function req(method, path, body, headers = {}) {
  return new Promise((resolve, reject) => {
    const opts = {
      hostname: 'localhost', port: PORT, path,
      method, headers: { 'Content-Type': 'application/json', ...headers },
    };
    const r = http.request(opts, res => {
      let data = '';
      res.on('data', c => data += c);
      res.on('end', () => {
        try { resolve({ status: res.statusCode, body: JSON.parse(data) }); }
        catch { resolve({ status: res.statusCode, body: data }); }
      });
    });
    r.on('error', reject);
    if (body) r.write(JSON.stringify(body));
    r.end();
  });
}

function pingServer() {
  return new Promise(resolve => {
    const r = http.request({ hostname: 'localhost', port: PORT, path: '/api/config', method: 'GET' }, res => {
      res.resume();
      res.on('end', () => resolve(true));
    });
    r.on('error', () => resolve(false));
    r.setTimeout(1000, () => {
      r.destroy();
      resolve(false);
    });
    r.end();
  });
}

async function ensureServer() {
  if (await pingServer()) return;

  _serverProcess = spawn(process.execPath, [path.join(__dirname, 'server.js')], {
    cwd: path.join(__dirname, '..'),
    env: { ...process.env, PORT: String(PORT) },
    stdio: 'ignore',
    detached: false,
  });

  for (let i = 0; i < 20; i++) {
    await new Promise(r => setTimeout(r, 500));
    if (await pingServer()) return;
  }

  throw new Error(`Server did not start on ${BASE}`);
}

function stopServer() {
  if (_serverProcess) {
    _serverProcess.kill();
    _serverProcess = null;
  }
}

async function check(label, fn) {
  try {
    const result = await fn();
    if (result) { console.log(`  ✓ ${label}`); _passed++; }
    else        { console.log(`  ✗ ${label}`); _failed++; }
  } catch (e) {
    console.log(`  ✗ ${label}: ${e.message}`);
    _failed++;
  }
}

async function run() {
  console.log(`\nBKDN API Smoke Test → ${BASE}\n`);

  await ensureServer();

  await check('GET /api/config returns version', async () => {
    const r = await req('GET', '/api/config');
    return r.status === 200 && r.body.version;
  });

  await check('POST /api/auth/login with valid creds', async () => {
    const r = await req('POST', '/api/auth/login', { email: 'admin@bakudanramen.com', password: 'admin123' });
    _token = r.body.token;
    return r.status === 200 && r.body.ok && r.body.token;
  });

  await check('POST /api/auth/login with bad creds → 401', async () => {
    const r = await req('POST', '/api/auth/login', { email: 'admin@bakudanramen.com', password: 'wrong' });
    return r.status === 401;
  });

  const authHeader = { Authorization: 'Bearer ' + _token };

  await check('GET /api/auth/me returns user', async () => {
    const r = await req('GET', '/api/auth/me', null, authHeader);
    return r.status === 200 && r.body.user?.email;
  });

  await check('GET /api/links/pages returns pages', async () => {
    const r = await req('GET', '/api/links/pages', null, authHeader);
    return r.status === 200 && Array.isArray(r.body.data?.pages) && r.body.data.pages.length > 0;
  });

  await check('GET /api/links/pages/1/buttons returns buttons', async () => {
    const r = await req('GET', '/api/links/pages/1/buttons', null, authHeader);
    return r.status === 200 && Array.isArray(r.body.data?.buttons);
  });

  await check('GET /api/links/dashboard returns KPIs', async () => {
    const r = await req('GET', '/api/links/dashboard', null, authHeader);
    return r.status === 200 && r.body.data?.total !== undefined;
  });

  await check('GET /api/links/settings returns settings', async () => {
    const r = await req('GET', '/api/links/settings', null, authHeader);
    return r.status === 200 && r.body.data?.settings?.instagram_url !== undefined;
  });

  await check('GET /api/public/links/bakudan returns public buttons', async () => {
    const r = await req('GET', '/api/public/links/bakudan');
    return r.status === 200 && r.body.ok && Array.isArray(r.body.data?.buttons);
  });

  await check('GET /api/public/blog returns posts array', async () => {
    const r = await req('GET', '/api/public/blog');
    return r.status === 200 && r.body.ok;
  });

  await check('GET /api/blog/posts returns posts', async () => {
    const r = await req('GET', '/api/blog/posts', null, authHeader);
    return r.status === 200 && Array.isArray(r.body.data?.posts);
  });

  await check('POST /api/blog/posts creates draft', async () => {
    const r = await req('POST', '/api/blog/posts', { title: 'Test Post from Smoke Test', excerpt: 'Test', content_html: '<p>Hello</p>' }, authHeader);
    return r.status === 201 && r.body.ok && r.body.data?.post?.id;
  });

  await check('GET /links returns HTML (public links page)', async () => {
    const r = await req('GET', '/links');
    return r.status === 200;
  });

  await check('GET /links-admin returns HTML (admin SPA)', async () => {
    const r = await req('GET', '/links-admin');
    return r.status === 200;
  });

  await check('GET /blog-cms returns HTML', async () => {
    const r = await req('GET', '/blog-cms');
    return r.status === 200;
  });

  await check('Unauthenticated /api/links/pages → 401', async () => {
    const r = await req('GET', '/api/links/pages');
    return r.status === 401;
  });

  console.log(`\n  Results: ${_passed} passed, ${_failed} failed\n`);
  stopServer();
  if (_failed > 0) process.exit(1);
}

run().catch(e => { stopServer(); console.error('Test runner error:', e); process.exit(1); });
