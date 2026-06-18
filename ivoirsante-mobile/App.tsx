import React, { useEffect, useState, useCallback } from 'react';
import {
  SafeAreaView, View, Text, StyleSheet, ActivityIndicator, Pressable,
} from 'react-native';
import { StatusBar } from 'expo-status-bar';
import { GradientBackground } from './src/components/GradientBackground';
import { Logo } from './src/components/Logo';
import { checkHealth, API_URL } from './src/config/api';
import { colors, spacing, radius, typography, shadow } from './src/theme/theme';

type Etat = 'chargement' | 'ok' | 'erreur';

/**
 * Écran de test du SOCLE (§5.4) : prouve la connectivité app -> Ngrok -> Laravel.
 * S'il affiche « API OK ✅ », la chaîne de communication est saine.
 * (Cet écran sera remplacé par le vrai écran d'accueil au Module 1.)
 */
export default function App() {
  const [etat, setEtat] = useState<Etat>('chargement');
  const [detail, setDetail] = useState<string>('');

  const tester = useCallback(async () => {
    setEtat('chargement');
    setDetail('');
    try {
      const data = await checkHealth();
      setEtat('ok');
      setDetail(`service: ${data.service} · base: ${data.database} · env: ${data.environment}`);
    } catch (e: any) {
      setEtat('erreur');
      setDetail(e?.message ?? 'Erreur inconnue');
    }
  }, []);

  useEffect(() => { tester(); }, [tester]);

  const semantique =
    etat === 'ok' ? colors.success : etat === 'erreur' ? colors.danger : colors.warning;

  return (
    <GradientBackground>
      <StatusBar style="dark" />
      <SafeAreaView style={styles.safe}>
        <View style={styles.header}>
          <Logo size={88} />
          <Text style={styles.title}>MaSante</Text>
          <Text style={styles.subtitle}>Socle technique — test de connectivité</Text>
        </View>

        <View style={styles.card}>
          {etat === 'chargement' ? (
            <>
              <ActivityIndicator size="large" color={colors.blue[600]} />
              <Text style={styles.cardText}>Connexion à l'API…</Text>
            </>
          ) : (
            <>
              <View style={[styles.badge, { backgroundColor: semantique.bg }]}>
                <View style={[styles.dot, { backgroundColor: semantique.solid }]} />
                <Text style={[styles.badgeText, { color: semantique.text }]}>
                  {etat === 'ok' ? 'API OK ✅' : 'API INJOIGNABLE'}
                </Text>
              </View>
              <Text style={styles.cardText}>{detail}</Text>
              <Text style={styles.url}>{API_URL || '(URL API non configurée)'}</Text>
              <Pressable
                onPress={tester}
                style={({ pressed }) => [
                  styles.btn,
                  { backgroundColor: pressed ? colors.blue[700] : colors.blue[600] },
                ]}
                accessibilityLabel="Relancer le test de connexion"
              >
                <Text style={styles.btnText}>Réessayer</Text>
              </Pressable>
            </>
          )}
        </View>
      </SafeAreaView>
    </GradientBackground>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, paddingHorizontal: spacing[6], justifyContent: 'center' },
  header: { alignItems: 'center', marginBottom: spacing[8] },
  title: { ...typography.h1, color: colors.blue[900], marginTop: spacing[3] },
  subtitle: { ...typography.body, color: colors.ink[700], marginTop: spacing[1], textAlign: 'center' },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.card,
    padding: spacing[6],
    alignItems: 'center',
    ...shadow.card,
  },
  cardText: { ...typography.body, color: colors.ink[700], marginTop: spacing[3], textAlign: 'center' },
  url: { ...typography.caption, color: colors.ink[500], marginTop: spacing[2], textAlign: 'center' },
  badge: {
    flexDirection: 'row', alignItems: 'center', alignSelf: 'center',
    paddingVertical: 8, paddingHorizontal: 16, borderRadius: radius.pill,
  },
  dot: { width: 10, height: 10, borderRadius: 5, marginRight: spacing[2] },
  badgeText: { fontSize: 16, fontWeight: '800' },
  btn: {
    height: 52, borderRadius: radius.pill, alignItems: 'center', justifyContent: 'center',
    paddingHorizontal: spacing[8], marginTop: spacing[5],
  },
  btnText: { ...typography.button, color: '#FFFFFF' },
});
