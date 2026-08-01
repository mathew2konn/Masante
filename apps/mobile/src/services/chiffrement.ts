import * as SecureStore from 'expo-secure-store';
import * as Crypto from 'expo-crypto';
import aesjs from 'aes-js';

/**
 * chiffrement.ts — chiffrement local des données de santé mises en cache hors ligne (CDC_10).
 *
 * AES-256-CBC en JS pur (aes-js) : Expo Go n'expose pas d'AES natif (SQLCipher indisponible).
 * La CLÉ vit dans SecureStore (Keychain/Keystore) — JAMAIS en base ni dans le cache. L'IV est
 * aléatoire par écriture (expo-crypto) et stocké en clair devant la charge : `ivHex:donnéeHex`.
 */
const CLE_STORE = 'masante_cache_key_v1';
let cleMem: Uint8Array | null = null;

async function obtenirCle(): Promise<Uint8Array> {
  if (cleMem) return cleMem;
  let hex = await SecureStore.getItemAsync(CLE_STORE);
  if (!hex) {
    // 256 bits aléatoires, générés une seule fois par installation.
    hex = aesjs.utils.hex.fromBytes(Crypto.getRandomBytes(32));
    await SecureStore.setItemAsync(CLE_STORE, hex);
  }
  cleMem = aesjs.utils.hex.toBytes(hex);
  return cleMem;
}

export async function chiffrer(texte: string): Promise<string> {
  const cle = await obtenirCle();
  const iv = Crypto.getRandomBytes(16);
  const cbc = new aesjs.ModeOfOperation.cbc(cle, iv);
  const chiffre = cbc.encrypt(aesjs.padding.pkcs7.pad(aesjs.utils.utf8.toBytes(texte)));
  return `${aesjs.utils.hex.fromBytes(iv)}:${aesjs.utils.hex.fromBytes(chiffre)}`;
}

export async function dechiffrer(charge: string): Promise<string> {
  const cle = await obtenirCle();
  const [ivHex, dataHex] = charge.split(':');
  if (!ivHex || !dataHex) throw new Error('Charge chiffrée invalide.');
  const cbc = new aesjs.ModeOfOperation.cbc(cle, aesjs.utils.hex.toBytes(ivHex));
  const octets = aesjs.padding.pkcs7.strip(cbc.decrypt(aesjs.utils.hex.toBytes(dataHex)));
  return aesjs.utils.utf8.fromBytes(octets);
}
