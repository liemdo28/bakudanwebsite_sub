'use client';

import { useState } from 'react';
import type { Brand, Store, LinkPage, LinkButton } from '@prisma/client';

type PageWithRelations = LinkPage & {
  brand: Brand;
  store: Store | null;
  buttons: LinkButton[];
};

/* ─── Icon map ────────────────────────────────────────────────── */
const ICON_MAP: Record<string, string> = {
  website: 'W', email: '✉', events: '★', instagram: 'IG',
  facebook: 'f', twitter: 'X', tiktok: '♪', youtube: '▶',
  menu: 'M', merch: '🛍', catering: '🍱', phone: '📞',
  map: '📍', order: '🥢',
};

function resolveIcon(btn: LinkButton): string {
  if (btn.icon_name && ICON_MAP[btn.icon_name]) return ICON_MAP[btn.icon_name];
  const t = btn.title.toLowerCase();
  if (t.includes('website') || t.includes('web')) return ICON_MAP.website;
  if (t.includes('email') || t.includes('club') || t.includes('signup')) return ICON_MAP.email;
  if (t.includes('event')) return ICON_MAP.events;
  if (t.includes('instagram')) return ICON_MAP.instagram;
  if (t.includes('facebook')) return ICON_MAP.facebook;
  if (t.includes('tiktok')) return ICON_MAP.tiktok;
  if (t.includes('youtube')) return ICON_MAP.youtube;
  if (t.includes('menu')) return ICON_MAP.menu;
  if (t.includes('merch')) return ICON_MAP.merch;
  if (t.includes('catering')) return ICON_MAP.catering;
  if (t.includes('order')) return ICON_MAP.order;
  if (t.includes('direction') || t.includes('map') || t.includes('location')) return ICON_MAP.map;
  return btn.title.slice(0, 2).toUpperCase();
}

/* ─── Sub-title from button title ───────────────────────────────── */
function getSub(btn: LinkButton): string | null {
  const t = btn.title.toLowerCase();
  if (t.includes('instagram')) return `@${btn.url.split('/').filter(Boolean).pop() ?? 'bakudanramen'}`;
  if (t.includes('facebook')) return 'Bakudan Ramen';
  if (t.includes('website')) return 'Menu, locations, story';
  if (t.includes('email') || t.includes('club')) return 'Specials, new menu drops, events';
  if (t.includes('event')) return 'Monthly limited-edition bowls';
  return null;
}

/* ─── Components ─────────────────────────────────────────────── */

function ChipGrid({ chips, onTrack }: { chips: LinkButton[]; onTrack: (id: number) => void }) {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: `repeat(${chips.length}, 1fr)`, gap: 8, marginBottom: 24 }}>
      {chips.map((btn) => (
        <a
          key={btn.id}
          href={btn.url}
          target={btn.opens_in_new_tab ? '_blank' : '_self'}
          rel="noopener noreferrer"
          onClick={() => onTrack(btn.id)}
          style={{
            background: '#141414',
            border: '1px solid #262626',
            borderRadius: 10,
            padding: '12px 6px',
            textAlign: 'center',
            textDecoration: 'none',
            display: 'block',
            transition: 'background 0.15s, border-color 0.15s',
          }}
          onMouseEnter={(e) => { (e.currentTarget as HTMLElement).style.borderColor = '#B91C1C'; (e.currentTarget as HTMLElement).style.background = '#1a1a1a'; }}
          onMouseLeave={(e) => { (e.currentTarget as HTMLElement).style.borderColor = '#262626'; (e.currentTarget as HTMLElement).style.background = '#141414'; }}
        >
          <div style={{ color: '#B91C1C', fontSize: 12, fontWeight: 700, letterSpacing: '0.8px', lineHeight: 1.2 }}>{btn.title}</div>
          <div style={{ color: '#666', fontSize: 10, marginTop: 3 }}>Directions</div>
        </a>
      ))}
    </div>
  );
}

