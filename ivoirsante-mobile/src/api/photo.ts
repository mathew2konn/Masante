/**
 * api/photo.ts — Photo de profil d'un membre (profil enrichi).
 *
 * Upload multipart via expo-file-system (fiable pour un fichier local en RN), suppression via
 * le client axios unique. L'affichage se fait avec `<Image>` en passant l'en-tête Bearer
 * (URL absolue + en-têtes construits depuis la même source unique que le reste — config/api.ts).
 */
import { createUploadTask, FileSystemUploadType } from 'expo-file-system/legacy';
import { api, API_URL, getStoredToken } from '../config/api';
import type { Membre } from '../types/membre';

const chemin = (membreId: number) => `/v1/membres/${membreId}/photo`;

/** URL absolue de la photo (à passer à `<Image source={{ uri }}>` avec les en-têtes Bearer). */
export function urlPhotoAbsolue(membreId: number): string {
  return `${API_URL}/api${chemin(membreId)}`;
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
