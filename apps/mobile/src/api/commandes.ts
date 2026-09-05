/**
 * api/commandes.ts — Panier et commande de médicaments (B3-d, CDC_11 §9.5). Client axios unique.
 *
 * Le panier reste LOCAL (store `panier.ts`, F1) : cette API ne reçoit que l'acte de commander.
 */
import { api } from '../config/api';
import type { Commande } from '../types/commande';

export interface LigneCommandeEnvoi {
  medicament_id: number;
  quantite: number;
}

export interface NouvelleCommande {
  membre_id: number;
  structure_id: number;
  ordonnance_id?: number | null;
  lignes: LigneCommandeEnvoi[];
  mode_retrait: 'retrait' | 'livraison';
  adresse_livraison?: string | null;
  commentaire?: string | null;
}

export async function listerCommandes(): Promise<Commande[]> {
  const { data } = await api.get<{ commandes: Commande[] }>('/v1/commandes');
  return data.commandes;
}

export async function obtenirCommande(id: number): Promise<Commande> {
  const { data } = await api.get<{ commande: Commande }>(`/v1/commandes/${id}`);
  return data.commande;
}

export async function passerCommande(commande: NouvelleCommande): Promise<Commande> {
  const { data } = await api.post<{ commande: Commande }>('/v1/commandes', commande);
  return data.commande;
}

export async function annulerCommande(id: number): Promise<Commande> {
  const { data } = await api.patch<{ commande: Commande }>(`/v1/commandes/${id}/annuler`);
  return data.commande;
}

/** F6 (réécrit, B4) — cette officine peut-elle encaisser cette commande en ligne aujourd'hui ? */
export async function disponibiliteEnLignePaiementCommande(id: number): Promise<boolean> {
  const { data } = await api.get<{ disponible: boolean }>(`/v1/commandes/${id}/paiement-en-ligne`);
  return data.disponible;
}

/**
 * Ouvre (ou réutilise) un checkout GeniusPay réel pour cette commande. Ne règle RIEN : seule la
 * notification reçue plus tard par le serveur confirme le paiement (S6, transposé) — l'appelant
 * ne doit jamais supposer que l'ouverture du navigateur équivaut à un règlement.
 */
export async function payerCommandeEnLigne(
  id: number,
): Promise<{ checkout_url: string | null; reference: string }> {
  const { data } = await api.post<{ checkout_url: string | null; reference: string }>(
    `/v1/commandes/${id}/paiement-en-ligne`,
  );
  return data;
}
