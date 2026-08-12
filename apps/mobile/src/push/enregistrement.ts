/**
 * push/enregistrement.ts — jeton de notification poussée (incrément D1).
 *
 * CE FICHIER EST ÉCRIT POUR ÉCHOUER PROPREMENT, et ce n'est pas de la défensive gratuite :
 * **le push distant est indisponible dans Expo Go sur Android depuis le SDK 53** (doc Expo v54),
 * et le G4 de ce projet se tient précisément sur Expo Go. Chaque appel est donc gardé — l'absence
 * de jeton est le cas NORMAL aujourd'hui, pas une anomalie.
 *
 * Ce qui manque pour que le push fonctionne vraiment (dette D1, cf. plan G1) :
 *   1. un *development build* EAS (Expo Go ne suffit plus) ;
 *   2. un `projectId` EAS dans `app.config.ts` ;
 *   3. `masante.notifications.push.enabled = true` côté serveur.
 *
 * Tant que ces trois conditions ne sont pas réunies, l'utilisateur garde TOUTES ses notifications
 * en application. Rien n'est perdu — seule l'alerte téléphone-en-poche manque.
 */
import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';
import { enregistrerJetonPush, retirerJetonPush } from '../api/notifications';

/** Mémorisé pour pouvoir le retirer à la déconnexion sans le redemander au système. */
let jetonCourant: string | null = null;

/**
 * Demande l'autorisation, obtient le jeton Expo et l'enregistre côté serveur.
 *
 * Ne lève JAMAIS. Renvoie le jeton si tout a fonctionné, `null` sinon — et `null` est un résultat
 * parfaitement acceptable.
 */
export async function enregistrerCetAppareil(): Promise<string | null> {
  try {
    // Un émulateur n'a pas de service de notification : inutile d'aller plus loin.
    if (!Device.isDevice) {
      return null;
    }

    if (Platform.OS === 'android') {
      // Android exige un canal déclaré, sinon la notification n'est pas affichée.
      await Notifications.setNotificationChannelAsync('default', {
        name: 'MaSanté',
        importance: Notifications.AndroidImportance.DEFAULT,
      });
    }

    const { status: existant } = await Notifications.getPermissionsAsync();
    const status =
      existant === 'granted'
        ? existant
        : (await Notifications.requestPermissionsAsync()).status;

    if (status !== 'granted') {
      return null;   // Refus explicite de l'utilisateur : on n'insiste pas, on ne réessaie pas.
    }

    const projectId =
      Constants.expoConfig?.extra?.eas?.projectId ?? Constants.easConfig?.projectId;

    if (!projectId) {
      // Cas actuel du projet : aucun `projectId` EAS n'est configuré. Sans lui, Expo ne peut pas
      // délivrer de jeton. C'est attendu, et ce n'est pas une erreur à remonter à l'utilisateur.
      return null;
    }

    const { data: jeton } = await Notifications.getExpoPushTokenAsync({ projectId });

    await enregistrerJetonPush(jeton, Platform.OS);
    jetonCourant = jeton;

    return jeton;
  } catch {
    // Sous Expo Go Android, `getExpoPushTokenAsync` lève. C'est le chemin nominal aujourd'hui.
    return null;
  }
}

/**
 * À la déconnexion : ce téléphone ne doit plus recevoir les notifications de ce compte.
 *
 * Même logique que la purge du cache chiffré (P2) — un appareil partagé ne doit rien conserver du
 * compte précédent.
 */
export async function retirerCetAppareil(): Promise<void> {
  if (jetonCourant === null) {
    return;
  }

  await retirerJetonPush(jetonCourant);
  jetonCourant = null;
}
