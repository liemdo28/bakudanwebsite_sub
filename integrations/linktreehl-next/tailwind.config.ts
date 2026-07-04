import type { Config } from 'tailwindcss';

const config: Config = {
  content: [
    './src/pages/**/*.{js,ts,jsx,tsx,mdx}',
    './src/components/**/*.{js,ts,jsx,tsx,mdx}',
    './src/app/**/*.{js,ts,jsx,tsx,mdx}',
  ],
  safelist: [
    'bg-[var(--btn-color)]',
    'text-[var(--btn-text)]',
    'border-[var(--btn-color)]',
    'text-[var(--text-color)]',
    'text-[var(--accent-color)]',
    'ring-[var(--accent-color)]',
  ],
  theme: {
    extend: {
      colors: {
        primary: {
          50: '#fef2f2',
          100: '#fee2e2',
          500: '#C8102E',
          600: '#b00d27',
          700: '#900a20',
          900: '#5c0615',
        },
      },
    },
  },
  plugins: [],
};
export default config;
