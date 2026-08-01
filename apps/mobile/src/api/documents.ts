/**
 * api/documents.ts — Documents médicaux importés (F2.10).
 *
 * Deux transports selon le besoin :
 *  - liste / suppression : CLIENT AXIOS UNIQUE (token Bearer injecté par l'intercepteur).
 *  - import (multipart) et téléchargement (fichier binaire) : expo-file-system, qui gère
 *    proprement l'upload multipart avec progression et l'écriture disque du binaire déchiffré.
 *    Le token et l'URL de base viennent de la même source unique (config/api.ts).
 */
import { File, Paths } from 'expo-file-system';
import { createUploadTask, FileSystemUploadType } from 'expo-file-system/legacy';
import { api, API_URL, getStoredToken } from '../config/api';
import type { DocumentMedical } from '../types/document';

const base = (membreId: number) => `/v1/membres/${membreId}/documents`;
const urlAbsolue = (chemin: string) => `${API_URL}/api${chemin}`;

/** En-têtes communs aux appels hors axios (auth Bearer + contournement de l'avertissement Ngrok). */
async function entetes(extra: Record<string, string> = {}): Promise<Record<string, string>> {
  const token = await getStoredToken();
  return {
    Accept: 'application/json',
    'ngrok-skip-browser-warning': 'true',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...extra,
  };
}

/** Liste des documents d'un membre (les plus récents d'abord). */
export async function listerDocuments(membreId: number): Promise<DocumentMedical[]> {
  const { data } = await api.get<{ items: DocumentMedical[] }>(base(membreId));
  return data.items;
}

export type FichierAImporter = { uri: string; nom: string; mimeType?: string | null };
export type MetaImport = { categorie: string; titre?: string; date_document?: string | null };

/**
 * Import multipart d'un document. `onProgress` reçoit un ratio 0→1 (barre de progression).
 * Le MIME réel est revalidé côté serveur (liste blanche) ; une 422 remonte un message lisible.
 */
export async function importerDocument(
  membreId: number,
  fichier: FichierAImporter,
  meta: MetaImport,
  onProgress?: (ratio: number) => void,
): Promise<DocumentMedical> {
  const parametres: Record<string, string> = { categorie: meta.categorie };
  if (meta.titre) parametres.titre = meta.titre;
  if (meta.date_document) parametres.date_document = meta.date_document;

  const tache = createUploadTask(
    urlAbsolue(base(membreId)),
    fichier.uri,
    {
      httpMethod: 'POST',
      uploadType: FileSystemUploadType.MULTIPART,
      fieldName: 'fichier',
      mimeType: fichier.mimeType ?? undefined,
      parameters: parametres,
      headers: await entetes(),
    },
    (p) => {
      if (p.totalBytesExpectedToSend > 0) onProgress?.(p.totalBytesSent / p.totalBytesExpectedToSend);
    },
  );

  const reponse = await tache.uploadAsync();
  if (!reponse) throw new Error('Import interrompu.');

  const corps = analyser(reponse.body);
  if (reponse.status >= 200 && reponse.status < 300) {
    return (corps as { item: DocumentMedical }).item;
  }
  throw new Error(messageDErreur(corps));
}

/**
 * Télécharge le fichier déchiffré vers le cache de l'app et renvoie son URI local (pour ouverture).
 * Le serveur bloque le téléchargement (423) tant que l'analyse antivirus n'est pas « sain ».
 */
export async function telechargerDocument(membreId: number, doc: DocumentMedical): Promise<string> {
  const nomSain = `masante-${doc.id}-${doc.nom_fichier_original.replace(/[^\w.\-]+/g, '_')}`;
  const destination = new File(Paths.cache, nomSain);
  if (destination.exists) destination.delete();

  const fichier = await File.downloadFileAsync(urlAbsolue(`${base(membreId)}/${doc.id}`), destination, {
    headers: await entetes({ Accept: '*/*' }),
  });

  return fichier.uri;
}

/** Suppression (soft-delete côté serveur : rétention médicale, blob conservé). */
export async function supprimerDocument(membreId: number, id: number): Promise<void> {
  await api.delete(`${base(membreId)}/${id}`);
}

/** Parse prudemment un corps de réponse JSON (chaîne) issu de l'upload. */
function analyser(corps: string | undefined): unknown {
  if (!corps) return null;
  try {
    return JSON.parse(corps);
  } catch {
    return null;
  }
}

/** Extrait un message lisible d'un corps d'erreur Laravel (message / première erreur de validation). */
function messageDErreur(corps: unknown): string {
  const c = corps as { message?: string; errors?: Record<string, string[]> } | null;
  if (c?.errors) {
    const premier = Object.values(c.errors)[0];
    if (premier?.length) return premier[0];
  }
  return c?.message ?? "L'import a échoué. Réessayez.";
}
