/**
 * types/donSang.ts — Don de sang (CdC FN6, Module 5.7).
 *
 * Reflète `GET /v1/don-sang` (mes donneurs + les urgences qui me concernent, ciblage serveur),
 * `GET /v1/don-sang/besoins` (public) et les endpoints d'inscription.
 *
 * Le mobile ne calcule NI la compatibilité ABO/Rhésus, NI l'éligibilité, NI le délai de carence :
 * tout vient du serveur. Une erreur de compatibilité tue — elle n'a rien à faire dans une app.
 */
import type { Structure } from './structure';

export type GroupeSanguin = 'A+' | 'A-' | 'B+' | 'B-' | 'AB+' | 'AB-' | 'O+' | 'O-';

/** Un besoin publié par un établissement. `urgent` alerte les donneurs ; `courant` informe. */
export interface BesoinSang {
  id: number;
  structure_id: number;
  groupe_sanguin: GroupeSanguin;
  niveau: 'courant' | 'urgent';
  message: string | null;
  date_debut: string;
  date_fin: string | null;
  actif: boolean;
  structure?: Pick<Structure, 'id' | 'nom' | 'commune' | 'latitude' | 'longitude'> & {
    adresse?: string;
    telephone?: string | null;
  };
}

/** Une urgence à laquelle CE compte peut répondre (ciblage calculé serveur). */
export interface AlerteDon {
  besoin: BesoinSang;
  /** Groupes de mes membres donneurs qui conviennent à cette poche. */
  mes_groupes_utiles: GroupeSanguin[];
}

/** Un membre inscrit comme donneur volontaire. */
export interface Donneur {
  id: number;
  membre_id: number;
  nom: string;
  groupe_sanguin: GroupeSanguin | null;
  inscrit_at: string | null;
  dernier_don_at: string | null;
  /** Faux pendant le délai de carence : le donneur est au repos, pas désinscrit. */
  peut_donner: boolean;
  jours_avant_don: number;
}

/** Réponse de `GET /v1/don-sang`. */
export interface DonSangVue {
  donneurs: Donneur[];
  alertes: AlerteDon[];
  /** Règles de collecte (âge, carence) : affichées telles quelles, jamais recodées. */
  regles: { age_min: number; age_max: number; delai_jours: number };
}
