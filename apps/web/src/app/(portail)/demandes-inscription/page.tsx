import Link from 'next/link';
import { exigerZone } from '@/lib/session';
import { getDemandes, LIBELLE_STATUT, STATUTS_DEMANDE, type StatutDemande } from '@/lib/demandes';
import { Card } from '@/components/ui/Card';

/**
 * File des candidatures d'établissements (P11.1, CDC_11 §3 méthode 2).
 *
 * Première application métier branchée sur le socle de P11.0 : la permission qui l'ouvre est
 * déclarée une seule fois, dans le registre de zones, et sert autant la garde ci-dessous que
 * l'entrée de navigation qui y mène.
 */
export default async function DemandesInscriptionPage({
  searchParams,
}: {
  searchParams: Promise<{ statut?: string }>;
}) {
  await exigerZone('demandes-inscription');

  const sp = await searchParams;
  const statut = STATUTS_DEMANDE.includes(sp.statut as StatutDemande)
    ? (sp.statut as StatutDemande)
    : 'en_attente';

  const { demandes, interdit } = await getDemandes(statut);

  if (interdit) {
    // Filet : le registre pourrait mentir, le backend non. C'est lui qui fait autorité.
    return (
      <Card>
        <h1 className="mb-1 text-lg font-semibold text-blue-900">Accès restreint</h1>
        <p className="text-sm text-ink-700">
          Le traitement des demandes d’inscription est réservé à l’administration de la plateforme.
        </p>
      </Card>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-blue-900">Demandes d’inscription</h1>
        <p className="text-ink-700">
          Établissements souhaitant rejoindre la plateforme. Vérifiez le numéro d’autorisation
          auprès de l’autorité de tutelle avant d’approuver.
        </p>
      </div>

      <nav aria-label="Filtrer par état" className="flex flex-wrap gap-2">
        {STATUTS_DEMANDE.map((s) => (
          <Link
            key={s}
            href={`/demandes-inscription?statut=${s}`}
            aria-current={s === statut ? 'page' : undefined}
            className={[
              'rounded-pill px-4 py-2 text-sm font-medium',
              'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
              s === statut ? 'bg-primary text-surface' : 'bg-surface text-ink-700 hover:bg-blue-50',
            ].join(' ')}
          >
            {LIBELLE_STATUT[s]}
          </Link>
        ))}
      </nav>

      {demandes.length === 0 ? (
        <Card>
          <p className="text-sm text-ink-700">Aucune demande dans cet état.</p>
        </Card>
      ) : (
        <ul className="space-y-3">
          {demandes.map((d) => (
            <li key={d.id}>
              <Card>
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="font-semibold text-blue-900">{d.nom}</p>
                    <p className="text-sm text-ink-700">
                      {d.commune ?? '—'} · autorisation {d.numero_autorisation}
                    </p>
                    <p className="mt-1 text-sm text-ink-500">
                      Demandé par {d.demandeur_prenom} {d.demandeur_nom} — {d.demandeur_fonction}
                    </p>
                  </div>
                  <Link
                    href={`/demandes-inscription/${d.id}`}
                    className="shrink-0 rounded-pill bg-primary px-4 py-2 text-sm font-semibold text-surface hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                  >
                    Examiner
                  </Link>
                </div>
                <p className="mt-2 font-mono text-xs text-ink-500">{d.reference}</p>
              </Card>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
