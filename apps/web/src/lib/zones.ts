import type { Permission, Role } from '@masante/shared';

/**
 * REGISTRE DES ZONES DU PORTAIL — source unique du « qui voit quoi » (P11.0).
 *
 * ═══ CE QU'IL REMPLACE ═══
 *
 * Le portail n'avait qu'une seule porte, et elle était grossière :
 *
 *     const ROLES_PATIENT: Role[] = ['patient'];
 *     estProfessionnel = (u) => u.roles.some((r) => !ROLES_PATIENT.includes(r));
 *
 * « Tout ce qui n'est pas patient entre, et une fois entré atteint tout. » C'était tenable avec
 * trois modules. Ça devient un défaut de sécurité avec les onze applications professionnelles de
 * CDC_11 : un infirmier atteindrait le portail du Ministère.
 *
 * ═══ CE QU'IL EST, ET CE QU'IL N'EST PAS ═══
 *
 * Une zone déclare LA PERMISSION QUI L'OUVRE, une seule fois, et cette déclaration sert deux
 * choses qui ne peuvent alors plus diverger : la **garde serveur** de la zone et la **navigation**
 * qui la propose. C'est la propriété qui compte — *un utilisateur ne voit que ce qu'il peut
 * atteindre*, non parce qu'on a pensé à masquer le lien, mais parce que le lien et la garde
 * lisent la même ligne.
 *
 * **Ce registre n'est pas une autorité.** Le backend décide, et revérifie chaque requête : chaque
 * route Laravel porte déjà `permission:…`, et rien ici ne l'assouplit. Si ce fichier mentait, un
 * utilisateur verrait une entrée de menu et recevrait un 403 — jamais l'inverse. C'est la défense
 * en profondeur du module fraude (ADR-020 §B2), où Next vérifie le rôle avant de signer un
 * principal que le paiement revérifie de son côté.
 *
 * ═══ POURQUOI IL EST COURT ═══
 *
 * Il ne liste que les zones qui EXISTENT dans ce portail. Y inscrire les dix applications de
 * CDC_11 avant de les avoir écrites afficherait un menu vers des pages absentes — le « socle à
 * vide » refusé en P6.3-D3. Chaque zone migrée depuis Blade, et chaque application neuve, ajoute
 * **une ligne** ici et hérite de la garde comme de la navigation sans qu'aucun code ne bouge.
 */

/** Groupes de navigation — l'ordre du tableau est l'ordre affiché. */
export const GROUPES_ZONES = ['parcours', 'gouvernance', 'compte'] as const;
export type GroupeZone = (typeof GROUPES_ZONES)[number];

export type Zone = {
  /** Segment d'URL, sans slash initial. Sert de clé. */
  readonly chemin: string;
  readonly libelle: string;
  readonly description: string;
  readonly groupe: GroupeZone;
  /**
   * Permission qui ouvre la zone. `null` = ouverte à tout compte professionnel connecté
   * (aujourd'hui : seulement la sécurité du compte, que chacun doit pouvoir gérer).
   */
  readonly permission: Permission | null;
  /**
   * Rôles supplémentaires exigés EN PLUS de la permission, quand la décision propriétaire porte
   * sur le rôle et non sur une permission. Cas unique aujourd'hui : les alertes de fraude, dont
   * ADR-017 §7 exige qu'elles n'aillent qu'à un contrôleur plateforme INDÉPENDANT de la
   * structure signalée — le prévenir reviendrait à prévenir le fraudeur. Aucune permission
   * n'exprime cette indépendance, c'est une propriété du rôle.
   */
  readonly roles?: readonly Role[];
};

export const ZONES: readonly Zone[] = [
  {
    chemin: 'rendez-vous',
    libelle: 'Rendez-vous',
    description: 'File d’attente des demandes à valider pour vos services.',
    groupe: 'parcours',
    permission: 'rdv.validate',
  },
  {
    chemin: 'demandes-inscription',
    libelle: 'Demandes d’inscription',
    description: 'Candidatures d’établissements souhaitant rejoindre la plateforme.',
    groupe: 'gouvernance',
    permission: 'etablissement.manage',
  },
  {
    chemin: 'fraude-alertes',
    libelle: 'Alertes de fraude',
    description: 'Signalements de conformité de la plateforme — détection seule.',
    groupe: 'gouvernance',
    permission: null,
    roles: ['admin_ivoirsante', 'ministere'],
  },
  {
    chemin: 'securite/mfa',
    libelle: 'Sécurité du compte',
    description: 'Double authentification par code à usage unique.',
    groupe: 'compte',
    permission: null,
  },
] as const;

/** Libellés des groupes, pour la navigation. */
export const LIBELLES_GROUPES: Record<GroupeZone, string> = {
  parcours: 'Parcours du patient',
  gouvernance: 'Gouvernance',
  compte: 'Mon compte',
};

/**
 * Un compte peut-il atteindre cette zone ?
 *
 * Les deux conditions se CUMULENT quand elles sont déclarées : c'est volontaire. Une zone qui
 * exigerait « la permission OU le rôle » laisserait le rôle contourner la permission, ce qui
 * viderait de son sens le fait que ce projet garde sur des permissions.
 */
export function zoneAccessible(
  zone: Zone,
  permissions: readonly string[],
  roles: readonly string[],
): boolean {
  if (zone.permission !== null && !permissions.includes(zone.permission)) return false;
  if (zone.roles !== undefined && !zone.roles.some((r) => roles.includes(r))) return false;
  return true;
}

/** Les zones qu'un compte peut réellement atteindre, dans l'ordre des groupes. */
export function zonesAccessibles(
  permissions: readonly string[],
  roles: readonly string[],
): Zone[] {
  return GROUPES_ZONES.flatMap((groupe) =>
    ZONES.filter((z) => z.groupe === groupe && zoneAccessible(z, permissions, roles)),
  );
}

/** La zone qui porte ce chemin, ou `undefined` si le chemin n'est pas une zone déclarée. */
export function zoneParChemin(chemin: string): Zone | undefined {
  return ZONES.find((z) => z.chemin === chemin);
}
