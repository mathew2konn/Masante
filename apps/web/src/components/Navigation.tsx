'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { GROUPES_ZONES, LIBELLES_GROUPES, type Zone } from '@/lib/zones';

/**
 * Navigation du portail — DÉRIVÉE du registre de zones (P11.0).
 *
 * Elle ne connaît aucun lien : elle affiche ce que `mesZones()` a retenu, donc exactement ce que
 * la garde serveur laisserait passer. Les deux lisent la même déclaration, ce qui rend
 * impossible le cas qui aurait été le plus probable — *un lien visible vers une page qui refuse*.
 *
 * Aucun métier ici (CDC_02 §0.1) : le calcul de ce qui est atteignable a eu lieu côté serveur,
 * à partir des permissions que le backend a renvoyées.
 */
export function Navigation({ zones }: { zones: Zone[] }) {
  // Composant client uniquement pour connaître la page courante : les zones affichables ont été
  // décidées côté serveur, à partir des permissions du backend, et arrivent ici déjà filtrées.
  const cheminActif = usePathname().replace(/^\//, '');

  if (zones.length === 0) {
    // Un compte professionnel sans aucune zone n'est pas une anomalie à masquer : c'est le cas
    // du rôle `assurance`, dont le portail n'existe pas encore. On le lui dit.
    return null;
  }

  return (
    <nav aria-label="Sections du portail" className="space-y-6">
      {GROUPES_ZONES.map((groupe) => {
        const duGroupe = zones.filter((z) => z.groupe === groupe);
        if (duGroupe.length === 0) return null;

        return (
          <div key={groupe}>
            <p className="mb-2 px-3 text-xs font-semibold uppercase tracking-wide text-ink-500">
              {LIBELLES_GROUPES[groupe]}
            </p>
            <ul className="space-y-1">
              {duGroupe.map((zone) => {
                const actif = cheminActif === zone.chemin || cheminActif.startsWith(`${zone.chemin}/`);
                return (
                  <li key={zone.chemin}>
                    <Link
                      href={`/${zone.chemin}`}
                      aria-current={actif ? 'page' : undefined}
                      className={[
                        'block rounded-lg px-3 py-2 text-sm transition-colors',
                        'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                        actif
                          ? 'bg-primary font-semibold text-surface'
                          : 'text-ink-700 hover:bg-blue-50 hover:text-blue-900',
                      ].join(' ')}
                    >
                      {zone.libelle}
                    </Link>
                  </li>
                );
              })}
            </ul>
          </div>
        );
      })}
    </nav>
  );
}
