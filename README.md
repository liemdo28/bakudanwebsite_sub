# Bakudan Ramen — Website (MAIN SOURCE)

> **DEPLOY SOURCE:** This is the active source deployed to `bakudanramen.com` via DreamHost SFTP.
> Deploy scripts: `scripts/_deploy_static_pages.py`, `scripts/_deploy_links_temp.py`
> Git remote: `heoventure/BakudanWebsite.git`
> Old WordPress source archived at: `_archive/bakudanramen.com-old-20260601/`

**Bold Flavor. Modern Japanese Soul. Texas Spirit.**

Official website for Bakudan Ramen, a ramen restaurant with three locations across San Antonio, Texas.

---

## Project Overview

This is a static, multi-page website for Bakudan Ramen. It serves as the pla canteraary digital presence for the brand, providing customers with information about the menu, restaurant locations, online ordering, company history, happy hour offerings, and blog content.

The site is built with plain HTML, CSS, and vanilla JavaScript — no build pipeline, no framework dependencies, and no server-side rendering. It can be opened directly in any modern browser or served via any static file server.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Structure | HTML5 (semantic) |
| Styling | CSS3 |
| Interactivity | Vanilla JavaScript (ES6+) |
| Fonts | Google Fonts — Bebas Neue, Playfair Display, Noto Sans JP |
| Online Ordering | [Toast](https://www.toasttab.com/) (external platform, triggered via order.html) |
| Local Dev Server | `live-server` (npm, optional) |

### Design Philosophy
- No build tools required — plain `.html`, `.css`, and `.js` files
- Zero framework or bundler dependencies
- Google Fonts loaded via CDN
- Online ordering handled by an external Toast instance (no embedded iframe)

---

## Project Structure

```
bakudan-website/
│
├── index.html            # Homepage
├── menu.html             # Full menu
├── locations.html        # All 3 locations (Bandera, Stone Oak, La Cantera)
├── order.html            # Online ordering — location picker → Toast
├── about.html            # Our story / brand background
├── happy-hour.html       # Happy hour specials and schedule
├── blog.html             # Blog listing page
├── blog-*.html           # Individual blog article pages
├── privacy.html          # Privacy policy
├── terms.html            # Terms of service
│
├── css/
│   ├── styles.css        # Pla canteraary stylesheet — layout, typography, components
│   └── accessibility.css # Accessibility-specific overrides and enhancements
│
├── js/
│   ├── main.js           # Navigation, animations, general UI logic
│   ├── consent.js        # Cookie consent banner (CCPA compliance)
│   └── accessibility.js  # Keyboard nav, ARIA, reduced-motion support
│
└── images/               # Site imagery (logos, food photography, icons)
```

### Page Purpose

| Page | Description |
|---|---|
| `index.html` | Homepage — hero section, highlights, call-to-action |
| `menu.html` | Full menu with categories, descriptions, pricing |
| `locations.html` | Store addresses, hours, and map/directions links for all 3 sites |
| `order.html` | Location selector that redirects to the appropriate Toast ordering page |
| `about.html` | Brand story, values, team background |
| `happy-hour.html` | Current happy hour deals and time windows |
| `blog.html` + `blog-*.html` | Blog listing and individual articles |
| `privacy.html` | Privacy policy (CCPA/GDPR compliant statement) |
| `terms.html` | Terms of service |

---

## Key Features

- **Multi-location support** — Separate pages for each of the three San Antonio locations
- **Online ordering integration** — `order.html` presents a location picker; selection routes to the correct Toast portal for that location
- **Cookie consent banner** — Displays on first visit; blocks non-essential scripts until consent is given; CCPA compliant
- **Blog system** — Static blog articles with listing and individual post pages
- **Accessibility-first design** — Full WCAG 2.1 AA compliance (see below)
- **Responsive design** — Mobile-first CSS approach for all screen sizes

---

## How to Run

### Option 1 — Direct browser open
Simply open any `.html` file directly in your browser (Chrome, Firefox, Safari, Edge). No build step needed.

### Option 2 — Local dev server with live reload (recommended)
Requires Node.js.

```bash
# Install live-server globally (one-time)
npm install -g live-server

# Run from the project root
npx live-server
```

This will open the site in your default browser and auto-refresh when any file changes.

### No other tooling required
No `npm install` for the project itself, no webpack, no Vite, no bundler.

---

## Accessibility (WCAG 2.1 AA)

The site is built with accessibility as a core requirement. Key implementations:

- **Semantic HTML5** — Correct use of `<nav>`, `<main>`, `<article>`, `<section>`, `<header>`, `<footer>`, and heading hierarchy (`h1`–`h6`)
- **Skip-to-content link** — Hidden link at the top of every page bypasses navigation and jumps to main content
- **Keyboard navigation** — All interactive elements (links, buttons, form controls) are fully navigable via Tab / Shift+Tab and activatable via Enter / Space
- **ARIA labels** — `aria-label`, `aria-expanded`, `aria-current`, and other ARIA attributes used where visual context alone is insufficient
- **Color contrast** — All text and UI components meet the minimum 4.5:1 contrast ratio (4.5:1 for normal text, 3:1 for large text)
- **Reduced motion** — CSS media query `@media (prefers-reduced-motion: reduce)` disables animations and transitions for users who have this preference enabled in their OS

---

## Developer Notes

### CSS Architecture
- `styles.css` contains all layout, color, typography, and component styles
- `accessibility.css` contains purely accessibility-related overrides and enhancements (focus rings, contrast tweaks, motion suppression)
- No CSS preprocessor (Sass/Less) — pure CSS

### JavaScript Architecture
- `main.js` — general page behavior: mobile nav toggle, scroll animations, utility helpers
- `consent.js` — cookie consent banner logic: shows on first visit, stores preference in `localStorage`, applies blocking classes to non-essential scripts until consent is granted
- `accessibility.js` — keyboard nav enhancements, ARIA state management, reduced-motion support detection

### Online Ordering (Toast)
`order.html` presents the user with a location picker. Once a location is selected, the page redirects to:
```
https://www.toasttab.com/orders/<location-slug>
```
No Toast SDK or API integration is embedded — it's a straightforward redirect pattern.

### Cookie Consent & Tracking
- Non-essential scripts (analytics, marketing pixels, etc.) must be wrapped so they are blocked until `consent.js` unblocks them
- The consent banner meets CCPA requirements (clear opt-in/out, no pre-checked boxes)
- Privacy policy and terms of service are on dedicated static pages

### Adding a New Blog Article
1. Copy the latest `blog-*.html` as a template
2. Rename it (e.g., `blog-new-feature.html`)
3. Add a link entry to `blog.html`
4. Update the page `<title>` and meta description

### Adding a New Location
1. Add the new location entry to `locations.html`
2. Update `order.html` with the new location option in the picker
3. Add the Toast redirect URL for the new location
4. (Optional) Update the nav and footer across all pages if applicable

---

## Legal

- **Privacy Policy**: `privacy.html`
- **Terms of Service**: `terms.html`
- Cookie consent is handled client-side via `consent.js` and is CCPA compliant.
