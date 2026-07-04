import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { requireRole } from '../../_guard';

export async function GET(_req: NextRequest, { params }: { params: { id: string } }) {
  const denied = await requireRole('super_admin', 'marketing_manager', 'store_manager', 'viewer');
  if (denied) return denied;
  const page = await prisma.linkPage.findUnique({
    where: { id: Number(params.id) },
    include: {
      brand: true,
      store: true,
      buttons: { orderBy: { sort_order: 'asc' } },
      qr_assets: { orderBy: { created_at: 'desc' }, take: 2 },
    },
  });
  if (!page) return NextResponse.json({ error: 'Not found' }, { status: 404 });
  return NextResponse.json({ data: page });
}

export async function PUT(req: NextRequest, { params }: { params: { id: string } }) {
  const denied = await requireRole('super_admin', 'marketing_manager', 'store_manager');
  if (denied) return denied;
  const body = await req.json();
  const page = await prisma.linkPage.update({
    where: { id: Number(params.id) },
    data: {
      title: body.title,
      slug: body.slug,
      headline: body.headline,
      subheadline: body.subheadline,
      hero_image_path: body.hero_image_path,
      seo_title: body.seo_title,
      seo_description: body.seo_description,
      social_image_path: body.social_image_path,
      theme_json: body.theme_json,
      is_active: body.is_active,
      published_at: body.published_at ? new Date(body.published_at) : undefined,
      expires_at: body.expires_at ? new Date(body.expires_at) : null,
    },
  });

  await fetch(`${process.env.NEXT_PUBLIC_BASE_URL}/api/revalidate`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ slug: page.slug }),
  }).catch(() => {});

  return NextResponse.json({ data: page });
}

export async function DELETE(_req: NextRequest, { params }: { params: { id: string } }) {
  const denied = await requireRole('super_admin', 'marketing_manager');
  if (denied) return denied;
  await prisma.linkPage.delete({ where: { id: Number(params.id) } });
  return NextResponse.json({ ok: true });
}
