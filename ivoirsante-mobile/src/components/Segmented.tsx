import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, radius, spacing, typography } from '../theme/theme';

export interface SegmentOption<T> {
  value: T;
  label: string;
}

/**
 * Segmented — sélecteur à boutons exclusifs (réutilise les tokens DS).
 * Sert au sexe du patient (M / F) et aux questions booléennes (Oui / Non).
 * Le segment actif passe en blue-600 ; cible tactile ≥ 48 dp (§6).
 */
export function Segmented<T extends string | boolean | number>({
  options,
  value,
  onChange,
  accessibilityLabel,
}: {
  options: SegmentOption<T>[];
  value: T | null;
  onChange: (v: T) => void;
  accessibilityLabel?: string;
}) {
  return (
    <View style={styles.wrap} accessibilityLabel={accessibilityLabel}>
      {options.map((opt) => {
        const actif = value === opt.value;
        return (
          <Pressable
            key={String(opt.value)}
            onPress={() => onChange(opt.value)}
            accessibilityRole="radio"
            accessibilityState={{ selected: actif }}
            accessibilityLabel={opt.label}
            style={[styles.seg, actif && styles.segActif]}
          >
            <Text style={[styles.txt, { color: actif ? '#FFFFFF' : colors.ink[700] }]}>{opt.label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    flexDirection: 'row',
    backgroundColor: colors.surfaceMuted,
    borderRadius: radius.md,
    padding: spacing[1],
    gap: spacing[1],
  },
  seg: {
    flex: 1,
    minHeight: 44,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: radius.sm,
    paddingHorizontal: spacing[3],
  },
  segActif: { backgroundColor: colors.blue[600] },
  txt: { ...typography.bodyStrong },
});
