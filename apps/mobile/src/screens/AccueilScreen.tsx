import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { Screen } from '../components/Screen';
import { Card } from '../components/Card';
import { Logo } from '../components/Logo';
import { PrimaryButton } from '../components/PrimaryButton';
import { SecondaryButton } from '../components/SecondaryButton';
import { SosButton } from '../components/SosButton';
import { colors, spacing, typography } from '../theme/theme';

/**
 * AccueilScreen — point d'entrée du Module 1 (Triage).
 * Une seule action principale visible (« Démarrer le triage », §1 Principes), le SOS
 * restant l'unique élément rouge proéminent. Pas de tuiles Carnet/Carte : ces modules
 * n'existent pas encore (on n'anticipe jamais le module suivant).
 */
export function AccueilScreen({ onStart, onHistorique }: { onStart: () => void; onHistorique: () => void }) {
  return (
    <Screen>
      <View style={styles.header}>
        <Logo size={84} />
        <Text style={styles.title}>MaSante</Text>
        <Text style={styles.subtitle}>Votre santé, orientée du bon côté.</Text>
      </View>

      <Card style={styles.card}>
        <Text style={styles.h2}>Triage et orientation</Text>
        <Text style={styles.body}>
          Décrivez vos symptômes : MaSante évalue leur gravité et vous oriente vers le bon
          niveau de soin (pharmacie, médecin, ou urgences).
        </Text>
        <View style={styles.actions}>
          <PrimaryButton label="Démarrer le triage" onPress={onStart} accessibilityLabel="Démarrer le triage" />
        </View>
        <View style={styles.actionsSecondary}>
          <SecondaryButton label="Mes triages précédents" onPress={onHistorique} />
        </View>
      </Card>

      <View style={styles.sosWrap}>
        <Text style={styles.sosHint}>En cas d'urgence vitale, n'attendez pas :</Text>
        <SosButton />
      </View>

      <Text style={styles.disclaimer}>
        MaSante est un outil d'orientation et ne remplace pas un avis médical professionnel.
      </Text>
    </Screen>
  );
}

const styles = StyleSheet.create({
  header: { alignItems: 'center', marginBottom: spacing[6], marginTop: spacing[4] },
  title: { ...typography.h1, color: colors.blue[900], marginTop: spacing[3] },
  subtitle: { ...typography.body, color: colors.ink[700], marginTop: spacing[1], textAlign: 'center' },
  card: {},
  h2: { ...typography.h2, color: colors.ink[900], marginBottom: spacing[2] },
  body: { ...typography.body, color: colors.ink[700] },
  actions: { marginTop: spacing[5] },
  actionsSecondary: { marginTop: spacing[3] },
  sosWrap: { marginTop: spacing[8] },
  sosHint: { ...typography.caption, color: colors.ink[700], marginBottom: spacing[2], textAlign: 'center' },
  disclaimer: { ...typography.caption, color: colors.ink[500], marginTop: spacing[6], textAlign: 'center' },
});
