import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import type { AlerteDon } from '../types/donSang';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * Bannière d'urgence transfusionnelle (FN6), sur l'accueil — sœur de {@see BanniereAlerte} (FN3).
 *
 * Elle ne s'affiche QUE si le serveur a établi qu'un membre donneur du foyer peut réellement fournir
 * la poche demandée : le mobile ne compare aucun groupe sanguin lui-même (une erreur de
 * compatibilité tue). Si elle est là, c'est que « c'est vous qu'on cherche ».
 *
 * Rouge, comme le SOS : c'est une urgence vitale — pour quelqu'un d'autre.
 */
export function BanniereDonSang({ alertes, onPress }: { alertes: AlerteDon[]; onPress: () => void }) {
  if (alertes.length === 0) return null;

  const principale = alertes[0];
  const autres = alertes.length - 1;

  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={`Urgence : sang ${principale.besoin.groupe_sanguin} recherché. Votre don peut convenir.`}
      style={styles.banniere}
    >
      <Ionicons name="water" size={22} color={colors.danger.solid} />
      <View style={styles.corps}>
        <Text style={styles.niveau}>Urgence — don de sang</Text>
        <Text style={styles.titre} numberOfLines={2}>
          {principale.besoin.structure?.nom ?? 'Un établissement'} recherche du sang{' '}
          {principale.besoin.groupe_sanguin} : votre don peut convenir.
        </Text>
        {autres > 0 && (
          <Text style={styles.autres}>
            +{autres} autre{autres > 1 ? 's' : ''} appel{autres > 1 ? 's' : ''} vous concerne
            {autres > 1 ? 'nt' : ''}
          </Text>
        )}
      </View>
      <Ionicons name="chevron-forward" size={18} color={colors.danger.solid} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  banniere: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[3],
    padding: spacing[4],
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.danger.solid,
    backgroundColor: colors.danger.bg,
    marginBottom: spacing[5],
  },
  corps: { flex: 1 },
  niveau: { ...typography.caption, fontWeight: '700', textTransform: 'uppercase', color: colors.danger.text },
  titre: { ...typography.bodyStrong, color: colors.ink[900], marginTop: 2 },
  autres: { ...typography.caption, color: colors.ink[500], marginTop: 2 },
});
