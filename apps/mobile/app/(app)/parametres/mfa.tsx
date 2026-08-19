import React, { useEffect, useState } from 'react';
import { ActivityIndicator, Alert, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { QrMasante } from '../../../src/components/QrMasante';
import { Screen } from '../../../src/components/Screen';
import { ScreenHeader } from '../../../src/components/ScreenHeader';
import { Card } from '../../../src/components/Card';
import { TextField } from '../../../src/components/TextField';
import { PrimaryButton } from '../../../src/components/PrimaryButton';
import { SecondaryButton } from '../../../src/components/SecondaryButton';
import { confirmMfa, disableMfa, enrollMfa, mfaStatus } from '../../../src/api/mfa';
import { messageErreur } from '../../../src/utils/erreurs';
import type { MfaEnroll, MfaStatus } from '../../../src/types/mfa';
import { colors, radius, spacing, typography } from '../../../src/theme/theme';

const LONGUEUR_CODE = 6;

/**
 * Double authentification (P1, CDC_10 §3.5) — second facteur TOTP.
 *
 * Recommandée au patient (obligatoire pour les rôles pros, côté web). L'écran ne fait
 * qu'AFFICHER l'état fourni par le backend et présenter l'enrôlement : aucune décision
 * « MFA requise » n'est prise ici (frontière CDC_01 §0.1). Le secret n'apparaît qu'une fois.
 */
export default function MfaScreen() {
  const [statut, setStatut] = useState<MfaStatus | null>(null);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  // Enrôlement en cours (secret + QR), puis saisie du premier code.
  const [enrolement, setEnrolement] = useState<MfaEnroll | null>(null);
  const [code, setCode] = useState('');
  const [enCours, setEnCours] = useState(false);

  const chargerStatut = async () => {
    setErreur(null);
    try {
      setStatut(await mfaStatus());
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  };

  useEffect(() => {
    chargerStatut();
  }, []);

  const activer = async () => {
    setErreur(null);
    setEnCours(true);
    try {
      setEnrolement(await enrollMfa());
      setCode('');
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setEnCours(false);
    }
  };

  const confirmer = async () => {
    setErreur(null);
    setEnCours(true);
    try {
      await confirmMfa(code);
      setEnrolement(null);
      setCode('');
      await chargerStatut();
      Alert.alert('Double authentification activée', 'Votre compte demande désormais un code à la connexion.');
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setEnCours(false);
    }
  };

  const desactiver = () => {
    Alert.alert(
      'Désactiver la double authentification',
      'Votre compte ne sera plus protégé par un second code à la connexion.',
      [
        { text: 'Annuler', style: 'cancel' },
        {
          text: 'Désactiver',
          style: 'destructive',
          onPress: async () => {
            setEnCours(true);
            try {
              await disableMfa();
              await chargerStatut();
            } catch (e) {
              setErreur(messageErreur(e));
            } finally {
              setEnCours(false);
            }
          },
        },
      ],
    );
  };

  const annulerEnrolement = () => {
    setEnrolement(null);
    setCode('');
    setErreur(null);
  };

  if (chargement) {
    return (
      <Screen>
        <ScreenHeader title="Double authentification" onBack={() => router.back()} />
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      </Screen>
    );
  }

  // Enrôlement en cours : QR + secret + saisie du premier code.
  if (enrolement) {
    return (
      <Screen>
        <ScreenHeader
          title="Configurer"
          subtitle="Scannez le code avec votre application d'authentification"
          onBack={annulerEnrolement}
        />

        <Card style={styles.carteQr}>
          <View style={styles.qrBoite}>
            <QrMasante valeur={enrolement.otpauth_uri} size={200} />
          </View>
          <Text style={styles.secretLabel}>Ou saisissez cette clé manuellement :</Text>
          <Text style={styles.secret} selectable>
            {enrolement.secret}
          </Text>
        </Card>

        <Card style={styles.carte}>
          <Text style={styles.aideTitre}>Comment faire</Text>
          <Text style={styles.aide}>
            Ouvrez une application comme Google Authenticator ou Authy, ajoutez un compte en scannant
            le QR, puis saisissez le code à {LONGUEUR_CODE} chiffres qui s'affiche.
          </Text>
        </Card>

        <TextField
          label="Code de vérification"
          value={code}
          onChangeText={(t) => setCode(t.replace(/\D/g, ''))}
          keyboardType="number-pad"
          maxLength={LONGUEUR_CODE}
          placeholder="123456"
          erreur={erreur}
        />

        <PrimaryButton
          label="Activer"
          onPress={confirmer}
          loading={enCours}
          disabled={code.length !== LONGUEUR_CODE}
        />
      </Screen>
    );
  }

  const active = statut?.facteur_confirme === true;

  return (
    <Screen>
      <ScreenHeader
        title="Double authentification"
        subtitle="Un second code à la connexion (2FA)"
        onBack={() => router.back()}
      />

      <Card style={styles.carte}>
        <View style={styles.ligne}>
          <Ionicons
            name={active ? 'shield-checkmark' : 'shield-outline'}
            size={22}
            color={active ? colors.success.solid : colors.ink[500]}
          />
          <View style={styles.ligneTexte}>
            <Text style={styles.titreLigne}>{active ? 'Activée' : 'Inactive'}</Text>
            <Text style={styles.sousLigne}>
              {active
                ? 'À la connexion, un code de votre application d\'authentification est demandé en plus du mot de passe.'
                : 'Ajoutez une seconde barrière : un code temporaire généré par une application, en plus de votre mot de passe.'}
            </Text>
          </View>
        </View>
      </Card>

      {erreur && !enrolement ? <Text style={styles.erreur}>{erreur}</Text> : null}

      <View style={styles.actions}>
        {active ? (
          <SecondaryButton label="Désactiver" onPress={desactiver} disabled={enCours} />
        ) : (
          <>
            <PrimaryButton label="Activer la double authentification" onPress={activer} loading={enCours} />
            <Text style={styles.recommande}>
              Recommandé pour protéger vos données de santé même si votre mot de passe est compromis.
            </Text>
          </>
        )}
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[6] },
  carte: { marginBottom: spacing[3] },
  carteQr: { alignItems: 'center', marginBottom: spacing[3] },
  qrBoite: { padding: spacing[3], backgroundColor: colors.surface, borderRadius: radius.md },
  secretLabel: { ...typography.caption, color: colors.ink[500], marginTop: spacing[4] },
  secret: { ...typography.bodyStrong, color: colors.blue[900], letterSpacing: 2, marginTop: spacing[1], textAlign: 'center' },
  ligne: { flexDirection: 'row', alignItems: 'center', gap: spacing[3] },
  ligneTexte: { flex: 1 },
  titreLigne: { ...typography.bodyStrong, color: colors.blue[900] },
  sousLigne: { ...typography.caption, color: colors.ink[700], marginTop: 2 },
  aideTitre: { ...typography.bodyStrong, color: colors.blue[900], marginBottom: spacing[1] },
  aide: { ...typography.caption, color: colors.ink[700] },
  actions: { marginTop: spacing[4] },
  recommande: { ...typography.caption, color: colors.ink[500], marginTop: spacing[3], textAlign: 'center' },
  erreur: { ...typography.bodyStrong, color: colors.danger.text, marginBottom: spacing[3] },
});
