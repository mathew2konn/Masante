/**
 * api/itineraire.ts — Calcul d'itinéraire via OSRM (Module 3, F3.7).
 *
 * OSRM (Open Source Routing Machine), serveur de démonstration public : GRATUIT, sans clé API
 * (alternative à Google Directions, payant — cf. décisions de cadrage Module 3). En production,
 * on pourra héberger sa propre instance OSRM ou basculer sur OpenRouteService sans changer ce
 * contrat.
 *
 * Sécurité (§8 / A10 SSRF) : l'hôte est FIXE et de confiance ; on n'y interpole que des
 * coordonnées numériques (jamais d'entrée utilisateur libre). Aucune donnée médicale transmise.
 */
import axios from 'axios';
import type { Coordonnees } from '../types/structure';

/** Hôte OSRM de démonstration (fixe — pas d'URL dynamique, §8). */
const OSRM_BASE = 'https://router.project-osrm.org/route/v1/driving';

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
 * Itinéraire routier entre deux points. Lève une erreur si OSRM ne renvoie pas de route.
 * `depart` = position de l'utilisateur, `arrivee` = structure.
 */
export async function calculerItineraire(depart: Coordonnees, arrivee: Coordonnees): Promise<Itineraire> {
  const url = `${OSRM_BASE}/${depart.lng},${depart.lat};${arrivee.lng},${arrivee.lat}`;
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
