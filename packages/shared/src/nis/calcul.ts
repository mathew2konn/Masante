/**
 * NIS — algorithme pur (ISO 7064 MOD 97-10). CDC_09 §3.3/§3.4, ADR-021.
 *
 * Ce fichier n'importe RIEN : ni zod, ni JSON, ni runtime. C'est délibéré —
 *  1. le cœur de l'algorithme reste indépendant de tout écosystème (Rule-002) ;
 *  2. il peut être exécuté tel quel par le runner natif de Node (`node --test` +
 *     type-stripping), ce qui permet de prouver la parité avec PHP **sans ajouter
 *     la moindre dépendance de test** (discipline §2.6).
 *
 * L'habillage (schéma Zod, messages, vecteurs) vit dans `index.ts`.
 */

/** Longueur totale : 3 (préfixe) + 2 (année) + 8 (compteur) + 2 (clé). */
export const NIS_LONGUEUR = 15;

/** Préfixe du pays de lancement (Côte d'Ivoire Santé). */
export const NIS_PREFIXE_CI = 'CIS';

/** Motifs d'invalidité — libellés identiques côté backend (CalculateurNis). */
export const NisMotifInvalide = {
  LONGUEUR_INVALIDE: 'LONGUEUR_INVALIDE',
  FORMAT_INVALIDE: 'FORMAT_INVALIDE',
  CLE_INVALIDE: 'CLE_INVALIDE',
} as const;
export type NisMotifInvalide = (typeof NisMotifInvalide)[keyof typeof NisMotifInvalide];

export interface NisSegments {
  prefixe: string;
  annee: string;
  compteur: string;
  cle: string;
}

export type NisVerification =
  | { valide: true; segments: NisSegments }
  | { valide: false; motif: NisMotifInvalide };

/**
 * Calcule la clé de contrôle.
 *
 * Les lettres du préfixe sont converties A=10 … Z=35 : deux pays partageant le même couple
 * année + compteur obtiennent des clés différentes (exigence multi-pays, CDC_09 §1.2).
 *
 * Le nombre formé dépasse Number.MAX_SAFE_INTEGER : le modulo est appliqué chiffre par
 * chiffre — équivalent exact, sans BigInt.
 */
export function calculerCleNis(prefixe: string, annee: string, compteur: string): string {
  let converti = '';
  for (const c of prefixe.toUpperCase()) {
    converti += c >= 'A' && c <= 'Z' ? String(c.charCodeAt(0) - 55) : c;
  }

  let reste = 0;
  for (const chiffre of converti + annee + compteur) {
    reste = (reste * 10 + Number(chiffre)) % 97;
  }

  return String(98 - reste).padStart(2, '0');
}

/** Assemble un NIS complet ; la clé est calculée, jamais fournie. */
export function composerNis(prefixe: string, annee: number, compteur: number): string {
  const aa = String(annee).padStart(2, '0');
  const cc = String(compteur).padStart(8, '0');

  return prefixe.toUpperCase() + aa + cc + calculerCleNis(prefixe, aa, cc);
}

/**
 * Vérifie qu'un NIS est bien formé et que sa clé est correcte.
 * Ne dit RIEN de son existence : cette question appartient au serveur (anti-énumération).
 */
export function verifierNis(valeur: string): NisVerification {
  const v = valeur.trim().toUpperCase();

  if (v.length !== NIS_LONGUEUR) {
    return { valide: false, motif: NisMotifInvalide.LONGUEUR_INVALIDE };
  }
  if (!/^[A-Z]{3}\d{12}$/.test(v)) {
    return { valide: false, motif: NisMotifInvalide.FORMAT_INVALIDE };
  }

  const segments: NisSegments = {
    prefixe: v.slice(0, 3),
    annee: v.slice(3, 5),
    compteur: v.slice(5, 13),
    cle: v.slice(13, 15),
  };

  if (calculerCleNis(segments.prefixe, segments.annee, segments.compteur) !== segments.cle) {
    return { valide: false, motif: NisMotifInvalide.CLE_INVALIDE };
  }

  return { valide: true, segments };
}

/** Raccourci booléen, pour les gardes de rendu. */
export function estNisValide(valeur: string): boolean {
  return verifierNis(valeur).valide;
}

/** Messages en français clair (CDC_01 §8.3) — un motif, un message. */
const MESSAGES: Record<NisMotifInvalide, string> = {
  LONGUEUR_INVALIDE: 'Le numéro de santé doit contenir 15 caractères.',
  FORMAT_INVALIDE: 'Format attendu : 3 lettres suivies de 12 chiffres (ex. CIS241200012535).',
  CLE_INVALIDE: 'Ce numéro de santé est incorrect. Vérifiez votre saisie.',
};

export function messageNisInvalide(motif: NisMotifInvalide): string {
  return MESSAGES[motif];
}
