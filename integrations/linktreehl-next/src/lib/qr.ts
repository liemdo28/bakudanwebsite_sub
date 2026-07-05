import QRCode from 'qrcode';
import path from 'path';
import fs from 'fs/promises';

export async function generateQrPng(url: string, slug: string): Promise<string> {
  const dir = path.join(process.cwd(), 'public', 'uploads', 'qr');
  await fs.mkdir(dir, { recursive: true });
  const filename = `${slug}-${Date.now()}.png`;
  const filePath = path.join(dir, filename);
  await QRCode.toFile(filePath, url, {
    type: 'png',
    width: 400,
    margin: 2,
    color: { dark: '#1A1A1A', light: '#FFFFFF' },
  });
  return `/uploads/qr/${filename}`;
}

export async function generateQrSvg(url: string, slug: string): Promise<string> {
  const dir = path.join(process.cwd(), 'public', 'uploads', 'qr');
  await fs.mkdir(dir, { recursive: true });
  const filename = `${slug}-${Date.now()}.svg`;
  const filePath = path.join(dir, filename);
  const svg = await QRCode.toString(url, { type: 'svg' });
  await fs.writeFile(filePath, svg);
  return `/uploads/qr/${filename}`;
}
