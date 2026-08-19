import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { QrMasante } from '../components/QrMasante';
import { Screen } from '../components/Screen';
import { ScreenHeader } from '../components/ScreenHeader';
import { Card } from '../components/Card';
import { PrimaryButton } from '../components/PrimaryButton';
import { obtenirCarteCmu } from '../api/membres';
import { messageErreur } from '../utils/erreurs';
import { formatChrono, formatDateFr } from '../utils/dates';
import { LIBELLE_CMU_STATUT, type CarteCmu, type CmuStatut } from '../types/membre';
import { colors, radius, spacing, typography } from '../theme/theme';

/** Tonalité du badge de statut CMU (élément visuel principal de la carte, §5.3 doc). */
const STATUT_TON: Record<CmuStatut, { bg: string; text: string }> = {
  actif: { bg: colors.success.bg, text: colors.success.text },
  expire: { bg: colors.danger.bg, text: colors.danger.text },
  non_inscrit: { bg: colors.surfaceMuted, text: colors.ink[500] },
};

/**
 * CarteCmuEcran (F2.3) — carte CMU numérique présentable à l'accueil d'une structure.
 *
 * Affiche le titulaire, le numéro CMU **masqué**, un badge de statut et la validité. Le bouton
 * « Présenter ma carte » révèle le **code de présentation** (QR signé) en grand, avec décompte.
 * Le code n'est disponible qu'au **palier vérifié** (sinon message d'invitation). Le numéro complet
 * n'est jamais affiché ni encodé dans le QR.
 */
