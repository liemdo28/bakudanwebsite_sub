import { prisma } from '@/lib/prisma';
import Link from 'next/link';

export default async function AdminDashboard() {
  const [pageCount, brandCount, storeCount, clicksToday] = await Promise.all([
    prisma.linkPage.count({ where: { is_active: true } }),
    prisma.brand.count({ where: { is_active: true } }),
    prisma.store.count({ where: { is_active: true } }),
    prisma.linkClickEvent.count({
      where: {
        event_type: 'button_click',
        created_at: { gte: new Date(new Date().setHours(0, 0, 0, 0)) },
      },
    }),
  ]);

  const topPages = await prisma.linkClickEvent.groupBy({
    by: ['link_page_id'],
    where: { event_type: 'page_view', created_at: { gte: new Date(Date.now() - 7 * 24 * 60 * 60 * 1000) } },
    _count: { link_page_id: true },
    orderBy: { _count: { link_page_id: 'desc' } },
    take: 5,
  });

  const pageIds = topPages.map((p) => p.link_page_id);
  const pages = pageIds.length
    ? await prisma.linkPage.findMany({ where: { id: { in: pageIds } }, select: { id: true, title: true, slug: true } })
    : [];

  const stats = [
    { label: 'Active Pages', value: pageCount, href: '/admin/pages' },
    { label: 'Brands', value: brandCount, href: '/admin/brands' },
    { label: 'Stores', value: storeCount, href: '/admin/stores' },
    { label: 'Clicks Today', value: clicksToday, href: '/admin/analytics' },
  ];

  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Dashboard</h1>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        {stats.map((s) => (
          <Link key={s.label} href={s.href} className="bg-white rounded-xl p-4 shadow-sm border border-gray-100 hover:shadow-md transition">
            <div className="text-3xl font-bold text-gray-900">{s.value}</div>
            <div className="text-sm text-gray-500 mt-1">{s.label}</div>
          </Link>
        ))}
      </div>

      <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-semibold text-gray-800">Top Pages (Last 7 Days)</h2>
          <Link href="/admin/analytics" className="text-sm text-red-600 hover:underline">View all</Link>
        </div>
        {topPages.length === 0 ? (
          <p className="text-gray-400 text-sm">No data yet.</p>
        ) : (
          <ul className="space-y-2">
            {topPages.map((tp) => {
              const page = pages.find((p) => p.id === tp.link_page_id);
              return (
                <li key={tp.link_page_id} className="flex items-center justify-between text-sm">
                  <span className="text-gray-700">{page?.title ?? 'Unknown'}</span>
                  <span className="text-gray-500 font-medium">{tp._count.link_page_id} views</span>
                </li>
              );
            })}
          </ul>
        )}
      </div>

      <div className="mt-6 bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h2 className="font-semibold text-gray-800 mb-4">Quick Actions</h2>
        <div className="flex flex-wrap gap-3">
          <Link href="/admin/pages/new" className="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-red-700 transition">
            + New Link Page
          </Link>
          <Link href="/admin/brands/new" className="bg-gray-800 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-900 transition">
            + New Brand
          </Link>
          <Link href="/admin/stores/new" className="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-50 transition">
            + New Store
          </Link>
        </div>
      </div>
    </div>
  );
}
