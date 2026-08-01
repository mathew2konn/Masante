import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors, spacing, typography } from '../theme/theme';

/**
 * ScreenHeader — en-tête d'écran du Design System (§5.6).
 * Titre H1 en blue-900 (posé sur la zone claire du dégradé), flèche retour à gauche
 * si nécessaire. Cible tactile du retour ≥ 48 dp (§6).
 */
export function ScreenHeader({
  title,
  subtitle,
  onBack,
}: {
  title: string;
  subtitle?: string;
  onBack?: () => void;
}) {
  return (
    <View style={styles.wrap}>
      <View style={styles.row}>
        {onBack && (
          <Pressable onPress={onBack} accessibilityRole="button" accessibilityLabel="Revenir à l'écran précédent" style={styles.back}>
            <Ionicons name="arrow-back" size={26} color={colors.blue[900]} />
          </Pressable>
        )}
        <Text style={styles.title} numberOfLines={2}>
          {title}
        </Text>
      </View>
      {subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { marginBottom: spacing[5] },
  row: { flexDirection: 'row', alignItems: 'center' },
  back: { width: 48, height: 48, alignItems: 'center', justifyContent: 'center', marginLeft: -spacing[3], marginRight: spacing[1] },
  title: { ...typography.h1, color: colors.blue[900], flex: 1 },
  subtitle: { ...typography.body, color: colors.ink[700], marginTop: spacing[1] },
});
