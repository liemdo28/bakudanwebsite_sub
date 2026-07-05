'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';

export default function NewStorePage() {
  const router = useRouter();
  const [brands, setBrands] = useState<{ id: number; name: string }[]>([]);
  const [form, setForm] = useState({ brand_id: '', name: '', slug: '', location_name: '' });
  const [error, setError] = useState('');

  useEffect(() => {
    fetch('/api/admin/brands').then((r) => r.json()).then((d) => setBrands(d.data ?? []));
  }, []);

  function slugify(v: string) {
    return v.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '');
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const res = await fetch('/api/admin/stores', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(form),
    });
    if (!res.ok) { setError('Failed to create store'); return; }
    router.push('/admin/stores');
  }

  return (
    <div className="max-w-lg">
      <h1 className="text-2xl font-bold text-gray-900 mb-6">New Store</h1>
      <form onSubmit={handleSubmit} className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-4">
        {error && <div className="text-red-600 text-sm">{error}</div>}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Brand</label>
          <select required value={form.brand_id} onChange={(e) => setForm({ ...form, brand_id: e.target.value })}
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <option value="">Select brand…</option>
            {brands.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </select>
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Store Name</label>
          <input type="text" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value, slug: slugify(e.target.value) })}
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Slug</label>
          <input type="text" required value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })}
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Location</label>
          <input type="text" value={form.location_name} onChange={(e) => setForm({ ...form, location_name: e.target.value })}
            placeholder="Address or area"
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
        </div>
        <div className="flex gap-3 pt-2">
          <button type="submit" className="bg-red-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-red-700">Create Store</button>
          <button type="button" onClick={() => router.back()} className="border border-gray-300 text-gray-700 px-5 py-2 rounded-lg text-sm">Cancel</button>
        </div>
      </form>
    </div>
  );
}
