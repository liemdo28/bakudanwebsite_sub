import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { requireRole } from '../../../_guard';

export async function GET(_req: NextRequest, { params }: { params: { id: string } }) {
  const denied = await requireRole('super_admin', 'marketing_manager', 'store_manager', 'viewer');
  if (denied) return denied;
  const buttons = await prisma.linkButton.findMany({
    where: { link_page_id: Number(params.id) },
    orderBy: { sort_order: 'asc' },
  });
  return NextResponse.json({ data: buttons });
}

export async function POST(req: NextRequest, { params }: { params: { id: string } }) {
  const denied = await requireRole('super_admin', 'marketing_manager', 'store_manager');
  if (denied) return denied;
  const body = await req.json();

  if (body.url) {
    try { new URL(body.url); } catch {
      return NextResponse.json({ error: 'Invalid URL' }, { status: 400 });
    }
  }

  const maxOrder = await prisma.linkButton.aggregate({
    where: { link_page_id: Number(params.id) },
    _max: { sort_order: true },
  });

  const button = await prisma.linkButton.create({
    data: {
      link_page_id: Number(params.id),
      title: body.title,
      url: body.url,
      button_style: body.button_style ?? 'primary',
      sort_order: (maxOrder._max.sort_order ?? -1) + 1,
      icon_name: body.icon_name ?? null,
      is_active: body.is_active ?? true,
      is_featured: body.is_featured ?? false,
      animation: body.animation ?? null,
      opens_in_new_tab: body.opens_in_new_tab ?? true,
      start_at: body.start_at ? new Date(body.start_at) : null,
      end_at: body.end_at ? new Date(body.end_at) : null,
    },
  });
  return NextResponse.json({ data: button }, { status: 201 });
}
