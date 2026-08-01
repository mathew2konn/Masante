import * as SQLite from 'expo-sqlite';
import axios from 'axios';
import { chiffrer, dechiffrer } from './chiffrement';
import { useReseau } from '../store/reseau';

/**
 * dossierCache.ts — cache de LECTURE hors ligne du dossier (ADR-009 : expo-sqlite/SecureStore).
 *
 * Stratégie « write-through / read-on-offline » : en ligne, on sert le réseau et on écrit une
 * copie CHIFFRÉE en base ; hors ligne (erreur réseau, pas de réponse serveur), on relit la copie.
 * Aucune logique métier ici (frontière CDC_01 §0.1) — on ne fait que mémoriser/rendre des données
 * déjà calculées par le backend. Lecture seule : les écritures exigent le réseau (synchro = P4+).
 */
const db = SQLite.openDatabaseSync('masante_cache.db');
db.execSync(
  'CREATE TABLE IF NOT EXISTS dossier_cache (cle TEXT PRIMARY KEY NOT NULL, contenu TEXT NOT NULL, maj INTEGER NOT NULL)',
);

/** Erreur Axios sans réponse serveur = réseau/hors-ligne (à distinguer d'un 4xx/5xx applicatif). */
function estErreurReseau(e: unknown): boolean {
  return axios.isAxiosError(e) && !e.response;
}

async function ecrire(cle: string, data: unknown): Promise<void> {
  const contenu = await chiffrer(JSON.stringify(data));
  await db.runAsync(
    'INSERT OR REPLACE INTO dossier_cache (cle, contenu, maj) VALUES (?, ?, ?)',
    cle,
    contenu,
    Date.now(),
  );
}

async function lire<T>(cle: string): Promise<{ data: T; maj: number } | null> {
  const row = await db.getFirstAsync<{ contenu: string; maj: number }>(
    'SELECT contenu, maj FROM dossier_cache WHERE cle = ?',
    cle,
  );
  if (!row) return null;
  try {
    return { data: JSON.parse(await dechiffrer(row.contenu)) as T, maj: row.maj };
  } catch {
    return null; // entrée illisible (clé changée, corruption) : on ignore.
  }
}

/**
 * Sert `fetcher()` (réseau) et met le résultat en cache chiffré ; en cas de coupure réseau,
 * rend la dernière copie locale et signale l'état « hors ligne ». Toute autre erreur (4xx/5xx)
 * remonte telle quelle (le backend reste l'autorité).
 */
export async function lireAvecCache<T>(cle: string, fetcher: () => Promise<T>): Promise<T> {
  try {
    const data = await fetcher();
    await ecrire(cle, data);
    useReseau.getState().marquerEnLigne();
    return data;
  } catch (e) {
    if (estErreurReseau(e)) {
      const cache = await lire<T>(cle);
      if (cache) {
        useReseau.getState().marquerHorsLigne(cache.maj);
        return cache.data;
      }
    }
    throw e;
  }
}

/** Purge complète du cache local (à appeler à la déconnexion — ne pas laisser de dossier en clair… chiffré résiduel). */
export async function viderDossierCache(): Promise<void> {
  await db.runAsync('DELETE FROM dossier_cache');
}
