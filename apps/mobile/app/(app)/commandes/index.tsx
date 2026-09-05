import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Screen } from '../../../src/components/Screen';
import { ScreenHeader } from '../../../src/components/ScreenHeader';
import { Card } from '../../../src/components/Card';
import { listerCommandes } from '../../../src/api/commandes';
import { messageErreur } from '../../../src/utils/erreurs';
import { formatDateFr } from '../../../src/utils/dates';
import { colors, radius, spacing, typography } from '../../../src/theme/theme';
import type { Commande } from '../../../src/types/commande';

/** « Mes commandes » (B3-d, CDC_01 §7.2) — suivi des commandes de médicaments, statut fourni par le serveur. */
export default function MesCommandesEcran() {
  const [commandes, setCommandes] = useState<Commande[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  useFocusEffect(
    useCallback(() => {
      void listerCommandes()
        .then(setCommandes)
        .catch((e) => setErreur(messageErreur(e)))
        .finally(() => setChargement(false));
    }, []),
  );

  if (chargement) {
    return (
      <Screen>
        <ScreenHeader title="Mes commandes" onBack={() => router.back()} />
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      </Screen>
    );
  }

  return (
    <Screen>
      <ScreenHeader title="Mes commandes" onBack={() => router.back()} />
      {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}
      {commandes.length === 0 ? (
        <Text style={styles.vide}>Aucune commande pour le moment.</Text>
      ) : (
        commandes.map((c) => (
          <Pressable key={c.id} onPress={() => router.push(`/(app)/commandes/${c.id}`)}>
            <Card style={styles.carte}>
              <View style={styles.entete}>
                <Text style={styles.reference}>{c.reference}</Text>
                <View style={[styles.badge, { backgroundColor: COULEUR_BADGE[c.statut] ?? colors.surfaceMuted }]}>
                  <Text style={styles.badgeTxt}>{LIBELLE_STATUT[c.statut] ?? c.statut}</Text>
                </View>
              </View>
              <Text style={styles.meta}>
                {c.lignes.length} article{c.lignes.length > 1 ? 's' : ''} · {formatDateFr(c.created_at)}
              </Text>
              <Text style={styles.montant}>
                {c.montant_indicatif_cfa !== null ? `${c.montant_indicatif_cfa} F` : 'Montant non connu'}
              </Text>
            </Card>
          </Pressable>
        ))
      )}
    </Screen>
  );
}

/** Présentation seule — le statut lui-même vient du serveur (frontière CDC_01 §0.1). */
const LIBELLE_STATUT: Record<string, string> = {
  en_attente: 'En attente', acceptee: 'Acceptée', refusee: 'Refusée',
  prete: 'Prête', remise: 'Remise', annulee: 'Annulée',
};
const COULEUR_BADGE: Record<string, string> = {
  en_attente: colors.blue[100], acceptee: colors.blue[100], refusee: colors.danger.bg,
  prete: colors.warning.bg, remise: colors.success.bg, annulee: colors.surfaceMuted,
};

const styles = StyleSheet.create({
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text, padding: spacing[4] },
  vide: { ...typography.body, color: colors.ink[500], padding: spacing[5], textAlign: 'center' },
  carte: { marginBottom: spacing[3] },
  entete: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  reference: { ...typography.bodyStrong, color: colors.blue[900], fontVariant: ['tabular-nums'] },
  badge: { paddingVertical: 2, paddingHorizontal: spacing[2], borderRadius: radius.pill },
  badgeTxt: { ...typography.caption, fontWeight: '700', color: colors.ink[900] },
  meta: { ...typography.caption, color: colors.ink[500], marginTop: spacing[1] },
  montant: { ...typography.bodyStrong, color: colors.ink[900], marginTop: spacing[2] },
});
