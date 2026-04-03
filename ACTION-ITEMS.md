# Bakudan Ramen Website — Action Items

*Generated April 3, 2026 after B2 Stone Oak menu implementation*

---

## MENU CONTENT GAPS (Needs Input from Team)

### 1. Kids Karage Price
- **Status:** On website with $TBD placeholder
- **Action:** Maria or team to confirm Kids Karage price for all locations
- **File:** `menu.html` line ~205

### 2. Bandera (B1) Menu Differences
- **Status:** Current website menu reflects B2 (Stone Oak/Rim) pricing and items
- **Action:** Maria to provide the B1 Bandera-specific menu so we can note any differences in pricing, items, or availability
- **Priority:** HIGH — Bandera has a different drink program (saké bar vs. cocktails) and may have food price differences
- **Files affected:** `menu.html`, `happy-hour.html`

### 3. Bandera Saké Bar — Full Drink List
- **Status:** Placeholder entries for saké cocktails, saké sangria pitcher, and hot sake (all $TBD)
- **Action:** Maria to provide complete Bandera saké cocktail menu with names, descriptions, and prices
- **File:** `menu.html` (Saké Bar section)

### 4. Spicy Tuna Poke Nachos Description
- **Status:** Listed on website with price ($9.50) but no description
- **Action:** Team to provide a short description for the menu
- **File:** `menu.html`

---

## PHOTOS NEEDED

### 5. Geisha Mural Artwork for Page Headers
- **Status:** Placeholder system built into all page heroes (CSS classes ready)
- **Action:** Staff to photograph the geisha mural artwork at each location for use as decorative header elements
- **Photos received so far:**
  - `geisha-rim-right.jpg` — Rim location geisha (right side)
  - `geisha-bandera.jpg` / `geisha-bandera-alt.jpg` — Bandera location geisha
  - `mural-logo.png` — Mural with Bakudan logo
  - `mural-stone-oak.jpg` — Stone Oak additional mural
- **Still needed:** Clean, high-resolution cropped versions suitable for left/right hero overlays
- **Files affected:** All pages with `page-hero` sections (index.html, menu.html, locations.html, about.html, etc.)

### 6. Spicy Umami Miso Bowl Photo
- **Status:** Placeholder emoji on homepage signature bowls section
- **Action:** Get a professional photo of the Spicy Umami Miso bowl
- **File:** `index.html` (signature bowls section)

### 7. Blog Article Photos
- **Status:** Gradient/emoji placeholders on homepage blog cards and individual blog pages
- **Action:** Provide photos for:
  - "The Art of Tonkotsu" article (DONE — using garlic-tonkotsu.png)
  - "Ramen 101" article — needs photo of ramen ingredients (broth, noodles, tare, toppings)
  - "From Japan to Texas" article — needs photo of Tokyo ramen alley or travel image
- **Files affected:** `index.html` (blog cards), `blog-tonkotsu.html`, `blog-ramen-101.html`, `blog-journey.html`

### 8. HEIC Photos — Need Conversion
- **Status:** 4 HEIC files in "website Recommendations & Requested Pics" folder (IMG_7558-7564)
- **Action:** These Apple HEIC format images cannot be used on the web. Maria to either:
  - Re-export as JPG/PNG, OR
  - Let us know what these photos are of so we can convert them
- **Files:** `IMG_7558.HEIC`, `IMG_7559.HEIC`, `IMG_7560.HEIC`, `IMG_7564.HEIC`

---

## WEBSITE FEATURES

### 9. Favicon
- **Status:** No favicon set
- **Action:** Create or provide a Bakudan Ramen favicon (the 爆 kanji icon or logo mark)
- **Files affected:** All HTML pages (need `<link rel="icon">` tag)

### 10. Google Maps Embeds
- **Status:** Placeholder text ("Map Placeholder") on all 3 location pages
- **Action:** Generate Google Maps embed URLs for each location and replace placeholders
- **Files affected:** `locations/bandera.html`, `locations/stone-oak.html`, `locations/the-rim.html`

### 11. Open Graph / Social Media Meta Tags
- **Status:** Not yet implemented
- **Action:** Add `og:image`, `og:title`, `og:description` meta tags for social media sharing
- **Files affected:** All pages

---

## DEPLOYMENT

### 12. Push to GitHub
- **Status:** Local git repo initialized with commits, not yet pushed to remote
- **Action:** Create GitHub repo and push (commands provided in earlier session)

### 13. Domain / Hosting Setup
- **Status:** Website files ready for deployment
- **Action:** Set up hosting (Netlify, Vercel, or traditional hosting) and point bakudanramen.com domain

---

## COMPLETED ITEMS ✓

- [x] All ramen prices updated from B2 PDF ($14.99-$15.99)
- [x] All starter prices and items updated from B2 PDF
- [x] "Not Ramen" entrées section with full descriptions and prices
- [x] "Be Extra" add-ons section (26 items with prices)
- [x] Spice Bombs section (4 items at $0.75 each)
- [x] Dessert section (Mochi Ice Cream $5.50)
- [x] Full cocktail menu (11 cocktails) with taglines and ingredients
- [x] Complete saké list (10 options with glass/bottle prices)
- [x] Wine list (5 options with glass/bottle prices)
- [x] Beer list split into Draft (4) and Bottles/Cans (5)
- [x] Mocktails section (4 items at $6 each)
- [x] Beverages section (Soda, Ramune, Topo Chico at $3.50)
- [x] Kids Ramen price ($8.50)
- [x] Homepage signature bowl prices updated
- [x] "Spicy Miso" renamed to "Spicy Umami Miso" everywhere
- [x] Cilantro Lime Chicken photo added to homepage
- [x] Garlic Tonkotsu photo added to homepage + blog card
- [x] Honey Sriracha Wings photo copied to images
- [x] All Maria photos organized in images/ directory
- [x] JSON-LD schema updated with all menu data
- [x] Allergen notice and gratuity policy updated
- [x] Location-specific drink badges (Stone Oak & Rim vs. Bandera)
- [x] Ramune listed as "multiple flavors at Bandera" per Hoang's request
- [x] Happy Hour CTA time corrected to "Daily 3 PM – 6 PM"
