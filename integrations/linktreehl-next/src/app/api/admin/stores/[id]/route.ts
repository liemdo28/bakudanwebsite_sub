import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { requireRole } from '../../_guard';

export async function GET(_req: NextRequest, { params }: { params: { id: string } }) {
  const denied = await requireRole('super_admin', 'marketing_manager', 'store_manager', 'viewer');
  if (denied) return denied;
  const store = await prisma.store.findUnique({
    where: { id: Number(params.id) },
    include: { brand: true },
  });
  if (!store) return NextResponse.json({ error: 'Not found' }, { status: 404 });
  return NextResponse.json({ data: store });
}

export async function PUT(req: NextRequest, { params }: { params: { id: string } }) {
  const denied = await requireRole('super_admin', 'marketing_manager');
  if (denied) return denied;
  const body = await req.json();
  const store = await prisma.store.update({
    where: { id: Number(params.id) },
    data: {
      name: body.name,
      slug: body.slug,
      location_name: body.location_name,
      is_active: body.is_active,
    },
  });
  return NextResponse.json({ data: store });
}

export async function DELETE(_req: NextRequest, { params }: { params: { id: string } }) {
  const denied = await requireRole('super_admin', 'marketing_manager');
  if (denied) return denied;
  await prisma.store.delete({ where: { id: Number(params.id) } });
  return NextResponse.json({ ok: true });
}
