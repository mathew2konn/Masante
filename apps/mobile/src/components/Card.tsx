import React from 'react';
import { StyleProp, StyleSheet, View, ViewStyle } from 'react-native';
import { colors, radius, spacing, shadow } from '../theme/theme';

/**
 * Card — conteneur de base du Design System (§5.2).
 * Surface blanche, rayon 24, ombre niveau 1, marge intérieure 16. Posé sur le dégradé.
 */
export function Card({ children, style }: { children: React.ReactNode; style?: StyleProp<ViewStyle> }) {
  return <View style={[styles.card, style]}>{children}</View>;
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.card,
    padding: spacing[4],
    ...shadow.card,
  },
});
