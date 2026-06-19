import React, { useCallback } from 'react';
import { Alert, Linking, Pressable, StyleSheet, Text } from 'react-native';
import { colors, radius, spacing, typography } from '../theme/theme';
import { SAMU_NUMERO } from '../config/constants';

/**
 * SosButton — bouton SOS du Design System (§5.1).
 * Rouge #DC2626 (danger), pleine largeur, rayon pill. SEUL élément rouge proéminent
 * de l'écran : impossible à confondre avec une action ordinaire. Compose le SAMU 185.
 */
export function SosButton() {
  const appeler = useCallback(() => {
    Alert.alert(
      'Appeler les urgences',
      `Composer le SAMU au ${SAMU_NUMERO} (numéro vert, Côte d'Ivoire) ?`,
      [
        { text: 'Annuler', style: 'cancel' },
        {
          text: 'Appeler',
          style: 'destructive',
          onPress: () => {
            Linking.openURL(`tel:${SAMU_NUMERO}`).catch(() =>
              Alert.alert('Impossible de lancer l\'appel', `Composez vous-même le ${SAMU_NUMERO}.`),
            );
          },
        },
      ],
    );
  }, []);

  return (
    <Pressable
      onPress={appeler}
      accessibilityRole="button"
      accessibilityLabel={`Appeler le SAMU au ${SAMU_NUMERO}, urgences`}
      style={({ pressed }) => [styles.btn, { backgroundColor: pressed ? '#B91C1C' : colors.danger.solid }]}
    >
      <Text style={styles.txt}>🆘  Urgence — appeler le {SAMU_NUMERO}</Text>
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
