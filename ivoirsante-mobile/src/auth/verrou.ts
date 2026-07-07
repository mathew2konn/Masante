/**
 * verrou.ts — Verrou applicatif (note Securite_IVOIRSANTE_2, chap. 3) : seconde barrière LOCALE,
 * propre à l'application, face à la menace « téléphone en main ». Purement côté appareil :
 *
 *  - biométrie via expo-local-authentication (empreinte / visage), quand le matériel le permet ;
 *  - repli PIN applicatif à 6 chiffres, HACHÉ (SHA-256 + sel) et stocké dans expo-secure-store
 *    (déjà chiffré matériellement — Keychain/Keystore). Le PIN n'est jamais stocké en clair.
 *
 * Le PIN applicatif est INDÉPENDANT du mot de passe du compte (chap. 3.3). Anti-force brute :
 * 5 tentatives, puis délais progressifs 30 s / 1 min / 5 min.
 */
import * as SecureStore from 'expo-secure-store';
import * as Crypto from 'expo-crypto';
import * as LocalAuthentication from 'expo-local-authentication';

const K_ACTIF = 'verrou.actif';
const K_PIN_HASH = 'verrou.pin_hash';
const K_PIN_SEL = 'verrou.pin_sel';
const K_BIOMETRIE = 'verrou.biometrie';
const K_TENTATIVES = 'verrou.tentatives';
const K_LOCKOUT = 'verrou.lockout_until';

export const PIN_LONGUEUR = 6;
const MAX_TENTATIVES = 5;
/** Délais progressifs (secondes) appliqués APRÈS les 5 premières tentatives échouées. */
const PALIERS_DELAI = [30, 60, 300];

export type ConfigVerrou = {
  actif: boolean;
  aPin: boolean;
  biometrie: boolean;      // biométrie choisie par l'utilisateur
  biometrieDispo: boolean; // matériel présent ET au moins une empreinte/visage enrôlé
};

/* --------------------------------------------------------------- Hachage PIN */

async function hacher(pin: string, sel: string): Promise<string> {
  return Crypto.digestStringAsync(Crypto.CryptoDigestAlgorithm.SHA256, `${sel}:${pin}`);
}

/* ------------------------------------------------------------------- Lecture */

export async function verrouEstActif(): Promise<boolean> {
  return (await SecureStore.getItemAsync(K_ACTIF)) === '1';
}

export async function aUnPin(): Promise<boolean> {
  return (await SecureStore.getItemAsync(K_PIN_HASH)) !== null;
}

export async function biometrieEstActive(): Promise<boolean> {
  return (await SecureStore.getItemAsync(K_BIOMETRIE)) === '1';
}

export async function biometrieDisponible(): Promise<{ materiel: boolean; enrole: boolean }> {
  const [materiel, enrole] = await Promise.all([
    LocalAuthentication.hasHardwareAsync(),
    LocalAuthentication.isEnrolledAsync(),
  ]);
  return { materiel, enrole };
}

export async function lireConfig(): Promise<ConfigVerrou> {
  const [actif, aPin, biometrie, dispo] = await Promise.all([
    verrouEstActif(),
    aUnPin(),
    biometrieEstActive(),
    biometrieDisponible(),
  ]);
  return { actif, aPin, biometrie, biometrieDispo: dispo.materiel && dispo.enrole };
}

/* --------------------------------------------------------- Activation / config */

/** Définit (ou remplace) le PIN : génère un sel aléatoire et stocke uniquement l'empreinte. */
export async function definirPin(pin: string): Promise<void> {
  const sel = [...Crypto.getRandomValues(new Uint8Array(16))]
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
  const hash = await hacher(pin, sel);
  await SecureStore.setItemAsync(K_PIN_SEL, sel);
  await SecureStore.setItemAsync(K_PIN_HASH, hash);
  await reinitialiserTentatives();
}

/** Active le verrou (exige un PIN déjà défini) et fixe la préférence biométrie. */
export async function activerVerrou(avecBiometrie: boolean): Promise<void> {
  await SecureStore.setItemAsync(K_ACTIF, '1');
  await SecureStore.setItemAsync(K_BIOMETRIE, avecBiometrie ? '1' : '0');
}

export async function definirBiometrie(active: boolean): Promise<void> {
  await SecureStore.setItemAsync(K_BIOMETRIE, active ? '1' : '0');
}

/** Désactive le verrou et EFFACE toute trace (PIN, sel, compteurs). */
export async function desactiverVerrou(): Promise<void> {
  await Promise.all([
    SecureStore.deleteItemAsync(K_ACTIF),
    SecureStore.deleteItemAsync(K_PIN_HASH),
    SecureStore.deleteItemAsync(K_PIN_SEL),
    SecureStore.deleteItemAsync(K_BIOMETRIE),
    SecureStore.deleteItemAsync(K_TENTATIVES),
    SecureStore.deleteItemAsync(K_LOCKOUT),
  ]);
}

/* --------------------------------------------------- Vérification & blocage */

/** État du blocage anti-force brute : { bloque, secondes restantes }. */
export async function etatBlocage(): Promise<{ bloque: boolean; secondes: number }> {
  const brut = await SecureStore.getItemAsync(K_LOCKOUT);
  const jusqua = brut ? Number(brut) : 0;
  const reste = jusqua - Date.now();
  return reste > 0 ? { bloque: true, secondes: Math.ceil(reste / 1000) } : { bloque: false, secondes: 0 };
}

async function reinitialiserTentatives(): Promise<void> {
  await Promise.all([
    SecureStore.deleteItemAsync(K_TENTATIVES),
    SecureStore.deleteItemAsync(K_LOCKOUT),
  ]);
}

/**
 * Vérifie le PIN saisi. Gère le compteur de tentatives et le blocage progressif.
 * Renvoie true si correct ; false sinon (le blocage éventuel se lit via etatBlocage()).
 */
export async function verifierPin(pin: string): Promise<boolean> {
  const { bloque } = await etatBlocage();
  if (bloque) return false;

  const [hash, sel] = await Promise.all([
    SecureStore.getItemAsync(K_PIN_HASH),
    SecureStore.getItemAsync(K_PIN_SEL),
  ]);
  if (!hash || !sel) return false;

  if ((await hacher(pin, sel)) === hash) {
    await reinitialiserTentatives();
    return true;
  }

  // Échec : incrémente et, au-delà de 5, applique le palier de délai correspondant.
  const tentatives = Number((await SecureStore.getItemAsync(K_TENTATIVES)) ?? '0') + 1;
  await SecureStore.setItemAsync(K_TENTATIVES, String(tentatives));
  if (tentatives >= MAX_TENTATIVES) {
    const palier = PALIERS_DELAI[Math.min(tentatives - MAX_TENTATIVES, PALIERS_DELAI.length - 1)];
    await SecureStore.setItemAsync(K_LOCKOUT, String(Date.now() + palier * 1000));
  }
  return false;
}

/* --------------------------------------------------------------- Biométrie */

/** Lance l'authentification biométrique système ; true si réussie. */
export async function authentifierBiometrie(): Promise<boolean> {
  const res = await LocalAuthentication.authenticateAsync({
    promptMessage: 'Déverrouillez votre carnet de santé',
    fallbackLabel: 'Utiliser le code PIN',
    disableDeviceFallback: true, // on gère nous-mêmes le repli PIN applicatif.
  });
  return res.success;
}
