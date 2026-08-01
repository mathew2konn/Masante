import React from 'react';
import { Pressable, StyleSheet, Text } from 'react-native';
import { colors, radius, shadow, spacing, typography } from '../theme/theme';

/**
 * SecondaryButton — bouton secondaire du Design System (§5.1).
 * Bordure + texte blue-600, fond blanc (normal) / blue-50 (pressé).
 */
export function SecondaryButton({
  label,
  onPress,
  disabled,
  accessibilityLabel,
}: {
  label: string;
  onPress: () => void;
  disabled?: boolean;
  accessibilityLabel?: string;
}) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? label}
      accessibilityState={{ disabled: !!disabled }}
      style={({ pressed }) => [
        styles.btn,
        {
          backgroundColor: pressed ? colors.blue[50] : colors.surface,
          borderColor: disabled ? colors.disabled : colors.blue[600],
        },
        disabled && styles.inactif,
      ]}
    >
      <Text style={[styles.txt, { color: disabled ? colors.disabled : colors.blue[600] }]}>{label}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  btn: {
    height: 52,
    borderRadius: radius.pill,
    borderWidth: 2,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing[6],
    ...shadow.card,
  },
  inactif: { opacity: 0.5 },
  txt: { ...typography.button },
});
