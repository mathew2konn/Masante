import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { criteresMotDePasse } from '../auth/motDePasse';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * MotDePasseForce — barre de progression + liste des critères manquants (modification.txt §1 :
 * « au fur et à mesure qu'il saisit le mot de passe, on lui dira ce qu'il manque »).
 * Reflète exactement la politique serveur (hors contrôle HIBP, purement serveur).
 */
export function MotDePasseForce({ valeur }: { valeur: string }) {
  if (!valeur) return null;

  const criteres = criteresMotDePasse(valeur);
  const remplis = criteres.filter((c) => c.ok).length;
  const couleur = remplis <= 1 ? colors.danger.solid : remplis <= 3 ? colors.warning.solid : colors.success.solid;
  const niveau = remplis <= 1 ? 'Faible' : remplis <= 3 ? 'Moyen' : 'Fort';

  return (
    <View style={styles.wrap}>
      <View style={styles.barres}>
        {criteres.map((c, i) => (
          <View key={c.cle} style={[styles.seg, { backgroundColor: i < remplis ? couleur : colors.line }]} />
        ))}
      </View>
      <Text style={[styles.niveau, { color: couleur }]}>Sécurité : {niveau}</Text>

      <View style={styles.criteres}>
        {criteres.map((c) => (
          <View key={c.cle} style={styles.critere}>
            <Ionicons
              name={c.ok ? 'checkmark-circle' : 'ellipse-outline'}
              size={15}
              color={c.ok ? colors.success.solid : colors.ink[500]}
            />
            <Text style={[styles.critereTxt, { color: c.ok ? colors.ink[700] : colors.ink[500] }]}>{c.libelle}</Text>
          </View>
        ))}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { marginTop: -spacing[2], marginBottom: spacing[4] },
  barres: { flexDirection: 'row', gap: spacing[1] },
  seg: { flex: 1, height: 6, borderRadius: radius.pill },
  niveau: { ...typography.caption, fontWeight: '700', marginTop: spacing[1] },
  criteres: { marginTop: spacing[2], gap: spacing[1] },
  critere: { flexDirection: 'row', alignItems: 'center', gap: spacing[2] },
  critereTxt: { ...typography.caption },
});
