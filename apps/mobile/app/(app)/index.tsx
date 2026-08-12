import React, { useCallback, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../src/components/Screen';
import { Card } from '../../src/components/Card';
import { BanniereAlerte } from '../../src/components/BanniereAlerte';
import { BanniereDonSang } from '../../src/components/BanniereDonSang';
import { getAlertesEpidemiques } from '../../src/api/alertes';
import { obtenirDonSang } from '../../src/api/donSang';
import { compterNonLues } from '../../src/api/notifications';
import { useSession } from '../../src/auth/SessionContext';
import type { AlerteEpidemique } from '../../src/types/urgence';
import type { AlerteDon } from '../../src/types/donSang';
import { colors, radius, spacing, typography } from '../../src/theme/theme';

/** Accueil — tableau de bord d'entrée (§5.2 tuiles d'accès rapide). */
export default function AccueilTab() {
  const { user } = useSession();
  const [alertes, setAlertes] = useState<AlerteEpidemique[]>([]);
  const [appelsDon, setAppelsDon] = useState<AlerteDon[]>([]);
  const [nonLues, setNonLues] = useState(0);

  // Alertes sanitaires de la commune + appels au don qui NOUS concernent (ciblés serveur : si une
  // urgence transfusionnelle apparaît ici, c'est qu'un membre donneur du foyer peut y répondre).
  // Rafraîchies à chaque retour sur l'accueil. Échec silencieux (réseau, etc.) : une bannière est un
  // plus, son absence ne doit pas gêner l'accueil.
  useFocusEffect(
    useCallback(() => {
      getAlertesEpidemiques()
        .then(setAlertes)
        .catch(() => setAlertes([]));

      obtenirDonSang()
        .then((vue) => setAppelsDon(vue.alertes))
        .catch(() => setAppelsDon([]));

      // Incrément D1 — la pastille est rafraîchie au retour sur l'accueil. Sans push (indisponible
      // sous Expo Go depuis le SDK 53), c'est ici que le responsable découvre qu'un ajout l'attend.
      compterNonLues().then(setNonLues);
    }, []),
  );

  return (
    <Screen>
      <View style={styles.enteteLigne}>
        <View style={styles.enteteTexte}>
          <Text style={styles.salut}>Bonjour{user?.prenom ? `, ${user.prenom}` : ''} 👋</Text>
          <Text style={styles.sous}>Bienvenue sur votre espace santé.</Text>
        </View>

        <Pressable
          onPress={() => router.navigate('/(app)/notifications')}
          accessibilityRole="button"
          accessibilityLabel={
            nonLues > 0 ? `Notifications, ${nonLues} non lues` : 'Notifications'
          }
          style={styles.cloche}
        >
          <Ionicons name="notifications-outline" size={24} color={colors.blue[700]} />
          {nonLues > 0 && (
            <View style={styles.pastille}>
              <Text style={styles.pastilleTxt}>{nonLues > 99 ? '99+' : nonLues}</Text>
            </View>
          )}
        </Pressable>
      </View>

      <BanniereAlerte alertes={alertes} onPress={() => router.navigate('/(app)/alertes')} />
      <BanniereDonSang alertes={appelsDon} onPress={() => router.navigate('/(app)/don-sang')} />

      <View style={styles.tuiles}>
        <Tuile
          icone="pulse-outline"
          titre="Démarrer un triage"
          desc="Évaluer des symptômes et être orienté"
          onPress={() => router.navigate('/(app)/triage')}
        />
        <Tuile
          icone="folder-outline"
          titre="Mon carnet"
          desc="Membres, dossiers et QR de partage"
          onPress={() => router.navigate('/(app)/carnet')}
        />
        <Tuile
          icone="location-outline"
          titre="Trouver une structure"
          desc="Hôpitaux, cliniques et pharmacies près de vous"
          onPress={() => router.navigate('/(app)/carte')}
        />
        <Tuile
          icone="calendar-outline"
          titre="Mes rendez-vous"
          desc="Suivre et annuler mes demandes de RDV"
          onPress={() => router.navigate('/(app)/structures/mes-rendez-vous')}
        />
        <Tuile
          icone="medkit-outline"
          titre="Pharmacies de garde"
          desc="Les officines ouvertes aujourd'hui"
          onPress={() => router.navigate('/(app)/structures/pharmacies-garde')}
        />
        <Tuile
          icone="water-outline"
          titre="Don de sang"
          desc="Centres proches, groupes demandés, devenir donneur"
          onPress={() => router.navigate('/(app)/don-sang')}
        />
        <Tuile
          icone="medical-outline"
          titre="Médicaments"
          desc="Comparer les prix et voir les ruptures du moment"
          onPress={() => router.navigate('/(app)/medicaments')}
        />
      </View>
    </Screen>
  );
}

function Tuile({
  icone,
  titre,
  desc,
  onPress,
}: {
  icone: keyof typeof Ionicons.glyphMap;
  titre: string;
  desc: string;
  onPress: () => void;
}) {
  return (
    <Pressable onPress={onPress} accessibilityRole="button" accessibilityLabel={titre} style={styles.tuileWrap}>
      <Card>
        <View style={styles.cercle}>
          <Ionicons name={icone} size={26} color={colors.blue[600]} />
        </View>
        <Text style={styles.tuileTitre}>{titre}</Text>
        <Text style={styles.tuileDesc}>{desc}</Text>
      </Card>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  enteteLigne: { flexDirection: 'row', alignItems: 'flex-start' },
  enteteTexte: { flex: 1 },
  cloche: { padding: spacing[2] },
  pastille: {
    position: 'absolute', top: 0, right: 0, minWidth: 18, height: 18,
    borderRadius: radius.pill, backgroundColor: colors.danger.text,
    alignItems: 'center', justifyContent: 'center', paddingHorizontal: 4,
  },
  pastilleTxt: { ...typography.caption, color: colors.surface, fontWeight: '700', fontSize: 10 },
  salut: { ...typography.h1, color: colors.blue[900] },
  sous: { ...typography.body, color: colors.ink[700], marginTop: spacing[1], marginBottom: spacing[6] },
  tuiles: { gap: spacing[4] },
  tuileWrap: {},
  cercle: {
    width: 52, height: 52, borderRadius: radius.pill, backgroundColor: colors.blue[100],
    alignItems: 'center', justifyContent: 'center', marginBottom: spacing[3],
  },
  tuileTitre: { ...typography.h2, color: colors.blue[900] },
  tuileDesc: { ...typography.body, color: colors.ink[700], marginTop: spacing[1] },
});
