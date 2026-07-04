import { notFound } from 'next/navigation';
import type { Metadata } from 'next';
import { prisma } from '@/lib/prisma';
import LinkPageShell from '@/components/public/LinkPageShell';
import TrackPageView from '@/components/public/TrackPageView';

interface Props { params: { slug: string } }

async function getPage(slug: string) {
  const now = new Date();
  return prisma.linkPage.findFirst({
    where: {
      slug,
      is_active: true,
      published_at: { lte: now },
      OR: [{ expires_at: null }, { expires_at: { gte: now } }],
    },
    include: {
      brand: true,
      store: true,
      buttons: {
        where: {
          is_active: true,
          OR: [{ start_at: null }, { start_at: { lte: now } }],
          AND: [{ OR: [{ end_at: null }, { end_at: { gte: now } }] }],
        },
        orderBy: { sort_order: 'asc' },
      },
    },
  });
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const page = await getPage(params.slug);
  if (!page) return { title: 'Not Found' };
  return {
    title: page.seo_title ?? page.title,
    description: page.seo_description ?? page.subheadline ?? undefined,
    openGraph: {
      title: page.seo_title ?? page.title,
      description: page.seo_description ?? undefined,
      images: page.social_image_path ? [page.social_image_path] : [],
    },
  };
}

export default async function LinkPageRoute({ params }: Props) {
  const page = await getPage(params.slug);
  if (!page) notFound();
  return (
    <>
      <TrackPageView slug={params.slug} />
      <LinkPageShell page={page} />
    </>
  );
}

export const revalidate = 60;
