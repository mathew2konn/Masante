/**
 * Enums d'état métier — SOURCE UNIQUE (CDC_01 §6.3, CDC_04 §5.2, CDC_06 §4.2).
 * Ces états sont FOURNIS PAR LE BACKEND ; l'interface les affiche, ne les déduit jamais.
 */

/** États d'un rendez-vous (workflow de validation à deux étapes). */
export const RendezVousStatut = {
  EN_ATTENTE_VALIDATION: 'EN_ATTENTE_VALIDATION',
  PREVALIDE_SECRETAIRE: 'PREVALIDE_SECRETAIRE',
  CONFIRME_EN_ATTENTE_PAIEMENT: 'CONFIRME_EN_ATTENTE_PAIEMENT',
  PAYE: 'PAYE',
  ANNULE: 'ANNULE',
  REFUSE: 'REFUSE',
  TERMINE: 'TERMINE',
} as const;
export type RendezVousStatut = (typeof RendezVousStatut)[keyof typeof RendezVousStatut];

/** États d'une transaction de paiement (machine à états stricte — CDC_06 §4.2). */
export const PaiementStatut = {
  INITIATED: 'INITIATED',
  PENDING: 'PENDING',
  PROCESSING: 'PROCESSING',
  SUCCESS: 'SUCCESS',
  FAILED: 'FAILED',
  CANCELLED: 'CANCELLED',
  REFUNDED: 'REFUNDED',
} as const;
export type PaiementStatut = (typeof PaiementStatut)[keyof typeof PaiementStatut];

/** États d'une facture (CDC_06 §7). Fournis par le backend (microservice paiement), jamais déduits. */
export const FactureStatut = {
  EMISE: 'EMISE',
  PARTIELLEMENT_PAYEE: 'PARTIELLEMENT_PAYEE',
  PAYEE: 'PAYEE',
  ANNULEE: 'ANNULEE',
  REMPLACEE: 'REMPLACEE',
} as const;
export type FactureStatut = (typeof FactureStatut)[keyof typeof FactureStatut];

/** États d'un portefeuille Wallet (CDC_06 §6). Fourni par le backend, jamais déduit. */
export const WalletStatut = {
  ACTIF: 'ACTIF',
  GELE: 'GELE',
  CLOTURE: 'CLOTURE',
} as const;
export type WalletStatut = (typeof WalletStatut)[keyof typeof WalletStatut];

/** Niveaux de triage côté patient (4) — couleur + texte + icône obligatoires. */
export const TriageNiveauPatient = {
  FAIBLE: 'FAIBLE',
  RECOMMANDEE: 'RECOMMANDEE',
  RAPIDE: 'RAPIDE',
  URGENCE: 'URGENCE',
} as const;
export type TriageNiveauPatient = (typeof TriageNiveauPatient)[keyof typeof TriageNiveauPatient];

/** Niveaux de triage hospitalier (5) — Manchester / ESI, paramétrables par pays. */
export const TriageNiveauHospitalier = {
  ROUGE: 'ROUGE',
  ORANGE: 'ORANGE',
  JAUNE: 'JAUNE',
  VERT: 'VERT',
  BLEU: 'BLEU',
} as const;
export type TriageNiveauHospitalier =
  (typeof TriageNiveauHospitalier)[keyof typeof TriageNiveauHospitalier];

/**
 * Rôles RBAC (CDC_10 §3.6). Valeurs = noms spatie côté backend (snake_case minuscule,
 * guard `web`), tels que renvoyés par `getRoleNames()`. Le mobile n'utilise que `patient` ;
 * les autres servent aux portails web (ADR-011).
 */
export const Role = {
  PATIENT: 'patient',
  MEDECIN: 'medecin',
  INFIRMIER: 'infirmier',
  SECRETAIRE: 'secretaire',
  PHARMACIEN: 'pharmacien',
  LABORANTIN: 'laborantin',
  RADIOLOGUE: 'radiologue',
  ADMIN_ETABLISSEMENT: 'admin_etablissement',
  SUPER_ADMIN: 'super_admin',
  MINISTERE: 'ministere',
  ASSURANCE: 'assurance',
} as const;
export type Role = (typeof Role)[keyof typeof Role];

/**
 * Niveau d'une alerte de fraude IA (CDC_05, routage B — ADR-020). Valeurs = celles produites
 * par le fraud-detection-service et persistées par le paiement (`ia_fraude_alertes.niveau`).
 * Seuls SUSPECT/TRES_SUSPECT donnent lieu à une alerte routée ; NORMAL n'est jamais persisté.
 * Le NIVEAU est calculé backend (règles + ML) — jamais déduit par le front (frontière CDC_02).
 */
export const NiveauFraudeIa = {
  NORMAL: 'NORMAL',
  SUSPECT: 'SUSPECT',
  TRES_SUSPECT: 'TRES_SUSPECT',
} as const;
export type NiveauFraudeIa = (typeof NiveauFraudeIa)[keyof typeof NiveauFraudeIa];

