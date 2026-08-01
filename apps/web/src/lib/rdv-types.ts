/**
 * Types RDV du portail staff. Miroir de la réponse de l'API /v1/portail/rendez-vous
 * (mêmes champs que le type mobile RendezVous). Écrits à la main : l'OpenAPI ne détaille pas
 * encore le schéma RDV. Le backend reste l'autorité des statuts/transitions.
 */
export type StatutRdv = 'en_attente' | 'confirme' | 'refuse' | 'annule' | 'honore';

export const LIBELLE_RDV: Record<StatutRdv, string> = {
  en_attente: 'En attente',
  confirme: 'Confirmé',
  refuse: 'Refusé',
  annule: 'Annulé',
  honore: 'Honoré',
};

/** Statuts traitables dans la file d'attente (ordre d'affichage des onglets). */
export const STATUTS_FILE: StatutRdv[] = ['en_attente', 'confirme', 'refuse', 'annule', 'honore'];

export type StaffRdv = {
  id: number;
  statut: StatutRdv;
  motif: string;
  date_souhaitee: string;
  date_confirmee: string | null;
  message_agent: string | null;
  membre: { id: number; nom: string; prenom: string } | null;
  service: { id: number; nom_service: string; specialite: string } | null;
  medecin: { id: number; nom: string; prenom: string } | null;
};

export type MedecinReservable = { id: number; nom: string; prenom: string };

export type RdvDetail = { rendez_vous: StaffRdv; medecins: MedecinReservable[] };
