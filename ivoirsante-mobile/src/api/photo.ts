/**
 * api/photo.ts — Photo de profil d'un membre (profil enrichi).
 *
 * Upload multipart via expo-file-system (fiable pour un fichier local en RN), suppression via
 * le client axios unique. L'AFFICHAGE passe par un téléchargement authentifié vers le cache
 * (telechargerPhoto) puis affichage du fichier local — et non `<Image source={{ headers }}>`,
 * peu fiable sur Android (voir telechargerPhoto).
 */
import { File, Paths } from 'expo-file-system';
import { createUploadTask, FileSystemUploadType } from 'expo-file-system/legacy';
import { api, API_URL, getStoredToken } from '../config/api';
import type { Membre } from '../types/membre';

const chemin = (membreId: number) => `/v1/membres/${membreId}/photo`;

/** URL absolue de l'endpoint photo. */
export function urlPhotoAbsolue(membreId: number): string {
  return `${API_URL}/api${chemin(membreId)}`;
}

/**
 * Télécharge la photo (déchiffrée par le serveur) vers le cache et renvoie son URI LOCAL.
 *
 * On NE peut PAS afficher `<Image source={{ uri: <url>, headers: { Authorization } }}>` : sur
 * Android le loader natif (Fresco) n'envoie pas de façon fiable l'en-tête Bearer → la requête part
 * anonyme → 401. On télécharge donc via expo-file-system (qui honore les en-têtes, comme pour les
 * documents F2.10) et on affiche le fichier local. `version` distingue le fichier après remplacement.
 */
export async function telechargerPhoto(membreId: number, version = 0): Promise<string> {
  const token = await getStoredToken();
  const destination = new File(Paths.cache, `masante-photo-${membreId}-${version}.jpg`);
  if (destination.exists) destination.delete();

  const fichier = await File.downloadFileAsync(urlPhotoAbsolue(membreId), destination, {
    headers: {
      Accept: 'application/json',
      'ngrok-skip-browser-warning': 'true',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  return fichier.uri;
}

export type PhotoAChoisir = { uri: string; nom: string; mimeType?: string | null };

/** Téléverse (ou remplace) la photo de profil ; renvoie le membre mis à jour. */
export async function televerserPhoto(membreId: number, photo: PhotoAChoisir): Promise<Membre> {
  const token = await getStoredToken();
  const tache = createUploadTask(urlPhotoAbsolue(membreId), photo.uri, {
    httpMethod: 'POST',
    uploadType: FileSystemUploadType.MULTIPART,
    fieldName: 'photo',
    mimeType: photo.mimeType ?? undefined,
    headers: {
      Accept: 'application/json',
      'ngrok-skip-browser-warning': 'true',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  const reponse = await tache.uploadAsync();
  if (!reponse) throw new Error('Envoi interrompu.');

  const corps = analyser(reponse.body);
  if (reponse.status >= 200 && reponse.status < 300) {
    return (corps as { membre: Membre }).membre;
  }
  throw new Error(messageDErreur(corps));
}

/** Supprime la photo de profil ; renvoie le membre mis à jour. */
export async function supprimerPhoto(membreId: number): Promise<Membre> {
  const { data } = await api.delete<{ membre: Membre }>(chemin(membreId));
  return data.membre;
}

function analyser(corps: string | undefined): unknown {
  if (!corps) return null;
  try {
    return JSON.parse(corps);
  } catch {
    return null;
  }
}

function messageDErreur(corps: unknown): string {
  const c = corps as { message?: string; errors?: Record<string, string[]> } | null;
  if (c?.errors) {
    const premier = Object.values(c.errors)[0];
    if (premier?.length) return premier[0];
  }
  return c?.message ?? "L'envoi de la photo a échoué. Réessayez.";
}
