/**
 * types/triage.ts — contrats TypeScript du Module 1 (Triage).
 *
 * Reflètent EXACTEMENT les réponses JSON de l'API Laravel (routes /api/v1/...).
 * Le calcul du score reste 100 % serveur (TriageService) : le client n'envoie que
 * la sélection brute et affiche le résultat renvoyé. On ne duplique donc jamais la
 * logique d'impact ici (champ `impact` des questions volontairement ignoré côté app).
 */

/**
 * Niveau de priorité renvoyé par le triage (CDC_05 §5.3).
 *
 * ═══ P10b-1 — IL ÉTAIT REDÉFINI ICI, ET C'ÉTAIT UNE INFRACTION ═══
 *
 * Ce fichier portait `type Niveau = 'leger' | 'modere' | 'urgent'` — **trois** valeurs, alors que
 * `@masante/shared` porte les **quatre** de CDC_05 §5.3 depuis P0 et que `palette.json` en peint
 * les couleurs. La règle de source unique de CLAUDE.md l'interdit nommément : « aucune
 * redéfinition locale ». Même famille que les communes d'Abidjan en dur (P6.4b) et les libellés
 * de statut vaccinal (P6.8b) — à ceci près que la copie locale était **la mauvaise version**.
 *
 * `TriageNiveau` couvre les quatre actuels ET les trois hérités : plus rien ne produit ces
 * derniers, mais l'historique les porte, et les convertir changerait ce qu'un patient a lu.
 */
import type { TriageNiveau } from '@masante/shared';

export type Niveau = TriageNiveau;

/** Type d'une question du questionnaire (F1.2). */
export type TypeQuestion = 'nombre' | 'echelle' | 'booleen' | 'choix';

/** Une réponse POSSIBLE d'une question à choix — sa valeur et ce que le patient lit. */
export interface ReponsePossible {
  valeur: string;
  libelle: string;
}

/**
 * Une question du questionnaire adaptatif (CDC_08 §4.3b).
 *
 * ═══ P10b-3-i — ELLE N'APPARTIENT PLUS À UN SYMPTÔME ═══
 *
 * Elle vient d'un PROTOCOLE versionné et signé, servie un tour à la fois par
 * `POST /v1/triage/questions`. Le client ne sait jamais POURQUOI elle est posée — la condition est
 * une règle clinique, elle reste au serveur (règle de frontière, CDC_01 §0.1).
 *
 * `valeur_min`/`valeur_max` sont AFFICHÉS et non appliqués : le serveur refuse lui-même une valeur
 * hors plage, et il le fait sur la version publiée. Les répliquer ici comme garde ferait du client
 * une seconde autorité — la borne sert à dessiner l'échelle, pas à juger.
 */
export interface Question {
  cle: string;
  libelle: string;
  type: TypeQuestion;
  unite?: string | null;
  valeur_min?: number | null;
  valeur_max?: number | null;
  reponses: ReponsePossible[];
}

/**
 * Un symptôme sélectionnable (F1.1).
 *
 * P10b-3-i — `questions_complementaires_json` a disparu : les questions ne sont plus une propriété
 * du symptôme, et on ne sait lesquelles poser qu'après avoir su ce qui est coché.
 */
