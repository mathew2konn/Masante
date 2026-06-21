/**
 * utils/geoloc.ts — Position de l'utilisateur au premier plan (expo-location, SDK 54).
 *
 * Foreground uniquement (compatible Expo Go : aucune localisation en arrière-plan). La position
 * sert au calcul de proximité des structures (F3.2/F3.3) ; elle n'est jamais persistée ni envoyée
 * ailleurs qu'aux endpoints publics de l'annuaire (données non sensibles).
 */
import * as Location from 'expo-location';
import type { Coordonnees } from '../types/structure';

/** Résultat discriminé : succès (coords) ou échec explicite (permission / indisponible). */
export type ResultatPosition =
  | { ok: true; coords: Coordonnees }
  | { ok: false; raison: 'permission_refusee' | 'indisponible' };

/**
 * Demande l'autorisation (si nécessaire) puis renvoie la position courante.
 * Précision « Balanced » (~100 m) : suffisant pour trier par proximité, plus rapide et
 * plus économe que « High » sur un téléphone d'entrée de gamme.
 */
export async function obtenirPosition(): Promise<ResultatPosition> {
  try {
    const { status } = await Location.requestForegroundPermissionsAsync();
    if (status !== 'granted') {
      return { ok: false, raison: 'permission_refusee' };
    }

    const position = await Location.getCurrentPositionAsync({
      accuracy: Location.Accuracy.Balanced,
    });

    return {
      ok: true,
      coords: { lat: position.coords.latitude, lng: position.coords.longitude },
    };
  } catch {
    return { ok: false, raison: 'indisponible' };
  }
}
