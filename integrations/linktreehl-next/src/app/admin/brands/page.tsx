'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';

interface Brand {
  id: number; name: string; slug: string;
  primary_color: string; secondary_color: string; is_active: boolean;
}

export default function BrandsPage() {
  const [brands, setBrands] = useState<Brand[]>([]);

  useEffect(() => {
    fetch('/api/admin/brands').then((r) => r.json()).then((d) => setBrands(d.data ?? []));
  }, []);

  async function toggleActive(id: number, current: boolean) {
    await fetch(`/api/admin/brands/${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ is_active: !current }),
    });
    setBrands((prev) => prev.map((b) => b.id === id ? { ...b, is_active: !current } : b));
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Brands</h1>
        <Link href="/admin/brands/new" className="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-red-700 transition">
          + New Brand
        </Link>
      </div>
      <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 border-b border-gray-200">
            <tr>
              <th className="px-4 py-3 text-left font-medium text-gray-600">Name</th>
              <th className="px-4 py-3 text-left font-medium text-gray-600">Slug</th>
              <th className="px-4 py-3 text-left font-medium text-gray-600">Colors</th>
              <th className="px-4 py-3 text-left font-medium text-gray-600">Status</th>
              <th className="px-4 py-3 text-left font-medium text-gray-600">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {brands.map((b) => (
              <tr key={b.id} className="hover:bg-gray-50">
                <td className="px-4 py-3 font-medium text-gray-900">{b.name}</td>
                <td className="px-4 py-3 text-gray-500 font-mono">{b.slug}</td>
                <td className="px-4 py-3">
                  <div className="flex gap-2">
                    <span className="w-5 h-5 rounded-full border border-gray-200" style={{ background: b.primary_color }} title={b.primary_color} />
                    <span className="w-5 h-5 rounded-full border border-gray-200" style={{ background: b.secondary_color }} title={b.secondary_color} />
                  </div>
                </td>
                <td className="px-4 py-3">
                  <button onClick={() => toggleActive(b.id, b.is_active)}
                    className={`px-2 py-1 rounded-full text-xs font-medium ${b.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}`}>
                    {b.is_active ? 'Active' : 'Inactive'}
                  </button>
                </td>
                <td className="px-4 py-3">
                  <Link href={`/admin/brands/${b.id}`} className="text-red-600 hover:underline text-xs mr-3">Edit</Link>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
