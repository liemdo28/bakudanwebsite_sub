'use client';

import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import Link from 'next/link';
import { LineChart, Line, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid } from 'recharts';

export default function PageAnalytics() {
  const params = useParams();
  const id = params.id as string;
  const [data, setData] = useState<any>(null);
  const [days, setDays] = useState('30');

  useEffect(() => {
    const from = new Date(Date.now() - Number(days) * 24 * 60 * 60 * 1000).toISOString();
    fetch(`/api/admin/analytics?page_id=${id}&from=${from}`).then((r) => r.json()).then((d) => setData(d.data));
  }, [id, days]);

  if (!data) return <div className="text-gray-400 text-sm">Loading…</div>;

  const dailyChartData = (data.dailyViews ?? []).map((d: any) => ({ date: String(d.date).slice(5), views: Number(d.views) }));

  return (
    <div className="max-w-2xl">
      <Link href={`/admin/pages/${id}`} className="text-xs text-gray-400 hover:text-gray-600 block mb-3">← Back to Editor</Link>
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Page Analytics</h1>
        <select value={days} onChange={(e) => setDays(e.target.value)} className="border border-gray-300 rounded-lg px-3 py-2 text-sm">
          <option value="7">Last 7 days</option>
          <option value="30">Last 30 days</option>
        </select>
      </div>
      <div className="grid grid-cols-3 gap-4 mb-6">
        {[
          { label: 'Views', value: data.totalViews },
          { label: 'Clicks', value: data.totalClicks },
          { label: 'CTR', value: `${data.ctr}%` },
        ].map((s) => (
          <div key={s.label} className="bg-white rounded-xl p-4 shadow-sm border border-gray-100">
            <div className="text-2xl font-bold text-gray-900">{s.value}</div>
            <div className="text-sm text-gray-500">{s.label}</div>
          </div>
        ))}
      </div>
      <div className="bg-white rounded-xl p-5 shadow-sm border border-gray-100 mb-6">
        <h2 className="font-semibold text-gray-800 mb-4">Views Over Time</h2>
        <ResponsiveContainer width="100%" height={180}>
          <LineChart data={dailyChartData}>
            <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
            <XAxis dataKey="date" tick={{ fontSize: 11 }} />
            <YAxis tick={{ fontSize: 11 }} />
            <Tooltip />
            <Line type="monotone" dataKey="views" stroke="#C8102E" strokeWidth={2} dot={false} />
          </LineChart>
        </ResponsiveContainer>
      </div>
      <div className="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
        <h2 className="font-semibold text-gray-800 mb-4">Top Buttons</h2>
        {data.topButtons?.length === 0 ? (
          <p className="text-gray-400 text-sm">No clicks yet.</p>
        ) : (
          <ul className="space-y-2">
            {data.topButtons?.map((tb: any, i: number) => (
              <li key={i} className="flex justify-between text-sm border-b border-gray-50 pb-2">
                <span className="text-gray-700">{tb.button?.title ?? 'Deleted'}</span>
                <span className="font-medium text-gray-900">{tb.clicks} clicks</span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
