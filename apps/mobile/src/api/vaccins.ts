/**
 * api/vaccins.ts — Référentiel des vaccins et calendrier vaccinal (P6.8b, CDC_09 §8).
 *
 * Le référentiel est PUBLIC en lecture (savoir à quel âge une dose est prévue est une information
 * de santé publique) ; le calendrier D'UNE PERSONNE passe par la barrière anti-IDOR du carnet.
 *
 * FRONTIÈRE : aucune de ces fonctions ne calcule quoi que ce soit. Les statuts, les dates prévues
 * et le caractère obligatoire arrivent déjà décidés par le serveur.
 */
import { api } from '../config/api';
import type { CalendrierVaccinal, VaccinCatalogue } from '../types/vaccin';

/** Recherche au référentiel national (libellé ou abréviation). */
export async function rechercherVaccins(q?: string): Promise<VaccinCatalogue[]> {
  const { data } = await api.get<{ vaccins: VaccinCatalogue[] }>('/v1/vaccins', {
    params: q ? { q } : undefined,
  });
  return data.vaccins;
}

/** Le calendrier vaccinal d'un membre : ce qui est fait, dû, en retard ou encore à venir. */
export async function obtenirCalendrierVaccinal(membreId: number): Promise<CalendrierVaccinal> {
  const { data } = await api.get<CalendrierVaccinal>(`/v1/membres/${membreId}/calendrier-vaccinal`);
  return data;
}
