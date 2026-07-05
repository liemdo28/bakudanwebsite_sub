import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { requireRole } from '../../../../_guard';

export async function PATCH(req: NextRequest, { params }: { params: { id: string } }) {
  const denied = await requireRole('super_admin', 'marketing_manager', 'store_manager');
  if (denied) return denied;

  const { order } = (await req.json()) as { order: number[] };
  if (!Array.isArray(order)) return NextResponse.json({ error: 'order must be array' }, { status: 400 });

  await prisma.$transaction(
    order.map((buttonId, index) =>
      prisma.linkButton.update({
        where: { id: buttonId, link_page_id: Number(params.id) },
        data: { sort_order: index },
      })
    )
  );

  return NextResponse.json({ ok: true });
}