export function CarteCmuEcran({ membreId, nomMembre }: { membreId: number; nomMembre?: string }) {
  const [carte, setCarte] = useState<CarteCmu | null>(null);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);
  const [presenter, setPresenter] = useState(false);
  const [restant, setRestant] = useState(0);
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);

  const stopTimer = () => {
    if (timer.current) {
      clearInterval(timer.current);
      timer.current = null;
    }
  };

  const charger = useCallback(async () => {
    setErreur(null);
    setChargement(true);
    try {
      setCarte(await obtenirCarteCmu(membreId));
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, [membreId]);

  useEffect(() => {
    charger();
    return stopTimer;
  }, [charger]);

  // Décompte du code de présentation pendant qu'on le présente ; s'arrête à zéro.
  useEffect(() => {
    if (!presenter || !carte?.code_presentation || !carte.code_expire_dans) return;
    setRestant(carte.code_expire_dans);
    stopTimer();
    timer.current = setInterval(() => {
      setRestant((s) => {
        if (s <= 1) {
          stopTimer();
          return 0;
        }
        return s - 1;
      });
    }, 1000);
    return stopTimer;
  }, [presenter, carte]);

  // Régénère un code frais (le précédent a expiré) puis le présente.
  const regenerer = async () => {
    setPresenter(false);
    await charger();
    setPresenter(true);
  };

  const statut = carte?.cmu_statut ?? null;
  const ton = statut ? STATUT_TON[statut] : STATUT_TON.non_inscrit;
  const expire = restant <= 0;

  return (
    <Screen>
      <ScreenHeader
        title="Carte CMU"
        subtitle={nomMembre ? `Titulaire : ${nomMembre}` : undefined}
        onBack={() => router.back()}
      />

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      ) : erreur ? (
        <Text style={styles.erreur}>{erreur}</Text>
      ) : carte ? (
        <>
          {/* Vue « carte » : badge de statut = élément visuel principal. */}
          <Card style={styles.carte}>
            <View style={styles.carteEntete}>
              <Text style={styles.carteLabel}>Couverture Maladie Universelle</Text>
              <View style={[styles.statutBadge, { backgroundColor: ton.bg }]}>
                <Text style={[styles.statutTxt, { color: ton.text }]}>
                  {statut ? LIBELLE_CMU_STATUT[statut] : 'Non inscrit'}
                </Text>
              </View>
            </View>

            <Text style={styles.titulaire}>{carte.titulaire}</Text>
            <Text style={styles.numero}>{carte.cmu_numero_masque ?? 'Numéro non renseigné'}</Text>

            <View style={styles.carteBas}>
              {/* P6.8d — l'organisme vient du référentiel national, lu à l'affichage. */}
              {carte.organisme ? (
                <>
                  <Text style={styles.validiteLabel}>Organisme</Text>
                  <Text style={styles.validite}>{carte.organisme_sigle ?? carte.organisme}</Text>
                </>
              ) : null}
              <Text style={[styles.validiteLabel, carte.organisme ? styles.espaceHaut : null]}>Validité</Text>
              <Text style={styles.validite}>{formatDateFr(carte.cmu_validite)}</Text>
            </View>

            {/*
              P6.8d — LA MENTION VIENT DU SERVEUR, elle n'est pas réécrite ici.

              L'écran annonçait plus bas « Il CONFIRME votre statut CMU » d'une case remplie par
              l'intéressé lui-même. Aucune vérification auprès de la CNAM n'existe dans ce projet
              (l'étape 2 du §8.1 du CDC_06), donc rien ne peut le confirmer — ce qui pouvait être
              corrigé, c'est le mot.
            */}
            {carte.mention_provenance ? (
              <Text style={styles.provenance}>{carte.mention_provenance}</Text>
            ) : null}

            {carte.expiration_proche ? (
              <View style={styles.alerte}>
                <Text style={styles.alerteTxt}>⚠ Expire bientôt — pensez à renouveler votre CMU.</Text>
              </View>
            ) : null}
          </Card>

          {carte.disponible ? (
            presenter && carte.code_presentation ? (
              <Card style={styles.presentation}>
                <View style={[styles.qrBoite, expire && styles.qrExpire]}>
                  <QrMasante valeur={carte.code_presentation} size={220} />
                </View>
                <View style={[styles.chrono, { backgroundColor: expire ? colors.danger.bg : colors.success.bg }]}>
                  <Text style={[styles.chronoTxt, { color: expire ? colors.danger.text : colors.success.text }]}>
                    {expire ? 'Code expiré' : `Valable encore ${formatChrono(restant)}`}
                  </Text>
                </View>
                {/*
                  P6.8d — « il confirme votre statut CMU » disait faux : le code prouve que la
                  carte vient de MaSanté, pas que la couverture est valide. Le verbe change, la
                  fonction ne change pas.
                */}
                <Text style={styles.presentAide}>
                  Présentez ce code à l'agent d'accueil. Il prouve que cette carte vient de MaSanté
                  et restitue le statut que vous avez déclaré — sans donner accès à votre dossier.
                </Text>
                <Text style={styles.presentLimite}>
                  {carte.mention_provenance ?? 'Statut déclaré par l\'assuré.'}
                </Text>
                {expire ? <PrimaryButton label="Régénérer le code" onPress={regenerer} /> : null}
              </Card>
            ) : (
              <PrimaryButton label="Présenter ma carte" onPress={() => setPresenter(true)} />
            )
          ) : (
            <Card style={styles.gate}>
              <Text style={styles.gateTitre}>Identité à confirmer</Text>
              {/*
                P6.8d — « présentable comme justificatif » promettait une valeur probante que la
                carte n'a pas : elle restitue une déclaration. Ce que le palier vérifié change,
                c'est que l'identité du porteur est confirmée — pas la couverture.
              */}
              <Text style={styles.gateTxt}>
                Le code de présentation devient disponible une fois votre identité confirmée (CMU ou CNI) :
                il atteste alors de votre identité, jamais de vos droits — ceux-ci restent une déclaration
                que seul l'organisme peut confirmer. Vous pouvez déjà consulter vos informations ci-dessus.
              </Text>
            </Card>
          )}
        </>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text },

  carte: { backgroundColor: colors.blue[700], marginBottom: spacing[5] },
  carteEntete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  carteLabel: { ...typography.caption, color: colors.blue[100], flex: 1, marginRight: spacing[2] },
  statutBadge: { borderRadius: radius.pill, paddingHorizontal: spacing[3], paddingVertical: spacing[1] },
  statutTxt: { ...typography.caption, fontWeight: '700' },
  titulaire: { ...typography.h2, color: colors.surface, marginTop: spacing[4] },
  numero: { ...typography.bodyStrong, color: colors.blue[100], letterSpacing: 2, marginTop: spacing[2] },
  carteBas: { marginTop: spacing[5] },
  validiteLabel: { ...typography.caption, color: colors.blue[200] },
  validite: { ...typography.bodyStrong, color: colors.surface, marginTop: 2 },
  alerte: { marginTop: spacing[4], backgroundColor: colors.warning.bg, borderRadius: radius.md, padding: spacing[3] },
  alerteTxt: { ...typography.caption, color: colors.warning.text, fontWeight: '700' },
  espaceHaut: { marginTop: spacing[3] },
  provenance: { ...typography.caption, color: colors.blue[200], marginTop: spacing[4] },

  presentation: { alignItems: 'center', marginBottom: spacing[5] },
  qrBoite: { padding: spacing[3], backgroundColor: colors.surface, borderRadius: radius.md },
  qrExpire: { opacity: 0.25 },
  chrono: { marginTop: spacing[4], borderRadius: radius.pill, paddingHorizontal: spacing[4], paddingVertical: spacing[2] },
  chronoTxt: { ...typography.bodyStrong },
  presentAide: { ...typography.caption, color: colors.ink[700], textAlign: 'center', marginTop: spacing[3] },
  presentLimite: { ...typography.caption, color: colors.ink[500], textAlign: 'center', marginTop: spacing[2], fontStyle: 'italic' },

  gate: { marginBottom: spacing[5] },
  gateTitre: { ...typography.h2, color: colors.blue[900], marginBottom: spacing[2] },
  gateTxt: { ...typography.body, color: colors.ink[700] },
});
