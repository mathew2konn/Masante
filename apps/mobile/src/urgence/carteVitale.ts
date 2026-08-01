/**
 * carteVitale.ts — Cache local de la carte vitale d'urgence (CdC FN2).
 *
 * POURQUOI UN CACHE. FN2 demande que les données vitales soient consultables « sans
 * déverrouillage », pour qu'un secouriste puisse agir sur un patient inconscient. L'écran verrouillé
 * du téléphone est une fonction du système (Medical ID iOS, Informations d'urgence Android), hors de
 * portée d'Expo. On tient donc la promesse au plus près : la carte s'ouvre depuis l'écran de
 * CONNEXION, sans compte, sans mot de passe et sans PIN — donc nécessairement depuis un cache local,
 * puisque l'API exige un token.
 *
 * OÙ. `expo-secure-store` = Keychain (iOS) / Keystore (Android) : chiffré par le matériel, isolé de
 * l'application et absent des sauvegardes en clair. Jamais AsyncStorage.
 *
 * CE QUE CELA EXPOSE, ET POURQUOI C'EST ACCEPTABLE. Qui tient le téléphone déverrouillé peut lire
 * les fiches activées. C'est le but : ce sont ces données-là, et seulement elles, que le CdC destine
 * aux secouristes (groupe sanguin, allergies, maladies chroniques, contacts). Le dossier complet
 * reste derrière le verrou applicatif B2. Le titulaire choisit membre par membre ce qu'il expose,
 * et rien n'est activé par défaut.
 *
 * Une fiche est stockée par membre (les valeurs de SecureStore doivent rester petites), avec un
 * index des membres activés.
 */
import * as SecureStore from 'expo-secure-store';
import { getFicheVitale } from '../api/urgence';
import type { FicheVitale } from '../types/urgence';

const K_INDEX = 'carte_vitale.index';
const prefixe = (membreId: number) => `carte_vitale.m${membreId}`;

/** Identifiants des membres dont la carte vitale est activée. */
export async function membresActives(): Promise<number[]> {
  const brut = await SecureStore.getItemAsync(K_INDEX);
  if (!brut) return [];
  try {
    const ids: unknown = JSON.parse(brut);
    return Array.isArray(ids) ? ids.filter((i): i is number => typeof i === 'number') : [];
  } catch {
    return [];
  }
}

async function ecrireIndex(ids: number[]): Promise<void> {
  await SecureStore.setItemAsync(K_INDEX, JSON.stringify(ids));
}

/** La carte vitale de ce membre est-elle exposée sur l'écran de connexion ? */
export async function estActivee(membreId: number): Promise<boolean> {
  return (await membresActives()).includes(membreId);
}

/**
 * Active la carte d'un membre : télécharge sa fiche vitale et la met en cache.
 * Exige une connexion réseau et une session valide (c'est le titulaire qui décide).
 */
export async function activer(membreId: number): Promise<FicheVitale> {
  const fiche = await getFicheVitale(membreId);
  await SecureStore.setItemAsync(prefixe(membreId), JSON.stringify(fiche));

  const ids = await membresActives();
  if (!ids.includes(membreId)) await ecrireIndex([...ids, membreId]);

  return fiche;
}

/** Retire la carte d'un membre : la fiche quitte immédiatement l'appareil. */
export async function desactiver(membreId: number): Promise<void> {
  await SecureStore.deleteItemAsync(prefixe(membreId));
  await ecrireIndex((await membresActives()).filter((id) => id !== membreId));
}

/**
 * Relit toutes les fiches en cache, sans réseau. C'est l'unique source de l'écran consulté
 * hors connexion. Une entrée illisible est ignorée plutôt que de faire échouer l'écran :
 * en urgence, afficher deux fiches sur trois vaut mieux qu'une page d'erreur.
 */
export async function lireCache(): Promise<FicheVitale[]> {
  const ids = await membresActives();
  const fiches: FicheVitale[] = [];

  for (const id of ids) {
    const brut = await SecureStore.getItemAsync(prefixe(id));
    if (!brut) continue;
    try {
      fiches.push(JSON.parse(brut) as FicheVitale);
    } catch {
      // entrée corrompue : on la saute
    }
  }

  return fiches;
}

/**
 * Met à jour les fiches déjà activées (le carnet a pu changer : nouvelle allergie, nouveau
 * contact). Renvoie le nombre de fiches rafraîchies. Exige réseau + session.
 */
export async function rafraichir(): Promise<number> {
  const ids = await membresActives();
  let n = 0;

  for (const id of ids) {
    try {
      await activer(id);
      n++;
    } catch {
      // membre supprimé ou réseau indisponible : on garde l'ancienne fiche, mieux que rien
    }
  }

  return n;
}
