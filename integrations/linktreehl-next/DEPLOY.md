# Deployment Guide — bakudanramen.com/links

## Architecture

```
bakudanramen.com/links  (WordPress)
        ↓  301 redirect
links.bakudanramen.com  (Railway — Next.js app)
```

---

## Step 1 — Deploy to Railway

1. Go to https://railway.app → **New Project** → **Deploy from GitHub repo**
2. Select `liemdo28/LinkTreeHL`
3. Add a **MySQL** service (Railway sidebar → Add Service → Database → MySQL)
4. Set these **environment variables** on the web service:
   ```
   DATABASE_URL       = (copy from Railway MySQL service → Connect → MySQL URL)
   NEXTAUTH_SECRET    = (generate: openssl rand -base64 32)
   NEXTAUTH_URL       = https://links.bakudanramen.com
   NEXT_PUBLIC_BASE_URL = https://links.bakudanramen.com
   NODE_ENV           = production
   ```
5. Railway will auto-build and deploy. Watch the build logs.

---

## Step 2 — Run migration + seed on Railway

In Railway → your web service → **Shell** tab:
```bash
npx prisma migrate deploy
npx ts-node --compiler-options '{"module":"CommonJS"}' prisma/seed.ts
```

---

## Step 3 — Set custom domain on Railway

1. Railway → web service → **Settings** → **Custom Domain**
2. Add: `links.bakudanramen.com`
3. Railway shows you a CNAME record, e.g.:
   ```
   CNAME   links   →   xxxxxxxx.up.railway.app
   ```
4. In your DNS (Cloudflare or your registrar): add that CNAME for `links`

---

## Step 4 — Redirect bakudanramen.com/links in WordPress

**Option A — Redirection plugin (recommended)**

1. Install the free "Redirection" plugin in WordPress
2. Go to Tools → Redirection → Add New
3. Source URL: `/links`  
   Match: `URL only`  
   Action: `Redirect to URL` → `https://links.bakudanramen.com/links/bakudan`
   HTTP Code: `301`
4. Save

**Option B — .htaccess** (if you have file access)

Add to your WordPress `.htaccess` ABOVE the `# BEGIN WordPress` block:
```apache
# Bakudan Links redirect
RewriteRule ^links/?$ https://links.bakudanramen.com/links/bakudan [R=301,L]
RewriteRule ^links/(.*)$ https://links.bakudanramen.com/links/$1 [R=301,L]
```

---

## Final URLs

| URL | Page |
|-----|------|
| `bakudanramen.com/links` | → redirects to main hub |
| `links.bakudanramen.com/links/bakudan` | Main Bakudan hub |
| `links.bakudanramen.com/links/la cantera` | La Cantera |
| `links.bakudanramen.com/links/stone-oak` | Stone Oak |
| `links.bakudanramen.com/links/bandera` | Bandera |
| `links.bakudanramen.com/admin` | Admin panel |

---

## Update Toast order URLs

After deployment, update the real Toast order URLs in the admin panel:

1. Go to `links.bakudanramen.com/admin`
2. Login: `admin@bakudanramen.com` / `admin123` **(change this password first!)**
3. Go to **Link Pages** → `bakudan` → **Buttons** tab
4. Edit the La Cantera, STONE OAK, BANDERA order_sub buttons → paste real Toast URLs
5. Do the same for `/links/la cantera`, `/links/stone-oak`, `/links/bandera` pages

---

## Change admin password

In Railway shell:
```bash
npx ts-node -e "
const { PrismaClient } = require('@prisma/client');
const bcrypt = require('bcryptjs');
const p = new PrismaClient();
bcrypt.hash('YOUR_NEW_PASSWORD', 10).then(h => 
  p.user.update({ where: { email: 'admin@bakudanramen.com' }, data: { password_hash: h } })
).then(() => { console.log('Done'); p.\$disconnect(); });
"
```
