/**
 * config/constants.ts — constantes métier d'affichage du Module 1.
 */

/**
 * P6.8e — LE REPLI DE DERNIER RECOURS, ET RIEN D'AUTRE.
 *
 * Cette valeur était lue directement par cinq écrans, en double avec `TriageService::NUMERO_SAMU`
 * côté backend et avec « SOS 185 » collé dans les traductions partagées. CDC_02 §37 l'interdit
 * nommément : « rien en dur — **y compris les numéros d'urgence** ».
 *
 * Elle ne disparaît pas pour autant, et c'est délibéré : le consommateur de ce module n'a **ni
 * réseau, ni session, ni compte** — la carte vitale d'urgence s'ouvre depuis l'écran de CONNEXION,
 * pour un secouriste. Un refus, ici, signifierait « pas de numéro d'urgence, dans une urgence ».
 *
 * ELLE N'EST PLUS LUE PAR AUCUN ÉCRAN. Le seul appelant légitime est
 * `src/urgence/numerosUrgence.ts`, qui la place au **troisième et dernier rang** derrière le
 * référentiel publié et le cache `SecureStore`.
 *
 * **Un seul numéro y figure**, celui que le corpus nomme (CDC_00 §4 l'oppose explicitement au
 * « 15 » français). Les autres numéros de secours vivent dans le référentiel et **n'ont pas été
 * compilés ici** : les y mettre reviendrait à refaire, en plus discret, le défaut que ce module
 * referme.
 */
export const SAMU_NUMERO_REPLI = '185';

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
