import Link from 'next/link';
import { getFileRdv } from '@/lib/rdv';
import { LIBELLE_RDV, STATUTS_FILE, type StatutRdv } from '@/lib/rdv-types';
import { Card } from '@/components/ui/Card';

/**
 * File d'attente staff des RDV (Module 4 / 4.4). Serveur : la garde `rdv.validate` et le périmètre
 * sont appliqués par l'API. Filtrage par statut via l'URL. Les transitions se font sur le détail.
 */
export default async function FileRdvPage({
  searchParams,
}: {
  searchParams: Promise<{ statut?: string }>;
}) {
  const sp = await searchParams;
  const statut: StatutRdv = STATUTS_FILE.includes(sp.statut as StatutRdv)
    ? (sp.statut as StatutRdv)
    : 'en_attente';

  const { rdvs, interdit } = await getFileRdv(statut);

  if (interdit) {
    return (
      <Card>
        <h1 className="mb-1 text-lg font-semibold text-blue-900">Accès restreint</h1>
        <p className="text-sm text-ink-700">
          La validation des rendez-vous est réservée aux agents et gestionnaires d’établissement.
        </p>
      </Card>
    );
  }

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold text-blue-900">Rendez-vous à valider</h1>

      <nav className="flex flex-wrap gap-2" aria-label="Filtrer par statut">
        {STATUTS_FILE.map((s) => (
          <Link
            key={s}
            href={`/rendez-vous?statut=${s}`}
            aria-current={s === statut ? 'page' : undefined}
            className={
              'rounded-pill border px-4 py-1.5 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ' +
              (s === statut
                ? 'border-primary bg-primary text-surface'
                : 'border-line bg-surface text-ink-700 hover:bg-surface-muted')
            }
          >
            {LIBELLE_RDV[s]}
          </Link>
        ))}
      </nav>

      {rdvs.length === 0 ? (
        <Card>
          <p className="text-sm text-ink-700">Aucun rendez-vous « {LIBELLE_RDV[statut]} ».</p>
        </Card>
      ) : (
        <ul className="space-y-3">
          {rdvs.map((r) => (
            <li key={r.id}>
              <Link
                href={`/rendez-vous/${r.id}`}
                className="block rounded-card focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
              >
                <Card className="hover:bg-surface-muted">
                  <p className="font-semibold text-blue-900">
                    {r.membre ? `${r.membre.prenom} ${r.membre.nom}` : 'Patient inconnu'}
                  </p>
                  <p className="text-sm text-ink-700">{r.motif}</p>
                  <p className="mt-1 text-xs text-ink-500">
                    Souhaité le {r.date_souhaitee}
                    {r.service ? ` · ${r.service.nom_service}` : ''}
                  </p>
                </Card>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
