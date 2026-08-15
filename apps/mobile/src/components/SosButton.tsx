import React from 'react';
import { Pressable, StyleSheet, Text } from 'react-native';
import { router } from 'expo-router';
import { colors, radius, spacing, typography } from '../theme/theme';
import { numeroSamu, useNumerosUrgence } from '../urgence/numerosUrgence';

/**
 * SosButton — bouton SOS du Design System (§5.1).
 * Rouge #DC2626 (danger), pleine largeur, rayon pill. SEUL élément rouge proéminent
 * de l'écran : impossible à confondre avec une action ordinaire.
 *
 * Module 5.2 (FN1) : le bouton n'appelle plus directement le SAMU. Il ouvre l'écran d'urgence, qui
 * cherche la position en tâche de fond, laisse choisir le membre concerné (le carnet est familial)
 * et propose l'appel au 185 ET l'alerte du contact d'urgence par SMS. Un appel et un SMS ne peuvent
 * pas partir du même geste : l'appel met l'application en arrière-plan.
 */
export function SosButton() {
  // P6.8e — le numéro vient du référentiel national. L'état initial est le repli, jamais une valeur
  // vide : ce bouton ne doit à aucun instant s'afficher sans numéro, pas même le temps d'un
  // chargement.
  const samu = numeroSamu(useNumerosUrgence());

  return (
    <Pressable
      onPress={() => router.push('/(app)/sos')}
      accessibilityRole="button"
      accessibilityLabel={`Urgence : appeler le SAMU au ${samu} et alerter un proche`}
      style={({ pressed }) => [styles.btn, { backgroundColor: pressed ? '#B91C1C' : colors.danger.solid }]}
    >
      <Text style={styles.txt}>🆘  Urgence — SAMU {samu}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  btn: {
    height: 52,
    borderRadius: radius.pill,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing[6],
    width: '100%',
  },
  txt: { ...typography.button, color: '#FFFFFF' },
});
