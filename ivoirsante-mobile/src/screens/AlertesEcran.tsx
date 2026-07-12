import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../components/Screen';
import { Card } from '../components/Card';
import { ScreenHeader } from '../components/ScreenHeader';
import { getAlertesEpidemiques } from '../api/alertes';
import { styleNiveau } from '../urgence/alertes';
import { messageErreur } from '../utils/erreurs';
import { formatDateFr } from '../utils/dates';
import type { AlerteEpidemique } from '../types/urgence';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * Détail des alertes épidémiques (FN3). Lecture seule : chaque alerte affiche sa maladie, son
 * niveau, la période, les consignes à suivre et la source (OMS / Ministère). Les consignes sont
 * l'essentiel — c'est ce qui protège l'utilisateur.
 */
export function AlertesEcran({ onFermer }: { onFermer: () => void }) {
  const [alertes, setAlertes] = useState<AlerteEpidemique[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  const charger = useCallback(async () => {
    setChargement(true);
    setErreur(null);
    try {
      setAlertes(await getAlertesEpidemiques());
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, []);

  useEffect(() => {
    void charger();
  }, [charger]);

  return (
    <Screen>
      <ScreenHeader
        title="Alertes sanitaires"
        subtitle="Épidémies signalées dans votre commune"
        onBack={onFermer}
      />

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.centre} />
      ) : erreur ? (
        <Card style={styles.bloc}>
          <Text style={styles.corps}>{erreur}</Text>
          <Pressable onPress={() => void charger()} accessibilityRole="button" style={styles.reessayer}>
            <Text style={styles.lien}>Réessayer</Text>
          </Pressable>
        </Card>
      ) : alertes.length === 0 ? (
        <Card style={styles.bloc}>
          <Text style={styles.corps}>Aucune alerte sanitaire en cours dans votre commune.</Text>
          <Text style={styles.muted}>
            Renseignez votre commune dans votre profil pour recevoir les alertes qui vous concernent.
          </Text>
        </Card>
      ) : (
        alertes.map((a) => {
          const st = styleNiveau(a.niveau_alerte);
          return (
            <Card key={a.id} style={[styles.bloc, { borderLeftWidth: 4, borderLeftColor: st.couleur }]}>
              <View style={styles.entete}>
                <View style={[styles.puceNiveau, { backgroundColor: st.fond }]}>
                  <Ionicons name={st.icone} size={14} color={st.couleur} />
                  <Text style={[styles.puceTxt, { color: st.couleur }]}>{st.libelle}</Text>
                </View>
                <Text style={styles.maladie}>{a.maladie}</Text>
              </View>

              <Text style={styles.titre}>{a.titre}</Text>
              <Text style={styles.corps}>{a.description}</Text>

              <View style={styles.meta}>
                <Text style={styles.muted}>
                  Depuis le {formatDateFr(a.date_debut)}
                  {a.date_fin ? ` · jusqu'au ${formatDateFr(a.date_fin)}` : ''}
                </Text>
                <Text style={styles.source}>Source : {a.source}</Text>
              </View>
            </Card>
          );
        })
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  centre: { marginTop: spacing[8] },
  bloc: { marginBottom: spacing[4], gap: spacing[2] },
  entete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing[2] },
  puceNiveau: { flexDirection: 'row', alignItems: 'center', gap: 4, borderRadius: radius.sm, paddingHorizontal: spacing[2], paddingVertical: 2 },
  puceTxt: { ...typography.caption, fontWeight: '700', textTransform: 'uppercase' },
  maladie: { ...typography.bodyStrong, color: colors.ink[700] },
  titre: { ...typography.h2, color: colors.blue[900] },
  corps: { ...typography.body, color: colors.ink[900] },
  muted: { ...typography.caption, color: colors.ink[500] },
  meta: { marginTop: spacing[2], gap: 2 },
  source: { ...typography.caption, color: colors.ink[500], fontStyle: 'italic' },
  lien: { ...typography.bodyStrong, color: colors.blue[700] },
  reessayer: { minHeight: 44, justifyContent: 'center' },
});
