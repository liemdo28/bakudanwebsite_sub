import type { Brand, Store, LinkPage, LinkButton } from '@prisma/client';

export type Role = 'super_admin' | 'marketing_manager' | 'store_manager' | 'viewer';

export type ButtonStyle = 'primary' | 'secondary' | 'outline' | 'ghost';

export type LinkPageWithRelations = LinkPage & {
  brand: Brand;
  store: Store | null;
  buttons: LinkButton[];
};

export type Theme = {
  background: string;
  text: string;
  button: string;
  buttonText: string;
  accent: string;
};

export const DEFAULT_THEME: Theme = {
  background: '#FFFFFF',
  text: '#1A1A1A',
  button: '#C8102E',
  buttonText: '#FFFFFF',
  accent: '#C8102E',
};

export type SessionUser = {
  id: string;
  name?: string | null;
  email?: string | null;
  role: Role;
  brandId: number | null;
  storeId: number | null;
};
