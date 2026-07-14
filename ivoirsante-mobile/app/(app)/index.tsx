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
import { useSession } from '../../src/auth/SessionContext';
import type { AlerteEpidemique } from '../../src/types/urgence';
import type { AlerteDon } from '../../src/types/donSang';
import { colors, radius, spacing, typography } from '../../src/theme/theme';

/** Accueil — tableau de bord d'entrée (§5.2 tuiles d'accès rapide). */
export default function AccueilTab() {
  const { user } = useSession();
  const [alertes, setAlertes] = useState<AlerteEpidemique[]>([]);
  const [appelsDon, setAppelsDon] = useState<AlerteDon[]>([]);

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
    }, []),
  );

  return (
    <Screen>
      <Text style={styles.salut}>Bonjour{user?.prenom ? `, ${user.prenom}` : ''} 👋</Text>
      <Text style={styles.sous}>Bienvenue sur votre espace santé.</Text>

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
