/**
 * utils/dates.ts — petites aides de date pour le carnet (sans dépendance externe).
 *
 * L'API renvoie les dates en ISO (cast `date` Laravel) ; la saisie se fait au format
 * AAAA-MM-JJ. On reste volontairement minimaliste (pas de lib de dates).
 */

/** Extrait la partie date (AAAA-MM-JJ) d'une chaîne ISO ; '' si absente. */
export function isoVersDateInput(iso: string | null | undefined): string {
  if (!iso) return '';
  return iso.slice(0, 10);
}

/** Affiche une date ISO au format jour/mois/année (fr) ; '—' si absente. */
export function formatDateFr(iso: string | null | undefined): string {
  const base = isoVersDateInput(iso);
  if (!base) return '—';
  const [a, m, j] = base.split('-');
  if (!a || !m || !j) return '—';
  return `${j}/${m}/${a}`;
}

/** Âge en années révolues à partir d'une date de naissance ISO ; null si invalide. */
export function calculerAge(iso: string | null | undefined): number | null {
  const base = isoVersDateInput(iso);
  if (!base) return null;
  const naissance = new Date(`${base}T00:00:00`);
  if (Number.isNaN(naissance.getTime())) return null;
  const maintenant = new Date();
  let age = maintenant.getFullYear() - naissance.getFullYear();
  const m = maintenant.getMonth() - naissance.getMonth();
  if (m < 0 || (m === 0 && maintenant.getDate() < naissance.getDate())) age -= 1;
  return age >= 0 ? age : null;
}

/**
 * Valide une saisie AAAA-MM-JJ : format correct, date réelle, non future.
 * Renvoie un message d'erreur (string) ou null si valide.
 */
export function validerDateNaissance(saisie: string): string | null {
  const v = saisie.trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) {
    return 'Format attendu : AAAA-MM-JJ (ex. 1990-05-21).';
  }
  const d = new Date(`${v}T00:00:00`);
  if (Number.isNaN(d.getTime()) || isoVersDateInput(d.toISOString()) !== v) {
    return 'Cette date n’existe pas.';
  }
  const aujourdhui = new Date();
  aujourdhui.setHours(23, 59, 59, 999);
  if (d.getTime() > aujourdhui.getTime()) {
    return 'La date de naissance ne peut pas être dans le futur.';
  }
  return null;
}

/** Valide une date facultative (CMU) ; null si vide ou valide, message sinon. */
export function validerDateFacultative(saisie: string): string | null {
  const v = saisie.trim();
  if (!v) return null;
  if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) {
    return 'Format attendu : AAAA-MM-JJ.';
  }
  const d = new Date(`${v}T00:00:00`);
  if (Number.isNaN(d.getTime()) || isoVersDateInput(d.toISOString()) !== v) {
    return 'Cette date n’existe pas.';
  }
  return null;
}
