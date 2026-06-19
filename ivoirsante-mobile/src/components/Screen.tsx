import React from 'react';
import { Platform, SafeAreaView, ScrollView, StatusBar, StyleSheet, View } from 'react-native';
import { StatusBar as ExpoStatusBar } from 'expo-status-bar';
import { GradientBackground } from './GradientBackground';
import { spacing } from '../theme/theme';

/**
 * Screen — gabarit commun à tous les écrans du Module 1.
 * Pose le dégradé signature (§2.2), la zone sûre, et soit un ScrollView (contenu long),
 * soit une vue fixe. Marges latérales = space-6 (§4.1). Le contenu vit sur des cartes.
 */
export function Screen({
  children,
  scroll = true,
  footer,
}: {
  children: React.ReactNode;
  scroll?: boolean;
  footer?: React.ReactNode;
}) {
  // Sur Android, SafeAreaView n'inclut pas la barre d'état : on compense.
  const topPad = Platform.OS === 'android' ? (StatusBar.currentHeight ?? 0) : 0;

  return (
    <GradientBackground>
      <ExpoStatusBar style="dark" />
      <SafeAreaView style={[styles.safe, { paddingTop: topPad }]}>
        {scroll ? (
          <ScrollView
            contentContainerStyle={styles.scrollContent}
            keyboardShouldPersistTaps="handled"
            showsVerticalScrollIndicator={false}
          >
            {children}
          </ScrollView>
        ) : (
          <View style={styles.fixed}>{children}</View>
        )}
        {footer ? <View style={styles.footer}>{footer}</View> : null}
      </SafeAreaView>
    </GradientBackground>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  scrollContent: { paddingHorizontal: spacing[6], paddingTop: spacing[5], paddingBottom: spacing[8] },
  fixed: { flex: 1, paddingHorizontal: spacing[6], paddingTop: spacing[5] },
  footer: {
    paddingHorizontal: spacing[6],
    paddingTop: spacing[3],
    paddingBottom: spacing[4],
  },
});
