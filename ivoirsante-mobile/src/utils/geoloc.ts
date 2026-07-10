/**
 * utils/geoloc.ts — Position de l'utilisateur au premier plan (expo-location, SDK 54).
 *
 * Foreground uniquement (compatible Expo Go : aucune localisation en arrière-plan). La position
 * sert au calcul de proximité des structures (F3.2/F3.3) ; elle n'est alors ni persistée ni envoyée
 * ailleurs qu'aux endpoints publics de l'annuaire (données non sensibles).
 *
 * SEULE EXCEPTION — le bouton SOS (FN1, Module 5.2) : la position accompagne l'alerte et est
 * journalisée côté serveur, parce que le patient a lui-même déclenché l'urgence. Voir
 * `obtenirPositionUrgence()` : une seule mesure au moment du tap, jamais de suivi continu.
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

/** Position d'urgence : coordonnées + précision annoncée par le capteur (en mètres). */
export type PositionUrgence = { lat: number; lng: number; precision: number | null };

/**
 * Position pour le bouton SOS (FN1). Deux différences avec `obtenirPosition()` :
 *
 *  - on tente d'abord la DERNIÈRE POSITION CONNUE, immédiate, avant d'interroger le GPS. En urgence,
 *    une position approximative tout de suite vaut mieux qu'une position exacte dans trente secondes ;
 *  - l'échec ne renvoie pas d'erreur mais `null` : un SOS sans position doit partir quand même.
 *
 * Jamais bloquant : l'appel au SAMU ne dépend pas de cette fonction.
 */
export async function obtenirPositionUrgence(): Promise<PositionUrgence | null> {
  try {
    const { status } = await Location.requestForegroundPermissionsAsync();
    if (status !== 'granted') return null;

    const derniere = await Location.getLastKnownPositionAsync({ maxAge: 120_000 });
    const position = derniere ?? (await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High }));

    return {
      lat: position.coords.latitude,
      lng: position.coords.longitude,
      precision: position.coords.accuracy ?? null,
    };
  } catch {
    return null;
  }
}
