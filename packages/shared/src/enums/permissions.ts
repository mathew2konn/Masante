/**
 * PERMISSIONS RBAC — SOURCE UNIQUE (CDC_10 §3.6, CDC_02 §2.2).
 *
 * ═══ POURQUOI CE FICHIER EXISTE (P11.0) ═══
 *
 * Ce projet garde ses routes sur des PERMISSIONS, jamais sur des noms de rôles : quatorze
 * d'entre elles n'appartiennent délibérément à aucun rôle métier et sont accordées
 * nominativement. Tant que le front ne connaissait que les rôles, il ne pouvait pas savoir ce
 * qu'un compte peut réellement atteindre — il affichait un menu au jugé et laissait
 * l'utilisateur découvrir ses refus par un 403.
 *
 * Le G0 de P11.0 a montré ce que coûte une liste tenue à deux endroits : l'enum `Role` et le
 * seeder Laravel avaient divergé au point que **trois rôles dormants doublaient trois rôles
 * vivants**, et que les trois qui font tourner le portail ne figuraient nulle part côté front.
 * On ne recommence pas ici : cette liste est la référence, et
 * `tests/Feature/PermissionsSourceUniqueTest.php` **casse le build** si le seeder s'en écarte
 * dans un sens ou dans l'autre. C'est le motif de `nis/vecteurs.json` (P6.1), qui garde depuis
 * l'algorithme du NIS de diverger entre TypeScript et PHP.
 *
 * ═══ CE QUE CE FICHIER N'EST PAS ═══
 *
 * Ce n'est **pas** une autorité. Le backend décide, revérifie à chaque requête, et reste seul
 * juge. Le front s'en sert uniquement pour n'AFFICHER que ce qui est atteignable — défense en
 * profondeur, exactement comme le module fraude vérifie le rôle avant de signer un principal
 * que le paiement revérifie (ADR-020 §B2).
 */

/** Les 43 permissions du portail, telles que `PortailRolesSeeder::PERMISSIONS` les crée. */
export const PERMISSIONS = [
  // Administration de la plateforme
  'etablissement.manage',
  'compte.manage',
  'moderation.manage',
  'stats.global',
  'sante_publique.manage',
  // Gestion d'un établissement
  'service.manage',
  'agent.manage',
  'medecin.manage',
  'don_sang.manage',
  'medicament.manage',
  'ordonnance.delivrer',
  'commande.traiter',
  'analyse.executer',
  'stats.etablissement',
  // Accueil et parcours du patient
  'disponibilite.manage',
  'rdv.prevalider',
  'rdv.validate',
  'qr.scan',
  'triage.view',
  'dossier.referent',
  'urgence.bris_de_glace',
  'dossier.ecrire',
  'triage.retour',
  // Gouvernance des référentiels nationaux (CDC_09 §10)
  'referentiel.proposer',
  'referentiel.publier',
  'professionnel.habiliter',
  'medicament.referentiel',
  'analyse.referentiel',
  'specialite.referentiel',
  'vaccin.referentiel',
  'maladie.referentiel',
  'assurance.referentiel',
  'urgence.referentiel',
  // Finance et recouvrement
  'recouvrement.manage',
  // Intelligence artificielle (CDC_05)
  'apprentissage.valider',
  'ia_triage.valider',
  // Le verdict biologique (CDC_09 §7.4 étape 7) — QUINZIÈME occurrence des permissions
  // volontairement orphelines : un résultat biologique validé engage la responsabilité d'un
  // biologiste NOMMÉ, jamais un rôle métier par défaut.
  'analyse.valider',
  // Signature électronique (CDC_10 §4)
  'document.signer',
  // Protocoles médicaux (CDC_08 §7 — une permission par type de validation)
  'protocole.rediger',
  'protocole.valider.clinique',
  'protocole.valider.reglementaire',
  'protocole.valider.scientifique',
  'protocole.valider.technique',
  'protocole.publier',
  'protocole.evaluer',
] as const;

export type Permission = (typeof PERMISSIONS)[number];

/** Une chaîne quelconque est-elle une permission connue ? (garde de désérialisation) */
export function estPermission(valeur: string): valeur is Permission {
  return (PERMISSIONS as readonly string[]).includes(valeur);
}
