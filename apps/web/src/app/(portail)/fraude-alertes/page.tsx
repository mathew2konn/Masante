import Link from 'next/link';
import type { StatutAlerteFraudeIa } from '@masante/shared';
import { getAlertes } from '@/lib/fraude';
import { LIBELLE_STATUT, STATUTS_ALERTE } from '@/lib/fraude-types';
import { Card } from '@/components/ui/Card';
import { NiveauBadge } from './NiveauBadge';
import { FraudeScanBouton } from './FraudeScanBouton';

/**
 * Alertes de fraude IA — écran du contrôleur plateforme (CDC_05, ADR-020 §B2). Serveur : la garde
 * (super_admin/ministere → principal signé ADMIN_FINANCE) est dans `getAlertes`. Détection seule :
 * on consulte et on marque « revue » ; aucune action de gel (ADR-017). Filtre par statut via l'URL.
 */
export default async function FraudeAlertesPage({
  searchParams,
}: {
  searchParams: Promise<{ statut?: string }>;
}) {
  const sp = await searchParams;
  const statut = STATUTS_ALERTE.includes(sp.statut as StatutAlerteFraudeIa)
    ? (sp.statut as StatutAlerteFraudeIa)
    : undefined;

  const { alertes, interdit } = await getAlertes(statut);

  if (interdit) {
    return (
      <Card>
        <h1 className="mb-1 text-lg font-semibold text-blue-900">Accès restreint</h1>
        <p className="text-sm text-ink-700">
          Les alertes de fraude sont réservées au contrôle plateforme (administration nationale).
        </p>
      </Card>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold text-blue-900">Alertes de fraude</h1>
        <FraudeScanBouton />
      </div>

      <p className="text-sm text-ink-500">
        Signalements de conformité (détection seule). La revue est une trace humaine — aucun gel
        n’est déclenché depuis cet écran.
      </p>

      <nav className="flex flex-wrap gap-2" aria-label="Filtrer par statut">
        <FiltreLien libelle="Toutes" href="/fraude-alertes" actif={statut === undefined} />
        {STATUTS_ALERTE.map((s) => (
          <FiltreLien
            key={s}
            libelle={LIBELLE_STATUT[s]}
            href={`/fraude-alertes?statut=${s}`}
            actif={s === statut}
          />
        ))}
      </nav>

      {alertes.length === 0 ? (
        <Card>
          <p className="text-sm text-ink-700">Aucune alerte{statut ? ` « ${LIBELLE_STATUT[statut]} »` : ''}.</p>
        </Card>
      ) : (
        <ul className="space-y-3">
          {alertes.map((a) => (
            <li key={a.id}>
              <Link
                href={`/fraude-alertes/${a.id}`}
                className="block rounded-card focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
              >
                <Card className="hover:bg-surface-muted">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="font-semibold text-blue-900">Facture {a.factureRef}</p>
                    <NiveauBadge niveau={a.niveau} />
                  </div>
                  <p className="mt-1 text-sm text-ink-700">
                    Score {a.score}/100 · {a.etablissementRef ?? 'établissement inconnu'}
                  </p>
                  <p className="mt-1 text-xs text-ink-500">
                    {LIBELLE_STATUT[a.statut]} · rapport du {a.dateRapport}
                    {a.notifiee ? ' · notifiée' : ''}
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

function FiltreLien({ libelle, href, actif }: { libelle: string; href: string; actif: boolean }) {
  return (
    <Link
      href={href}
      aria-current={actif ? 'page' : undefined}
      className={
        'rounded-pill border px-4 py-1.5 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ' +
        (actif
          ? 'border-primary bg-primary text-surface'
          : 'border-line bg-surface text-ink-700 hover:bg-surface-muted')
      }
    >
      {libelle}
    </Link>
  );
}
