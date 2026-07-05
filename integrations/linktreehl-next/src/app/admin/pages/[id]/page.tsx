'use client';

import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import Link from 'next/link';
import Image from 'next/image';
import type { LinkButton } from '@prisma/client';
import SortableButtonList from '@/components/admin/SortableButtonList';
import { QrCodeIcon, ArrowTopRightOnSquareIcon } from '@heroicons/react/24/outline';
import type { Theme } from '@/types';

type Tab = 'settings' | 'buttons' | 'theme' | 'qr' | 'seo';

interface PageData {
  id: number; title: string; slug: string; headline: string | null; subheadline: string | null;
  hero_image_path: string | null; seo_title: string | null; seo_description: string | null;
  social_image_path: string | null; theme_json: Theme | null; is_active: boolean;
  brand: { name: string; primary_color: string };
  store: { name: string } | null;
  buttons: LinkButton[];
  qr_assets: { id: number; file_path: string; format: string }[];
}

const defaultTheme: Theme = { background: '#FFFFFF', text: '#1A1A1A', button: '#C8102E', buttonText: '#FFFFFF', accent: '#C8102E' };

export default function EditPagePage() {
  const params = useParams();
  const id = params.id as string;
  const [page, setPage] = useState<PageData | null>(null);
  const [tab, setTab] = useState<Tab>('settings');
  const [settings, setSettings] = useState({ title: '', slug: '', headline: '', subheadline: '', hero_image_path: '', is_active: true });
  const [theme, setTheme] = useState<Theme>(defaultTheme);
  const [seo, setSeo] = useState({ seo_title: '', seo_description: '', social_image_path: '' });
  const [buttons, setButtons] = useState<LinkButton[]>([]);
  const [editingBtn, setEditingBtn] = useState<LinkButton | null>(null);
  const [newBtn, setNewBtn] = useState({ title: '', url: '', button_style: 'primary', is_featured: false, opens_in_new_tab: true });
  const [qrLoading, setQrLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [toast, setToast] = useState('');

  function showToast(msg: string) {
    setToast(msg);
    setTimeout(() => setToast(''), 3000);
  }

  useEffect(() => {
    fetch(`/api/admin/pages/${id}`).then((r) => r.json()).then((d) => {
      if (!d.data) return;
      const p: PageData = d.data;
      setPage(p);
      setSettings({ title: p.title, slug: p.slug, headline: p.headline ?? '', subheadline: p.subheadline ?? '', hero_image_path: p.hero_image_path ?? '', is_active: p.is_active });
      setTheme(p.theme_json ?? defaultTheme);
      setSeo({ seo_title: p.seo_title ?? '', seo_description: p.seo_description ?? '', social_image_path: p.social_image_path ?? '' });
      setButtons(p.buttons);
    });
  }, [id]);

  async function saveSettings() {
    setSaving(true);
    await fetch(`/api/admin/pages/${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...settings }),
    });
    setSaving(false);
    showToast('Settings saved');
  }

  async function saveTheme() {
    setSaving(true);
    await fetch(`/api/admin/pages/${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ theme_json: theme }),
    });
    setSaving(false);
    showToast('Theme saved');
  }

  async function saveSeo() {
    setSaving(true);
    await fetch(`/api/admin/pages/${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...seo }),
    });
    setSaving(false);
    showToast('SEO saved');
  }

  async function addButton() {
    if (!newBtn.title || !newBtn.url) return;
    const res = await fetch(`/api/admin/pages/${id}/buttons`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(newBtn),
    });
    const data = await res.json();
    setButtons((prev) => [...prev, data.data]);
    setNewBtn({ title: '', url: '', button_style: 'primary', is_featured: false, opens_in_new_tab: true });
    showToast('Button added');
  }

  async function updateButton() {
    if (!editingBtn) return;
    const res = await fetch(`/api/admin/buttons/${editingBtn.id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(editingBtn),
    });
    const data = await res.json();
    setButtons((prev) => prev.map((b) => b.id === editingBtn.id ? data.data : b));
    setEditingBtn(null);
    showToast('Button updated');
  }

  async function deleteButton(btnId: number) {
    await fetch(`/api/admin/buttons/${btnId}`, { method: 'DELETE' });
    setButtons((prev) => prev.filter((b) => b.id !== btnId));
    showToast('Button deleted');
  }

  async function uploadImage(file: File, category: string, field: keyof typeof settings | 'social_image_path') {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('category', category);
    const res = await fetch('/api/admin/upload', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.data?.path) {
      if (field === 'social_image_path') {
        setSeo((prev) => ({ ...prev, social_image_path: data.data.path }));
      } else {
        setSettings((prev) => ({ ...prev, [field]: data.data.path }));
      }
    }
  }

  async function generateQR() {
    setQrLoading(true);
    const res = await fetch(`/api/admin/pages/${id}/qr`, { method: 'POST' });
    const data = await res.json();
    setQrLoading(false);
    if (data.data) {
      setPage((prev) => prev ? { ...prev, qr_assets: [data.data.png, data.data.svg, ...prev.qr_assets] } : prev);
      showToast('QR codes generated');
    }
  }

  if (!page) return <div className="text-gray-400 text-sm p-4">Loading…</div>;

  const tabs: { key: Tab; label: string }[] = [
    { key: 'settings', label: 'Settings' },
    { key: 'buttons', label: `Buttons (${buttons.length})` },
    { key: 'theme', label: 'Theme' },
    { key: 'qr', label: 'QR Code' },
    { key: 'seo', label: 'SEO' },
  ];

  return (
    <div className="max-w-2xl">
      {toast && (
        <div className="fixed top-4 right-4 bg-gray-900 text-white px-4 py-2 rounded-lg text-sm z-50 shadow-lg">{toast}</div>
      )}
      <div className="flex items-center justify-between mb-4">
        <div>
          <Link href="/admin/pages" className="text-xs text-gray-400 hover:text-gray-600">← Pages</Link>
          <h1 className="text-2xl font-bold text-gray-900 mt-1">{page.title}</h1>
          <p className="text-xs text-gray-400 font-mono">/links/{page.slug}</p>
        </div>
        <a href={`/links/${page.slug}`} target="_blank" rel="noopener noreferrer"
          className="flex items-center gap-1 text-sm text-red-600 hover:underline">
          <ArrowTopRightOnSquareIcon className="w-4 h-4" /> Preview
        </a>
      </div>

      <div className="flex gap-1 mb-6 border-b border-gray-200">
        {tabs.map((t) => (
          <button key={t.key} onClick={() => setTab(t.key)}
            className={`px-4 py-2 text-sm font-medium transition-colors border-b-2 ${tab === t.key ? 'border-red-600 text-red-600' : 'border-transparent text-gray-500 hover:text-gray-700'}`}>
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'settings' && (
        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5 space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Title</label>
              <input value={settings.title} onChange={(e) => setSettings({ ...settings, title: e.target.value })}
                className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Slug</label>
              <input value={settings.slug} onChange={(e) => setSettings({ ...settings, slug: e.target.value })}
                className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" />
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Headline</label>
            <input value={settings.headline} onChange={(e) => setSettings({ ...settings, headline: e.target.value })}
              className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Subheadline</label>
            <input value={settings.subheadline} onChange={(e) => setSettings({ ...settings, subheadline: e.target.value })}
              className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Hero Image</label>
            {settings.hero_image_path && (
              <div className="mb-2 relative w-full h-32 rounded-lg overflow-hidden border border-gray-200">
                <Image src={settings.hero_image_path} alt="Hero" fill className="object-cover" />
              </div>
            )}
            <input type="file" accept="image/*" onChange={(e) => e.target.files?.[0] && uploadImage(e.target.files[0], 'heroes', 'hero_image_path')}
              className="text-sm text-gray-500" />
          </div>
          <div className="flex items-center gap-2">
            <input type="checkbox" id="is_active" checked={settings.is_active} onChange={(e) => setSettings({ ...settings, is_active: e.target.checked })} />
            <label htmlFor="is_active" className="text-sm text-gray-700">Active (visible publicly)</label>
          </div>
          <button onClick={saveSettings} disabled={saving} className="bg-red-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-red-700 disabled:opacity-50">
            {saving ? 'Saving…' : 'Save Settings'}
          </button>
        </div>
      )}

      {tab === 'buttons' && (
        <div className="space-y-4">
          <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h3 className="font-semibold text-gray-800 mb-3">Add Button</h3>
            <div className="grid grid-cols-2 gap-3 mb-3">
              <input placeholder="Button title" value={newBtn.title} onChange={(e) => setNewBtn({ ...newBtn, title: e.target.value })}
                className="border border-gray-300 rounded-lg px-3 py-2 text-sm col-span-2" />
              <input placeholder="https://..." value={newBtn.url} onChange={(e) => setNewBtn({ ...newBtn, url: e.target.value })}
                className="border border-gray-300 rounded-lg px-3 py-2 text-sm col-span-2" />
              <select value={newBtn.button_style} onChange={(e) => setNewBtn({ ...newBtn, button_style: e.target.value })}
                className="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="primary">Primary</option>
                <option value="secondary">Secondary</option>
                <option value="outline">Outline</option>
                <option value="ghost">Ghost</option>
              </select>
              <div className="flex items-center gap-2">
                <input type="checkbox" id="is_featured" checked={newBtn.is_featured} onChange={(e) => setNewBtn({ ...newBtn, is_featured: e.target.checked })} />
                <label htmlFor="is_featured" className="text-sm text-gray-700">⭐ Featured</label>
              </div>
            </div>
            <button onClick={addButton} className="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-red-700">Add Button</button>
          </div>

          {editingBtn && (
            <div className="bg-blue-50 rounded-xl border border-blue-200 p-5 space-y-3">
              <h3 className="font-semibold text-blue-800 mb-1">Editing: {editingBtn.title}</h3>
              <input value={editingBtn.title} onChange={(e) => setEditingBtn({ ...editingBtn, title: e.target.value })}
                className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
              <input value={editingBtn.url} onChange={(e) => setEditingBtn({ ...editingBtn, url: e.target.value })}
                className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
              <div className="flex gap-3">
                <select value={editingBtn.button_style} onChange={(e) => setEditingBtn({ ...editingBtn, button_style: e.target.value })}
                  className="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                  <option value="primary">Primary</option>
                  <option value="secondary">Secondary</option>
                  <option value="outline">Outline</option>
                  <option value="ghost">Ghost</option>
                </select>
                <div className="flex items-center gap-2">
                  <input type="checkbox" checked={(editingBtn as any).is_featured ?? false}
                    onChange={(e) => setEditingBtn({ ...editingBtn, is_featured: e.target.checked } as any)} />
                  <label className="text-sm text-gray-700">⭐ Featured</label>
                </div>
                <div className="flex items-center gap-2">
                  <input type="checkbox" checked={editingBtn.is_active}
                    onChange={(e) => setEditingBtn({ ...editingBtn, is_active: e.target.checked })} />
                  <label className="text-sm text-gray-700">Active</label>
                </div>
              </div>
              <div className="flex gap-2">
                <button onClick={updateButton} className="bg-blue-600 text-white px-4 py-1.5 rounded-lg text-sm">Save</button>
                <button onClick={() => setEditingBtn(null)} className="border border-gray-300 text-gray-700 px-4 py-1.5 rounded-lg text-sm">Cancel</button>
              </div>
            </div>
          )}

          <SortableButtonList
            pageId={Number(id)}
            initialButtons={buttons}
            onEdit={setEditingBtn}
            onDelete={deleteButton}
            onReorder={setButtons}
          />
        </div>
      )}

      {tab === 'theme' && (
        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5 space-y-4">
          <h3 className="font-semibold text-gray-800 mb-1">Theme Colors</h3>
          {(['background', 'text', 'button', 'buttonText', 'accent'] as (keyof Theme)[]).map((key) => (
            <div key={key} className="flex items-center justify-between">
              <label className="text-sm font-medium text-gray-700 capitalize">{key.replace(/([A-Z])/g, ' $1')}</label>
              <div className="flex items-center gap-2">
                <input type="color" value={theme[key]} onChange={(e) => setTheme({ ...theme, [key]: e.target.value })}
                  className="w-10 h-10 rounded border border-gray-300 cursor-pointer" />
                <span className="text-sm text-gray-500 font-mono w-20">{theme[key]}</span>
              </div>
            </div>
          ))}
          <div className="border rounded-xl p-4 mt-2" style={{ backgroundColor: theme.background }}>
            <p className="text-center text-sm font-bold mb-2" style={{ color: theme.text }}>Preview</p>
            <div className="rounded-lg py-2 text-center text-sm font-medium" style={{ backgroundColor: theme.button, color: theme.buttonText }}>
              Sample Button
            </div>
          </div>
          <button onClick={saveTheme} disabled={saving} className="bg-red-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-red-700 disabled:opacity-50">
            {saving ? 'Saving…' : 'Save Theme'}
          </button>
        </div>
      )}

      {tab === 'qr' && (
        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5 space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="font-semibold text-gray-800">QR Code</h3>
              <p className="text-xs text-gray-500 mt-1">Points to: /links/{page.slug}</p>
            </div>
            <button onClick={generateQR} disabled={qrLoading}
              className="flex items-center gap-2 bg-gray-800 text-white px-4 py-2 rounded-lg text-sm hover:bg-gray-900 disabled:opacity-50">
              <QrCodeIcon className="w-4 h-4" />
              {qrLoading ? 'Generating…' : 'Generate QR'}
            </button>
          </div>
          {page.qr_assets.length > 0 && (
            <div className="space-y-2">
              {page.qr_assets.filter((a) => a.format === 'png').slice(0, 1).map((a) => (
                <div key={a.id}>
                  <Image src={a.file_path} alt="QR Code" width={200} height={200} className="border border-gray-200 rounded-lg" />
                  <div className="flex gap-2 mt-2">
                    <a href={a.file_path} download className="text-sm text-red-600 hover:underline">Download PNG</a>
                    {page.qr_assets.find((x) => x.format === 'svg') && (
                      <a href={page.qr_assets.find((x) => x.format === 'svg')!.file_path} download className="text-sm text-gray-500 hover:underline">Download SVG</a>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
          {page.qr_assets.length === 0 && (
            <p className="text-gray-400 text-sm">No QR code yet. Click "Generate QR" to create one.</p>
          )}
        </div>
      )}

      {tab === 'seo' && (
        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5 space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">SEO Title <span className="text-gray-400 font-normal">(browser tab)</span></label>
            <input value={seo.seo_title} onChange={(e) => setSeo({ ...seo, seo_title: e.target.value })}
              className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">SEO Description <span className="text-gray-400 font-normal">(search snippet)</span></label>
            <textarea value={seo.seo_description} onChange={(e) => setSeo({ ...seo, seo_description: e.target.value })} rows={3}
              className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Social Preview Image <span className="text-gray-400 font-normal">(Open Graph)</span></label>
            {seo.social_image_path && (
              <div className="mb-2 relative w-full h-32 rounded-lg overflow-hidden border border-gray-200">
                <Image src={seo.social_image_path} alt="Social" fill className="object-cover" />
              </div>
            )}
            <input type="file" accept="image/*" onChange={(e) => e.target.files?.[0] && uploadImage(e.target.files[0], 'social', 'social_image_path')}
              className="text-sm text-gray-500" />
          </div>
          <button onClick={saveSeo} disabled={saving} className="bg-red-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-red-700 disabled:opacity-50">
            {saving ? 'Saving…' : 'Save SEO'}
          </button>
        </div>
      )}
    </div>
  );
}
