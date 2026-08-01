/**
 * api/donSang.ts — Don de sang (CdC FN6). Client axios unique.
 *
 * Les CENTRES DE COLLECTE n'ont pas d'endpoint dédié : ce sont les structures de l'annuaire portant
 * un service de spécialité `don_sang`. On réutilise donc `rechercherStructures` (Module 3), qui sait
 * déjà trier par proximité — la carte, les fiches et les distances existent, on ne les refait pas.
 */
import { api } from '../config/api';
import { rechercherStructures } from './structures';
import type { Coordonnees, Structure } from '../types/structure';
import type { BesoinSang, Donneur, DonSangVue } from '../types/donSang';

/** Spécialité de service qui fait d'une structure un centre de collecte. */
export const SPECIALITE_DON_SANG = 'don_sang';

/** Mes membres donneurs + les urgences auxquelles ils peuvent répondre (ciblage serveur). */
export async function obtenirDonSang(): Promise<DonSangVue> {
  const { data } = await api.get<DonSangVue>('/v1/don-sang');
  return data;
}

/** Les groupes les plus demandés, en cours (public). Urgences en tête. */
export async function obtenirBesoins(commune?: string): Promise<BesoinSang[]> {
  const { data } = await api.get<{ besoins: BesoinSang[] }>('/v1/don-sang/besoins', {
    params: commune ? { commune } : undefined,
  });
  return data.besoins;
}

/** Centres de collecte proches (structures ayant un service `don_sang`). */
export async function obtenirCentres(position?: Coordonnees): Promise<Structure[]> {
  return rechercherStructures({
    specialite: SPECIALITE_DON_SANG,
    ...(position ? { lat: position.lat, lng: position.lng } : {}),
  });
}

/** Inscrit un membre comme donneur volontaire (consentement explicite, membre par membre). */
export async function inscrireDonneur(membreId: number): Promise<Donneur> {
  const { data } = await api.post<{ donneur: Donneur }>(`/v1/membres/${membreId}/donneur`);
  return data.donneur;
}

/** Déclare un don effectué : ouvre le délai de carence (le donneur n'est plus sollicité d'ici là). */
export async function declarerDon(membreId: number, date: string): Promise<Donneur> {
  const { data } = await api.post<{ donneur: Donneur }>(`/v1/membres/${membreId}/donneur/don`, { date });
  return data.donneur;
}

/** Retire le consentement (effet immédiat). La date du dernier don est conservée côté serveur. */
export async function retirerDonneur(membreId: number): Promise<void> {
  await api.delete(`/v1/membres/${membreId}/donneur`);
}