export interface Symptome {
  id: number;
  nom_fr: string;
  categorie: string;
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

/**
 * Une réponse au questionnaire envoyée à l'API.
 *
 * P10b-3-i — `symptome_id` a disparu : la clé d'une question est unique dans la version du
 * protocole, et la faire porter par un symptôme laisserait croire qu'elle peut avoir deux sens.
 */
export interface Reponse {
  cle: string;
  valeur: ValeurReponse;
}

/**
 * P10c-1 — Une constante clinique du §5.2 envoyée à l'API.
 *
 * ═══ LE CLIENT N'ENVOIE QUE LE TYPE ET LA VALEUR ═══
 *
 * Ni `origine`, ni `mesure_id`. C'est le SERVEUR qui reconnaît une valeur reprise du carnet, en la
 * comparant à celle qu'il a lui-même proposée. Déclarer sa propre provenance depuis le client
 * rejouerait la faute refermée quatre fois — `source` d'une contribution (P7-C), `obligatoire`
 * d'une vaccination (P6.8b), `provenance` d'une couverture (P6.8d), `medecin_nom` d'une ordonnance
 * (P6.5a).
 */
export interface ConstanteSaisie {
  type_mesure: string;
  valeur: number | null;
}

/**
 * Ce que le serveur sait d'une constante collectable, et ce que le carnet en dit.
 *
 * ═══ TROIS ÉTATS, ET UN SEUL EST UNE AFFIRMATION SUR LE PRÉSENT ═══
 *
 * `proposition` = une mesure du carnet DANS sa fenêtre de fraîcheur : le champ est pré-rempli avec
 * sa date, le patient corrige s'il veut. `contexte` = une mesure hors fenêtre : montrée pour
 * information, **jamais pré-remplie**. Ni l'un ni l'autre = rien à dire.
 *
 * *Une température prise il y a trois mois n'est pas une température.* La fenêtre est une donnée
 * du référentiel publié — le client ne la connaît pas et n'a pas à la connaître : il affiche ce
 * que le serveur a rangé dans l'une ou l'autre case.
 */
export interface ConstanteProposable {
  type_mesure: string;
  libelle: string;
  unite: string;
  decimales: number;
  valeur_min: number;
  valeur_max: number;
  proposition: { valeur: number; date_mesure: string | null; mesure_id: number } | null;
  contexte: { valeur: number; date_mesure: string | null; mesure_id: number } | null;
}

/** Réponse GET /v1/triage/constantes. */
export interface ConstantesResponse {
  constantes: ConstanteProposable[];
  referentiel_version: number;
}

/** Corps POST /v1/triage/questions — un tour de questionnaire adaptatif. */
export interface QuestionsPayload {
  symptomes: number[];
  reponses?: Reponse[];
  constantes?: ConstanteSaisie[];
  patient_age?: number | null;
  patient_sexe?: 'M' | 'F' | null;
}

/** Réponse POST /v1/triage/questions. */
export interface QuestionsResultat {
  /** Les questions débloquées et pas encore répondues. Vide quand il n'y a plus rien à demander. */
  questions: Question[];
  /** Le serveur dit quand l'interrogatoire est terminé — le client ne le déduit pas. */
  termine: boolean;
  /** §9.1 — le ou les protocoles appliqués et leur version. */
  protocoles: Array<{ code: string; version: string; numero: number }>;
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
  /** P10c-1 — les constantes du §5.2. Type et valeur seulement : voir {@link ConstanteSaisie}. */
  constantes?: ConstanteSaisie[];
  /** Rattache le triage à un membre du carnet — condition du pré-remplissage. */
  membre_id?: number | null;
}

/** Réponse POST /v1/triage/analyser (201). */
export interface AnalyseResultat {
  triage_id: number;
  score_severite: number;
  niveau: Niveau;
  /** Le libellé citoyen, FOURNI par le backend (CDC_05 §5.3) — jamais dérivé côté client. */
  niveau_libelle: string;
  specialite_requise: string | null;
  /** Les orientations, DANS L'ORDRE décidé par le référentiel publié (rang). */
  specialites: Orientation[];
  referentiel_version: number;

  /**
   * P10b-1 — Le protocole qui a rendu la décision et sa version exacte (CDC_08 §6.1, §9.1).
   *
   * Affiché, jamais interprété : le client ne relit aucune règle. C'est l'exigence médico-légale
   * du §6.1 rendue visible — « chaque décision conserve la version exacte du protocole utilisée ».
   */
  protocole: { code: string; version: string; numero: number };

  /** Les règles effectivement déclenchées (§9.1 « justification »). */
  regles_declenchees: Array<{ ordre: number; libelle: string }>;

  recommandation_texte: string;
  drapeau_rouge: boolean;
  details_score: {
    symptomes: number;
    reponses: number;
    antecedents: number;
  };

  /**
   * P10c-1 — Ce que le serveur a RETENU, avec l'origine qu'il a lui-même décidée.
   *
   * `origine` n'est pas décorative : elle dit si le patient a relevé la valeur maintenant ou s'il a
   * validé celle que le carnet proposait. Ce n'est pas la même information clinique.
   */
  constantes: Array<{
    type_mesure: string;
    libelle: string;
    valeur: number;
    unite: string;
    origine: 'saisie' | 'reprise_du_carnet';
  }>;
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
    /**
     * Les réponses au questionnaire — exigées par le §5.4, jamais affichées avant P10a.
     *
     * P10b-3-i — `libelle` porte l'énoncé FIGÉ au moment du triage. `valeur_impact` n'existe plus
     * pour les triages postérieurs à la bascule : depuis que l'impact est une règle, une règle
     * peut porter sur plusieurs réponses et ses points ne se répartissent entre elles par aucun
     * partage défendable. Les triages antérieurs, eux, le portent encore — d'où l'optionnalité.
     */
    reponses: Array<{
      cle: string;
      libelle?: string;
      valeur: ValeurReponse;
      symptome_id?: number;
      valeur_impact?: number;
    }>;
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
