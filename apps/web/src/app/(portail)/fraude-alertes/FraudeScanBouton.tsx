'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { Button } from '@/components/ui/Button';
import type { RapportRoutage } from '@/lib/fraude-types';

/**
 * Déclenche un scan de routage de fraude (POST proxy). Détection seule : le scan extrait, score et
 * notifie le contrôleur plateforme — il ne gèle rien. Le résultat (compteurs) est backend ; on l'AFFICHE.
 */
export function FraudeScanBouton() {
  const router = useRouter();
  const [chargement, setChargement] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);
  const [rapport, setRapport] = useState<RapportRoutage | null>(null);

  const lancer = async () => {
    setErreur(null);
    setChargement(true);
    setRapport(null);
    try {
      const res = await fetch('/api/portail/fraude-alertes/scan', { method: 'POST' });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        setErreur((data as { message?: string }).message ?? 'Le scan a échoué.');
        return;
      }
      setRapport(data as RapportRoutage);
      router.refresh();
    } catch {
      setErreur('Connexion impossible. Vérifiez votre réseau.');
    } finally {
      setChargement(false);
    }
  };

  return (
    <div className="space-y-2">
      <div className="w-auto">
        <Button onClick={lancer} loading={chargement} className="w-auto">
          Lancer un scan
        </Button>
      </div>
      {erreur ? (
        <p role="alert" className="text-sm text-danger">
          {erreur}
        </p>
      ) : null}
      {rapport ? (
        <p className="text-sm text-ink-700" aria-live="polite">
          Scan du {rapport.journee} : {rapport.nbEvaluees} facture(s) évaluée(s), {rapport.nbSuspectes}{' '}
          suspecte(s), {rapport.nbNouvelles} nouvelle(s) alerte(s), {rapport.nbNotifiees} notifiée(s).
        </p>
      ) : null}
    </div>
  );
}
