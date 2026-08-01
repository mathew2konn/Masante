import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * IntensityScale — échelle d'intensité 1-10 du Design System (§5.7), pour F1.2.
 * Pastilles tactiles (zone ≥ 48 dp) ; la valeur choisie ET les précédentes se
 * remplissent en blue-600 (lecture « jauge »). Redondance par le nombre affiché.
 */
export function IntensityScale({
  value,
  onChange,
  min = 1,
  max = 10,
}: {
  value: number | null;
  onChange: (v: number) => void;
  min?: number;
  max?: number;
}) {
  const valeurs: number[] = [];
  for (let i = min; i <= max; i++) valeurs.push(i);

  return (
    <View style={styles.row} accessibilityRole="adjustable" accessibilityLabel={`Intensité de ${min} à ${max}`}>
      {valeurs.map((n) => {
        const rempli = value !== null && n <= value;
        return (
          <Pressable
            key={n}
            onPress={() => onChange(n)}
            accessibilityRole="button"
            accessibilityLabel={`Intensité ${n}`}
            accessibilityState={{ selected: value === n }}
            hitSlop={6}
            style={styles.touch}
          >
            <View
              style={[
                styles.pastille,
                {
                  backgroundColor: rempli ? colors.blue[600] : colors.surface,
                  borderColor: rempli ? colors.blue[600] : colors.line,
                },
              ]}
            >
              <Text style={[styles.num, { color: rempli ? '#FFFFFF' : colors.ink[500] }]}>{n}</Text>
            </View>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing[2] },
  touch: { width: 48, height: 48, alignItems: 'center', justifyContent: 'center' },
  pastille: { width: 36, height: 36, borderRadius: radius.pill, borderWidth: 1.5, alignItems: 'center', justifyContent: 'center' },
  num: { ...typography.bodyStrong },
});
