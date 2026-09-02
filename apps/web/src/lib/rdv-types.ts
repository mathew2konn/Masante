/**
 * Types RDV du portail staff. Miroir de la réponse de l'API /v1/portail/rendez-vous
 * (mêmes champs que le type mobile RendezVous). Écrits à la main : l'OpenAPI ne détaille pas
 * encore le schéma RDV. Le backend reste l'autorité des statuts/transitions.
 *
 * B1-a — le statut vient désormais de `@masante/shared::RendezVousStatut` (miroir
 * `RendezVousValidationService::STATUTS`), au lieu d'un littéral dupliqué à la main : c'est le
 * défaut trouvé au G0 (l'enum `PREVALIDE_SECRETAIRE`, mort, divergeait du vrai contrat dupliqué
 * indépendamment ici, côté mobile et côté PHP).
 */
import type { RendezVousStatut } from '@masante/shared';
import { LIBELLE_RDV as LIBELLE_RDV_PARTAGE } from '@masante/shared';

export type StatutRdv = RendezVousStatut;
export const LIBELLE_RDV = LIBELLE_RDV_PARTAGE;

/** Statuts traitables dans la file d'attente (ordre d'affichage des onglets). */
export const STATUTS_FILE: StatutRdv[] = ['en_attente', 'prevalide', 'confirme', 'refuse', 'annule', 'honore'];

export type StaffRdv = {
  id: number;
  statut: StatutRdv;
  motif: string;
  motif_orientation: string | null;
  message_orientation: string | null;
  date_souhaitee: string;
  date_confirmee: string | null;
  message_agent: string | null;
  membre: { id: number; nom: string; prenom: string } | null;
  service: { id: number; nom_service: string; specialite: string } | null;
  medecin: {
    id: number;
    nom: string;
    prenom: string;
    numero_professionnel?: string | null;
    photo_url?: string | null;
  } | null;
};

export type MedecinReservable = { id: number; nom: string; prenom: string };

/**
 * Référent actif du membre (B1-b / D6) — lu via `ReferentService::actif()`, aucun nouveau
 * mécanisme. `null` si le membre n'a désigné personne.
 */
export type ReferentActif = {
  medecin: { titre: string; nom: string; prenom: string; structure: { nom: string } | null } | null;
} | null;

export type RdvDetail = {
  rendez_vous: StaffRdv;
  medecins: MedecinReservable[];
  referent: ReferentActif;
  /** B1-b / D7 — aperçu, jamais persisté ici : même méthode que le paiement (`RecuRdvService`). */
  tarif: number | null;
  tarif_source: 'service' | 'medecin' | 'structure' | null;
};
