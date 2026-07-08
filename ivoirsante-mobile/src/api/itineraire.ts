/**
 * api/itineraire.ts — Calcul d'itinéraire via OSRM (Module 3, F3.7).
 *
 * OSRM (Open Source Routing Machine) hébergé par `routing.openstreetmap.de` (le routeur public
 * d'openstreetmap.org) : GRATUIT, sans clé API, avec des instances séparées par profil — voiture
 * (`routed-car`) et à pied (`routed-foot`). Alternative à Google Directions (payant — cf. décisions
 * de cadrage Module 3). Le TRANSPORT COMMUN (F3.7) n'a pas de routage public transit gratuit
 * fiable → limite assumée (migration Google/transit en prod). En production on pourra héberger sa
 * propre instance OSRM ou basculer sur OpenRouteService sans changer ce contrat.
 *
 * Sécurité (§8 / A10 SSRF) : l'hôte est FIXE et de confiance ; on n'y interpole que des
 * coordonnées numériques (jamais d'entrée utilisateur libre). Aucune donnée médicale transmise.
 */
import axios from 'axios';
import type { Coordonnees } from '../types/structure';

/** Modes de déplacement proposés (F3.7). Le transport commun est hors périmètre (limite assumée). */
export type ModeItineraire = 'voiture' | 'pied';

/** Hôtes OSRM par mode (FIXES — pas d'URL dynamique, §8). */
const OSRM_BASE: Record<ModeItineraire, string> = {
  voiture: 'https://routing.openstreetmap.de/routed-car/route/v1/driving',
  pied: 'https://routing.openstreetmap.de/routed-foot/route/v1/foot',
};

export interface Itineraire {
  /** Géométrie du tracé en [lat, lng] (prête pour Leaflet). */
  coords: [number, number][];
  distance_km: number;
  duree_min: number;
}

/** Réponse OSRM (champs utiles uniquement). */
interface OsrmReponse {
  code: string;
  routes: {
    distance: number; // mètres
    duration: number; // secondes
    geometry: { coordinates: [number, number][] }; // [lng, lat]
  }[];
}

/**
 * Itinéraire entre deux points selon le mode (voiture par défaut). Lève une erreur si OSRM ne
 * renvoie pas de route. `depart` = position de l'utilisateur, `arrivee` = structure.
 */
export async function calculerItineraire(
  depart: Coordonnees,
  arrivee: Coordonnees,
  mode: ModeItineraire = 'voiture',
): Promise<Itineraire> {
  const url = `${OSRM_BASE[mode]}/${depart.lng},${depart.lat};${arrivee.lng},${arrivee.lat}`;
  const { data } = await axios.get<OsrmReponse>(url, {
    params: { overview: 'full', geometries: 'geojson' },
    timeout: 15000,
  });

  const route = data.routes?.[0];
  if (data.code !== 'Ok' || !route) {
    throw new Error('Itinéraire introuvable');
  }

  return {
    // OSRM renvoie [lng, lat] ; Leaflet attend [lat, lng].
    coords: route.geometry.coordinates.map(([lng, lat]) => [lat, lng]),
    distance_km: Math.round((route.distance / 1000) * 10) / 10,
    duree_min: Math.round(route.duration / 60),
  };
}
