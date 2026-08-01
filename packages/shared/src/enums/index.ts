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

/** Rôles RBAC (CDC_10 §3.6) — utilisés par la navigation ET le rendu. */
export const Role = {
  PATIENT: 'PATIENT',
  MEDECIN: 'MEDECIN',
  INFIRMIER: 'INFIRMIER',
  SECRETAIRE: 'SECRETAIRE',
  PHARMACIEN: 'PHARMACIEN',
  LABORANTIN: 'LABORANTIN',
  RADIOLOGUE: 'RADIOLOGUE',
  ADMIN_ETABLISSEMENT: 'ADMIN_ETABLISSEMENT',
  SUPER_ADMIN: 'SUPER_ADMIN',
  MINISTERE: 'MINISTERE',
  ASSURANCE: 'ASSURANCE',
} as const;
export type Role = (typeof Role)[keyof typeof Role];
