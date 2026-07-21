import { PrismaClient } from '@prisma/client';
import bcrypt from 'bcryptjs';

const prisma = new PrismaClient();

async function main() {
  const brand = await prisma.brand.upsert({
    where: { slug: 'bakudan-ramen' },
    update: {},
    create: {
      name: 'Bakudan Ramen',
      slug: 'bakudan-ramen',
      primary_color: '#B91C1C',
      secondary_color: '#1A1A1A',
    },
  });

  const storeData = [
    { name: 'Bakudan Ramen – Bandera', slug: 'bandera', location_name: '11309 Bandera Rd Ste 111, San Antonio TX 78254' },
    { name: 'Bakudan Ramen – La Cantera', slug: 'La Cantera', location_name: '17619 La Cantera Pkwy UNIT 208, San Antonio TX 78256' },
    { name: 'Bakudan Ramen – Stone Oak', slug: 'stone-oak', location_name: '22506 U.S. Hwy 281 N Ste 106, San Antonio TX 78258' },
    { name: 'Bakudan Ramen – Downtown', slug: 'downtown', location_name: 'San Antonio, TX' },
  ];

  const stores: Record<string, number> = {};
  for (const s of storeData) {
    const store = await prisma.store.upsert({
      where: { slug: s.slug },
      update: {},
      create: { brand_id: brand.id, ...s },
    });
    stores[s.slug] = store.id;
  }

  const now = new Date();

  // ── Main Bakudan hub page (bakudanramen.com/links) ────────────────────────
  const mainPage = await prisma.linkPage.upsert({
    where: { slug: 'bakudan' },
    update: {},
    create: {
      brand_id: brand.id,
      store_id: null,
      title: 'Bakudan Ramen',
      slug: 'bakudan',
      headline: 'BAKUDAN RAMEN',
      subheadline: 'Authentic Japanese Ramen · San Antonio',
      seo_title: 'Bakudan Ramen — Links',
      seo_description: 'Order online, get directions, and find events for Bakudan Ramen San Antonio.',
      theme_json: { background: '#0a0a0a', text: '#ffffff', button: '#B91C1C', buttonText: '#ffffff', accent: '#B91C1C' },
      is_active: true,
      published_at: now,
    },
  });

  // Clear existing buttons and re-seed
  await prisma.linkButton.deleteMany({ where: { link_page_id: mainPage.id } });
  await prisma.linkButton.createMany({
    data: [
      // Location direction chips
      { link_page_id: mainPage.id, title: 'La Cantera', url: 'https://maps.google.com/?q=Bakudan+Ramen+The+La Cantera+San+Antonio', button_style: 'chip', sort_order: 0, icon_name: 'map', is_featured: false, opens_in_new_tab: true },
      { link_page_id: mainPage.id, title: 'STONE OAK', url: 'https://maps.google.com/?q=Bakudan+Ramen+Stone+Oak+San+Antonio', button_style: 'chip', sort_order: 1, icon_name: 'map', is_featured: false, opens_in_new_tab: true },
      { link_page_id: mainPage.id, title: 'BANDERA', url: 'https://maps.google.com/?q=Bakudan+Ramen+Bandera+San+Antonio', button_style: 'chip', sort_order: 2, icon_name: 'map', is_featured: false, opens_in_new_tab: true },
      // Order Online CTA (expandable)
      { link_page_id: mainPage.id, title: 'Order Online', url: '#', button_style: 'primary', sort_order: 3, icon_name: 'order', is_featured: true, opens_in_new_tab: false },
      // Order sub-buttons (shown when Order Online is expanded)
      { link_page_id: mainPage.id, title: 'La Cantera', url: 'https://www.toasttab.com/REPLACE-WITH-La Cantera-ORDER-URL', button_style: 'order_sub', sort_order: 4, opens_in_new_tab: true },
      { link_page_id: mainPage.id, title: 'STONE OAK', url: 'https://www.toasttab.com/REPLACE-WITH-STONEOAK-ORDER-URL', button_style: 'order_sub', sort_order: 5, opens_in_new_tab: true },
      { link_page_id: mainPage.id, title: 'BANDERA', url: 'https://www.toasttab.com/REPLACE-WITH-BANDERA-ORDER-URL', button_style: 'order_sub', sort_order: 6, opens_in_new_tab: true },
      // Link rows
      { link_page_id: mainPage.id, title: 'Visit Main Website', url: 'https://bakudanramen.com', button_style: 'secondary', sort_order: 7, icon_name: 'website', opens_in_new_tab: true },
      { link_page_id: mainPage.id, title: 'Join Our Email Club', url: 'https://bakudanramen.com/email-club', button_style: 'secondary', sort_order: 8, icon_name: 'email', opens_in_new_tab: true },
      { link_page_id: mainPage.id, title: 'Specialty Ramen Events', url: 'https://bakudanramen.com/events', button_style: 'secondary', sort_order: 9, icon_name: 'events', opens_in_new_tab: true },
      { link_page_id: mainPage.id, title: 'Instagram', url: 'https://instagram.com/bakudanramen', button_style: 'secondary', sort_order: 10, icon_name: 'instagram', opens_in_new_tab: true },
      { link_page_id: mainPage.id, title: 'Facebook', url: 'https://facebook.com/bakudanramen', button_style: 'secondary', sort_order: 11, icon_name: 'facebook', opens_in_new_tab: true },
      // Coming soon placeholder
      { link_page_id: mainPage.id, title: 'Merchandise · Coming Soon', url: '#', button_style: 'coming_soon', sort_order: 12, is_active: true, opens_in_new_tab: false },
    ],
  });

  // ── Per-store pages ───────────────────────────────────────────────────────
  const storePages = [
    {
      slug: 'La Cantera',
      store: 'La Cantera',
      headline: 'BAKUDAN — La Cantera',
      subheadline: '17619 La Cantera Pkwy UNIT 208 · (210) 257-8080',
      orderUrl: 'https://www.toasttab.com/REPLACE-WITH-La Cantera-ORDER-URL',
    },
    {
      slug: 'stone-oak',
      store: 'stone-oak',
      headline: 'BAKUDAN — STONE OAK',
      subheadline: '22506 U.S. Hwy 281 N Ste 106 · (210) 437-0632',
      orderUrl: 'https://www.toasttab.com/REPLACE-WITH-STONEOAK-ORDER-URL',
    },
    {
      slug: 'bandera',
      store: 'bandera',
      headline: 'BAKUDAN — BANDERA',
      subheadline: '11309 Bandera Rd Ste 111 · (210) 277-7740',
      orderUrl: 'https://www.toasttab.com/REPLACE-WITH-BANDERA-ORDER-URL',
    },
  ];

  for (const sp of storePages) {
    const page = await prisma.linkPage.upsert({
      where: { slug: sp.slug },
      update: {},
      create: {
        brand_id: brand.id,
        store_id: stores[sp.store],
        title: sp.headline,
        slug: sp.slug,
        headline: sp.headline,
        subheadline: sp.subheadline,
        seo_title: `Bakudan Ramen ${sp.store.replace('-', ' ').replace(/\b\w/g, (c) => c.toUpperCase())} — Links`,
        seo_description: `Order online, get directions, and find events for Bakudan Ramen ${sp.slug}.`,
        theme_json: { background: '#0a0a0a', text: '#ffffff', button: '#B91C1C', buttonText: '#ffffff', accent: '#B91C1C' },
        is_active: true,
        published_at: now,
      },
    });

    await prisma.linkButton.deleteMany({ where: { link_page_id: page.id } });
    await prisma.linkButton.createMany({
      data: [
        { link_page_id: page.id, title: 'Order Online', url: sp.orderUrl, button_style: 'primary', sort_order: 0, icon_name: 'order', is_featured: true, opens_in_new_tab: true },
        { link_page_id: page.id, title: 'Visit Main Website', url: 'https://bakudanramen.com', button_style: 'secondary', sort_order: 1, icon_name: 'website', opens_in_new_tab: true },
        { link_page_id: page.id, title: 'Join Our Email Club', url: 'https://bakudanramen.com/email-club', button_style: 'secondary', sort_order: 2, icon_name: 'email', opens_in_new_tab: true },
        { link_page_id: page.id, title: 'Specialty Ramen Events', url: 'https://bakudanramen.com/events', button_style: 'secondary', sort_order: 3, icon_name: 'events', opens_in_new_tab: true },
        { link_page_id: page.id, title: 'Instagram', url: 'https://instagram.com/bakudanramen', button_style: 'secondary', sort_order: 4, icon_name: 'instagram', opens_in_new_tab: true },
      ],
    });
  }

  // ── Super Admin ───────────────────────────────────────────────────────────
  const passwordHash = await bcrypt.hash('admin123', 10);
  await prisma.user.upsert({
    where: { email: 'admin@bakudanramen.com' },
    update: {},
    create: {
      name: 'Super Admin',
      email: 'admin@bakudanramen.com',
      password_hash: passwordHash,
      role: 'super_admin',
      brand_id: brand.id,
      is_active: true,
    },
  });

  console.log('✓ Seed complete');
  console.log('  Login: admin@bakudanramen.com / admin123');
  console.log('  Pages: /links/bakudan · /links/La Cantera · /links/stone-oak · /links/bandera');
}

main()
  .catch((e) => { console.error(e); process.exit(1); })
  .finally(() => prisma.$disconnect());
