import React from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, radius, shadow, spacing, typography } from '../theme/theme';

/**
 * PrimaryButton — bouton primaire du Design System (§5.1 / §7.3).
 * Fond blue-600 (normal) / blue-700 (pressé) / disabled. Hauteur 52, rayon pill.
 */
export function PrimaryButton({
  label,
  onPress,
  disabled,
  loading,
  accessibilityLabel,
}: {
  label: string;
  onPress: () => void;
  disabled?: boolean;
  loading?: boolean;
  accessibilityLabel?: string;
}) {
  const inactif = disabled || loading;
  return (
    <Pressable
      onPress={onPress}
      disabled={inactif}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? label}
      accessibilityState={{ disabled: !!inactif, busy: !!loading }}
      style={({ pressed }) => [
        styles.btn,
        // Fond bleu plein en toutes circonstances (lisible sur le dégradé) ; l'état inactif
        // se lit à l'opacité, pas à un gris qui disparaît sur le fond (correction G4).
        { backgroundColor: pressed ? colors.blue[700] : colors.blue[600] },
        inactif && styles.inactif,
      ]}
    >
      <View style={styles.row}>
        {loading && <ActivityIndicator color="#FFFFFF" style={styles.spinner} />}
        <Text style={styles.txt}>{label}</Text>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  btn: { height: 52, borderRadius: radius.pill, alignItems: 'center', justifyContent: 'center', paddingHorizontal: spacing[6], ...shadow.card },
  inactif: { opacity: 0.5 },
  row: { flexDirection: 'row', alignItems: 'center' },
  spinner: { marginRight: spacing[2] },
  txt: { ...typography.button, color: '#FFFFFF' },
});
