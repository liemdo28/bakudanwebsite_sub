import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { hashIp, parseDeviceType, getIpFromRequest } from '@/lib/analytics';

export async function POST(req: NextRequest, { params }: { params: { id: string } }) {
  try {
    const buttonId = Number(params.id);
    const button = await prisma.linkButton.findUnique({
      where: { id: buttonId },
      include: { link_page: true },
    });
    if (!button) return NextResponse.json({ ok: false }, { status: 404 });

    const ua = req.headers.get('user-agent') ?? '';
    const referer = req.headers.get('referer') ?? null;
    const ip = getIpFromRequest(req);

    prisma.linkClickEvent.create({
      data: {
        link_page_id: button.link_page_id,
        link_button_id: buttonId,
        event_type: 'button_click',
        ip_hash: hashIp(ip),
        user_agent: ua.slice(0, 500),
        referer: referer?.slice(0, 1000) ?? null,
        device_type: parseDeviceType(ua),
      },
    }).catch(() => {});

    return NextResponse.json({ ok: true });
  } catch {
    return NextResponse.json({ ok: true });
  }
}
