import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { colors, radius, spacing } from '../theme/theme';
import type { Niveau } from '../types/triage';

/**
 * TriageBadge — badge de niveau de triage (§5.4), élément central du Module 1.
 *
 * Redondance OBLIGATOIRE icône + couleur + texte (§6 Accessibilité) : une personne
 * daltonienne distingue le niveau par le pictogramme et le mot, pas seulement la couleur.
 * Couleurs issues de theme.ts (source unique) — aucune couleur en dur.
 */
const MAP: Record<Niveau, { c: { solid: string; bg: string; text: string }; label: string; icone: string }> = {
  leger: { c: colors.success, label: 'LÉGER', icone: '✓' },
  modere: { c: colors.warning, label: 'MODÉRÉ', icone: '!' },
  urgent: { c: colors.danger, label: 'URGENT', icone: '⚠' },
};

export function TriageBadge({ niveau, grand }: { niveau: Niveau; grand?: boolean }) {
  const { c, label, icone } = MAP[niveau];
  return (
    <View
      style={[
        styles.badge,
        { backgroundColor: c.bg },
        grand && styles.badgeGrand,
      ]}
      accessibilityLabel={`Niveau ${label}`}
    >
      <View style={[styles.dot, { backgroundColor: c.solid }]}>
        <Text style={styles.dotIcone}>{icone}</Text>
      </View>
      <Text style={[styles.txt, { color: c.text }, grand && styles.txtGrand]}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  badge: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    paddingVertical: 6,
    paddingHorizontal: 14,
    borderRadius: radius.pill,
  },
  badgeGrand: { paddingVertical: 10, paddingHorizontal: 18 },
  dot: { width: 18, height: 18, borderRadius: 9, alignItems: 'center', justifyContent: 'center', marginRight: spacing[2] },
  dotIcone: { color: '#FFFFFF', fontSize: 11, fontWeight: '900', lineHeight: 14 },
  txt: { fontSize: 13, fontWeight: '800' },
  txtGrand: { fontSize: 18 },
});
