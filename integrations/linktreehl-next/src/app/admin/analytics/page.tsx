'use client';

import { useEffect, useState } from 'react';
import {
  LineChart, Line, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid,
  PieChart, Pie, Cell, Legend,
} from 'recharts';

const COLORS = ['#C8102E', '#1A1A1A', '#6B7280'];

interface AnalyticsData {
  totalViews: number;
  totalClicks: number;
  ctr: string;
  deviceBreakdown: { device_type: string | null; _count: { device_type: number } }[];
  topButtons: { button: { id: number; title: string; url: string } | undefined; clicks: number }[];
  dailyViews: { date: string; views: number | string }[];
}

export default function AnalyticsPage() {
  const [data, setData] = useState<AnalyticsData | null>(null);
  const [days, setDays] = useState('30');

  useEffect(() => {
    const from = new Date(Date.now() - Number(days) * 24 * 60 * 60 * 1000).toISOString();
    fetch(`/api/admin/analytics?from=${from}`).then((r) => r.json()).then((d) => setData(d.data));
  }, [days]);

  if (!data) return <div className="text-gray-400 text-sm">Loading…</div>;

  const dailyChartData = data.dailyViews.map((d) => ({ date: d.date.slice(5), views: Number(d.views) }));
  const deviceData = data.deviceBreakdown.map((d) => ({ name: d.device_type ?? 'unknown', value: d._count.device_type }));

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Analytics</h1>
        <select value={days} onChange={(e) => setDays(e.target.value)} className="border border-gray-300 rounded-lg px-3 py-2 text-sm">
          <option value="7">Last 7 days</option>
          <option value="30">Last 30 days</option>
          <option value="90">Last 90 days</option>
        </select>
      </div>

      <div className="grid grid-cols-3 gap-4 mb-8">
        {[
          { label: 'Page Views', value: data.totalViews },
          { label: 'Button Clicks', value: data.totalClicks },
          { label: 'Click-Through Rate', value: `${data.ctr}%` },
        ].map((s) => (
          <div key={s.label} className="bg-white rounded-xl p-4 shadow-sm border border-gray-100">
            <div className="text-3xl font-bold text-gray-900">{s.value}</div>
            <div className="text-sm text-gray-500 mt-1">{s.label}</div>
          </div>
        ))}
      </div>

      <div className="grid grid-cols-2 gap-6 mb-6">
        <div className="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
          <h2 className="font-semibold text-gray-800 mb-4">Page Views Over Time</h2>
          <ResponsiveContainer width="100%" height={200}>
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
          <h2 className="font-semibold text-gray-800 mb-4">Device Breakdown</h2>
          {deviceData.length > 0 ? (
            <ResponsiveContainer width="100%" height={200}>
              <PieChart>
                <Pie data={deviceData} dataKey="value" nameKey="name" cx="50%" cy="50%" outerRadius={70} label>
                  {deviceData.map((_, i) => <Cell key={i} fill={COLORS[i % COLORS.length]} />)}
                </Pie>
                <Legend />
                <Tooltip />
              </PieChart>
            </ResponsiveContainer>
          ) : <p className="text-gray-400 text-sm">No data</p>}
        </div>
      </div>

      <div className="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
        <h2 className="font-semibold text-gray-800 mb-4">Top Buttons</h2>
        {data.topButtons.length === 0 ? (
          <p className="text-gray-400 text-sm">No clicks yet.</p>
        ) : (
          <table className="w-full text-sm">
            <thead><tr className="border-b border-gray-100">
              <th className="text-left py-2 text-gray-500 font-medium">Button</th>
              <th className="text-right py-2 text-gray-500 font-medium">Clicks</th>
            </tr></thead>
            <tbody>
              {data.topButtons.map((tb, i) => (
                <tr key={i} className="border-b border-gray-50">
                  <td className="py-2 text-gray-700">{tb.button?.title ?? 'Deleted button'}</td>
                  <td className="py-2 text-right font-medium text-gray-900">{tb.clicks}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
