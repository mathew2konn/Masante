'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Button } from '@/components/ui/Button';

/**
 * Décision sur une candidature (P11.1). Aucun métier ici : ce composant collecte ce que l'agent
 * complète et proxie ; le backend décide, crée l'établissement et rend le lien d'activation.
 *
 * Le lien d'activation est affiché à l'écran plutôt qu'envoyé : il n'y a pas de passerelle de
 * courriel dans cet environnement, et le prétendre serait pire que de le dire.
 */
export function DecisionDemande({
  id,
  nomEtablissement,
  demandeurNom,
  demandeurPrenom,
  demandeurEmail,
  typeDeclare,
}: {
  id: number;
  nomEtablissement: string;
  demandeurNom: string;
  demandeurPrenom: string;
  demandeurEmail: string;
  typeDeclare: string;
}) {
  const router = useRouter();
  const [latitude, setLatitude] = useState('');
  const [longitude, setLongitude] = useState('');
  const [type, setType] = useState(typeDeclare);
  const [nom, setNom] = useState(demandeurNom);
  const [prenom, setPrenom] = useState(demandeurPrenom);
  const [email, setEmail] = useState(demandeurEmail);
  const [motif, setMotif] = useState('');
  const [enCours, setEnCours] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);
  const [lien, setLien] = useState<string | null>(null);

  async function envoyer(action: 'approuver' | 'rejeter') {
    setEnCours(true);
    setErreur(null);

    const corps =
      action === 'approuver'
        ? {
            latitude,
            longitude,
            type,
            gestionnaire_nom: nom,
            gestionnaire_prenom: prenom,
            gestionnaire_email: email,
          }
        : { motif_rejet: motif };

    const res = await fetch(`/api/portail/demandes-inscription/${id}/${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(corps),
    });

    const data = await res.json().catch(() => ({}));
    setEnCours(false);

    if (!res.ok) {
      setErreur(data?.message ?? 'La décision n’a pas pu être enregistrée.');
      return;
    }

    if (action === 'approuver') {
      setLien(data?.lien_activation ?? null);
    }
    router.refresh();
  }

  if (lien) {
    return (
      <Card>
        <h2 className="mb-1 text-lg font-semibold text-blue-900">
          {nomEtablissement} a rejoint la plateforme
        </h2>
        <p className="mb-3 text-sm text-ink-700">
          Transmettez ce lien d’activation au gestionnaire. Il est à usage unique et expire dans
          24 heures ; personne — pas même vous — ne connaît son futur mot de passe.
        </p>
        <p className="break-all rounded-lg bg-background p-3 font-mono text-xs text-ink-700">
          {lien}
        </p>
      </Card>
    );
  }

  return (
    <div className="space-y-4">
      {erreur ? (
        <Card>
          <p className="text-sm font-semibold text-red-700">{erreur}</p>
        </Card>
      ) : null}

      <Card>
        <h2 className="mb-1 text-lg font-semibold text-blue-900">Approuver</h2>
        <p className="mb-4 text-sm text-ink-700">
          Les informations de la candidature font foi : vous vérifiez, vous ne ressaisissez pas.
          Seule la catégorie est rectifiable, et les coordonnées sont à compléter — elles placent
          l’établissement sur la carte que lira un patient.
        </p>

        <div className="grid gap-3 sm:grid-cols-2">
          <Input label="Latitude" value={latitude} onChange={(e) => setLatitude(e.target.value)} inputMode="decimal" />
          <Input label="Longitude" value={longitude} onChange={(e) => setLongitude(e.target.value)} inputMode="decimal" />
          <Input label="Catégorie" value={type} onChange={(e) => setType(e.target.value)} />
        </div>

        <p className="mb-2 mt-5 text-sm font-semibold text-blue-900">Compte gestionnaire</p>
        <div className="grid gap-3 sm:grid-cols-2">
          <Input label="Prénom" value={prenom} onChange={(e) => setPrenom(e.target.value)} />
          <Input label="Nom" value={nom} onChange={(e) => setNom(e.target.value)} />
          <Input label="Courriel" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
        </div>

        <Button className="mt-4" disabled={enCours} onClick={() => envoyer('approuver')}>
          Approuver et créer l’établissement
        </Button>
      </Card>

      <Card>
        <h2 className="mb-1 text-lg font-semibold text-blue-900">Rejeter</h2>
        <p className="mb-3 text-sm text-ink-700">
          Le motif est obligatoire : le demandeur le lira, et un refus sans raison ne lui dit pas
          quoi corriger.
        </p>
        <textarea
          value={motif}
          onChange={(e) => setMotif(e.target.value)}
          rows={3}
          className="w-full rounded-lg border border-line bg-surface p-3 text-sm text-ink-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          placeholder="Numéro d’autorisation introuvable auprès de la tutelle…"
        />
        <Button className="mt-3" disabled={enCours} onClick={() => envoyer('rejeter')}>
          Rejeter la candidature
        </Button>
      </Card>
    </div>
  );
}
