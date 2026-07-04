import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { requireRole } from '../_guard';

export async function GET() {
  const denied = await requireRole('super_admin', 'marketing_manager', 'store_manager', 'viewer');
  if (denied) return denied;
  const brands = await prisma.brand.findMany({ orderBy: { name: 'asc' } });
  return NextResponse.json({ data: brands });
}

export async function POST(req: NextRequest) {
  const denied = await requireRole('super_admin');
  if (denied) return denied;
  const body = await req.json();
  const brand = await prisma.brand.create({
    data: {
      name: body.name,
      slug: body.slug,
      logo_path: body.logo_path ?? null,
      primary_color: body.primary_color ?? '#C8102E',
      secondary_color: body.secondary_color ?? '#1A1A1A',
      is_active: body.is_active ?? true,
    },
  });
  return NextResponse.json({ data: brand }, { status: 201 });
}
