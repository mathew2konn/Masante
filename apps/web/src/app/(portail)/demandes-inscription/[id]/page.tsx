import Link from 'next/link';
import { notFound } from 'next/navigation';
import { exigerZone } from '@/lib/session';
import { getDemande, LIBELLE_STATUT } from '@/lib/demandes';
import { Card } from '@/components/ui/Card';
import { DecisionDemande } from './DecisionDemande';

/** Détail d'une candidature et décision (P11.1, CDC_11 §3 méthode 2). */
export default async function DetailDemandePage({ params }: { params: Promise<{ id: string }> }) {
  await exigerZone('demandes-inscription');

  const { id } = await params;
  const { demande, interdit } = await getDemande(id);

  if (interdit) {
    return (
      <Card>
        <h1 className="mb-1 text-lg font-semibold text-blue-900">Accès restreint</h1>
        <p className="text-sm text-ink-700">
          Le traitement des demandes d’inscription est réservé à l’administration de la plateforme.
        </p>
      </Card>
    );
  }

  if (!demande) notFound();

  const enAttente = demande.statut === 'en_attente';

  return (
    <div className="space-y-6">
      <Link href="/demandes-inscription" className="text-sm text-primary underline">
        ← Retour à la file
      </Link>

      <div>
        <h1 className="text-2xl font-bold text-blue-900">{demande.nom}</h1>
        <p className="font-mono text-sm text-ink-500">{demande.reference}</p>
      </div>

      <Card>
        <h2 className="mb-3 text-lg font-semibold text-blue-900">L’établissement</h2>
        <dl className="grid gap-3 sm:grid-cols-2">
          <Champ intitule="Catégorie déclarée" valeur={demande.type} />
          <Champ intitule="Statut juridique" valeur={demande.statut_juridique} />
          <Champ
            intitule="Numéro d’autorisation"
            valeur={demande.numero_autorisation}
            accent
            note="À confronter à l’autorité de tutelle : c’est la pièce qui rend cette demande vérifiable."
          />
          <Champ intitule="Commune" valeur={demande.commune} />
          <Champ intitule="Adresse" valeur={demande.adresse} />
          <Champ intitule="Téléphone" valeur={demande.telephone} />
          <Champ intitule="Courriel" valeur={demande.email} />
        </dl>
      </Card>

      <Card>
        <h2 className="mb-3 text-lg font-semibold text-blue-900">Le demandeur</h2>
        <dl className="grid gap-3 sm:grid-cols-2">
          <Champ intitule="Nom" valeur={`${demande.demandeur_prenom} ${demande.demandeur_nom}`} />
          <Champ intitule="Fonction" valeur={demande.demandeur_fonction} />
          <Champ intitule="Courriel" valeur={demande.demandeur_email} />
          <Champ intitule="Téléphone" valeur={demande.demandeur_telephone} />
        </dl>
        {demande.message ? (
          <p className="mt-4 whitespace-pre-line rounded-lg bg-background p-3 text-sm text-ink-700">
            {demande.message}
          </p>
        ) : null}
      </Card>

      {enAttente ? (
        <DecisionDemande
          id={demande.id}
          nomEtablissement={demande.nom}
          demandeurNom={demande.demandeur_nom}
          demandeurPrenom={demande.demandeur_prenom}
          demandeurEmail={demande.demandeur_email}
          typeDeclare={demande.type}
        />
      ) : (
        <Card>
          <h2 className="mb-1 text-lg font-semibold text-blue-900">
            Décision : {LIBELLE_STATUT[demande.statut]}
          </h2>
          <p className="text-sm text-ink-700">
            {demande.decide_par_nom ? `Par ${demande.decide_par_nom}` : 'Auteur non enregistré'}
            {demande.decide_le ? ` le ${new Date(demande.decide_le).toLocaleString('fr-FR')}` : ''}
          </p>
          {demande.motif_rejet ? (
            <p className="mt-3 whitespace-pre-line rounded-lg bg-background p-3 text-sm text-ink-700">
              {demande.motif_rejet}
            </p>
          ) : null}
        </Card>
      )}
    </div>
  );
}

function Champ({
  intitule,
  valeur,
  accent,
  note,
}: {
  intitule: string;
  valeur: string | null;
  accent?: boolean;
  note?: string;
}) {
  return (
    <div>
      <dt className="text-xs font-semibold uppercase tracking-wide text-ink-500">{intitule}</dt>
      <dd className={accent ? 'font-semibold text-blue-900' : 'text-ink-700'}>{valeur ?? '—'}</dd>
      {note ? <p className="mt-1 text-xs text-ink-500">{note}</p> : null}
    </div>
  );
}
