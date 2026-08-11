/**
 * carnet/registre.ts — SOURCE UNIQUE des 5 sections du carnet (Module 2 / 2A.4).
 *
 * Chaque section décrit : son chemin API, ses libellés, son icône, son schéma de champs
 * (rendu et validés génériquement par CarnetSectionForm) et la façon de résumer un élément
 * en liste. Ajouter/modifier une section = éditer ce fichier, rien d'autre.
 *
 * Les énumérations reflètent À L'IDENTIQUE les règles `in:...` des contrôleurs backend.
 */
import type { CarnetItem, Medicament, SectionDescriptor, Ton } from '../types/carnet';
import { formatDateFr, formatDateHeureFr, heureCourte } from '../utils/dates';

// --- Libellés des énumérations (miroir des règles backend) ---

const ANTECEDENT_TYPE: Record<string, string> = {
  maladie_chronique: 'Maladie chronique',
  allergie: 'Allergie',
  chirurgie: 'Chirurgie',
  hospitalisation: 'Hospitalisation',
  autre: 'Autre',
};

const VACCIN_STATUT: Record<string, string> = {
  fait: 'Fait',
  a_faire: 'À faire',
  en_retard: 'En retard',
};

const VACCIN_STATUT_TON: Record<string, Ton> = {
  fait: 'success',
  a_faire: 'warning',
  en_retard: 'danger',
};

const ANALYSE_TYPE: Record<string, string> = {
  biologique: 'Biologique',
  radiologique: 'Radiologique',
  cardiologique: 'Cardiologique',
  autre: 'Autre',
};

const RAPPEL_TYPE: Record<string, string> = {
  medicament: 'Médicament',
  rendez_vous: 'Rendez-vous',
  vaccin: 'Vaccin',
  controle: 'Contrôle',
};

const RAPPEL_FREQUENCE: Record<string, string> = {
  unique: 'Unique',
  quotidien: 'Quotidien',
  hebdomadaire: 'Hebdomadaire',
  mensuel: 'Mensuel',
};

/** Auteur d'une note (F2.12) : le patient saisit ; le médecin enrichira via QR (Modules 3/4). */
const NOTE_AUTEUR: Record<string, string> = {
  patient: 'Vous',
  medecin: 'Médecin',
};

/**
 * Lien de parenté d'un contact d'urgence (F2.11) — les CLÉS sont le miroir exact de
 * ContactUrgenceController::LIENS_PARENTE ; les VALEURS sont le libellé affiché.
 *
 * La clé part en base et transite par l'API : elle reste ASCII, minuscule, `_` en
 * séparateur. Tout l'habillage (accents, parenthèses, tirets) appartient au libellé.
 * Exporté : les contacts d'urgence ont leur écran dédié (ContactsUrgenceEcran), hors
 * moteur générique — il construit ses options à partir de cet objet, donc une entrée
 * ajoutée ici apparaît automatiquement dans le menu déroulant.
 */
export const LIEN_PARENTE: Record<string, string> = {
  papa: 'Papa',
  maman: 'Maman',
  epouse: 'Épouse',
  epoux: 'Époux',
  frere: 'Frère',
  soeur: 'Sœur',
  cousin: 'Cousin',
  cousine: 'Cousine',
  tante: 'Tante',
  oncle: 'Oncle',
  tuteur: 'Tuteur',
  tutrice: 'Tutrice',
  grand_mere: 'Grand-mère',
  grand_pere: 'Grand-père',
  ami: 'Ami(e)',
  autre: 'Autre',
};

/**
 * Provenance d'une entrée de dossier (F2.13) — miroir de l'ENUM `source` backend
 * (`antecedents`, `ordonnances`, `resultats_analyses`, `documents_medicaux`).
 * `patient` = auto-déclaré (atténué) ; `medecin`/`structure` = source de vérité (mis en avant).
 * Les sections sans colonne `source` (vaccinations, rappels, notes) n'affichent pas de pastille.
 */
export const PROVENANCE_SOURCE: Record<string, { label: string; icone: string; officiel: boolean }> = {
  patient: { label: 'Auto-déclaré', icone: 'person-outline', officiel: false },
  medecin: { label: 'Médecin', icone: 'medkit-outline', officiel: true },
  structure: { label: 'Structure', icone: 'business-outline', officiel: true },
};

/** Transforme une table de libellés en options pour un champ select. */
const opts = (table: Record<string, string>) =>
  Object.entries(table).map(([value, label]) => ({ value, label }));

const str = (v: unknown): string => (typeof v === 'string' ? v : '');

