'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import {
  LinkIcon,
  BuildingStorefrontIcon,
  ChartBarIcon,
  UsersIcon,
  TagIcon,
  HomeIcon,
} from '@heroicons/react/24/outline';
import { signOut } from 'next-auth/react';
import clsx from 'clsx';

const nav = [
  { href: '/admin', label: 'Dashboard', icon: HomeIcon, exact: true },
  { href: '/admin/pages', label: 'Link Pages', icon: LinkIcon },
  { href: '/admin/brands', label: 'Brands', icon: TagIcon },
  { href: '/admin/stores', label: 'Stores', icon: BuildingStorefrontIcon },
  { href: '/admin/analytics', label: 'Analytics', icon: ChartBarIcon },
  { href: '/admin/users', label: 'Users', icon: UsersIcon },
];

export default function Sidebar() {
  const pathname = usePathname();

  return (
    <aside className="w-56 bg-gray-900 text-white flex flex-col h-full min-h-screen">
      <div className="px-4 py-5 border-b border-gray-700">
        <span className="text-lg font-bold text-red-400">LinkTreeHL</span>
        <p className="text-xs text-gray-400 mt-0.5">Bakudan Ramen</p>
      </div>

      <nav className="flex-1 px-2 py-4 space-y-1">
        {nav.map(({ href, label, icon: Icon, exact }) => {
          const active = exact ? pathname === href : pathname.startsWith(href);
          return (
            <Link
              key={href}
              href={href}
              className={clsx(
                'flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors',
                active ? 'bg-red-600 text-white' : 'text-gray-300 hover:bg-gray-700'
              )}
            >
              <Icon className="w-5 h-5 shrink-0" />
              {label}
            </Link>
          );
        })}
      </nav>

      <div className="px-4 py-4 border-t border-gray-700">
        <button
          onClick={() => signOut({ callbackUrl: '/admin/login' })}
          className="w-full text-left text-sm text-gray-400 hover:text-white transition-colors"
        >
          Sign out
        </button>
      </div>
    </aside>
  );
}
