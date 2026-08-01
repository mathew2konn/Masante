import React, { useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, Share, StyleSheet, Text, View } from 'react-native';
import { Screen } from '../components/Screen';
import { Card } from '../components/Card';
import { TriageBadge } from '../components/TriageBadge';
import { ScreenHeader } from '../components/ScreenHeader';
import { SecondaryButton } from '../components/SecondaryButton';
import { colors, spacing, typography } from '../theme/theme';
import { getFiche, getHistorique } from '../api/triage';
import type { TriageHistorique } from '../types/triage';

/**
 * HistoriqueScreen — F1.6 : liste des triages récents (50 max, récents d'abord).
 * Chaque carte rappelle le niveau (badge), le patient, le score et les symptômes.
 * Toucher une carte propose de repartager la fiche (F1.8).
 */
export function HistoriqueScreen({ onBack }: { onBack: () => void }) {
  const [items, setItems] = useState<TriageHistorique[] | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);
  const [partageId, setPartageId] = useState<number | null>(null);

  const charger = () => {
    setErreur(null);
    setItems(null);
    getHistorique()
      .then((d) => setItems(d.triages))
      .catch((e) => setErreur(e?.message ?? "Impossible de charger l'historique."));
  };

  useEffect(charger, []);

  const partager = async (id: number) => {
    try {
      setPartageId(id);
      const { texte_partage } = await getFiche(id);
      await Share.share({ message: texte_partage });
    } catch (e: any) {
      Alert.alert('Partage impossible', e?.message ?? 'Réessayez dans un instant.');
    } finally {
      setPartageId(null);
    }
  };

  return (
    <Screen>
      <ScreenHeader title="Mes triages" subtitle="Vos évaluations récentes." onBack={onBack} />

      {!items && !erreur && (
        <View style={styles.center}>
          <ActivityIndicator size="large" color={colors.blue[600]} />
          <Text style={styles.muted}>Chargement…</Text>
        </View>
      )}

      {erreur && (
        <Card style={styles.erreurCard}>
          <Text style={styles.erreurTxt}>{erreur}</Text>
          <View style={{ marginTop: spacing[3] }}>
            <SecondaryButton label="Réessayer" onPress={charger} />
          </View>
        </Card>
      )}

      {items && items.length === 0 && (
        <Text style={styles.muted}>Aucun triage pour le moment.</Text>
      )}

      {items?.map((t) => {
        const noms = t.symptomes_json.map((s) => s.nom).join(', ');
        const date = new Date(t.created_at).toLocaleDateString('fr-FR', {
          day: '2-digit',
          month: 'short',
          year: 'numeric',
          hour: '2-digit',
          minute: '2-digit',
        });
        return (
          <Pressable
            key={t.id}
            onPress={() => partager(t.id)}
            accessibilityRole="button"
            accessibilityLabel={`Triage du ${date}, partager la fiche`}
          >
            <Card style={styles.item}>
              <View style={styles.itemHead}>
                <TriageBadge niveau={t.niveau} />
                <Text style={styles.score}>{t.score_severite}/100</Text>
              </View>
              <Text style={styles.patient}>
                {t.patient_nom || 'Patient non précisé'}
                {t.patient_age != null ? ` · ${t.patient_age} ans` : ''}
              </Text>
              {noms ? (
                <Text style={styles.symptomes} numberOfLines={2}>
                  {noms}
                </Text>
              ) : null}
              <View style={styles.itemFoot}>
                <Text style={styles.date}>{date}</Text>
                <Text style={styles.partager}>{partageId === t.id ? 'Partage…' : 'Partager ›'}</Text>
              </View>
            </Card>
          </Pressable>
        );
      })}
    </Screen>
  );
}

const styles = StyleSheet.create({
  center: { alignItems: 'center', paddingVertical: spacing[10] },
  muted: { ...typography.body, color: colors.ink[500], marginTop: spacing[3], textAlign: 'center' },
  item: { marginBottom: spacing[4] },
  itemHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  score: { ...typography.bodyStrong, color: colors.ink[700] },
  patient: { ...typography.bodyStrong, color: colors.ink[900], marginTop: spacing[3] },
  symptomes: { ...typography.body, color: colors.ink[700], marginTop: spacing[1] },
  itemFoot: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: spacing[3] },
  date: { ...typography.caption, color: colors.ink[500] },
  partager: { ...typography.caption, color: colors.blue[600], fontWeight: '700' },
  erreurCard: { backgroundColor: colors.danger.bg },
  erreurTxt: { ...typography.body, color: colors.danger.text },
});
