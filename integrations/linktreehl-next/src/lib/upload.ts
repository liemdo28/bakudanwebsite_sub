import path from 'path';
import fs from 'fs/promises';

type UploadCategory = 'logos' | 'heroes' | 'social';

export async function saveUploadedFile(
  file: File,
  category: UploadCategory
): Promise<string> {
  const bytes = await file.arrayBuffer();
  const buffer = Buffer.from(bytes);
  const ext = file.name.split('.').pop()?.toLowerCase() ?? 'jpg';
  const allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
  if (!allowedExts.includes(ext)) throw new Error('Invalid file type');
  const filename = `${Date.now()}-${Math.random().toString(36).slice(2)}.${ext}`;
  const dir = path.join(process.cwd(), 'public', 'uploads', category);
  await fs.mkdir(dir, { recursive: true });
  await fs.writeFile(path.join(dir, filename), buffer);
  return `/uploads/${category}/${filename}`;
}
