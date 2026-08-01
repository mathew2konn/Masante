import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import QRCode from 'react-native-qrcode-svg';
import { Screen } from '../../../../src/components/Screen';
import { ScreenHeader } from '../../../../src/components/ScreenHeader';
import { Card } from '../../../../src/components/Card';
import { PrimaryButton } from '../../../../src/components/PrimaryButton';
import { genererQr } from '../../../../src/api/qr';
import { messageErreur } from '../../../../src/utils/erreurs';
import { formatChrono } from '../../../../src/utils/dates';
import type { QrGenere } from '../../../../src/types/qr';
import { colors, radius, spacing, typography } from '../../../../src/theme/theme';

/**
 * Écran « QR de partage » d'un membre (F2, Sécurité §5). Génère un token à usage unique
 * (10 min), l'affiche en QR et décompte le temps restant. À expiration, on propose de
 * régénérer. Le contenu encodé est opaque : jamais le matricule interne.
 */
export default function QrMembreScreen() {
  const { id, prenom, nom } = useLocalSearchParams<{ id: string; prenom?: string; nom?: string }>();
  const membreId = Number(id);

  const [qr, setQr] = useState<QrGenere | null>(null);
  const [restant, setRestant] = useState(0);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);

  const stopTimer = () => {
    if (timer.current) {
      clearInterval(timer.current);
      timer.current = null;
    }
  };

  const generer = useCallback(async () => {
    stopTimer();
    setErreur(null);
    setChargement(true);
    try {
      const data = await genererQr(membreId);
      setQr(data);
      setRestant(data.expires_in);
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, [membreId]);

  // Première génération au montage.
  useEffect(() => {
    generer();
    return stopTimer;
  }, [generer]);

  // Compte à rebours : redémarre à chaque nouveau QR, s'arrête à zéro.
  useEffect(() => {
    if (!qr) return;
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
  }, [qr]);

  const expire = restant <= 0;
  const nomComplet = [prenom, nom].filter(Boolean).join(' ') || 'ce membre';

  return (
    <Screen>
      <ScreenHeader
        title="QR de partage"
        subtitle={`Dossier de ${nomComplet}`}
        onBack={() => router.back()}
      />

      <Card style={styles.carte}>
        {chargement ? (
          <View style={styles.zone}>
            <ActivityIndicator color={colors.blue[600]} />
          </View>
        ) : erreur ? (
          <View style={styles.zone}>
            <Text style={styles.erreur}>{erreur}</Text>
          </View>
        ) : qr ? (
          <>
            <View style={[styles.qrBoite, expire && styles.qrExpire]}>
              <QRCode value={qr.qr} size={220} color={colors.ink[900]} backgroundColor={colors.surface} />
            </View>
            <View style={[styles.chrono, { backgroundColor: expire ? colors.danger.bg : colors.success.bg }]}>
              <Text style={[styles.chronoTxt, { color: expire ? colors.danger.text : colors.success.text }]}>
                {expire ? 'QR expiré' : `Valable encore ${formatChrono(restant)}`}
              </Text>
            </View>
          </>
        ) : null}
      </Card>

      <Card style={styles.infoCarte}>
        <Text style={styles.infoTitre}>Comment l'utiliser</Text>
        <Text style={styles.info}>
          Présentez ce code à un agent de santé pour lui donner un accès temporaire au dossier.
          Le code est à <Text style={styles.gras}>usage unique</Text> et valable{' '}
          <Text style={styles.gras}>10 minutes</Text>. Chaque accès est inscrit au journal.
        </Text>
      </Card>

      <PrimaryButton
        label={expire ? 'Régénérer un QR' : 'Régénérer'}
        onPress={generer}
        loading={chargement}
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  carte: { alignItems: 'center', marginBottom: spacing[5] },
  zone: { height: 260, alignItems: 'center', justifyContent: 'center' },
  qrBoite: { padding: spacing[3], backgroundColor: colors.surface, borderRadius: radius.md },
  qrExpire: { opacity: 0.25 },
  chrono: { marginTop: spacing[4], borderRadius: radius.pill, paddingHorizontal: spacing[4], paddingVertical: spacing[2] },
  chronoTxt: { ...typography.bodyStrong },
  erreur: { ...typography.bodyStrong, color: colors.danger.text, textAlign: 'center' },
  infoCarte: { marginBottom: spacing[5] },
  infoTitre: { ...typography.h2, color: colors.blue[900], marginBottom: spacing[2] },
  info: { ...typography.body, color: colors.ink[700] },
  gras: { fontWeight: '700', color: colors.ink[900] },
});
