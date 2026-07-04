import { NextRequest, NextResponse } from 'next/server';
import { saveUploadedFile } from '@/lib/upload';
import { requireRole } from '../_guard';

export async function POST(req: NextRequest) {
  const denied = await requireRole('super_admin', 'marketing_manager', 'store_manager');
  if (denied) return denied;

  const formData = await req.formData();
  const file = formData.get('file') as File | null;
  const category = (formData.get('category') as string) ?? 'heroes';

  if (!file) return NextResponse.json({ error: 'No file provided' }, { status: 400 });
  if (file.size > 10 * 1024 * 1024) return NextResponse.json({ error: 'File too large (max 10MB)' }, { status: 400 });

  const validCategories = ['logos', 'heroes', 'social'];
  if (!validCategories.includes(category)) {
    return NextResponse.json({ error: 'Invalid category' }, { status: 400 });
  }

  const filePath = await saveUploadedFile(file, category as any);
  return NextResponse.json({ data: { path: filePath } });
}
