/**
 * Identifiant National de Santé (NIS) — SOURCE UNIQUE côté client (CDC_09 §3, ADR-001/ADR-021).
 *
 * FRONTIÈRE — pourquoi ce calcul a le droit d'exister dans le front :
 * le CDC_09 §3.4 IMPOSE explicitement la double validation « côté client (feedback immédiat)
 * ET côté serveur (autorité) ». Un checksum est une règle de FORMAT, jamais une règle médicale,
 * tarifaire ou d'éligibilité (CDC_01 §0.1). Le serveur reste seul juge : ce module ne dit
 * jamais si un NIS EXISTE, uniquement s'il est bien formé.
 *
 * ANTI-DIVERGENCE : l'implémentation PHP (`App\Services\Nis\CalculateurNis`) est le jumeau de
 * `calcul.ts`. Les deux suites de tests consomment le MÊME `vecteurs.json` — toute divergence
 * casse le build (ADR-021 §5).
 *
 * Le noyau algorithmique vit dans `calcul.ts`, sans aucun import : il reste exécutable par le
 * runner natif de Node, ce qui permet de prouver la parité sans dépendance de test.
 */
import { z } from 'zod';

import { messageNisInvalide, verifierNis } from './calcul';
import vecteurs from './vecteurs.json';

export * from './calcul';

/** Vecteurs de référence partagés TS ↔ PHP. Fichier généré : ne pas éditer à la main. */
export const nisVecteurs = vecteurs;

/**
 * Schéma Zod de saisie du NIS.
 * La validation métier fait toujours autorité côté backend — ceci n'est que du confort de saisie.
 */
export const nisSchema = z
  .string()
  .trim()
  .transform((v) => v.toUpperCase())
  .superRefine((v, ctx) => {
    const r = verifierNis(v);
    if (!r.valide) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: messageNisInvalide(r.motif) });
    }
  });
