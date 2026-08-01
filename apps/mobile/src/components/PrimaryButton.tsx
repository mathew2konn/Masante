import React from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, radius, spacing, typography } from '../theme/theme';

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
        {
          backgroundColor: inactif
            ? colors.disabled
            : pressed
              ? colors.blue[700]
              : colors.blue[600],
        },
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
  btn: { height: 52, borderRadius: radius.pill, alignItems: 'center', justifyContent: 'center', paddingHorizontal: spacing[6] },
  row: { flexDirection: 'row', alignItems: 'center' },
  spinner: { marginRight: spacing[2] },
  txt: { ...typography.button, color: '#FFFFFF' },
});
