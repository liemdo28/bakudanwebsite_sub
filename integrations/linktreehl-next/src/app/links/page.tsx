import { prisma } from '@/lib/prisma';
import Link from 'next/link';
import type { Metadata } from 'next';

export const metadata: Metadata = {
  title: 'Bakudan Ramen — All Links',
  description: 'Find your Bakudan Ramen location.',
};

export default async function LinksHub() {
  const pages = await prisma.linkPage.findMany({
    where: { is_active: true, published_at: { lte: new Date() } },
    include: { brand: { select: { name: true, primary_color: true } }, store: { select: { name: true, location_name: true } } },
    orderBy: { title: 'asc' },
  });

  return (
    <main className="min-h-screen bg-white flex flex-col items-center px-4 py-12">
      <div className="w-full max-w-sm">
        <h1 className="text-3xl font-bold text-center mb-2" style={{ color: '#C8102E' }}>Bakudan Ramen</h1>
        <p className="text-center text-gray-500 mb-8 text-sm">Choose your location</p>
        <div className="flex flex-col gap-3">
          {pages.map((page) => (
            <Link
              key={page.id}
              href={`/links/${page.slug}`}
              className="block w-full rounded-xl px-4 py-4 text-center font-semibold border-2 transition-all hover:opacity-90"
              style={{ borderColor: page.brand.primary_color, color: page.brand.primary_color }}
            >
              <div className="font-bold">{page.store?.name ?? page.brand.name}</div>
              {page.store?.location_name && (
                <div className="text-xs font-normal opacity-70 mt-1">{page.store.location_name}</div>
              )}
            </Link>
          ))}
        </div>
      </div>
    </main>
  );
}

export const revalidate = 60;
