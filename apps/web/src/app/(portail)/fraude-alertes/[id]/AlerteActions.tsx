'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { Button } from '@/components/ui/Button';

/**
 * Action « Marquer revue » sur une alerte OUVERTE. Trace humaine de conformité : le backend passe
 * OUVERTE→REVUE et horodate/attribue. AUCUN gel, aucune action automatique (détection seule — ADR-017).
 */
export function AlerteActions({ id }: { id: string }) {
  const router = useRouter();
  const [chargement, setChargement] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);

  const marquer = async () => {
    setErreur(null);
    setChargement(true);
    try {
      const res = await fetch(`/api/portail/fraude-alertes/${id}/revue`, { method: 'POST' });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        setErreur((data as { message?: string }).message ?? 'La revue a échoué.');
        return;
      }
      router.refresh();
    } catch {
      setErreur('Connexion impossible. Vérifiez votre réseau.');
    } finally {
      setChargement(false);
    }
  };

  return (
    <div className="space-y-2">
      {erreur ? (
        <p role="alert" className="rounded-md bg-danger/10 px-3 py-2 text-sm text-danger">
          {erreur}
        </p>
      ) : null}
      <div className="w-auto">
        <Button onClick={marquer} loading={chargement} className="w-auto">
          Marquer comme revue
        </Button>
      </div>
    </div>
  );
}
