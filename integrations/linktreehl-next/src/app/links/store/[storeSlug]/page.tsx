import { redirect, notFound } from 'next/navigation';
import { prisma } from '@/lib/prisma';

interface Props { params: { storeSlug: string } }

export default async function StoreRedirect({ params }: Props) {
  const store = await prisma.store.findUnique({ where: { slug: params.storeSlug } });
  if (!store) notFound();

  const page = await prisma.linkPage.findFirst({
    where: { store_id: store.id, is_active: true },
    orderBy: { updated_at: 'desc' },
  });

  if (!page) notFound();
  redirect(`/links/${page.slug}`);
}
