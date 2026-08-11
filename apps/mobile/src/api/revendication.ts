/**
 * api/revendication.ts — reconnaître un carnet comme le sien (carnet familial partagé, B).
 *
 * POURQUOI CE MODULE EXISTE : sans lui, la personne à qui l'on partage SON PROPRE carnet voit
 * quand même l'écran « Créez votre dossier de santé » (P6.1) et en crée un second, avec un second
 * numéro national. Le partage rendait le doublon visible ; il ne l'empêchait pas.
 *
 * FRONTIÈRE : c'est le backend qui décide si un carnet est revendicable — il exige l'assertion du
 * responsable au moment du partage ET l'absence de dossier titulaire sur ce compte. Le client ne
 * fait qu'appeler et afficher.
 *
 * Délibérément NON mis en cache hors ligne : revendiquer transfère la propriété d'un dossier
 * médical. Cela ne se décide pas sur des données périmées, et cela ne s'exécute pas sans réseau.
 */
import { api } from '../config/api';
import type { CarnetRevendicable } from '../types/delegation';
import type { Membre } from '../types/membre';

/** Les carnets qu'un proche a désignés comme étant celui de ce compte. */
export async function listerCarnetsRevendicables(): Promise<CarnetRevendicable[]> {
  const { data } = await api.get<{ revendicables: CarnetRevendicable[] }>(
    '/v1/membres/revendicables',
  );
  return data.revendicables;
}

/** Reconnaît le carnet comme sien : la propriété est transférée, aucun nouveau NIS n'est créé. */
export async function revendiquerCarnet(membreId: number): Promise<Membre> {
  const { data } = await api.post<{ membre: Membre }>(`/v1/membres/${membreId}/revendiquer`);
  return data.membre;
}
