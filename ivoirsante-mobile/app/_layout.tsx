import React from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';
import { Stack } from 'expo-router';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { StatusBar as ExpoStatusBar } from 'expo-status-bar';
import { SessionProvider, useSession } from '../src/auth/SessionContext';
import { VerrouProvider } from '../src/auth/VerrouContext';
import { GradientBackground } from '../src/components/GradientBackground';
import { Logo } from '../src/components/Logo';
import { colors } from '../src/theme/theme';

/**
 * Layout racine d'Expo Router. Enveloppe l'app dans le SessionProvider, puis route vers
 * deux groupes selon l'état d'authentification (Stack.Protected, §3 doc Sécurité) :
 *  - (app)  : écrans protégés (token requis) ;
 *  - (auth) : connexion / inscription / OTP (accessibles déconnecté).
 */
export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <SessionProvider>
        <VerrouProvider>
          <ExpoStatusBar style="dark" />
          <RootNavigator />
        </VerrouProvider>
      </SessionProvider>
    </SafeAreaProvider>
  );
}

function RootNavigator() {
  const { token, isLoading } = useSession();

  // Tant que la session se restaure, on tient l'utilisateur sur un écran d'attente neutre.
  if (isLoading) {
    return <SplashAttente />;
  }

  return (
    <Stack screenOptions={{ headerShown: false }}>
      <Stack.Protected guard={!!token}>
        <Stack.Screen name="(app)" />
      </Stack.Protected>
      <Stack.Protected guard={!token}>
        <Stack.Screen name="(auth)" />
      </Stack.Protected>
    </Stack>
  );
}

function SplashAttente() {
  return (
    <GradientBackground>
      <View style={styles.splash}>
        <Logo size={96} />
        <ActivityIndicator color={colors.blue[600]} style={styles.spinner} />
      </View>
    </GradientBackground>
  );
}

const styles = StyleSheet.create({
  splash: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  spinner: { marginTop: 24 },
});
