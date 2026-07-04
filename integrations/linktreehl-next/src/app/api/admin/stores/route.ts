import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { requireRole, getSession } from '../_guard';

export async function GET(req: NextRequest) {
  const denied = await requireRole('super_admin', 'marketing_manager', 'store_manager', 'viewer');
  if (denied) return denied;
  const session = await getSession();
  const user = session!.user as any;
  const brandId = req.nextUrl.searchParams.get('brand_id');

  const where: any = {};
  if (brandId) where.brand_id = Number(brandId);
  if (user.role === 'store_manager' && user.storeId) {
    where.id = user.storeId;
  }

  const stores = await prisma.store.findMany({
    where,
    include: { brand: { select: { name: true } } },
    orderBy: { name: 'asc' },
  });
  return NextResponse.json({ data: stores });
}

export async function POST(req: NextRequest) {
  const denied = await requireRole('super_admin', 'marketing_manager');
  if (denied) return denied;
  const body = await req.json();
  const store = await prisma.store.create({
    data: {
      brand_id: Number(body.brand_id),
      name: body.name,
      slug: body.slug,
      location_name: body.location_name ?? null,
      is_active: body.is_active ?? true,
    },
  });
  return NextResponse.json({ data: store }, { status: 201 });
}
