/**
 * types/urgence.ts — Module 5 (santé publique & urgences).
 *
 * La fiche vitale est le « sous-ensemble vital minimal » d'un membre (CdC FN2,
 * Note_Continuite §5.2), tel que le renvoie `GET /v1/membres/{id}/fiche-vitale`.
 * Elle ne contient JAMAIS de matricule ni de numéro CMU : elle sert à soigner,
 * pas à identifier administrativement.
 */

export interface MaladieChronique {
  libelle: string;
  /** Traitement en cours : un secouriste doit connaître les interactions possibles. */
  traitement: string | null;
}

export interface VaccinationEssentielle {
  vaccin: string;
  date: string | null;
}

export interface ContactUrgenceVital {
  nom: string;
  lien: string;
  telephone: string;
  principal: boolean;
}

export interface FicheVitale {
  membre_id: number;
  nom: string;
  prenom: string;
  age: number | null;
  sexe: string | null;
  groupe_sanguin: string | null;
  allergies: string[];
  maladies_chroniques: MaladieChronique[];
  vaccinations_essentielles: VaccinationEssentielle[];
  contacts_urgence: ContactUrgenceVital[];
  /** Instant de génération côté serveur : sert à dater le cache hors connexion. */
  genere_le: string;
}
