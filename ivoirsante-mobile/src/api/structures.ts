/**
 * api/structures.ts — Annuaire géolocalisé des structures (Module 3, F3.1→F3.8).
 *
 * Réutilise le CLIENT AXIOS UNIQUE (src/config/api.ts). Endpoints PUBLICS en lecture : trouver
 * un hôpital ou une pharmacie ne demande aucune identité (doc Identification — accès léger).
 * Le token Bearer est tout de même joint s'il existe (intercepteur), sans incidence ici.
 */
import { api } from '../config/api';
import type {
  Avis,
  Coordonnees,
  FiltresStructure,
  Structure,
  StructuresResponse,
} from '../types/structure';

/** F3.1/F3.2/F3.3 — Liste filtrée + géolocalisée (statut_jour, distance_km si position). */
export async function rechercherStructures(filtres: FiltresStructure = {}): Promise<Structure[]> {
  const { data } = await api.get<StructuresResponse>('/v1/structures', { params: filtres });
  return data.structures;
}

/** F3.5 — Fiche détaillée d'une structure (services + dispos du jour). */
export async function getStructure(id: number): Promise<Structure> {
  const { data } = await api.get<{ structure: Structure }>(`/v1/structures/${id}`);
  return data.structure;
}

/** F3.8 — Pharmacies de garde du jour, triées par proximité si une position est fournie. */
export async function getPharmaciesGarde(position?: Coordonnees): Promise<Structure[]> {
  const params = position ? { lat: position.lat, lng: position.lng } : undefined;
  const { data } = await api.get<{ pharmacies: Structure[] }>('/v1/pharmacies-garde', { params });
  return data.pharmacies;
}

/** F3.9 — Avis VISIBLES d'une structure (lecture publique, les plus récents d'abord). */
export async function getAvisStructure(id: number): Promise<Avis[]> {
  const { data } = await api.get<{ avis: Avis[] }>(`/v1/structures/${id}/avis`);
  return data.avis;
}
