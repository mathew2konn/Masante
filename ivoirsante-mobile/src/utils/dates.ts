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

/**
 * Convertit une saisie AAAA-MM-JJ en objet Date LOCAL (minuit local), ou null si invalide.
 * On construit la date par composants (année, mois, jour) pour éviter tout décalage de fuseau
 * — `new Date('2026-01-01')` serait interprété en UTC et pourrait reculer d'un jour.
 */
export function dateInputVersDate(v: string | null | undefined): Date | null {
  const base = isoVersDateInput(v);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(base)) return null;
  const [a, m, j] = base.split('-').map(Number);
  const d = new Date(a, m - 1, j);
  // Rejette les dates « qui glissent » (ex. 2026-02-31 → 3 mars) en revérifiant les composants.
  if (d.getFullYear() !== a || d.getMonth() !== m - 1 || d.getDate() !== j) return null;
  return d;
}

/** Formate un objet Date en AAAA-MM-JJ à partir de ses composants LOCAUX (pas d'UTC). */
export function dateVersDateInput(d: Date): string {
  const p = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

/** Affiche une date ISO au format jour/mois/année (fr) ; '—' si absente. */
export function formatDateFr(iso: string | null | undefined): string {
  const base = isoVersDateInput(iso);
  if (!base) return '—';
  const [a, m, j] = base.split('-');
  if (!a || !m || !j) return '—';
  return `${j}/${m}/${a}`;
}

/** Affiche une date+heure ISO au format « JJ/MM/AAAA à HH:MM » ; '—' si absente. */
export function formatDateHeureFr(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  const deuxChiffres = (n: number) => String(n).padStart(2, '0');
  const date = `${deuxChiffres(d.getDate())}/${deuxChiffres(d.getMonth() + 1)}/${d.getFullYear()}`;
  const heure = `${deuxChiffres(d.getHours())}:${deuxChiffres(d.getMinutes())}`;
  return `${date} à ${heure}`;
}

/** Formate un nombre de secondes en compte à rebours MM:SS. */
export function formatChrono(secondes: number): string {
  const s = Math.max(0, Math.floor(secondes));
  const m = Math.floor(s / 60);
  const r = s % 60;
  return `${String(m).padStart(2, '0')}:${String(r).padStart(2, '0')}`;
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

/**
 * Valide une saisie de date AAAA-MM-JJ générique (sections du carnet).
 * Renvoie un message d'erreur ou null.
 */
export function validerDate(
  saisie: string,
  options: { obligatoire?: boolean; futurInterdit?: boolean } = {},
): string | null {
  const v = saisie.trim();
  if (!v) return options.obligatoire ? 'Ce champ est obligatoire.' : null;
  if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return 'Format attendu : AAAA-MM-JJ.';
  const d = new Date(`${v}T00:00:00`);
  if (Number.isNaN(d.getTime()) || isoVersDateInput(d.toISOString()) !== v) {
    return 'Cette date n’existe pas.';
  }
  if (options.futurInterdit) {
    const fin = new Date();
    fin.setHours(23, 59, 59, 999);
    if (d.getTime() > fin.getTime()) return 'La date ne peut pas être dans le futur.';
  }
  return null;
}

/** Valide une heure HH:MM (24h). Renvoie un message d'erreur ou null. */
export function validerHeure(saisie: string, obligatoire = false): string | null {
  const v = saisie.trim();
  if (!v) return obligatoire ? 'Ce champ est obligatoire.' : null;
  if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(v)) return 'Format attendu : HH:MM (ex. 08:30).';
  return null;
}

/** Tronque une heure « HH:MM:SS » renvoyée par l'API en « HH:MM ». */
export function heureCourte(valeur: string | null | undefined): string {
  return valeur ? valeur.slice(0, 5) : '';
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