// --- Les 5 sections ---

const antecedents: SectionDescriptor = {
  slug: 'antecedents',
  chemin: 'antecedents',
  titre: 'Antécédents',
  titreSingulier: 'antécédent',
  icone: 'medkit-outline',
  ajoutParPatient: true,
  champs: [
    { kind: 'select', cle: 'type', label: 'Type', obligatoire: true, options: opts(ANTECEDENT_TYPE) },
    { kind: 'texte', cle: 'description', label: 'Description', obligatoire: true, multiligne: true, max: 2000 },
    { kind: 'date', cle: 'date_diagnostic', label: 'Date du diagnostic', futurInterdit: true },
    { kind: 'texte', cle: 'medecin_nom', label: 'Médecin', max: 200, autoCap: 'words' },
    { kind: 'texte', cle: 'structure_sanitaire', label: 'Structure sanitaire', max: 200, autoCap: 'words' },
    { kind: 'texte', cle: 'traitement_actuel', label: 'Traitement actuel', multiligne: true, max: 2000 },
  ],
  resume: (i: CarnetItem) => ({
    titre: ANTECEDENT_TYPE[str(i.type)] ?? 'Antécédent',
    lignes: [str(i.description), i.date_diagnostic ? `Diagnostiqué le ${formatDateFr(str(i.date_diagnostic))}` : ''].filter(Boolean),
  }),
};

const vaccinations: SectionDescriptor = {
  slug: 'vaccinations',
  chemin: 'vaccinations',
  titre: 'Vaccinations',
  titreSingulier: 'vaccination',
  icone: 'shield-outline',
  champs: [
    { kind: 'texte', cle: 'vaccin_nom', label: 'Vaccin', obligatoire: true, max: 200, autoCap: 'sentences' },
    { kind: 'select', cle: 'statut', label: 'Statut', obligatoire: true, options: opts(VACCIN_STATUT) },
    { kind: 'booleen', cle: 'obligatoire', label: 'Vaccin obligatoire', defaut: false },
    { kind: 'date', cle: 'date_administration', label: "Date d'administration" },
    { kind: 'date', cle: 'date_rappel', label: 'Date de rappel' },
    { kind: 'texte', cle: 'centre_vaccination', label: 'Centre de vaccination', max: 200, autoCap: 'words' },
    { kind: 'texte', cle: 'numero_lot', label: 'Numéro de lot', max: 100, autoCap: 'characters' },
    { kind: 'texte', cle: 'medecin_nom', label: 'Médecin', max: 200, autoCap: 'words' },
  ],
  resume: (i: CarnetItem) => ({
    titre: str(i.vaccin_nom) || 'Vaccin',
    lignes: [i.date_administration ? `Administré le ${formatDateFr(str(i.date_administration))}` : ''].filter(Boolean),
    badge: { texte: VACCIN_STATUT[str(i.statut)] ?? str(i.statut), ton: VACCIN_STATUT_TON[str(i.statut)] ?? 'neutre' },
  }),
};

const ordonnances: SectionDescriptor = {
  slug: 'ordonnances',
  chemin: 'ordonnances',
  titre: 'Ordonnances',
  titreSingulier: 'ordonnance',
  icone: 'document-text-outline',
  ajoutParPatient: true,
  champs: [
    { kind: 'texte', cle: 'medecin_nom', label: 'Médecin prescripteur', obligatoire: true, max: 200, autoCap: 'words' },
    { kind: 'texte', cle: 'structure_sanitaire', label: 'Structure sanitaire', obligatoire: true, max: 200, autoCap: 'words' },
    { kind: 'date', cle: 'date_prescription', label: 'Date de prescription', obligatoire: true },
    { kind: 'medicaments', cle: 'medicaments_json', label: 'Médicaments', obligatoire: true },
  ],
  resume: (i: CarnetItem) => {
    const meds = Array.isArray(i.medicaments_json) ? (i.medicaments_json as Medicament[]) : [];
    return {
      titre: str(i.medecin_nom) ? `Dr ${str(i.medecin_nom)}` : 'Ordonnance',
      lignes: [
        str(i.structure_sanitaire),
        i.date_prescription ? `Prescrite le ${formatDateFr(str(i.date_prescription))}` : '',
        `${meds.length} médicament${meds.length > 1 ? 's' : ''}`,
      ].filter(Boolean),
    };
  },
};

