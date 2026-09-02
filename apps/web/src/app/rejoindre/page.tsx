'use client';

import { useState, type ChangeEvent, type FormEvent } from 'react';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Button } from '@/components/ui/Button';

/**
 * Formulaire PUBLIC de candidature (P11.1 — CDC_11 §3, méthode 2).
 *
 * « Clinique Saint Joseph souhaite rejoindre la plateforme ». Cette page est délibérément hors de
 * la garde du portail : le demandeur n'a ni compte ni contact préalable, et lui en exiger un
 * ramènerait à la méthode 1, celle où l'administrateur crée lui-même l'établissement.
 *
 * Aucun métier ici (CDC_02 §0.1) : le formulaire collecte, le backend valide, décide de la
 * référence et refuse un second dépôt.
 */

const CATEGORIES: Array<[string, string]> = [
  ['chu', 'CHU'],
  ['chr', 'CHR'],
  ['hopital_general', 'Hôpital général'],
  ['clinique_privee', 'Clinique'],
  ['centre_sante', 'Centre de santé'],
  ['centre_sante_urbain', 'Centre de santé urbain'],
  ['centre_sante_rural', 'Centre de santé rural'],
  ['cabinet', 'Cabinet'],
  ['pharmacie', 'Pharmacie'],
  ['laboratoire', 'Laboratoire'],
  ['centre_imagerie', 'Centre d’imagerie'],
  ['centre_dialyse', 'Centre de dialyse'],
  ['centre_vaccination', 'Centre de vaccination'],
];

const CHAMPS_VIDES = {
  nom: '',
  type: 'clinique_privee',
  statut_juridique: '',
  numero_autorisation: '',
  adresse: '',
  commune: '',
  telephone: '',
  email: '',
  demandeur_nom: '',
  demandeur_prenom: '',
  demandeur_fonction: '',
  demandeur_email: '',
  demandeur_telephone: '',
  message: '',
};

type Champ = keyof typeof CHAMPS_VIDES;

export default function RejoindrePage() {
  const [champs, setChamps] = useState(CHAMPS_VIDES);
  const [erreurs, setErreurs] = useState<Record<string, string[]>>({});
  const [enCours, setEnCours] = useState(false);
  const [reference, setReference] = useState<string | null>(null);

  const saisir =
    (cle: Champ) =>
    (e: ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
      setChamps((c) => ({ ...c, [cle]: e.target.value }));

  async function envoyer(e: FormEvent) {
    e.preventDefault();
    setEnCours(true);
    setErreurs({});

    const res = await fetch('/api/rejoindre', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(champs),
    });
    const data = await res.json().catch(() => ({}));
    setEnCours(false);

    if (!res.ok) {
      setErreurs(data?.errors ?? { global: [data?.message ?? 'Envoi impossible.'] });
      return;
    }

    setReference(data.reference);
  }

  if (reference) {
    return (
      <main className="mx-auto max-w-2xl p-6">
        <Card>
          <h1 className="mb-2 text-2xl font-bold text-blue-900">Demande enregistrée</h1>
          <p className="mb-4 text-ink-700">
            L’équipe de MaSanté va vérifier votre numéro d’autorisation auprès de l’autorité de
            tutelle. Conservez cette référence : elle vous permet de suivre l’avancement de votre
            demande.
          </p>
          <p className="rounded-lg bg-background p-4 text-center font-mono text-lg font-semibold text-blue-900">
            {reference}
          </p>
        </Card>
      </main>
    );
  }

  return (
    <main className="mx-auto max-w-2xl p-6">
      <h1 className="text-2xl font-bold text-blue-900">Rejoindre MaSanté</h1>
      <p className="mb-6 text-ink-700">
        Votre établissement souhaite rejoindre la plateforme nationale ? Renseignez ce formulaire.
        Une fois votre demande validée, vous recevrez un lien pour créer votre compte et compléter
        vous-même vos services, vos médecins et vos horaires.
      </p>

      {erreurs.global ? (
        <Card>
          <p className="text-sm font-semibold text-red-700">{erreurs.global[0]}</p>
        </Card>
      ) : null}

      <form onSubmit={envoyer} className="mt-4 space-y-4" noValidate>
        <Card>
          <h2 className="mb-3 text-lg font-semibold text-blue-900">Votre établissement</h2>

          <Input
            label="Nom de l’établissement"
            value={champs.nom}
            onChange={saisir('nom')}
            erreur={erreurs.nom?.[0]}
          />

          <div className="mb-4">
            <label htmlFor="type" className="mb-1 block text-sm font-semibold text-ink-700">
              Catégorie
            </label>
            <select
              id="type"
              value={champs.type}
              onChange={saisir('type')}
              className="w-full rounded-lg border border-line bg-surface p-3 text-ink-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              {CATEGORIES.map(([code, libelle]) => (
                <option key={code} value={code}>
                  {libelle}
                </option>
              ))}
            </select>
          </div>

          <Input
            label="Numéro d’autorisation d’exercer"
            value={champs.numero_autorisation}
            onChange={saisir('numero_autorisation')}
            erreur={erreurs.numero_autorisation?.[0]}
          />
          <p className="-mt-3 mb-4 text-xs text-ink-500">
            C’est la pièce que la plateforme vérifiera auprès de votre autorité de tutelle.
          </p>

          <Input
            label="Adresse"
            value={champs.adresse}
            onChange={saisir('adresse')}
            erreur={erreurs.adresse?.[0]}
          />
          <Input
            label="Commune"
            value={champs.commune}
            onChange={saisir('commune')}
            erreur={erreurs.commune?.[0]}
          />
          <Input
            label="Téléphone"
            value={champs.telephone}
            onChange={saisir('telephone')}
            erreur={erreurs.telephone?.[0]}
          />
          <Input
            label="Courriel de l’établissement"
            type="email"
            value={champs.email}
            onChange={saisir('email')}
            erreur={erreurs.email?.[0]}
          />
        </Card>

        <Card>
          <h2 className="mb-1 text-lg font-semibold text-blue-900">Vous</h2>
          <p className="mb-3 text-sm text-ink-700">
            La personne qui répond de cette demande — pas le standard de l’établissement.
          </p>

          <Input
            label="Prénom"
            value={champs.demandeur_prenom}
            onChange={saisir('demandeur_prenom')}
            erreur={erreurs.demandeur_prenom?.[0]}
          />
          <Input
            label="Nom"
            value={champs.demandeur_nom}
            onChange={saisir('demandeur_nom')}
            erreur={erreurs.demandeur_nom?.[0]}
          />
          <Input
            label="Fonction"
            value={champs.demandeur_fonction}
            onChange={saisir('demandeur_fonction')}
            erreur={erreurs.demandeur_fonction?.[0]}
          />
          <Input
            label="Courriel"
            type="email"
            value={champs.demandeur_email}
            onChange={saisir('demandeur_email')}
            erreur={erreurs.demandeur_email?.[0]}
          />
          <Input
            label="Téléphone"
            value={champs.demandeur_telephone}
            onChange={saisir('demandeur_telephone')}
            erreur={erreurs.demandeur_telephone?.[0]}
          />

          <label htmlFor="message" className="mb-1 block text-sm font-semibold text-ink-700">
            Message (facultatif)
          </label>
          <textarea
            id="message"
            value={champs.message}
            onChange={saisir('message')}
            rows={3}
            className="w-full rounded-lg border border-line bg-surface p-3 text-sm text-ink-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          />
        </Card>

        <Button type="submit" loading={enCours}>
          Envoyer ma demande
        </Button>
      </form>
    </main>
  );
}
