import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useReseau } from '../store/reseau';
import { useT } from '../i18n/useT';
import { formatDateHeureFr } from '../utils/dates';
import { colors, spacing, typography } from '../theme/theme';

/**
 * Bandeau « hors ligne » — s'affiche quand une lecture du dossier a été servie depuis le CACHE
 * local faute de connexion (état posé par dossierCache via le store `reseau`). Rappelle la
 * fraîcheur de la donnée affichée. Décoratif d'information ; ne bloque aucune interaction.
 */
export function BanniereHorsLigne() {
  const horsLigne = useReseau((e) => e.horsLigne);
  const maj = useReseau((e) => e.majCache);
  const t = useT();

  if (!horsLigne) return null;

  return (
    <View style={styles.wrap} accessibilityRole="alert">
      <Ionicons name="cloud-offline-outline" size={16} color={colors.warning.text} />
      <Text style={styles.txt}>
        {t.commun.horsLigne}
        {maj ? ` · données du ${formatDateHeureFr(new Date(maj).toISOString())}` : ''}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[2],
    backgroundColor: colors.warning.bg,
    paddingHorizontal: spacing[6],
    paddingVertical: spacing[2],
  },
  txt: { ...typography.caption, color: colors.warning.text, fontWeight: '700' },
});
