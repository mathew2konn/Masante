'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';

/** Déconnexion : appelle le proxy (révoque + efface le cookie) puis renvoie vers le login. */
export function LogoutButton() {
  const router = useRouter();
  const [chargement, setChargement] = useState(false);

  const deconnecter = async () => {
    setChargement(true);
    try {
      await fetch('/api/auth/logout', { method: 'POST' });
    } finally {
      router.replace('/login');
      router.refresh();
    }
  };

  return (
    <button
      onClick={deconnecter}
      disabled={chargement}
      className="rounded-pill border border-line px-4 py-2 text-sm font-semibold text-ink-700 hover:bg-surface-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50"
    >
      Se déconnecter
    </button>
  );
}
