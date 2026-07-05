'use client';

import { useEffect, useState } from 'react';
import { useRouter, useParams } from 'next/navigation';

export default function EditBrandPage() {
  const router = useRouter();
  const params = useParams();
  const id = params.id as string;
  const [form, setForm] = useState({ name: '', slug: '', primary_color: '#C8102E', secondary_color: '#1A1A1A', is_active: true });
  const [error, setError] = useState('');

  useEffect(() => {
    fetch(`/api/admin/brands/${id}`).then((r) => r.json()).then((d) => {
      if (d.data) setForm({ name: d.data.name, slug: d.data.slug, primary_color: d.data.primary_color, secondary_color: d.data.secondary_color, is_active: d.data.is_active });
    });
  }, [id]);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const res = await fetch(`/api/admin/brands/${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(form),
    });
    if (!res.ok) { setError('Failed to update brand'); return; }
    router.push('/admin/brands');
  }

  return (
    <div className="max-w-lg">
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Edit Brand</h1>
      <form onSubmit={handleSubmit} className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-4">
        {error && <div className="text-red-600 text-sm">{error}</div>}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Brand Name</label>
          <input type="text" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })}
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Slug</label>
          <input type="text" required value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })}
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" />
        </div>
        <div className="flex gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Primary Color</label>
            <input type="color" value={form.primary_color} onChange={(e) => setForm({ ...form, primary_color: e.target.value })}
              className="w-20 h-10 rounded border border-gray-300 cursor-pointer" />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Secondary Color</label>
            <input type="color" value={form.secondary_color} onChange={(e) => setForm({ ...form, secondary_color: e.target.value })}
              className="w-20 h-10 rounded border border-gray-300 cursor-pointer" />
          </div>
        </div>
        <div className="flex items-center gap-2">
          <input type="checkbox" id="is_active" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
          <label htmlFor="is_active" className="text-sm text-gray-700">Active</label>
        </div>
        <div className="flex gap-3 pt-2">
          <button type="submit" className="bg-red-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-red-700">Save Changes</button>
          <button type="button" onClick={() => router.back()} className="border border-gray-300 text-gray-700 px-5 py-2 rounded-lg text-sm">Cancel</button>
        </div>
      </form>
    </div>
  );
}
