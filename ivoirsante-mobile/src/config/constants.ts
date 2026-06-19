/**
 * config/constants.ts — constantes métier d'affichage du Module 1.
 *
 * Le numéro d'urgence est le SAMU 185 (numéro vert Côte d'Ivoire) — JAMAIS le « 15 »
 * français (cohérent avec TriageService::NUMERO_SAMU côté backend).
 */

/** Numéro vert des urgences en Côte d'Ivoire (SAMU). */
export const SAMU_NUMERO = '185';

/**
 * Libellés lisibles + pictogramme (redondance icône + texte, §6 Accessibilité)
 * pour chaque catégorie de symptôme renvoyée par l'API.
 */
export const CATEGORIES: Record<string, { label: string; icone: string }> = {
  fievre: { label: 'Fièvre', icone: '🌡️' },
  douleur: { label: 'Douleurs', icone: '💢' },
  neurologique: { label: 'Neurologique', icone: '🧠' },
  respiratoire: { label: 'Respiratoire', icone: '🫁' },
  cardiaque: { label: 'Cardiaque', icone: '❤️' },
  digestif: { label: 'Digestif', icone: '🩺' },
  dentaire: { label: 'Dentaire', icone: '🦷' },
  orl: { label: 'ORL (gorge, nez, oreilles)', icone: '👂' },
  ophtalmologique: { label: 'Yeux', icone: '👁️' },
  dermatologique: { label: 'Peau', icone: '🩹' },
  general: { label: 'Général', icone: '⚕️' },
  gynecologique: { label: 'Gynécologie', icone: '🌸' },
  traumatologique: { label: 'Traumatologie', icone: '🦴' },
};

/** Repli pour une catégorie inconnue (robustesse si l'API ajoute une catégorie). */
export function categorieAffichage(cle: string): { label: string; icone: string } {
  return CATEGORIES[cle] ?? { label: cle, icone: '•' };
}
