import { revalidatePath } from 'next/cache';
import { NextRequest, NextResponse } from 'next/server';

export async function POST(req: NextRequest) {
  const { slug } = await req.json();
  if (!slug) return NextResponse.json({ error: 'slug required' }, { status: 400 });
  revalidatePath(`/links/${slug}`);
  revalidatePath('/links');
  return NextResponse.json({ revalidated: true });
}
