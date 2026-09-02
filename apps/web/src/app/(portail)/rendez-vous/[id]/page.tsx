import Link from 'next/link';
import { notFound } from 'next/navigation';
import { getRdvDetail } from '@/lib/rdv';
import { LIBELLE_RDV } from '@/lib/rdv-types';
import { Card } from '@/components/ui/Card';
import { RdvActions } from './RdvActions';
import { exigerZone } from '@/lib/session';

/**
 * Détail d'un RDV staff (Module 4 / 4.4, workflow à deux étapes — B1-a). Serveur pour les
 * données (garde/périmètre = API) ; les actions (prévalider/confirmer/refuser) vivent dans un
 * composant client, qui choisit lesquelles proposer selon le statut. L'API refait foi côté
 * transition ET autorisation (409/403 sinon) — ce composant n'affiche que ce qui est probable.
 */
export default async function RdvDetailPage({ params }: { params: Promise<{ id: string }> }) {
  // P11.0 — garde de zone : la permission qui ouvre « rendez-vous » est déclarée une seule
  // fois, dans le registre. Le backend reste l'autorité et refuse de son côté (branche
  // `interdit` plus bas, conservée comme filet) ; ici on évite d'afficher une page vide.
  await exigerZone('rendez-vous');
  const { id } = await params;
  const detail = await getRdvDetail(Number(id));
  if (!detail) notFound();

  const { rendez_vous: rdv, medecins, referent, tarif, tarif_source } = detail;

  return (
    <div className="space-y-6">
      <div>
        <Link href="/rendez-vous" className="text-sm text-primary underline">
          ← File d’attente
        </Link>
        {/* B1-b (D6) — préfixe « Patient », affichage seul : la fiche montre désormais aussi le
            médecin et le référent, il ne faut jamais confondre ces noms. */}
        <h1 className="mt-2 text-2xl font-bold text-blue-900">
          Patient {rdv.membre ? `${rdv.membre.prenom} ${rdv.membre.nom}` : 'inconnu'}
        </h1>
        <p className="text-sm text-ink-500">Statut : {LIBELLE_RDV[rdv.statut]}</p>
      </div>

      <Card>
        <dl className="space-y-2 text-sm">
          <Ligne terme="Motif" valeur={rdv.motif} />
          <Ligne terme="Date souhaitée" valeur={rdv.date_souhaitee} />
          {rdv.date_confirmee ? <Ligne terme="Date confirmée" valeur={rdv.date_confirmee} /> : null}
          <Ligne terme="Service" valeur={rdv.service?.nom_service ?? '—'} />
          <div className="flex items-center gap-2">
            <dt className="w-32 shrink-0 font-semibold text-ink-700">Médecin</dt>
            <dd className="flex items-center gap-2 text-ink-900">
              {rdv.medecin?.photo_url ? (
                // eslint-disable-next-line @next/next/no-img-element -- URL relative, backend privé
                <img src={rdv.medecin.photo_url} alt="" className="h-8 w-8 rounded-full object-cover" />
              ) : null}
              {rdv.medecin ? `${rdv.medecin.prenom} ${rdv.medecin.nom}` : 'Non attribué'}
              {rdv.medecin?.numero_professionnel ? (
                <span className="text-xs text-ink-500">(n° {rdv.medecin.numero_professionnel})</span>
              ) : null}
            </dd>
          </div>
          {/* B1-b (D7) — aperçu du tarif AVEC SA SOURCE : un montant ne doit jamais mentir sur
              d'où il vient (précédent `delai_source` P6.7b, `provenance` P6.8d). */}
          <Ligne
            terme="Tarif"
            valeur={tarif !== null ? `${tarif.toLocaleString('fr-FR')} FCFA (source : ${tarif_source})` : 'Aucun tarif configuré'}
          />
          {referent?.medecin ? (
            <Ligne
              terme="Médecin référent"
              valeur={`${referent.medecin.titre} ${referent.medecin.prenom} ${referent.medecin.nom}${
                referent.medecin.structure ? ` (${referent.medecin.structure.nom})` : ''
              }`}
            />
          ) : null}
          {rdv.motif_orientation || rdv.message_orientation ? (
            <Ligne
              terme="Orientation (patient)"
              valeur={[rdv.motif_orientation, rdv.message_orientation].filter(Boolean).join(' — ')}
            />
          ) : null}
          {rdv.message_agent ? <Ligne terme="Message" valeur={rdv.message_agent} /> : null}
        </dl>
      </Card>

      {rdv.statut === 'en_attente' || rdv.statut === 'prevalide' ? (
        <RdvActions id={rdv.id} statut={rdv.statut} medecins={medecins} />
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
