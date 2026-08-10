import type { NiveauFraudeIa } from '@masante/shared';
import { LIBELLE_NIVEAU } from '@/lib/fraude-types';

/**
 * Pastille de niveau de fraude. La couleur n'est qu'une AIDE VISUELLE du niveau calculé backend
 * (frontière : le front ne déduit rien). Tokens du preset partagé (aucune couleur en dur hors palette).
 */
export function NiveauBadge({ niveau }: { niveau: NiveauFraudeIa }) {
  const style =
    niveau === 'TRES_SUSPECT'
      ? 'bg-danger/10 text-danger'
      : niveau === 'SUSPECT'
        ? 'bg-warning/10 text-warning'
        : 'bg-surface-muted text-ink-700';
  return (
    <span className={`inline-flex rounded-pill px-3 py-1 text-xs font-semibold ${style}`}>
      {LIBELLE_NIVEAU[niveau]}
    </span>
  );
}
