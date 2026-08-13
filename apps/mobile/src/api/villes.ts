/**
 * api/villes.ts — Villes couvertes et localisation (P6.4b).
 *
 * Endpoints PUBLICS : l'écran a besoin de la liste des villes AVANT toute connexion, pour
 * proposer le sélecteur de repli quand la localisation est refusée.
 *
 * `chargerVilles` passe par le cache hors ligne (la liste change rarement) ; `localiserVille`
 * n'y passe PAS — une position est ponctuelle, et servir une localisation en cache reviendrait à
 * dire « vous êtes à Abidjan » à quelqu'un qui vient d'arriver à Bouaké. Hors ligne, c'est la
 * mémoire de la dernière ville connue qui prend le relais, annoncée comme telle.
 */
import { api } from '../config/api';
import { lireAvecCache } from '../services/dossierCache';
import type { Coordonnees } from '../types/structure';
import type { Localisation, VillesResponse } from '../types/ville';

/** Les villes couvertes + les libellés de catégorie (source unique serveur). */
export async function chargerVilles(): Promise<VillesResponse> {
  return lireAvecCache('villes', async () => {
    const { data } = await api.get<VillesResponse>('/v1/villes');
    return data;
  });
}

/** « Où suis-je ? » — la réponse vient du serveur, l'écran ne déduit rien. */
export async function localiserVille(position: Coordonnees): Promise<Localisation> {
  const { data } = await api.get<Localisation>('/v1/villes/localiser', {
    params: { lat: position.lat, lng: position.lng },
  });
  return data;
}
