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
 *
 * ═══ POURQUOI `expo-notifications` N'EST PAS IMPORTÉ EN HAUT DE CE FICHIER ═══
 *
 * Parce que le défaut n'est pas dans un APPEL, il est dans l'IMPORT. `expo-notifications/index.js`
 * tire `DevicePushTokenAutoRegistration.fx.js`, un module à effet de bord qui pose un écouteur de
 * jeton **au chargement**, lequel appelle `warnOfExpoGoPushUsage()` — et sous Expo Go Android
 * celui-ci fait un `console.error`. Résultat : un écran rouge au démarrage de l'application, avant
 * qu'une seule ligne de notre code ne s'exécute. Garder les appels ne servait donc à rien : le mal
 * était fait par la seule présence de l'import, tiré au démarrage par `app/(app)/_layout.tsx`.
 *
 * D'où le chargement **dynamique**, sous la même condition qu'Expo emploie lui-même :
 * `isRunningInExpoGo()`, la fonction exacte que `warnOfExpoGoPushUsage` interroge. Reproduire sa
 * condition avec une autre (`Constants.appOwnership`, une variable d'environnement) ferait dériver
 * les deux tests le jour où Expo changera la sienne — *un garde-fou qui n'est plus d'accord avec ce
 * qu'il garde ne protège plus rien*.
 *
 * Ce que ce fichier NE fait pas : désactiver le push. En *development build*, `isRunningInExpoGo()`
 * est faux, le module se charge normalement et le comportement de D1 est inchangé.
 */
import * as Device from 'expo-device';
import { isRunningInExpoGo } from 'expo';
import Constants from 'expo-constants';
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
    // ═══ EXPO GO : ON NE CHARGE MÊME PAS LE MODULE ═══
    //
    // Le push distant a été retiré d'Expo Go avec le SDK 53. C'est le cas NORMAL de ce projet
    // aujourd'hui (le G4 se tient sur Expo Go), et il se traite en amont de tout le reste : charger
    // le module ne servirait qu'à déclencher son effet de bord.
    if (isRunningInExpoGo()) {
      return null;
    }

    // Un émulateur n'a pas de service de notification : inutile d'aller plus loin.
    if (!Device.isDevice) {
      return null;
    }

    // Chargement différé : hors Expo Go seulement, donc jamais au démarrage de l'application.
    const Notifications = await import('expo-notifications');

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
    // Filet de sécurité pour tout ce qui reste : service Google Play absent, jeton refusé par
    // Expo, réseau coupé au moment de l'enregistrement. Le cas « Expo Go », lui, n'arrive plus
    // jusqu'ici — il est traité en tête de fonction, avant même le chargement du module.
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
