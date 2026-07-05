'use client';

interface Props {
  id: number;
  title: string;
  url: string;
  style: string;
  opensInNewTab: boolean;
  isFeatured: boolean;
  animation?: string | null;
}

export default function LinkButton({ id, title, url, style, opensInNewTab, isFeatured, animation }: Props) {
  function handleClick() {
    fetch(`/api/buttons/${id}/track-click`, { method: 'POST' }).catch(() => {});
  }

  const baseClass = 'block w-full rounded-xl px-4 py-3 text-center font-semibold transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2';

  const styleMap: Record<string, string> = {
    primary: 'bg-[var(--btn-color)] text-[var(--btn-text)] hover:opacity-90 shadow-md',
    secondary: 'bg-transparent border-2 border-[var(--btn-color)] text-[var(--btn-color)] hover:bg-[var(--btn-color)] hover:text-[var(--btn-text)]',
    outline: 'bg-transparent border border-[var(--text-color)] text-[var(--text-color)] hover:bg-black/5',
    ghost: 'bg-transparent text-[var(--text-color)] hover:bg-black/5 underline-offset-2 hover:underline',
  };

  const featuredClass = isFeatured ? 'ring-2 ring-[var(--accent-color)] ring-offset-2' : '';
  const animationClass = animation === 'pulse' ? 'animate-pulse' : animation === 'bounce' ? 'animate-bounce' : '';

  return (
    <a
      href={url}
      target={opensInNewTab ? '_blank' : '_self'}
      rel="noopener noreferrer"
      onClick={handleClick}
      className={`${baseClass} ${styleMap[style] ?? styleMap.primary} ${featuredClass} ${animationClass}`}
    >
      {isFeatured && <span className="mr-1">⭐</span>}
      {title}
    </a>
  );
}
