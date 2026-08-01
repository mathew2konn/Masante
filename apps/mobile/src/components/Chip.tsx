import React from 'react';
import { Pressable, StyleSheet, Text } from 'react-native';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * Chip — puce cochable du Design System (§5.7).
 * Repos : bordure line, fond blanc. Sélectionnée : fond blue-50 + bordure blue-600.
 * Sert à la sélection des symptômes (F1.1) et au choix d'options (questions « choix »).
 */
export function Chip({
  label,
  selected,
  onPress,
}: {
  label: string;
  selected: boolean;
  onPress: () => void;
}) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="checkbox"
      accessibilityState={{ checked: selected }}
      accessibilityLabel={label}
      style={({ pressed }) => [
        styles.chip,
        {
          borderColor: selected ? colors.blue[600] : colors.line,
          backgroundColor: selected ? colors.blue[50] : pressed ? colors.surfaceMuted : colors.surface,
        },
      ]}
    >
      <Text
        style={[
          styles.txt,
          { color: selected ? colors.blue[700] : colors.ink[900] },
          selected && styles.txtSelected,
        ]}
      >
        {selected ? '✓ ' : ''}
        {label}
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  chip: {
    minHeight: 48,
    justifyContent: 'center',
    borderWidth: 1.5,
    borderRadius: radius.lg,
    paddingVertical: spacing[2],
    paddingHorizontal: spacing[4],
  },
  txt: { ...typography.body },
  txtSelected: { fontWeight: '600' },
});
