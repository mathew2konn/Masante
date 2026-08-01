import React from 'react';
import { StyleSheet } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { SpheresRebondissantes } from './SpheresRebondissantes';
import { colors } from '../theme/theme';

/**
 * GradientBackground — le fond signature de MaSante (Design System §2.2 / §7.2).
 *
 * Dégradé vertical du clair (haut) vers le bleu pur (bas), surmonté des sphères
 * rebondissantes (identité MaSanté — ADR-000) posées comme fond de TOUTE l'application.
 * Décoratives (pointerEvents=none, non accessibles) : elles n'interceptent aucun geste.
 * Le contenu lisible vit sur des cartes blanches posées par-dessus (jamais de texte courant
 * directement sur la zone médiane/sombre du dégradé).
 */
export function GradientBackground({ children }: { children: React.ReactNode }) {
  return (
    <LinearGradient
      colors={colors.gradient as unknown as [string, string, ...string[]]}
      start={{ x: 0.5, y: 0 }}
      end={{ x: 0.5, y: 1 }}
      style={StyleSheet.absoluteFill}
    >
      <SpheresRebondissantes />
      {children}
    </LinearGradient>
  );
}
