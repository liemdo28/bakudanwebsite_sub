import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { requireRole } from '../../_guard';

export async function PUT(req: NextRequest, { params }: { params: { id: string } }) {
  const denied = await requireRole('super_admin', 'marketing_manager', 'store_manager');
  if (denied) return denied;
  const body = await req.json();

  if (body.url) {
    try { new URL(body.url); } catch {
      return NextResponse.json({ error: 'Invalid URL' }, { status: 400 });
    }
  }

  const button = await prisma.linkButton.update({
    where: { id: Number(params.id) },
    data: {
      title: body.title,
      url: body.url,
      button_style: body.button_style,
      icon_name: body.icon_name,
      is_active: body.is_active,
      is_featured: body.is_featured,
      animation: body.animation,
      opens_in_new_tab: body.opens_in_new_tab,
      start_at: body.start_at ? new Date(body.start_at) : null,
      end_at: body.end_at ? new Date(body.end_at) : null,
    },
  });
  return NextResponse.json({ data: button });
}

export async function DELETE(_req: NextRequest, { params }: { params: { id: string } }) {
  const denied = await requireRole('super_admin', 'marketing_manager', 'store_manager');
  if (denied) return denied;
  await prisma.linkButton.delete({ where: { id: Number(params.id) } });
  return NextResponse.json({ ok: true });
}
