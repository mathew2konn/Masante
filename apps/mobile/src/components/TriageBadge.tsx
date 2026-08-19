import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { tokens } from '@masante/shared';
import { radius, spacing } from '../theme/theme';
import { useT } from '../i18n/useT';
import type { Niveau } from '../types/triage';

/**
 * TriageBadge — badge de niveau de triage (§5.4), élément central du Module 1.
 *
 * Redondance OBLIGATOIRE icône + couleur + texte (§6 Accessibilité) : une personne
 * daltonienne distingue le niveau par le pictogramme et le mot, pas seulement la couleur.
 * Couleurs issues de theme.ts (source unique) — aucune couleur en dur.
 */
/**
 * ═══ P10b-1 — LES QUATRE NIVEAUX DE CDC_05 §5.3, ET LES TROIS HÉRITÉS ═══
 *
 * Cette table portait TROIS entrées et leurs libellés EN DUR (« LÉGER », « MODÉRÉ », « URGENT »).
 * Le corpus en exige quatre côté patient, `@masante/shared` les porte depuis P0, et
 * `palette.json` en peint les couleurs — sans que rien ne les consomme.
 *
 * Les libellés viennent désormais de l'i18n partagée (`useT`), et les couleurs des tokens
 * `tokens.triage`, qui étaient dormants depuis P0. Rien n'est plus écrit ici : ce composant décide
 * de la FORME du badge, jamais de son vocabulaire ni de ses couleurs.
 *
 * Les trois valeurs héritées restent rendues : l'historique les porte, et les convertir changerait
 * ce qu'un patient a réellement lu sur son écran.
 */
const MAP: Record<Niveau, { fond: string; icone: string }> = {
  faible: { fond: tokens.triage.faible, icone: '✓' },
  recommandee: { fond: tokens.triage.recommandee, icone: '!' },
  rapide: { fond: tokens.triage.rapide, icone: '!' },
  urgence: { fond: tokens.triage.urgence, icone: '⚠' },

  // Module 1 — plus rien ne les produit, tout doit encore savoir les lire.
  leger: { fond: tokens.triage.vert, icone: '✓' },
  modere: { fond: tokens.triage.jaune, icone: '!' },
  urgent: { fond: tokens.triage.rouge, icone: '⚠' },
};

export function TriageBadge({
  niveau,
  grand,
  libelle,
}: {
  niveau: Niveau;
  grand?: boolean;
  /** Le libellé fourni par le backend, quand l'appelant en dispose (§5.3). */
  libelle?: string;
}) {
  const t = useT();
  const { fond, icone } = MAP[niveau];

  // Le libellé du backend prime : c'est lui qui a été calculé avec la décision. L'i18n prend le
  // relais là où l'API n'en renvoie pas (l'historique, qui ne porte que le code).
  const label = (libelle ?? t.triage.niveau[niveau] ?? niveau).toUpperCase();
  const c = { solid: fond, bg: fond + '1F', text: fond };
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
