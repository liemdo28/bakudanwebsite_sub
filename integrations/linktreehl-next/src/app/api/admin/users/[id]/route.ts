import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { requireRole } from '../../_guard';
import bcrypt from 'bcryptjs';

export async function PUT(req: NextRequest, { params }: { params: { id: string } }) {
  const denied = await requireRole('super_admin');
  if (denied) return denied;
  const body = await req.json();
  const data: any = {
    name: body.name,
    role: body.role,
    brand_id: body.brand_id ? Number(body.brand_id) : null,
    store_id: body.store_id ? Number(body.store_id) : null,
    is_active: body.is_active,
  };
  if (body.password) {
    data.password_hash = await bcrypt.hash(body.password, 10);
  }
  const user = await prisma.user.update({
    where: { id: Number(params.id) },
    data,
    select: { id: true, name: true, email: true, role: true, is_active: true },
  });
  return NextResponse.json({ data: user });
}

export async function DELETE(_req: NextRequest, { params }: { params: { id: string } }) {
  const denied = await requireRole('super_admin');
  if (denied) return denied;
  await prisma.user.delete({ where: { id: Number(params.id) } });
  return NextResponse.json({ ok: true });
}
