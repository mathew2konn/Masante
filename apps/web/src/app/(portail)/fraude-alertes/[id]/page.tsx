import Link from 'next/link';
import { notFound } from 'next/navigation';
import { getAlerte } from '@/lib/fraude';
import { LIBELLE_STATUT } from '@/lib/fraude-types';
import { Card } from '@/components/ui/Card';
import { NiveauBadge } from '../NiveauBadge';
import { AlerteActions } from './AlerteActions';

/**
 * Détail d'une alerte de fraude IA (CDC_05, ADR-020 §B2). Serveur pour les données (garde/mint =
 * `getAlerte`). Affiche l'explication OBLIGATOIRE (règles déterministes + facteurs SHAP) : jamais de
 * sortie IA sans explication+confiance+limites (CDC_00 §4). Seule action : marquer « revue » (trace).
 */
export default async function AlerteDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const a = await getAlerte(id);
  if (!a) notFound();

  return (
    <div className="space-y-6">
      <div>
        <Link href="/fraude-alertes" className="text-sm text-primary underline">
          ← Alertes de fraude
        </Link>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-bold text-blue-900">Facture {a.factureRef}</h1>
          <NiveauBadge niveau={a.niveau} />
        </div>
        <p className="text-sm text-ink-500">
          {LIBELLE_STATUT[a.statut]} · score {a.score}/100 · rapport du {a.dateRapport}
        </p>
      </div>

      <Card>
        <dl className="space-y-2 text-sm">
          <Ligne terme="Établissement" valeur={a.etablissementRef ?? '—'} />
          <Ligne terme="Patient" valeur={a.patientRef ?? '—'} />
          <Ligne terme="Mode d’analyse" valeur={a.mode} />
          <Ligne terme="Notifiée" valeur={a.notifiee ? 'Oui — contrôle plateforme' : 'Non'} />
          {a.revuePar ? <Ligne terme="Revue par" valeur={a.revuePar} /> : null}
          {a.revueAt ? <Ligne terme="Revue le" valeur={formatInstant(a.revueAt)} /> : null}
        </dl>
      </Card>

      <section className="space-y-2">
        <h2 className="text-lg font-semibold text-blue-900">Règles déclenchées</h2>
        {a.regles && a.regles.length > 0 ? (
          <ul className="space-y-2">
            {a.regles.map((r, i) => (
              <li key={r.code ?? i}>
                <Card>
                  <p className="text-sm font-semibold text-ink-900">{r.code ?? 'Règle'}</p>
                  {r.libelle ? <p className="text-sm text-ink-700">{r.libelle}</p> : null}
                </Card>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-ink-500">Aucune règle déterministe déclenchée.</p>
        )}
      </section>

      <section className="space-y-2">
        <h2 className="text-lg font-semibold text-blue-900">Facteurs du modèle (SHAP)</h2>
        {a.facteurs && a.facteurs.length > 0 ? (
          <Card>
            <ul className="space-y-1 text-sm">
              {a.facteurs.map((f, i) => (
                <li key={f.feature ?? i} className="flex justify-between gap-3">
                  <span className="text-ink-700">{f.feature ?? 'facteur'}</span>
                  <span className="font-mono text-ink-900">
                    {typeof f.contribution === 'number' ? f.contribution.toFixed(3) : '—'}
                  </span>
                </li>
              ))}
            </ul>
          </Card>
        ) : (
          <p className="text-sm text-ink-500">
            Aucun facteur ML (analyse par règles seules — dégradation gracieuse CDC_05 §1.7).
          </p>
        )}
      </section>

      {a.signaux ? (
        <section className="space-y-2">
          <h2 className="text-lg font-semibold text-blue-900">Signaux évalués</h2>
          <Card>
            <dl className="grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
              {Object.entries(a.signaux).map(([cle, val]) => (
                <div key={cle} className="flex justify-between gap-3">
                  <dt className="text-ink-700">{cle}</dt>
                  <dd className="font-mono text-ink-900">{String(val)}</dd>
                </div>
              ))}
            </dl>
          </Card>
        </section>
      ) : null}

      {a.statut === 'OUVERTE' ? (
        <AlerteActions id={a.id} />
      ) : (
        <p className="text-sm text-ink-500">Cette alerte a déjà été revue.</p>
      )}
    </div>
  );
}

function Ligne({ terme, valeur }: { terme: string; valeur: string }) {
  return (
    <div className="flex gap-2">
      <dt className="w-40 shrink-0 font-semibold text-ink-700">{terme}</dt>
      <dd className="text-ink-900">{valeur}</dd>
    </div>
  );
}

function formatInstant(iso: string): string {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString('fr-FR');
}
