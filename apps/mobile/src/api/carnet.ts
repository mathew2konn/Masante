/**
 * api/carnet.ts — CRUD générique des sections du carnet (backend 2A.4).
 *
 * Réutilise le CLIENT AXIOS UNIQUE (token Bearer injecté). Toutes les sections partagent le
 * même contrat ; on paramètre par le `chemin` (segment d'URL : antecedents, vaccinations,
 * ordonnances, resultats-analyses, rappels). L'isolation anti-IDOR passe par le membre parent
 * + le scoping à la relation côté serveur.
 */
import { api } from '../config/api';
import { lireAvecCache } from '../services/dossierCache';
import type { CarnetItem } from '../types/carnet';

const base = (membreId: number, chemin: string) => `/v1/membres/${membreId}/${chemin}`;

/** Liste des éléments d'une section (les plus récents d'abord). Lisible hors ligne (cache). */
export async function listerSection(membreId: number, chemin: string): Promise<CarnetItem[]> {
  return lireAvecCache(`section:${membreId}:${chemin}`, async () => {
    const { data } = await api.get<{ items: CarnetItem[] }>(base(membreId, chemin));
    return data.items;
  });
}

/** Détail d'un élément. Lisible hors ligne (cache). */
export async function obtenirItem(membreId: number, chemin: string, id: number): Promise<CarnetItem> {
  return lireAvecCache(`item:${membreId}:${chemin}:${id}`, async () => {
    const { data } = await api.get<{ item: CarnetItem }>(`${base(membreId, chemin)}/${id}`);
    return data.item;
  });
}

/** Création d'un élément. */
export async function creerItem(
  membreId: number,
  chemin: string,
  payload: Record<string, unknown>,
): Promise<CarnetItem> {
  const { data } = await api.post<{ item: CarnetItem }>(base(membreId, chemin), payload);
  return data.item;
}

/** Mise à jour partielle d'un élément. */
export async function modifierItem(
  membreId: number,
  chemin: string,
  id: number,
  payload: Record<string, unknown>,
): Promise<CarnetItem> {
  const { data } = await api.put<{ item: CarnetItem }>(`${base(membreId, chemin)}/${id}`, payload);
  return data.item;
}

/** Suppression d'un élément. */
export async function supprimerItem(membreId: number, chemin: string, id: number): Promise<void> {
  await api.delete(`${base(membreId, chemin)}/${id}`);
}
