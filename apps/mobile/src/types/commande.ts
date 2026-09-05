/**
 * types/commande.ts — Panier et commande de médicaments (B3-d, CDC_11 §9.5).
 *
 * `statut` et `mode_retrait`/`mode_reglement` sont FOURNIS par le serveur, jamais déduits ici
 * (frontière CDC_01 §0.1) — le mobile affiche, il ne recalcule rien.
 */
import type { CommandeStatut, ModeReglementCommande, ModeRetraitCommande } from '@masante/shared';

export interface CommandeLigne {
  id: number;
  medicament_id: number | null;
  medicament_code: string | null;
  nom: string;
  dci: string | null;
  dosage: string | null;
  ordonnance_requise: boolean;
  ordonnance_ligne_id: number | null;
  quantite: number;
  prix_unitaire_indicatif_cfa: number | null;
}

export interface Commande {
  id: number;
  reference: string;
  membre_id: number;
  structure_id: number;
  structure?: { id: number; nom: string; commune: string };
  ordonnance_id: number | null;
  mode_retrait: ModeRetraitCommande;
  adresse_livraison: string | null;
  statut: CommandeStatut;
  montant_indicatif_cfa: number | null;
  mode_reglement: ModeReglementCommande;
  regle_le: string | null;
  commentaire: string | null;
  motif_refus: string | null;
  lignes: CommandeLigne[];
  created_at: string;
}
