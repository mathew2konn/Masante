import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../src/components/Screen';
import { ScreenHeader } from '../../src/components/ScreenHeader';
import { Card } from '../../src/components/Card';
import { SecondaryButton } from '../../src/components/SecondaryButton';
import { listerNotifications, marquerLue, toutMarquerLu } from '../../src/api/notifications';
import { iconeDe, routeDe } from '../../src/types/notification';
import { messageErreur } from '../../src/utils/erreurs';
import type { Notification } from '../../src/types/notification';
import { colors, spacing, typography } from '../../src/theme/theme';

/**
 * Notifications en application (incrément D1).
 *
 * CE QUE CET ÉCRAN RÉPARE : sans lui, un responsable devait penser à ouvrir « Ajouts à valider »
 * pour découvrir qu'un proche avait emmené un enfant à l'hôpital. C'est ce manque qui rendait
 * l'incrément C correct mais inutilisable.
 *
 * FRONTIÈRE : le titre, le texte et l'ordre viennent du serveur, figés au moment de l'événement.
 * Cet écran affiche, marque comme lu, et navigue. Il ne compose aucune phrase et ne décide de rien.
 *
 * LIMITE ASSUMÉE : sans push (indisponible sous Expo Go depuis le SDK 53), cette liste ne se met à
 * jour qu'à l'ouverture de l'écran. Téléphone en poche, application fermée, rien n'arrive.
 */
export default function NotificationsScreen() {
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  const charger = useCallback(async () => {
    setErreur(null);
    try {
      const { notifications: liste } = await listerNotifications();
      setNotifications(liste);
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      void charger();
    }, [charger]),
  );

  const ouvrir = async (notification: Notification) => {
    // Marquer AVANT de naviguer : si la requête échoue, l'utilisateur voit quand même son écran.
    // Une pastille en retard est un désagrément, un écran qui ne s'ouvre pas est une panne.
    if (!notification.lue) {
      try {
        await marquerLue(notification.id);
      } catch {
        // sans effet sur la navigation
      }
    }
    router.navigate(routeDe(notification));
  };

  const toutLire = async () => {
    try {
      await toutMarquerLu();
      await charger();
    } catch (e) {
      setErreur(messageErreur(e));
    }
  };

  const nonLues = notifications.filter((n) => !n.lue).length;

  return (
    <Screen>
      <ScreenHeader
        title="Notifications"
        subtitle={nonLues > 0 ? `${nonLues} non lue${nonLues > 1 ? 's' : ''}` : 'Tout est à jour'}
        onBack={() => router.back()}
      />

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      ) : erreur ? (
        <Text style={styles.erreur}>{erreur}</Text>
      ) : notifications.length === 0 ? (
        <Card style={styles.vide}>
          <Ionicons name="notifications-off-outline" size={28} color={colors.blue[400]} />
          <Text style={styles.videTxt}>Aucune notification.</Text>
          <Text style={styles.videSous}>
            Vous serez prévenu ici quand un proche proposera un ajout, qu&apos;un carnet vous sera
            partagé, ou qu&apos;un soignant consultera un dossier de votre famille.
          </Text>
        </Card>
      ) : (
        <>
          {nonLues > 0 && (
            <View style={styles.actions}>
              <SecondaryButton label="Tout marquer comme lu" onPress={() => void toutLire()} />
            </View>
          )}

          {notifications.map((n) => (
            <Pressable key={n.id} onPress={() => void ouvrir(n)}>
              <Card style={StyleSheet.flatten([styles.item, !n.lue && styles.itemNonLue])}>
                <View style={styles.entete}>
                  <Ionicons
                    name={iconeDe(n.type) as never}
                    size={20}
                    color={n.donnees.urgent ? colors.danger.text : colors.blue[600]}
                  />
                  <Text
                    style={StyleSheet.flatten([styles.titre, !n.lue && styles.titreNonLu])}
                    numberOfLines={2}
                  >
                    {n.donnees.titre}
                  </Text>
                  {!n.lue && <View style={styles.point} />}
                </View>

                <Text style={styles.corps}>{n.donnees.corps}</Text>
                <Text style={styles.date}>{formaterDate(n.creee_a)}</Text>
              </Card>
            </Pressable>
          ))}
        </>
      )}
    </Screen>
  );
}

/** Affichage relatif court — présentation pure, aucune règle métier. */
function formaterDate(iso: string): string {
  const date = new Date(iso);
  const minutes = Math.floor((Date.now() - date.getTime()) / 60000);

  if (minutes < 1) return "À l'instant";
  if (minutes < 60) return `Il y a ${minutes} min`;
  if (minutes < 60 * 24) return `Il y a ${Math.floor(minutes / 60)} h`;

  return date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[5] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text },
  vide: { alignItems: 'center' },
  videTxt: { ...typography.bodyStrong, color: colors.ink[700], marginTop: spacing[2] },
  videSous: {
    ...typography.caption,
    color: colors.ink[500],
    textAlign: 'center',
    marginTop: spacing[1],
  },
  actions: { marginBottom: spacing[3] },
  item: { marginBottom: spacing[3] },
  itemNonLue: { borderLeftWidth: 3, borderLeftColor: colors.blue[600] },
  entete: { flexDirection: 'row', alignItems: 'center', gap: spacing[2] },
  titre: { ...typography.bodyStrong, color: colors.ink[700], flex: 1 },
  titreNonLu: { color: colors.blue[900] },
  point: { width: 8, height: 8, borderRadius: 4, backgroundColor: colors.blue[600] },
  corps: { ...typography.body, color: colors.ink[700], marginTop: spacing[2] },
  date: { ...typography.caption, color: colors.ink[500], marginTop: spacing[1] },
});
