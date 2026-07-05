'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';

export default function NewPagePage() {
  const router = useRouter();
  const [brands, setBrands] = useState<{ id: number; name: string }[]>([]);
  const [stores, setStores] = useState<{ id: number; name: string }[]>([]);
  const [form, setForm] = useState({
    brand_id: '', store_id: '', title: '', slug: '',
    headline: '', subheadline: '', seo_title: '', seo_description: '',
  });
  const [error, setError] = useState('');

  useEffect(() => {
    fetch('/api/admin/brands').then((r) => r.json()).then((d) => setBrands(d.data ?? []));
    fetch('/api/admin/stores').then((r) => r.json()).then((d) => setStores(d.data ?? []));
  }, []);

  function slugify(v: string) {
    return v.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '');
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const res = await fetch('/api/admin/pages', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...form, brand_id: Number(form.brand_id), store_id: form.store_id ? Number(form.store_id) : null }),
    });
    if (!res.ok) { setError('Failed to create page'); return; }
    const data = await res.json();
    router.push(`/admin/pages/${data.data.id}`);
  }

  return (
    <div className="max-w-xl">
      <h1 className="text-2xl font-bold text-gray-900 mb-6">New Link Page</h1>
      <form onSubmit={handleSubmit} className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-4">
        {error && <div className="text-red-600 text-sm">{error}</div>}
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Brand *</label>
            <select required value={form.brand_id} onChange={(e) => setForm({ ...form, brand_id: e.target.value })}
              className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
              <option value="">Select…</option>
              {brands.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Store (optional)</label>
            <select value={form.store_id} onChange={(e) => setForm({ ...form, store_id: e.target.value })}
              className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
              <option value="">Brand-level page</option>
              {stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
          </div>
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Title *</label>
          <input type="text" required value={form.title}
            onChange={(e) => setForm({ ...form, title: e.target.value, slug: slugify(e.target.value) })}
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Slug * <span className="text-gray-400 font-normal">(URL: /links/{form.slug || '…'})</span></label>
          <input type="text" required value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })}
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Headline</label>
          <input type="text" value={form.headline} onChange={(e) => setForm({ ...form, headline: e.target.value })}
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Subheadline</label>
          <input type="text" value={form.subheadline} onChange={(e) => setForm({ ...form, subheadline: e.target.value })}
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
        </div>
        <div className="flex gap-3 pt-2">
          <button type="submit" className="bg-red-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-red-700">Create & Edit</button>
          <button type="button" onClick={() => router.back()} className="border border-gray-300 text-gray-700 px-5 py-2 rounded-lg text-sm">Cancel</button>
        </div>
      </form>
    </div>
  );
}
