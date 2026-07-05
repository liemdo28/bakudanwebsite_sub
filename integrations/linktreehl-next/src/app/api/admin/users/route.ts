import { NextRequest, NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { requireRole } from '../_guard';
import bcrypt from 'bcryptjs';

export async function GET() {
  const denied = await requireRole('super_admin');
  if (denied) return denied;
  const users = await prisma.user.findMany({
    select: { id: true, name: true, email: true, role: true, is_active: true, brand_id: true, store_id: true, created_at: true },
    orderBy: { created_at: 'desc' },
  });
  return NextResponse.json({ data: users });
}

export async function POST(req: NextRequest) {
  const denied = await requireRole('super_admin');
  if (denied) return denied;
  const body = await req.json();
  const password_hash = await bcrypt.hash(body.password, 10);
  const user = await prisma.user.create({
    data: {
      name: body.name,
      email: body.email,
      password_hash,
      role: body.role ?? 'viewer',
      brand_id: body.brand_id ? Number(body.brand_id) : null,
      store_id: body.store_id ? Number(body.store_id) : null,
      is_active: body.is_active ?? true,
    },
    select: { id: true, name: true, email: true, role: true, is_active: true, brand_id: true, store_id: true },
  });
  return NextResponse.json({ data: user }, { status: 201 });
}