function OrderCTA({ primary, subButtons, onTrack }: { primary: LinkButton; subButtons: LinkButton[]; onTrack: (id: number) => void }) {
  const [open, setOpen] = useState(false);

  const hasSub = subButtons.length > 0;

  if (!hasSub) {
    return (
      <a
        href={primary.url}
        target={primary.opens_in_new_tab ? '_blank' : '_self'}
        rel="noopener noreferrer"
        onClick={() => onTrack(primary.id)}
        style={{ display: 'block', background: '#B91C1C', color: '#fff', textDecoration: 'none', padding: '20px', borderRadius: 12, marginBottom: 10, fontWeight: 600, fontSize: 17, textAlign: 'center', letterSpacing: '0.3px', transition: 'background 0.15s' }}
        onMouseEnter={(e) => { (e.currentTarget as HTMLElement).style.background = '#A01818'; }}
        onMouseLeave={(e) => { (e.currentTarget as HTMLElement).style.background = '#B91C1C'; }}
      >
        {primary.title}
        <span style={{ display: 'block', fontSize: 12, fontWeight: 400, opacity: 0.9, marginTop: 4 }}>{getSub(primary) ?? 'Pickup · Delivery · Catering'}</span>
      </a>
    );
  }

  return (
    <>
      <button
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        style={{
          display: 'block', background: '#B91C1C', color: '#fff', padding: '20px', borderRadius: 12,
          marginBottom: open ? 0 : 10, fontWeight: 600, fontSize: 17, textAlign: 'center',
          letterSpacing: '0.3px', border: 'none', width: '100%', cursor: 'pointer',
          fontFamily: 'inherit', transition: 'background 0.15s', borderBottomLeftRadius: open ? 4 : 12, borderBottomRightRadius: open ? 4 : 12,
        }}
        onMouseEnter={(e) => { (e.currentTarget as HTMLElement).style.background = '#A01818'; }}
        onMouseLeave={(e) => { (e.currentTarget as HTMLElement).style.background = '#B91C1C'; }}
      >
        {primary.title}
        <span style={{ display: 'block', fontSize: 12, fontWeight: 400, opacity: 0.9, marginTop: 4 }}>
          Pickup · Delivery · Catering — tap to choose location
        </span>
      </button>

      <div style={{
        maxHeight: open ? 300 : 0, overflow: 'hidden',
        transition: 'max-height 0.35s ease-out', marginBottom: open ? 10 : 0,
      }}>
        <div style={{ padding: '4px 0 0 0', display: 'grid', gridTemplateColumns: `repeat(${subButtons.length}, 1fr)`, gap: 6 }}>
          {subButtons.map((btn) => (
            <a
              key={btn.id}
              href={btn.url}
              target={btn.opens_in_new_tab ? '_blank' : '_self'}
              rel="noopener noreferrer"
              onClick={() => onTrack(btn.id)}
              style={{
                background: '#2a0808', border: '1px solid #8B1313', borderRadius: 8,
                padding: '14px 6px', textAlign: 'center', textDecoration: 'none', color: '#fff',
                fontSize: 13, fontWeight: 600, letterSpacing: '0.5px', transition: 'background 0.15s', display: 'block',
              }}
              onMouseEnter={(e) => { (e.currentTarget as HTMLElement).style.background = '#3a1010'; }}
              onMouseLeave={(e) => { (e.currentTarget as HTMLElement).style.background = '#2a0808'; }}
            >
              {btn.title}
              <span style={{ display: 'block', fontSize: 10, fontWeight: 400, color: '#D4A0A0', marginTop: 2 }}>Order now</span>
            </a>
          ))}
        </div>
      </div>
    </>
  );
}

function LinkRow({ btn, onTrack }: { btn: LinkButton; onTrack: (id: number) => void }) {
  const sub = getSub(btn);
  const icon = resolveIcon(btn);

  if (btn.button_style === 'coming_soon') {
    return (
      <div style={{
        background: '#0f0f0f', color: '#666', padding: '14px 20px', borderRadius: 12,
        marginBottom: 10, border: '1px dashed #262626', fontWeight: 500, fontSize: 14, textAlign: 'center',
      }}>
        {btn.title}
      </div>
    );
  }

  return (
    <a
      href={btn.url}
      target={btn.opens_in_new_tab ? '_blank' : '_self'}
      rel="noopener noreferrer"
      onClick={() => onTrack(btn.id)}
      style={{
        display: 'flex', alignItems: 'center', background: '#141414', color: '#fff',
        textDecoration: 'none', padding: '16px 20px', borderRadius: 12, marginBottom: 10,
        border: '1px solid #262626', fontWeight: 500, fontSize: 15, transition: 'background 0.15s, border-color 0.15s, transform 0.1s',
      }}
      onMouseEnter={(e) => {
        const el = e.currentTarget as HTMLElement;
        el.style.background = '#1a1a1a'; el.style.borderColor = '#B91C1C'; el.style.transform = 'translateX(2px)';
      }}
      onMouseLeave={(e) => {
        const el = e.currentTarget as HTMLElement;
        el.style.background = '#141414'; el.style.borderColor = '#262626'; el.style.transform = 'translateX(0)';
      }}
    >
      <span style={{
        display: 'inline-flex', width: 32, height: 32, background: '#B91C1C', borderRadius: 6,
        alignItems: 'center', justifyContent: 'center', marginRight: 14, color: '#fff',
        fontWeight: 700, fontSize: 12, flexShrink: 0,
      }}>
        {icon}
      </span>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: 15, fontWeight: 500 }}>{btn.title}</div>
        {sub && <div style={{ color: '#888', fontSize: 12, fontWeight: 400, marginTop: 2 }}>{sub}</div>}
      </div>
    </a>
  );
}

