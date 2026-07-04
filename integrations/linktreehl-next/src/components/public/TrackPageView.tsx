'use client';

import { useEffect } from 'react';

export default function TrackPageView({ slug }: { slug: string }) {
  useEffect(() => {
    const url = `/api/links/${slug}/track-view${window.location.search}`;
    fetch(url, { method: 'POST' }).catch(() => {});
  }, [slug]);
  return null;
}
