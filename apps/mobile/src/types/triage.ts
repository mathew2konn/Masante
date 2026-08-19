/**
 * types/triage.ts — contrats TypeScript du Module 1 (Triage).
 *
 * Reflètent EXACTEMENT les réponses JSON de l'API Laravel (routes /api/v1/...).
 * Le calcul du score reste 100 % serveur (TriageService) : le client n'envoie que
 * la sélection brute et affiche le résultat renvoyé. On ne duplique donc jamais la
 * logique d'impact ici (champ `impact` des questions volontairement ignoré côté app).
 */

/** Niveau de soin renvoyé par le triage (§5.1.2). */
export type Niveau = 'leger' | 'modere' | 'urgent';

/** Type d'une question complémentaire (F1.2). */
export type TypeQuestion = 'nombre' | 'echelle' | 'booleen' | 'choix';

/** Une question complémentaire d'un symptôme (questions_complementaires_json). */
export interface Question {
  cle: string;
  libelle: string;
  type: TypeQuestion;
  unite?: string;
  min?: number;
  max?: number;
  options?: string[];
}

/** Un symptôme sélectionnable (F1.1). */
export interface Symptome {
  id: number;
  nom_fr: string;
  categorie: string;
  questions_complementaires_json: Question[] | null;
}

/**
 * P10a — Une orientation renvoyée par le serveur : le CODE et son libellé.
 *
 * Le code sert à chercher les établissements (`?specialite=`), le libellé à l'afficher. Le mobile
 * ne déduit ni l'un ni l'autre : `specialite_hint` a disparu de `Symptome` parce qu'elle ne
 * gouverne plus rien, et rien ici ne remplace le calcul serveur (règle de frontière).
 */
export interface Orientation {
  code: string;
  libelle: string;
}

/** Réponse GET /v1/symptomes. */
export interface SymptomesResponse {
  total: number;
  par_categorie: Record<string, Symptome[]>;
  symptomes: Symptome[];
  /** Version publiée du référentiel qui gouverne cette liste (CDC_09 §10). */
  referentiel_version: number;
}

/** Valeur d'une réponse au questionnaire (selon le type de question). */
export type ValeurReponse = string | number | boolean;

/** Une réponse au questionnaire complémentaire envoyée à l'API. */
export interface Reponse {
  symptome_id: number;
  cle: string;
  valeur: ValeurReponse;
}

/** Contexte patient facultatif (en attendant le membre du carnet, Module 2). */
export interface ContextePatient {
  patient_nom?: string | null;
  patient_age?: number | null;
  patient_sexe?: 'M' | 'F' | null;
}

/** Corps POST /v1/triage/analyser. */
export interface AnalyserPayload extends ContextePatient {
  symptomes: number[];
  reponses?: Reponse[];
}

/** Réponse POST /v1/triage/analyser (201). */
export interface AnalyseResultat {
  triage_id: number;
  score_severite: number;
  niveau: Niveau;
  specialite_requise: string | null;
  /** Les orientations, DANS L'ORDRE décidé par le référentiel publié (rang). */
  specialites: Orientation[];
  referentiel_version: number;
  recommandation_texte: string;
  drapeau_rouge: boolean;
  details_score: {
    symptomes: number;
    reponses: number;
    antecedents: number;
  };
}

/** Réponse GET /v1/triage/{id}/fiche (F1.8). */
export interface FicheResponse {
  fiche: {
    triage_id: number;
    patient: { nom: string | null; age: number | null; sexe: string | null };
    symptomes: Array<{ id: number; nom: string; poids: number }>;
    score_severite: number;
    niveau: Niveau;
    niveau_libelle: string;
    couleur: string;
    specialite_requise: string | null;
    specialites: Orientation[];
    /** Les réponses au questionnaire — exigées par le §5.4, jamais affichées avant P10a. */
    reponses: Array<{ symptome_id: number; cle: string; valeur: ValeurReponse; valeur_impact: number }>;
    etablissements: GroupeEtablissements[];
    referentiel_version: number | null;
    /** Texte IMPOSÉ par le §5.4. Affiché tel quel, jamais reformulé. */
    mention_obligatoire: string;
    recommandation_texte: string;
    date: string;
  };
  texte_partage: string;
  /** Charge utile du QR « permettant au médecin d'accéder au triage » (§5.4). */
  qr_payload: string;
}

/** Les hôpitaux proposant UNE spécialité (§5.4), groupés pour qu'on sache lequel répond à quoi. */
export interface GroupeEtablissements {
  specialite: Orientation;
  /** Vrai si l'annuaire en contient davantage : la troncature se dit, elle ne se devine pas. */
  tronquee: boolean;
  total: number;
  etablissements: EtablissementProche[];
}

export interface EtablissementProche {
  id: number;
  nom: string;
  type: string;
  commune: string | null;
  adresse: string | null;
  telephone: string | null;
  latitude: number | null;
  longitude: number | null;
  /** Distance à vol d'oiseau, calculée par le serveur. `null` sans position. */
  distance_km: number | null;
  statut_jour: string | null;
}

/** Un élément de l'historique (F1.6). */
export interface TriageHistorique {
  id: number;
  membre_id: number | null;
  patient_nom: string | null;
  patient_age: number | null;
  patient_sexe: string | null;
  symptomes_json: Array<{ id: number; nom: string; poids: number }>;
  score_severite: number;
  niveau: Niveau;
  specialite_requise: string | null;
  fiche_generee: boolean;
  created_at: string;
}

/** Réponse GET /v1/triage/historique (F1.6). */
export interface HistoriqueResponse {
  total: number;
  triages: TriageHistorique[];
}