/* ─── Main shell ─────────────────────────────────────────────── */
export default function LinkPageShell({ page }: { page: PageWithRelations }) {
  function trackClick(id: number) {
    fetch(`/api/buttons/${id}/track-click`, { method: 'POST' }).catch(() => {});
  }

  // Partition buttons by style
  const chips = page.buttons.filter((b) => b.button_style === 'chip');
  const primaryBtn = page.buttons.find((b) => b.button_style === 'primary');
  const orderSubs = page.buttons.filter((b) => b.button_style === 'order_sub');
  const rows = page.buttons.filter(
    (b) => b.button_style !== 'chip' && b.button_style !== 'primary' && b.button_style !== 'order_sub'
  );

  const displayName = page.store?.name?.replace('Bakudan Ramen – ', '') ?? page.brand.name;
  const location = page.store?.location_name ?? 'San Antonio, TX';

  return (
    <div style={{
      fontFamily: "'Helvetica Neue', Helvetica, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
      background: '#0a0a0a', color: '#fff', minHeight: '100vh', padding: '48px 20px 64px',
    }}>
      <style>{`
        @keyframes bkdn-fadeup {
          from { opacity: 0; transform: translateY(8px); }
          to   { opacity: 1; transform: translateY(0); }
        }
        .bkdn-section { animation: bkdn-fadeup 0.5s ease-out both; }
        .bkdn-section:nth-child(1) { animation-delay: 0.00s; }
        .bkdn-section:nth-child(2) { animation-delay: 0.06s; }
        .bkdn-section:nth-child(3) { animation-delay: 0.12s; }
        .bkdn-section:nth-child(4) { animation-delay: 0.18s; }
        .bkdn-section:nth-child(5) { animation-delay: 0.24s; }
        .bkdn-section:nth-child(6) { animation-delay: 0.30s; }
        .bkdn-section:nth-child(7) { animation-delay: 0.36s; }
        .bkdn-section:nth-child(8) { animation-delay: 0.42s; }
        .bkdn-section:nth-child(9) { animation-delay: 0.48s; }
      `}</style>

      <div style={{ maxWidth: 460, margin: '0 auto' }}>

        {/* Hero */}
        <div className="bkdn-section" style={{ textAlign: 'center', marginBottom: 32 }}>
          <div style={{
            width: 96, height: 96, margin: '0 auto 20px',
            background: '#B91C1C', borderRadius: '50%',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            fontSize: 46, fontWeight: 700, color: '#fff',
            border: '3px solid #8B1313', lineHeight: 1,
          }}>
            爆
          </div>
          <h1 style={{ fontSize: 26, fontWeight: 700, letterSpacing: 2, margin: '0 0 8px', color: '#fff' }}>
            {page.headline ?? 'BAKUDAN RAMEN'}
          </h1>
          <div style={{ width: 48, height: 2, background: '#B91C1C', margin: '8px auto 10px' }} />
          <p style={{ fontSize: 12, color: '#999', margin: 0, letterSpacing: '1.5px', textTransform: 'uppercase' }}>
            {page.subheadline ?? `${displayName} · ${location}`}
          </p>
        </div>

        {/* Location chips */}
        {chips.length > 0 && (
          <div className="bkdn-section">
            <ChipGrid chips={chips} onTrack={trackClick} />
          </div>
        )}

        {/* Primary CTA (Order) */}
        {primaryBtn && (
          <div className="bkdn-section">
            <OrderCTA primary={primaryBtn} subButtons={orderSubs} onTrack={trackClick} />
          </div>
        )}

        {/* Link rows */}
        {rows.map((btn) => (
          <div key={btn.id} className="bkdn-section">
            <LinkRow btn={btn} onTrack={trackClick} />
          </div>
        ))}

        {/* Footer */}
        <div className="bkdn-section" style={{ textAlign: 'center', borderTop: '1px solid #1a1a1a', paddingTop: 20, marginTop: 8 }}>
          <p style={{ color: '#555', fontSize: 11, margin: '0 0 4px', letterSpacing: '0.3px' }}>
            © {new Date().getFullYear()} Bakudan Ramen
          </p>
          <p style={{ color: '#555', fontSize: 11, margin: 0 }}>bakudanramen.com</p>
        </div>

      </div>
    </div>
  );
}
