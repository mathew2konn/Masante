/**
 * documents/selection.ts — Choix d'un fichier à importer (F2.10) : appareil photo, galerie ou fichier.
 *
 * Les images sont compressées (contexte réseau 3G) avant l'upload : redimensionnement large (≤ 1600 px)
 * + JPEG qualité 0.7, uniquement si l'image est plus large que la cible (jamais d'agrandissement).
 * Le MIME reste revalidé côté serveur (liste blanche) — ici on prépare juste un fichier propre à envoyer.
 */
import * as DocumentPicker from 'expo-document-picker';
import * as ImagePicker from 'expo-image-picker';
import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';
import type { FichierAImporter } from '../api/documents';

const LARGEUR_MAX = 1600;

/** Erreur de permission refusée (l'écran affiche un message clair). */
export class PermissionRefusee extends Error {
  constructor(public source: string) {
    super(`Accès à « ${source} » refusé. Autorisez-le dans les réglages du téléphone.`);
    this.name = 'PermissionRefusee';
  }
}

/** Prépare un fichier à partir d'une image sélectionnée (compression conditionnelle). */
async function depuisImage(asset: ImagePicker.ImagePickerAsset): Promise<FichierAImporter> {
  if ((asset.width ?? 0) > LARGEUR_MAX) {
    const contexte = ImageManipulator.manipulate(asset.uri);
    contexte.resize({ width: LARGEUR_MAX });
    const rendu = await contexte.renderAsync();
    const image = await rendu.saveAsync({ compress: 0.7, format: SaveFormat.JPEG });
    return { uri: image.uri, nom: `photo-${Date.now()}.jpg`, mimeType: 'image/jpeg' };
  }
  return {
    uri: asset.uri,
    nom: asset.fileName ?? `photo-${Date.now()}.jpg`,
    mimeType: asset.mimeType ?? 'image/jpeg',
  };
}

/** Prend une photo avec l'appareil (demande la permission caméra). */
export async function prendrePhoto(): Promise<FichierAImporter | null> {
  const permission = await ImagePicker.requestCameraPermissionsAsync();
  if (!permission.granted) throw new PermissionRefusee('Appareil photo');

  const resultat = await ImagePicker.launchCameraAsync({ mediaTypes: ['images'], quality: 1 });
  if (resultat.canceled || !resultat.assets?.length) return null;
  return depuisImage(resultat.assets[0]);
}

/** Choisit une image dans la galerie (demande la permission photos). */
export async function choisirDansGalerie(): Promise<FichierAImporter | null> {
  const permission = await ImagePicker.requestMediaLibraryPermissionsAsync();
  if (!permission.granted) throw new PermissionRefusee('Galerie photos');

  const resultat = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 1 });
  if (resultat.canceled || !resultat.assets?.length) return null;
  return depuisImage(resultat.assets[0]);
}

/** Choisit un fichier quelconque (PDF, DOCX, etc.) — pas de permission requise. */
export async function choisirFichier(): Promise<FichierAImporter | null> {
  const resultat = await DocumentPicker.getDocumentAsync({ copyToCacheDirectory: true, multiple: false });
  if (resultat.canceled || !resultat.assets?.length) return null;

  const a = resultat.assets[0];
  return { uri: a.uri, nom: a.name, mimeType: a.mimeType };
}

// --- Photo de profil (avatar) : recadrage carré + réduction plus agressive (~512 px) ---

const AVATAR_MAX = 512;

/** Prépare un avatar carré compressé (JPEG) à partir d'une image déjà recadrée 1:1. */
async function depuisAvatar(asset: ImagePicker.ImagePickerAsset): Promise<FichierAImporter> {
  const contexte = ImageManipulator.manipulate(asset.uri);
  contexte.resize({ width: AVATAR_MAX }); // carré (crop 1:1) → hauteur suivie automatiquement
  const rendu = await contexte.renderAsync();
  const image = await rendu.saveAsync({ compress: 0.8, format: SaveFormat.JPEG });
  return { uri: image.uri, nom: `avatar-${Date.now()}.jpg`, mimeType: 'image/jpeg' };
}

/** Prend une photo de profil (recadrage carré imposé). */
export async function prendrePhotoProfil(): Promise<FichierAImporter | null> {
  const permission = await ImagePicker.requestCameraPermissionsAsync();
  if (!permission.granted) throw new PermissionRefusee('Appareil photo');

  const resultat = await ImagePicker.launchCameraAsync({ mediaTypes: ['images'], allowsEditing: true, aspect: [1, 1], quality: 1 });
  if (resultat.canceled || !resultat.assets?.length) return null;
  return depuisAvatar(resultat.assets[0]);
}

/** Choisit une photo de profil dans la galerie (recadrage carré imposé). */
export async function choisirPhotoProfilGalerie(): Promise<FichierAImporter | null> {
  const permission = await ImagePicker.requestMediaLibraryPermissionsAsync();
  if (!permission.granted) throw new PermissionRefusee('Galerie photos');

  const resultat = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], allowsEditing: true, aspect: [1, 1], quality: 1 });
  if (resultat.canceled || !resultat.assets?.length) return null;
  return depuisAvatar(resultat.assets[0]);
}
