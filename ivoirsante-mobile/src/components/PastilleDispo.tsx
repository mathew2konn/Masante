import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { colors, radius, typography } from '../theme/theme';
import type { StatutDispo } from '../types/structure';

/**
 * PastilleDispo — pastille de disponibilité des structures (§5.5 Design System).
 *
 * Point coloré + libellé court, reprenant la sémantique des badges de triage (vert/orange/rouge).
 * Redondance d'accessibilité (§6) : le sens passe par la COULEUR **et** le TEXTE (jamais la
 * couleur seule) → lisible par une personne daltonienne et par un lecteur d'écran.
 */
const MAP: Record<StatutDispo, { dot: string; text: string; bg: string; label: string }> = {
  disponible:           { dot: colors.success.solid, text: colors.success.text, bg: colors.success.bg, label: 'Disponible' },
  disponible_apres_14h: { dot: colors.warning.solid, text: colors.warning.text, bg: colors.warning.bg, label: 'Après 14h' },
  complet:              { dot: colors.danger.solid,  text: colors.danger.text,  bg: colors.danger.bg,  label: 'Complet' },
  ferme:                { dot: colors.ink[500],      text: colors.ink[700],     bg: colors.surfaceMuted, label: 'Fermé' },
  inconnu:              { dot: colors.disabled,      text: colors.ink[500],     bg: colors.surfaceMuted, label: 'Horaires inconnus' },
};

export function PastilleDispo({ statut }: { statut: StatutDispo }) {
  const { dot, text, bg, label } = MAP[statut];
  return (
    <View style={[styles.wrap, { backgroundColor: bg }]} accessibilityLabel={`Disponibilité : ${label}`}>
      <View style={[styles.dot, { backgroundColor: dot }]} />
      <Text style={[styles.txt, { color: text }]}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    paddingVertical: 4,
    paddingHorizontal: 10,
    borderRadius: radius.pill,
  },
  dot: { width: 9, height: 9, borderRadius: 5, marginRight: 6 },
  txt: { ...typography.caption, fontWeight: '600' },
});
