import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Share, StyleSheet, Text, View } from 'react-native';
import { Screen } from '../components/Screen';
import { Card } from '../components/Card';
import { TriageBadge } from '../components/TriageBadge';
import { PrimaryButton } from '../components/PrimaryButton';
import { SecondaryButton } from '../components/SecondaryButton';
import { SosButton } from '../components/SosButton';
import { colors, spacing, typography } from '../theme/theme';
import { QrMasante } from '../components/QrMasante';
import { getFiche } from '../api/triage';
import { dureesVers } from '../api/itineraire';
import { useLocalisation } from '../store/localisation';
import type { AnalyseResultat, FicheResponse, Niveau } from '../types/triage';

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
  const [fiche, setFiche] = useState<FicheResponse | null>(null);
  const [dureesMin, setDureesMin] = useState<Record<number, number | null>>({});
  const [chargementFiche, setChargementFiche] = useState(true);
  const positionFraiche = useLocalisation((e) => e.positionFraiche);
  const sem = COULEUR_NIVEAU[resultat.niveau];

  /**
   * P10a — La fiche §5.4 est chargée dès l'affichage du résultat.
   *
   * C'est elle qui porte les hôpitaux proches, le QR et la mention obligatoire ; les demander au
   * moment du partage seulement les rendrait invisibles à l'écran, alors que le §5.4 en fait un
   * livrable. Le partage réutilise ensuite ce qui est déjà chargé.
   */
  const charger = useCallback(async () => {
    setChargementFiche(true);

    // Une position, si on peut en avoir une de FRAÎCHE. On ne bloque jamais dessus.
    const position = await positionFraiche().catch(() => null);

    try {
      const reponse = await getFiche(resultat.triage_id, position);
      setFiche(reponse);

      if (position === null) return;

      // ═══ UNE SEULE REQUÊTE POUR TOUS LES ÉTABLISSEMENTS ═══
      //
      // Et un échec ne retire personne de la liste : `dureesVers` renvoie autant de `null` que de
      // destinations plutôt que de lever.
      const cibles = reponse.fiche.etablissements
        .flatMap((g) => g.etablissements)
        .filter((e) => e.latitude !== null && e.longitude !== null);

      if (cibles.length === 0) return;

      const minutes = await dureesVers(
        position,
        cibles.map((e) => ({ lat: e.latitude as number, lng: e.longitude as number })),
      );

      setDureesMin(Object.fromEntries(cibles.map((e, i) => [e.id, minutes[i]])));
    } catch {
      // La fiche n'a pas pu être chargée : l'écran garde ce que l'analyse a déjà renvoyé. Le
      // résultat du triage reste lisible — c'est l'essentiel, le reste est un enrichissement.
    } finally {
      setChargementFiche(false);
    }
  }, [positionFraiche, resultat.triage_id]);

  useEffect(() => {
    void charger();
  }, [charger]);

  const partager = async () => {
    try {
      setPartageEnCours(true);
      const texte = fiche?.texte_partage ?? (await getFiche(resultat.triage_id)).texte_partage;
      await Share.share({ message: texte });
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

      {/* Recommandation + orientation */}
      <Card style={styles.card}>
        <Text style={styles.h2}>Recommandation</Text>
        <Text style={styles.body}>{resultat.recommandation_texte}</Text>

        {/*
          L'ORDRE VIENT DU SERVEUR, ON NE RETRIE JAMAIS. Le rang est une donnée du référentiel
          publié, relue par deux agents habilités (CDC_09 §10) : le réordonner ici referait la
          règle en dur que P10a vient de retirer du service.
        */}
        {resultat.specialites.length > 0 && (
          <View style={styles.specialite}>
            <Text style={styles.specialiteLabel}>
              {resultat.specialites.length > 1 ? 'Services conseillés' : 'Service conseillé'}
            </Text>
            {resultat.specialites.map((s, i) => (
              <Text key={s.code} style={i === 0 ? styles.specialiteValeur : styles.specialiteSecondaire}>
                {s.libelle}
              </Text>
            ))}
          </View>
        )}
      </Card>

      {/* Hôpitaux proches proposant ce service (§5.4) */}
      {chargementFiche && (
        <View style={styles.chargement}>
          <ActivityIndicator color={colors.blue[700]} />
        </View>
      )}

      {fiche?.fiche.etablissements.map((groupe) => (
        <Card key={groupe.specialite.code} style={styles.card}>
          <Text style={styles.h2}>{groupe.specialite.libelle}</Text>

          {groupe.etablissements.length === 0 ? (
            <Text style={styles.body}>
              Aucun établissement enregistré ne déclare ce service à proximité. L'annuaire est
              incomplet : renseignez-vous auprès du centre de santé le plus proche.
            </Text>
          ) : (
            groupe.etablissements.map((e) => (
              <View key={e.id} style={styles.etab}>
                <Text style={styles.etabNom}>{e.nom}</Text>
                <Text style={styles.etabMeta}>
                  {[
                    e.commune,
                    e.distance_km !== null ? `${e.distance_km} km` : null,
                    // Un temps de trajet absent n'affiche RIEN — il ne retire jamais l'hôpital.
                    dureesMin[e.id] != null ? `~${dureesMin[e.id]} min en voiture` : null,
                  ]
                    .filter(Boolean)
                    .join(' · ')}
                </Text>
              </View>
            ))
          )}

          {groupe.tronquee && (
            <Text style={styles.etabTronque}>
              {groupe.total} établissements au total — les plus proches sont affichés.
            </Text>
          )}
        </Card>
      ))}

      {/* QR « permettant au médecin d'accéder au triage » (§5.4) */}
      {fiche && (
        <Card style={[styles.card, styles.qrCard]}>
          <Text style={styles.h2}>À montrer au soignant</Text>
          <Text style={styles.body}>
            Ce code donne accès à cette fiche. Ne le partagez qu'avec un professionnel de santé.
          </Text>
          <View style={styles.qrWrap}>
            <QrMasante valeur={fiche.qr_payload} size={200} />
          </View>
        </Card>
      )}

      {/* SOS si urgent */}
      {resultat.niveau === 'urgent' && (
        <View style={styles.sosWrap}>
          <SosButton />
        </View>
      )}

      {/*
        LA MENTION DU §5.4 EST UN TEXTE IMPOSÉ, PAS UNE FORMULATION LIBRE. L'écran affichait
        jusqu'ici une paraphrase écrite côté mobile — proche, mais recopiée, donc capable de
        diverger. Elle vient désormais du serveur, qui la porte sur le modèle (source unique).
        La paraphrase reste le repli tant que la fiche n'est pas chargée : ne rien dire du tout
        serait pire que le dire avec d'autres mots.
      */}
      <Text style={styles.disclaimer}>
        {fiche?.fiche.mention_obligatoire ??
          'Cette évaluation est indicative et ne remplace pas un avis médical professionnel.'}
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
  specialiteSecondaire: { ...typography.body, color: colors.ink[700], marginTop: spacing[1] },
  chargement: { marginTop: spacing[5], alignItems: 'center' },
  etab: { marginTop: spacing[3] },
  etabNom: { ...typography.bodyStrong, color: colors.ink[900] },
  etabMeta: { ...typography.caption, color: colors.ink[500], marginTop: spacing[1] },
  etabTronque: { ...typography.caption, color: colors.ink[500], marginTop: spacing[3], fontStyle: 'italic' },
  qrCard: { alignItems: 'center' },
  qrWrap: { marginTop: spacing[4], alignItems: 'center' },
  sosWrap: { marginTop: spacing[5] },
  footerActions: {},
  disclaimer: { ...typography.caption, color: colors.ink[500], marginTop: spacing[6], textAlign: 'center' },
});
