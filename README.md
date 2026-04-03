# Bakudan Ramen Website

**Bold Flavor. Modern Japanese Soul. Texas Spirit.**

Official website for Bakudan Ramen — three locations in San Antonio, Texas.

## Locations

- **Bandera** — 11309 Bandera Rd Ste 111, San Antonio, TX 78254
- **Stone Oak** — 22506 U.S. Hwy 281 N Ste 106, San Antonio, TX 78258
- **The Rim** — 17619 La Cantera Pkwy UNIT 208, San Antonio, TX 78256

## Tech Stack

- HTML5 / CSS3 / Vanilla JavaScript
- No build tools required — static site
- Google Fonts (Bebas Neue, Playfair Display, Noto Sans JP)
- Toast integration for online ordering

## Structure

```
├── index.html          # Homepage
├── menu.html           # Full menu
├── locations.html      # All 3 locations
├── order.html          # Order online (location picker → Toast)
├── about.html          # Our story
├── happy-hour.html     # Happy hour specials
├── blog.html           # Blog listing
├── blog-*.html         # Blog articles
├── privacy.html        # Privacy policy
├── terms.html          # Terms of service
├── css/
│   ├── styles.css      # Main stylesheet
│   └── accessibility.css
├── js/
│   ├── main.js         # Navigation, animations
│   ├── consent.js      # Cookie consent banner
│   └── accessibility.js
└── images/             # Site imagery
```

## Development

Open any HTML file in a browser. No build step required.

For local development with live reload:
```bash
npx live-server
```

## Accessibility

WCAG 2.1 AA compliant:
- Semantic HTML5 structure
- Skip-to-content links
- Keyboard navigation support
- ARIA labels on interactive elements
- Color contrast ≥ 4.5:1
- Reduced motion support

## Privacy

- Cookie consent banner (CCPA compliant)
- Privacy policy and terms of service pages
- No tracking without user consent
