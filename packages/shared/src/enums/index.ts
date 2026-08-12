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
} as const;
export type TypeNotification = (typeof TypeNotification)[keyof typeof TypeNotification];
