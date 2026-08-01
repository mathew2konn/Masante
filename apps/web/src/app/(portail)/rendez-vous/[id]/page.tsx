import Link from 'next/link';
import { notFound } from 'next/navigation';
import { getRdvDetail } from '@/lib/rdv';
import { LIBELLE_RDV } from '@/lib/rdv-types';
import { Card } from '@/components/ui/Card';
import { RdvActions } from './RdvActions';

/**
 * Détail d'un RDV staff (Module 4 / 4.4). Serveur pour les données (garde/périmètre = API) ;
 * les actions confirmer/refuser vivent dans un composant client. Seul un RDV « en attente »
 * est traitable — l'API refait foi (409 sinon).
 */
export default async function RdvDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const detail = await getRdvDetail(Number(id));
  if (!detail) notFound();

  const { rendez_vous: rdv, medecins } = detail;

  return (
    <div className="space-y-6">
      <div>
        <Link href="/rendez-vous" className="text-sm text-primary underline">
          ← File d’attente
        </Link>
        <h1 className="mt-2 text-2xl font-bold text-blue-900">
          {rdv.membre ? `${rdv.membre.prenom} ${rdv.membre.nom}` : 'Patient inconnu'}
        </h1>
        <p className="text-sm text-ink-500">Statut : {LIBELLE_RDV[rdv.statut]}</p>
      </div>

      <Card>
        <dl className="space-y-2 text-sm">
          <Ligne terme="Motif" valeur={rdv.motif} />
          <Ligne terme="Date souhaitée" valeur={rdv.date_souhaitee} />
          {rdv.date_confirmee ? <Ligne terme="Date confirmée" valeur={rdv.date_confirmee} /> : null}
          <Ligne terme="Service" valeur={rdv.service?.nom_service ?? '—'} />
          <Ligne terme="Médecin" valeur={rdv.medecin ? `${rdv.medecin.prenom} ${rdv.medecin.nom}` : 'Non attribué'} />
          {rdv.message_agent ? <Ligne terme="Message" valeur={rdv.message_agent} /> : null}
        </dl>
      </Card>

      {rdv.statut === 'en_attente' ? (
        <RdvActions id={rdv.id} medecins={medecins} />
      ) : (
        <p className="text-sm text-ink-500">Ce rendez-vous a déjà été traité.</p>
      )}
    </div>
  );
}

function Ligne({ terme, valeur }: { terme: string; valeur: string }) {
  return (
    <div className="flex gap-2">
      <dt className="w-32 shrink-0 font-semibold text-ink-700">{terme}</dt>
      <dd className="text-ink-900">{valeur}</dd>
    </div>
  );
}
