import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { hashIp, parseDeviceType, getIpFromRequest } from '@/lib/analytics';

export async function POST(req: NextRequest, { params }: { params: { slug: string } }) {
  try {
    const page = await prisma.linkPage.findUnique({ where: { slug: params.slug } });
    if (!page) return NextResponse.json({ ok: false }, { status: 404 });

    const ua = req.headers.get('user-agent') ?? '';
    const referer = req.headers.get('referer') ?? null;
    const ip = getIpFromRequest(req);
    const sp = req.nextUrl.searchParams;

    prisma.linkClickEvent.create({
      data: {
        link_page_id: page.id,
        event_type: 'page_view',
        ip_hash: hashIp(ip),
        user_agent: ua.slice(0, 500),
        referer: referer?.slice(0, 1000) ?? null,
        device_type: parseDeviceType(ua),
        utm_source: sp.get('utm_source'),
        utm_medium: sp.get('utm_medium'),
        utm_campaign: sp.get('utm_campaign'),
      },
    }).catch(() => {});

    return NextResponse.json({ ok: true });
  } catch {
    return NextResponse.json({ ok: true });
  }
}
