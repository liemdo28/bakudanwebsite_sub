import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { requireRole, getSession } from '../_guard';

export async function GET(req: NextRequest) {
  const denied = await requireRole('super_admin', 'marketing_manager', 'store_manager', 'viewer');
  if (denied) return denied;
  const session = await getSession();
  const user = session!.user as any;

  const where: any = {};
  if (user.role === 'store_manager' && user.storeId) where.store_id = user.storeId;

  const storeId = req.nextUrl.searchParams.get('store_id');
  if (storeId) where.store_id = Number(storeId);

  const pages = await prisma.linkPage.findMany({
    where,
    include: {
      brand: { select: { name: true, slug: true } },
      store: { select: { name: true, slug: true } },
      _count: { select: { buttons: true, click_events: true } },
    },
    orderBy: { updated_at: 'desc' },
  });
  return NextResponse.json({ data: pages });
}

export async function POST(req: NextRequest) {
  const denied = await requireRole('super_admin', 'marketing_manager', 'store_manager');
  if (denied) return denied;
  const body = await req.json();
  const page = await prisma.linkPage.create({
    data: {
      brand_id: Number(body.brand_id),
      store_id: body.store_id ? Number(body.store_id) : null,
      title: body.title,
      slug: body.slug,
      headline: body.headline ?? null,
      subheadline: body.subheadline ?? null,
      seo_title: body.seo_title ?? null,
      seo_description: body.seo_description ?? null,
      theme_json: body.theme_json ?? { background: '#FFFFFF', text: '#1A1A1A', button: '#C8102E', buttonText: '#FFFFFF', accent: '#C8102E' },
      is_active: body.is_active ?? true,
      published_at: body.published_at ? new Date(body.published_at) : new Date(),
      expires_at: body.expires_at ? new Date(body.expires_at) : null,
    },
  });

  await fetch(`${process.env.NEXT_PUBLIC_BASE_URL}/api/revalidate`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ slug: page.slug }),
  }).catch(() => {});

  return NextResponse.json({ data: page }, { status: 201 });
}
