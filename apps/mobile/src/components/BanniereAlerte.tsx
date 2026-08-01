import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { styleNiveau } from '../urgence/alertes';
import type { AlerteEpidemique } from '../types/urgence';
import { radius, spacing, typography, colors } from '../theme/theme';

/**
 * Bannière d'alerte épidémique (FN3), affichée sur l'accueil quand une alerte concerne la commune
 * de l'utilisateur. Teintée selon la gravité (source unique : `styleNiveau`). S'il y a plusieurs
 * alertes, on montre la plus grave et on annonce le reste — l'accueil n'est pas un mur d'alertes.
 */
export function BanniereAlerte({
  alertes,
  onPress,
}: {
  alertes: AlerteEpidemique[];
  onPress: () => void;
}) {
  if (alertes.length === 0) return null;

  const principale = alertes[0]; // déjà triées par gravité côté serveur
  const st = styleNiveau(principale.niveau_alerte);
  const autres = alertes.length - 1;

  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={`Alerte sanitaire : ${principale.titre}. Voir le détail.`}
      style={[styles.banniere, { backgroundColor: st.fond, borderColor: st.couleur }]}
    >
      <Ionicons name={st.icone} size={22} color={st.couleur} />
      <View style={styles.corps}>
        <Text style={[styles.niveau, { color: st.couleur }]}>
          {st.libelle} sanitaire · {principale.maladie}
        </Text>
        <Text style={styles.titre} numberOfLines={2}>
          {principale.titre}
        </Text>
        {autres > 0 && (
          <Text style={styles.autres}>
            +{autres} autre{autres > 1 ? 's' : ''} alerte{autres > 1 ? 's' : ''} dans votre zone
          </Text>
        )}
      </View>
      <Ionicons name="chevron-forward" size={18} color={st.couleur} />
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
    marginBottom: spacing[5],
  },
  corps: { flex: 1 },
  niveau: { ...typography.caption, fontWeight: '700', textTransform: 'uppercase' },
  titre: { ...typography.bodyStrong, color: colors.ink[900], marginTop: 2 },
  autres: { ...typography.caption, color: colors.ink[500], marginTop: 2 },
});
