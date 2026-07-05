import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { requireRole } from '../../_guard';

export async function GET(_req: NextRequest, { params }: { params: { id: string } }) {
  const denied = await requireRole('super_admin', 'marketing_manager', 'store_manager', 'viewer');
  if (denied) return denied;
  const brand = await prisma.brand.findUnique({ where: { id: Number(params.id) } });
  if (!brand) return NextResponse.json({ error: 'Not found' }, { status: 404 });
  return NextResponse.json({ data: brand });
}

export async function PUT(req: NextRequest, { params }: { params: { id: string } }) {
  const denied = await requireRole('super_admin');
  if (denied) return denied;
  const body = await req.json();
  const brand = await prisma.brand.update({
    where: { id: Number(params.id) },
    data: {
      name: body.name,
      slug: body.slug,
      logo_path: body.logo_path,
      primary_color: body.primary_color,
      secondary_color: body.secondary_color,
      is_active: body.is_active,
    },
  });
  return NextResponse.json({ data: brand });
}

export async function DELETE(_req: NextRequest, { params }: { params: { id: string } }) {
  const denied = await requireRole('super_admin');
  if (denied) return denied;
  await prisma.brand.delete({ where: { id: Number(params.id) } });
  return NextResponse.json({ ok: true });
}
