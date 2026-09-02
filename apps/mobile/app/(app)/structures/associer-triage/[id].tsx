import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../../../src/components/Screen';
import { Card } from '../../../../src/components/Card';
import { ScreenHeader } from '../../../../src/components/ScreenHeader';
import { TriageBadge } from '../../../../src/components/TriageBadge';
import { getHistorique } from '../../../../src/api/triage';
import { associerTriage } from '../../../../src/api/rendezvous';
import { messageErreur } from '../../../../src/utils/erreurs';
import { formatDateFr } from '../../../../src/utils/dates';
import type { TriageHistorique } from '../../../../src/types/triage';
import { colors, radius, spacing, typography } from '../../../../src/theme/theme';

/**
 * Associer un triage à un rendez-vous après coup (B1-b / D6).
 *
 * Le lien `triage_id` existe depuis toujours (posé à la création si le patient en avait un sous
 * la main) ; cet écran comble le cas où il ne l'avait pas — sans recommencer une demande entière.
 * Liste les triages du MÊME membre (l'API refuse un triage d'un autre membre du compte, comme à
 * la création — mêmes vérifications anti-IDOR que `store()`).
 */
export default function AssocierTriageEcran() {
  const { id, membreId } = useLocalSearchParams<{ id: string; membreId: string }>();
  const rdvId = Number(id);
  const membre = membreId ? Number(membreId) : undefined;

  const [triages, setTriages] = useState<TriageHistorique[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);
  const [envoiId, setEnvoiId] = useState<number | null>(null);

  const charger = useCallback(async () => {
    setChargement(true);
    setErreur(null);
    try {
      const reponse = await getHistorique(membre);
      setTriages(reponse.triages);
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, [membre]);

  useFocusEffect(
    useCallback(() => {
      void charger();
    }, [charger]),
  );

  async function choisir(triageId: number) {
    setEnvoiId(triageId);
    setErreur(null);
    try {
      await associerTriage(rdvId, triageId);
      router.back();
    } catch (e) {
      setErreur(messageErreur(e));
      setEnvoiId(null);
    }
  }

  return (
    <Screen scroll={triages.length > 0}>
      <ScreenHeader title="Associer un triage" onBack={() => router.back()} />

      {chargement ? (
        <View style={styles.centre}>
          <ActivityIndicator color={colors.blue[600]} />
        </View>
      ) : erreur && triages.length === 0 ? (
        <View style={styles.centre}>
          <Ionicons name="cloud-offline-outline" size={28} color={colors.danger.solid} />
          <Text style={styles.etatTxt}>{erreur}</Text>
        </View>
      ) : triages.length === 0 ? (
        <View style={styles.centre}>
          <Ionicons name="clipboard-outline" size={28} color={colors.ink[500]} />
          <Text style={styles.etatTxt}>Ce membre n'a encore aucun triage à associer.</Text>
        </View>
      ) : (
        <>
          {erreur ? <Text style={styles.erreurTxt}>{erreur}</Text> : null}
          {triages.map((t) => (
            <Pressable
              key={t.id}
              onPress={() => void choisir(t.id)}
              disabled={envoiId !== null}
              accessibilityRole="button"
              style={({ pressed }) => [styles.item, pressed && styles.itemPressed]}
            >
              <Card style={styles.card}>
                <View style={styles.haut}>
                  <TriageBadge niveau={t.niveau} />
                  {envoiId === t.id ? <ActivityIndicator color={colors.blue[600]} /> : null}
                </View>
                <Text style={styles.date}>{formatDateFr(t.created_at)}</Text>
                {t.specialite_requise ? <Text style={styles.specialite}>{t.specialite_requise}</Text> : null}
              </Card>
            </Pressable>
          ))}
        </>
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  centre: { alignItems: 'center', gap: spacing[2], paddingVertical: spacing[10] },
  etatTxt: { ...typography.body, color: colors.ink[700], textAlign: 'center' },
  erreurTxt: { ...typography.body, color: colors.danger.solid, textAlign: 'center', marginBottom: spacing[2] },
  item: { marginBottom: spacing[3] },
  itemPressed: { opacity: 0.7 },
  card: { gap: 4 },
  haut: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  date: { ...typography.body, color: colors.ink[700] },
  specialite: { ...typography.caption, color: colors.ink[500] },
});
