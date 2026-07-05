import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { requireRole } from '../_guard';

export async function GET(req: NextRequest) {
  const denied = await requireRole('super_admin', 'marketing_manager', 'store_manager', 'viewer');
  if (denied) return denied;

  const sp = req.nextUrl.searchParams;
  const pageId = sp.get('page_id') ? Number(sp.get('page_id')) : undefined;
  const from = sp.get('from') ? new Date(sp.get('from')!) : new Date(Date.now() - 30 * 24 * 60 * 60 * 1000);
  const to = sp.get('to') ? new Date(sp.get('to')!) : new Date();

  const where: any = { created_at: { gte: from, lte: to } };
  if (pageId) where.link_page_id = pageId;

  const [totalViews, totalClicks, deviceBreakdown, topButtons, dailyViews] = await Promise.all([
    prisma.linkClickEvent.count({ where: { ...where, event_type: 'page_view' } }),
    prisma.linkClickEvent.count({ where: { ...where, event_type: 'button_click' } }),
    prisma.linkClickEvent.groupBy({
      by: ['device_type'],
      where: { ...where, event_type: 'page_view' },
      _count: { device_type: true },
    }),
    prisma.linkClickEvent.groupBy({
      by: ['link_button_id'],
      where: { ...where, event_type: 'button_click', link_button_id: { not: null } },
      _count: { link_button_id: true },
      orderBy: { _count: { link_button_id: 'desc' } },
      take: 10,
    }),
    prisma.$queryRaw<{ date: string; views: number }[]>`
      SELECT DATE(created_at) as date, COUNT(*) as views
      FROM link_click_events
      WHERE event_type = 'page_view'
        AND created_at >= ${from}
        AND created_at <= ${to}
        ${pageId ? prisma.$queryRaw`AND link_page_id = ${pageId}` : prisma.$queryRaw``}
      GROUP BY DATE(created_at)
      ORDER BY date ASC
    `,
  ]);

  const ctr = totalViews > 0 ? ((totalClicks / totalViews) * 100).toFixed(1) : '0.0';

  const buttonIds = topButtons.map((b) => b.link_button_id!);
  const buttonDetails = buttonIds.length
    ? await prisma.linkButton.findMany({ where: { id: { in: buttonIds } }, select: { id: true, title: true, url: true } })
    : [];

  const topButtonsWithDetails = topButtons.map((b) => ({
    button: buttonDetails.find((d) => d.id === b.link_button_id),
    clicks: b._count.link_button_id,
  }));

  return NextResponse.json({
    data: {
      totalViews,
      totalClicks,
      ctr,
      deviceBreakdown,
      topButtons: topButtonsWithDetails,
      dailyViews,
    },
  });
}