const resultatsAnalyses: SectionDescriptor = {
  slug: 'resultats-analyses',
  chemin: 'resultats-analyses',
  titre: "Résultats d'analyses",
  titreSingulier: 'résultat',
  icone: 'flask-outline',
  ajoutParPatient: true,
  champs: [
    { kind: 'select', cle: 'type_analyse', label: "Type d'analyse", obligatoire: true, options: opts(ANALYSE_TYPE) },
    { kind: 'texte', cle: 'intitule', label: 'Intitulé', obligatoire: true, max: 200, autoCap: 'sentences' },
    { kind: 'date', cle: 'date_analyse', label: "Date de l'analyse", obligatoire: true },
    { kind: 'texte', cle: 'laboratoire', label: 'Laboratoire', max: 200, autoCap: 'words' },
    { kind: 'texte', cle: 'medecin_prescripteur', label: 'Médecin prescripteur', max: 200, autoCap: 'words' },
    { kind: 'resultats', cle: 'resultats_json', label: 'Résultats (paramètre / valeur)' },
  ],
  resume: (i: CarnetItem) => ({
    titre: str(i.intitule) || 'Analyse',
    lignes: [
      i.date_analyse ? `Réalisée le ${formatDateFr(str(i.date_analyse))}` : '',
      str(i.laboratoire),
    ].filter(Boolean),
    badge: { texte: ANALYSE_TYPE[str(i.type_analyse)] ?? str(i.type_analyse), ton: 'neutre' },
  }),
};

const rappels: SectionDescriptor = {
  slug: 'rappels',
  chemin: 'rappels',
  titre: 'Rappels',
  titreSingulier: 'rappel',
  icone: 'alarm-outline',
  champs: [
    { kind: 'select', cle: 'type', label: 'Type', obligatoire: true, options: opts(RAPPEL_TYPE) },
    { kind: 'texte', cle: 'titre', label: 'Titre', obligatoire: true, max: 200, autoCap: 'sentences' },
    { kind: 'texte', cle: 'contenu', label: 'Contenu', obligatoire: true, multiligne: true, max: 2000 },
    { kind: 'select', cle: 'frequence', label: 'Fréquence', obligatoire: true, options: opts(RAPPEL_FREQUENCE) },
    { kind: 'heure', cle: 'heure', label: 'Heure', obligatoire: true },
    { kind: 'date', cle: 'date_debut', label: 'Date de début', obligatoire: true },
    { kind: 'date', cle: 'date_fin', label: 'Date de fin', apresChamp: 'date_debut' },
    { kind: 'booleen', cle: 'actif', label: 'Rappel actif', defaut: true },
  ],
  resume: (i: CarnetItem) => ({
    titre: str(i.titre) || 'Rappel',
    lignes: [
      RAPPEL_TYPE[str(i.type)] ?? str(i.type),
      `${RAPPEL_FREQUENCE[str(i.frequence)] ?? str(i.frequence)} à ${heureCourte(str(i.heure))}`,
    ].filter(Boolean),
    badge: i.actif === false ? { texte: 'Inactif', ton: 'neutre' } : undefined,
  }),
};

// NB : les contacts d'urgence (F2.11) ne sont PAS une section générique — écran dédié à 2 blocs
// (ContactsUrgenceEcran), lié depuis la fiche membre. Ils réutilisent l'API carnet par `chemin`.

const notesObservations: SectionDescriptor = {
  slug: 'notes-observations',
  chemin: 'notes-observations',
  titre: 'Notes & observations',
  titreSingulier: 'note',
  icone: 'create-outline',
  appendOnly: true, // F2.12 : append-only (pas d'édition) ; suppression = rétractation tracée
  champs: [
    { kind: 'texte', cle: 'contenu', label: 'Note', obligatoire: true, multiligne: true, max: 5000, autoCap: 'sentences' },
  ],
  resume: (i: CarnetItem) => ({
    titre: i.created_at ? formatDateHeureFr(str(i.created_at)) : 'Note',
    lignes: [str(i.contenu)].filter(Boolean),
    badge: { texte: NOTE_AUTEUR[str(i.auteur_type)] ?? 'Auteur', ton: 'neutre' },
  }),
};

/** Toutes les sections, dans l'ordre d'affichage du carnet. */
export const SECTIONS: SectionDescriptor[] = [
  antecedents,
  vaccinations,
  ordonnances,
  resultatsAnalyses,
  rappels,
  notesObservations,
];

/** Retrouve une section par son slug d'URL (undefined si inconnu). */
export function sectionParSlug(slug: string): SectionDescriptor | undefined {
  return SECTIONS.find((s) => s.slug === slug);
}
