'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { Button } from '@/components/ui/Button';
import type { MedecinReservable } from '@/lib/rdv-types';

/**
 * Actions staff sur un RDV en attente : confirmer (date définitive + médecin optionnel + message)
 * ou refuser (motif obligatoire). Appelle les proxys ; la décision d'état est backend (frontière).
 */
export function RdvActions({ id, medecins }: { id: number; medecins: MedecinReservable[] }) {
  const router = useRouter();
  const [mode, setMode] = useState<'idle' | 'confirmer' | 'refuser'>('idle');
  const [dateConfirmee, setDateConfirmee] = useState('');
  const [medecinId, setMedecinId] = useState('');
  const [message, setMessage] = useState('');
  const [motif, setMotif] = useState('');
  const [erreur, setErreur] = useState<string | null>(null);
  const [chargement, setChargement] = useState(false);

  const champ =
    'w-full rounded-md border border-line bg-surface-muted px-3 py-2 text-ink-900 focus:outline focus:outline-2 focus:outline-primary';

  const envoyer = async (url: string, corps: Record<string, unknown>, erreurDefaut: string) => {
    setErreur(null);
    setChargement(true);
    try {
      const res = await fetch(url, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(corps),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        setErreur((data as { message?: string }).message ?? erreurDefaut);
        return;
      }
      router.replace('/rendez-vous');
      router.refresh();
    } catch {
      setErreur('Connexion impossible. Vérifiez votre réseau.');
    } finally {
      setChargement(false);
    }
  };

  if (mode === 'idle') {
    return (
      <div className="flex gap-3">
        <Button onClick={() => setMode('confirmer')}>Confirmer</Button>
        <Button variant="secondary" onClick={() => setMode('refuser')}>
          Refuser
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {erreur ? (
        <p role="alert" className="rounded-md bg-danger/10 px-3 py-2 text-sm text-danger">
          {erreur}
        </p>
      ) : null}

      {mode === 'confirmer' ? (
        <form
          onSubmit={(e) => {
            e.preventDefault();
            void envoyer(
              `/api/portail/rendez-vous/${id}/confirmer`,
              {
                date_confirmee: dateConfirmee,
                medecin_id: medecinId ? Number(medecinId) : null,
                message_agent: message || null,
              },
              'La confirmation a échoué.',
            );
          }}
          className="space-y-3"
        >
          <div>
            <label htmlFor="date_confirmee" className="mb-1 block text-sm font-semibold text-ink-700">
              Date confirmée
            </label>
            <input
              id="date_confirmee"
              type="date"
              required
              value={dateConfirmee}
              onChange={(e) => setDateConfirmee(e.target.value)}
              className={champ}
            />
          </div>
          {medecins.length > 0 ? (
            <div>
              <label htmlFor="medecin" className="mb-1 block text-sm font-semibold text-ink-700">
                Médecin (optionnel)
              </label>
              <select id="medecin" value={medecinId} onChange={(e) => setMedecinId(e.target.value)} className={champ}>
                <option value="">— Non attribué —</option>
                {medecins.map((m) => (
                  <option key={m.id} value={m.id}>
                    {m.prenom} {m.nom}
                  </option>
                ))}
              </select>
            </div>
          ) : null}
          <div>
            <label htmlFor="message" className="mb-1 block text-sm font-semibold text-ink-700">
              Message au patient (optionnel)
            </label>
            <textarea id="message" rows={3} value={message} onChange={(e) => setMessage(e.target.value)} className={champ} />
          </div>
          <div className="flex gap-3">
            <Button type="submit" loading={chargement} disabled={!dateConfirmee}>
              Valider la confirmation
            </Button>
            <Button type="button" variant="secondary" onClick={() => setMode('idle')}>
              Annuler
            </Button>
          </div>
        </form>
      ) : (
        <form
          onSubmit={(e) => {
            e.preventDefault();
            void envoyer(`/api/portail/rendez-vous/${id}/refuser`, { message_agent: motif }, 'Le refus a échoué.');
          }}
          className="space-y-3"
        >
          <div>
            <label htmlFor="motif" className="mb-1 block text-sm font-semibold text-ink-700">
              Motif du refus (communiqué au patient)
            </label>
            <textarea id="motif" rows={3} required value={motif} onChange={(e) => setMotif(e.target.value)} className={champ} />
          </div>
          <div className="flex gap-3">
            <Button type="submit" loading={chargement} disabled={!motif.trim()}>
              Confirmer le refus
            </Button>
            <Button type="button" variant="secondary" onClick={() => setMode('idle')}>
              Annuler
            </Button>
          </div>
        </form>
      )}
    </div>
  );
}
