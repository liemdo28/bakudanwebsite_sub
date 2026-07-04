'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { QrCodeIcon, ArrowTopRightOnSquareIcon } from '@heroicons/react/24/outline';

interface LinkPageRow {
  id: number; title: string; slug: string; is_active: boolean;
  brand: { name: string }; store: { name: string } | null;
  _count: { buttons: number; click_events: number };
}

export default function PagesPage() {
  const [pages, setPages] = useState<LinkPageRow[]>([]);
  const [qrLoading, setQrLoading] = useState<number | null>(null);

  useEffect(() => {
    fetch('/api/admin/pages').then((r) => r.json()).then((d) => setPages(d.data ?? []));
  }, []);

  async function generateQR(id: number) {
    setQrLoading(id);
    const res = await fetch(`/api/admin/pages/${id}/qr`, { method: 'POST' });
    const data = await res.json();
    setQrLoading(null);
    if (data.data?.png) window.open(data.data.png.file_path, '_blank');
  }

  async function toggleActive(id: number, current: boolean) {
    await fetch(`/api/admin/pages/${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ is_active: !current }),
    });
    setPages((prev) => prev.map((p) => p.id === id ? { ...p, is_active: !current } : p));
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Link Pages</h1>
        <Link href="/admin/pages/new" className="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-red-700 transition">
          + New Page
        </Link>
      </div>
      <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 border-b border-gray-200">
            <tr>
              <th className="px-4 py-3 text-left font-medium text-gray-600">Title</th>
              <th className="px-4 py-3 text-left font-medium text-gray-600">Store</th>
              <th className="px-4 py-3 text-left font-medium text-gray-600">Buttons</th>
              <th className="px-4 py-3 text-left font-medium text-gray-600">Events</th>
              <th className="px-4 py-3 text-left font-medium text-gray-600">Status</th>
              <th className="px-4 py-3 text-left font-medium text-gray-600">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {pages.map((p) => (
              <tr key={p.id} className="hover:bg-gray-50">
                <td className="px-4 py-3">
                  <div className="font-medium text-gray-900">{p.title}</div>
                  <div className="text-xs text-gray-400 font-mono">/links/{p.slug}</div>
                </td>
                <td className="px-4 py-3 text-gray-500">{p.store?.name ?? p.brand.name}</td>
                <td className="px-4 py-3 text-gray-500">{p._count.buttons}</td>
                <td className="px-4 py-3 text-gray-500">{p._count.click_events}</td>
                <td className="px-4 py-3">
                  <button onClick={() => toggleActive(p.id, p.is_active)}
                    className={`px-2 py-1 rounded-full text-xs font-medium ${p.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}`}>
                    {p.is_active ? 'Active' : 'Inactive'}
                  </button>
                </td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-3">
                    <Link href={`/admin/pages/${p.id}`} className="text-red-600 hover:underline text-xs">Edit</Link>
                    <Link href={`/admin/pages/${p.id}/analytics`} className="text-gray-500 hover:underline text-xs">Stats</Link>
                    <button onClick={() => generateQR(p.id)} disabled={qrLoading === p.id} title="Generate QR">
                      <QrCodeIcon className="w-4 h-4 text-gray-400 hover:text-gray-700" />
                    </button>
                    <a href={`/links/${p.slug}`} target="_blank" rel="noopener noreferrer" title="Preview">
                      <ArrowTopRightOnSquareIcon className="w-4 h-4 text-gray-400 hover:text-gray-700" />
                    </a>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
