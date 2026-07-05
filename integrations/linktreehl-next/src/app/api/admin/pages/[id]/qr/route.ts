import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { generateQrPng, generateQrSvg } from '@/lib/qr';
import { requireRole } from '../../../_guard';

export async function POST(_req: NextRequest, { params }: { params: { id: string } }) {
  const denied = await requireRole('super_admin', 'marketing_manager', 'store_manager');
  if (denied) return denied;

  const page = await prisma.linkPage.findUnique({ where: { id: Number(params.id) } });
  if (!page) return NextResponse.json({ error: 'Not found' }, { status: 404 });

  const pageUrl = `${process.env.NEXT_PUBLIC_BASE_URL}/links/${page.slug}`;
  const [pngPath, svgPath] = await Promise.all([
    generateQrPng(pageUrl, page.slug),
    generateQrSvg(pageUrl, page.slug),
  ]);

  const [pngAsset, svgAsset] = await Promise.all([
    prisma.qrAsset.create({ data: { link_page_id: page.id, file_path: pngPath, format: 'png' } }),
    prisma.qrAsset.create({ data: { link_page_id: page.id, file_path: svgPath, format: 'svg' } }),
  ]);

  return NextResponse.json({ data: { png: pngAsset, svg: svgAsset } });
}