/**
 * Statut de traitement d'une alerte de fraude IA par le contrôleur plateforme (CDC_05, ADR-020).
 * OUVERTE à la création ; REVUE après revue humaine (trace, aucune action automatique — détection
 * seule, ADR-017). La transition est opérée par le backend paiement, jamais par le front.
 */
export const StatutAlerteFraudeIa = {
  OUVERTE: 'OUVERTE',
  REVUE: 'REVUE',
} as const;
export type StatutAlerteFraudeIa = (typeof StatutAlerteFraudeIa)[keyof typeof StatutAlerteFraudeIa];

/**
 * Types de notification en application (carnet familial partagé, incrément D1).
 *
 * Le TYPE est décidé par le backend et voyage tel quel jusqu'au mobile, qui s'en sert uniquement
 * pour choisir l'icône et l'écran de destination. Il ne le recalcule ni ne l'interprète : c'est
 * l'énumération miroir de `App\Support\TypeNotification` côté Laravel.
 *
 * `DOSSIER_CONSULTE` couvre les trois voies d'accès d'un soignant (scan QR à l'accueil, médecin
 * référent, bris de glace) — le niveau d'urgence est porté par la charge utile, pas par le type.
 */
export const TypeNotification = {
  CONTRIBUTION_DEPOSEE: 'CONTRIBUTION_DEPOSEE',
  CONTRIBUTION_VALIDEE: 'CONTRIBUTION_VALIDEE',
  CONTRIBUTION_REJETEE: 'CONTRIBUTION_REJETEE',
  DELEGATION_RECUE: 'DELEGATION_RECUE',
  RESPONSABLE_DESIGNE: 'RESPONSABLE_DESIGNE',
  DOSSIER_CONSULTE: 'DOSSIER_CONSULTE',
  /** Un soignant a consigné un acte dans le carnet, pendant une session ouverte (D0). */
  CARNET_ENRICHI: 'CARNET_ENRICHI',
} as const;
export type TypeNotification = (typeof TypeNotification)[keyof typeof TypeNotification];

/**
 * Voies d'accès à un dossier (`acces_dossier.type_acces`) — incrément D2.
 *
 * POURQUOI CET ENUM ARRIVE ICI ET MAINTENANT. Les libellés vivaient en dur dans l'application
 * mobile depuis le Module 2 et avaient divergé de la base : trois des cinq voies n'y figuraient
 * pas, et un parent lisait littéralement « bris_de_glace » à l'écran de son journal d'accès. La
 * source unique n'est pas une élégance ici — c'est ce qui empêche la divergence de se reformer.
 *
 * Miroir PHP : `App\Support\TypeAccesDossier`.
 */
export const TypeAccesDossier = {
  /** Un agent a scanné le QR présenté par le patient (voie consentie, 30 min). */
  QR_SCAN: 'qr_scan',
  /** Le médecin désigné référent du membre a ouvert le dossier. */
  REFERENT: 'referent',
  /** Un proche à qui le carnet est partagé l'a consulté depuis son application (incrément A). */
  DELEGATION: 'delegation',
  /** Urgence vitale : ouverture SANS consentement, périmètre vital, 15 min, motif obligatoire. */
  BRIS_DE_GLACE: 'bris_de_glace',
  /** Accès exceptionnel d'un administrateur de la plateforme. */
  ADMIN: 'admin',
} as const;
export type TypeAccesDossier = (typeof TypeAccesDossier)[keyof typeof TypeAccesDossier];

/**
 * Libellés destinés au CITOYEN (décision propriétaire, 2026-08-12).
 *
 * « Bris de glace » est un terme métier (*break the glass*) : clair entre professionnels, opaque
 * pour une famille. « Accès d'urgence vitale » porte à lui seul la justification de l'absence de
 * consentement — c'est ce que le lecteur doit comprendre en une ligne, sans connaître le
 * mécanisme. Les valeurs techniques, elles, ne changent nulle part : elles sont dans l'ENUM de la
 * base, dans la permission `urgence.bris_de_glace` et dans des modules validés.
 */
export const LIBELLE_TYPE_ACCES: Record<TypeAccesDossier, string> = {
  [TypeAccesDossier.QR_SCAN]: 'Consultation après scan de votre QR',
  [TypeAccesDossier.REFERENT]: 'Consultation par votre médecin référent',
  [TypeAccesDossier.DELEGATION]: 'Consultation par un proche',
  [TypeAccesDossier.BRIS_DE_GLACE]: "Accès d'urgence vitale",
  [TypeAccesDossier.ADMIN]: 'Accès administrateur MaSanté',
};

/**
 * Rend lisible une voie d'accès. Le repli sur la valeur brute est CONSERVÉ à dessein : si la base
 * gagnait une sixième voie sans que cette table suive, mieux vaut afficher un mot inconnu que
 * masquer un accès au dossier. Mais le repli ne doit plus jamais être le comportement normal —
 * c'était le défaut trouvé au G0 de D2.
 */
export function libelleTypeAcces(type: string): string {
  return LIBELLE_TYPE_ACCES[type as TypeAccesDossier] ?? type;
}
