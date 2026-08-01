import React, { useState } from 'react';
import { Alert, Share, StyleSheet, Text, View } from 'react-native';
import { Screen } from '../components/Screen';
import { Card } from '../components/Card';
import { TriageBadge } from '../components/TriageBadge';
import { PrimaryButton } from '../components/PrimaryButton';
import { SecondaryButton } from '../components/SecondaryButton';
import { SosButton } from '../components/SosButton';
import { colors, spacing, typography } from '../theme/theme';
import { getFiche } from '../api/triage';
import type { AnalyseResultat, Niveau } from '../types/triage';

/** Couleur sémantique du score selon le niveau (sens médical, jamais décoratif). */
const COULEUR_NIVEAU: Record<Niveau, { solid: string; bg: string; text: string }> = {
  leger: colors.success,
  modere: colors.warning,
  urgent: colors.danger,
};

/**
 * ResultatScreen — F1.3 (résultat du triage) + F1.8 (partage de la fiche).
 * Score 0-100 en grand, badge de niveau (icône + couleur + texte), recommandation,
 * spécialité, et — si URGENT — bouton SOS proéminent. Partage via la fiche serveur.
 */
export function ResultatScreen({
  resultat,
  onNouveau,
  onAccueil,
}: {
  resultat: AnalyseResultat;
  onNouveau: () => void;
  onAccueil: () => void;
}) {
  const [partageEnCours, setPartageEnCours] = useState(false);
  const sem = COULEUR_NIVEAU[resultat.niveau];

  const partager = async () => {
    try {
      setPartageEnCours(true);
      const { texte_partage } = await getFiche(resultat.triage_id);
      await Share.share({ message: texte_partage });
    } catch (e: any) {
      Alert.alert('Partage impossible', e?.message ?? 'Réessayez dans un instant.');
    } finally {
      setPartageEnCours(false);
    }
  };

  return (
    <Screen
      footer={
        <View style={styles.footerActions}>
          <PrimaryButton
            label="Partager ma fiche"
            onPress={partager}
            loading={partageEnCours}
            accessibilityLabel="Partager ma fiche de triage"
          />
          <View style={{ marginTop: spacing[3] }}>
            <SecondaryButton label="Nouveau triage" onPress={onNouveau} />
          </View>
        </View>
      }
    >
      <Text style={styles.titre}>Résultat du triage</Text>

      {/* Score + niveau */}
      <Card style={styles.scoreCard}>
        <Text style={[styles.score, { color: sem.solid }]}>{resultat.score_severite}</Text>
        <Text style={styles.scoreSur}>/ 100</Text>
        <View style={styles.badgeWrap}>
          <TriageBadge niveau={resultat.niveau} grand />
        </View>
        <Text style={styles.detailScore}>
          Symptômes {resultat.details_score.symptomes} · Questions {resultat.details_score.reponses}
          {resultat.details_score.antecedents ? ` · Antécédents ${resultat.details_score.antecedents}` : ''}
        </Text>
      </Card>

      {/* Drapeau rouge */}
      {resultat.drapeau_rouge && (
        <Card style={[styles.card, styles.drapeauCard]}>
          <Text style={styles.drapeauTitre}>⚠ Signe d'alerte détecté</Text>
          <Text style={styles.drapeauTxt}>
            Un de vos symptômes ou réponses est un signe de gravité. Ne tardez pas à consulter.
          </Text>
        </Card>
      )}

      {/* Recommandation */}
      <Card style={styles.card}>
        <Text style={styles.h2}>Recommandation</Text>
        <Text style={styles.body}>{resultat.recommandation_texte}</Text>
        {resultat.specialite_requise && (
          <View style={styles.specialite}>
            <Text style={styles.specialiteLabel}>Spécialité conseillée</Text>
            <Text style={styles.specialiteValeur}>{resultat.specialite_requise}</Text>
          </View>
        )}
      </Card>

      {/* SOS si urgent */}
      {resultat.niveau === 'urgent' && (
        <View style={styles.sosWrap}>
          <SosButton />
        </View>
      )}

      <Text style={styles.disclaimer}>
        Cette évaluation est indicative et ne remplace pas un avis médical professionnel.
      </Text>

      <View style={{ alignItems: 'center', marginTop: spacing[3] }}>
        <SecondaryButton label="Retour à l'accueil" onPress={onAccueil} />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  titre: { ...typography.h1, color: colors.blue[900], marginBottom: spacing[5] },
  scoreCard: { alignItems: 'center', paddingVertical: spacing[6] },
  score: { ...typography.display },
  scoreSur: { ...typography.body, color: colors.ink[500], marginTop: -spacing[2] },
  badgeWrap: { marginTop: spacing[4] },
  detailScore: { ...typography.caption, color: colors.ink[500], marginTop: spacing[4], textAlign: 'center' },
  card: { marginTop: spacing[4] },
  drapeauCard: { backgroundColor: colors.danger.bg },
  drapeauTitre: { ...typography.bodyStrong, color: colors.danger.text, marginBottom: spacing[1] },
  drapeauTxt: { ...typography.body, color: colors.danger.text },
  h2: { ...typography.h2, color: colors.ink[900], marginBottom: spacing[2] },
  body: { ...typography.body, color: colors.ink[700] },
  specialite: { marginTop: spacing[4], paddingTop: spacing[4], borderTopWidth: 1, borderTopColor: colors.line },
  specialiteLabel: { ...typography.caption, color: colors.ink[500] },
  specialiteValeur: { ...typography.bodyStrong, color: colors.blue[700], marginTop: spacing[1] },
  sosWrap: { marginTop: spacing[5] },
  footerActions: {},
  disclaimer: { ...typography.caption, color: colors.ink[500], marginTop: spacing[6], textAlign: 'center' },
});
