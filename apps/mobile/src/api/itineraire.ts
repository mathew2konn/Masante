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

/**
 * Racines du service `table` — la MATRICE de durées, distincte du service `route`.
 *
 * P10a — POURQUOI `table` ET NON N APPELS À `route` : le §5.4 fait proposer plusieurs hôpitaux,
 * parfois pour plusieurs spécialités. Calculer un trajet par établissement multiplierait les
 * requêtes sur un routeur public gratuit ; `table` en renvoie autant qu'on veut **en un seul
 * appel**. C'est la borne promise, faite par la forme de la requête et non par un compteur.
 */
const OSRM_TABLE: Record<ModeItineraire, string> = {
  voiture: 'https://routing.openstreetmap.de/routed-car/table/v1/driving',
  pied: 'https://routing.openstreetmap.de/routed-foot/table/v1/foot',
};

/** Au-delà, on ne demande pas : une matrice géante sur un service gratuit n'est pas raisonnable. */
export const MAX_DESTINATIONS_DUREE = 12;

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

/** Réponse OSRM `table` (champs utiles uniquement). */
interface OsrmTable {
  code: string;
  /** durations[0][i] = secondes entre la source et la destination i. `null` si inatteignable. */
  durations: (number | null)[][];
}

/**
 * P10a — Durées de trajet depuis une position vers plusieurs destinations, EN UN SEUL APPEL.
 *
 * ═══ CE QUE CETTE FONCTION NE FAIT JAMAIS ═══
 *
 * Elle ne trie pas, ne filtre pas, ne retire aucun établissement. Elle renvoie un tableau de la
 * MÊME longueur que `destinations`, avec `null` là où la durée est inconnue. C'est la seconde
 * garantie promise au propriétaire : **une durée manquante ne fait jamais disparaître un hôpital**.
 *
 * Un routeur public gratuit tombe, se limite, ou ne sait pas relier un point isolé au réseau
 * routier. Si son silence supprimait un établissement de la liste, un patient pourrait ne jamais
 * voir l'hôpital le plus proche de chez lui — *un service d'itinéraire n'a pas à décider où l'on
 * se soigne*.
 *
 * Ne lève pas : un échec global renvoie autant de `null` que de destinations.
 */
export async function dureesVers(
  depart: Coordonnees,
  destinations: Coordonnees[],
  mode: ModeItineraire = 'voiture',
): Promise<(number | null)[]> {
  const vide = destinations.map(() => null);

  if (destinations.length === 0 || destinations.length > MAX_DESTINATIONS_DUREE) {
    return vide;
  }

  const points = [depart, ...destinations].map((c) => `${c.lng},${c.lat}`).join(';');
  const cibles = destinations.map((_, i) => i + 1).join(';');

  try {
    const { data } = await axios.get<OsrmTable>(`${OSRM_TABLE[mode]}/${points}`, {
      params: { sources: 0, destinations: cibles, annotations: 'duration' },
      timeout: 12000,
    });

    if (data.code !== 'Ok' || !Array.isArray(data.durations?.[0])) return vide;

    return destinations.map((_, i) => {
      const secondes = data.durations[0][i];
      return typeof secondes === 'number' ? Math.round(secondes / 60) : null;
    });
  } catch {
    // Silence assumé : le temps de trajet est un CONFORT. Son absence n'est pas une panne, et
    // afficher une erreur ferait douter d'une liste d'hôpitaux parfaitement valide.
    return vide;
  }
}
