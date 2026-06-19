import React, { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { useLocalSearchParams } from 'expo-router';
import { Screen } from '../../src/components/Screen';
import { TextField } from '../../src/components/TextField';
import { PrimaryButton } from '../../src/components/PrimaryButton';
import { SecondaryButton } from '../../src/components/SecondaryButton';
import { useSession } from '../../src/auth/SessionContext';
import { resendOtp, verifyOtp } from '../../src/api/auth';
import { messageErreur } from '../../src/utils/erreurs';
import { colors, spacing, typography } from '../../src/theme/theme';

/** Vérification du code OTP : active le compte et délivre le token (connexion). */
export default function VerificationScreen() {
  const { telephone, devCode } = useLocalSearchParams<{ telephone: string; devCode?: string }>();
  const { signIn } = useSession();
  const [code, setCode] = useState(devCode ?? '');
  const [erreur, setErreur] = useState<string | null>(null);
  const [chargement, setChargement] = useState(false);
  const [info, setInfo] = useState<string | null>(null);

  const verifier = async () => {
    setErreur(null);
    setChargement(true);
    try {
      const res = await verifyOtp({ telephone, code: code.trim() });
      await signIn(res.token, res.user);
      // Redirection automatique vers (app) (Stack.Protected).
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  };

  const renvoyer = async () => {
    setErreur(null);
    setInfo(null);
    try {
      const res = await resendOtp(telephone);
      if (res.dev_code_otp) setCode(res.dev_code_otp);
      setInfo('Un nouveau code a été envoyé.');
    } catch (e) {
      setErreur(messageErreur(e));
    }
  };

  return (
    <Screen>
      <Text style={styles.titre}>Vérification</Text>
      <Text style={styles.sous}>
        Saisissez le code à 6 chiffres envoyé au {telephone}.
        {devCode ? '\n(En mode démo, le code est pré-rempli.)' : ''}
      </Text>

      <TextField
        label="Code de vérification"
        value={code}
        onChangeText={setCode}
        keyboardType="number-pad"
        placeholder="123456"
        maxLength={6}
      />

      {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}
      {info ? <Text style={styles.info}>{info}</Text> : null}

      <View style={styles.actions}>
        <PrimaryButton label="Vérifier et se connecter" onPress={verifier} loading={chargement} />
        <View style={styles.sep} />
        <SecondaryButton label="Renvoyer le code" onPress={renvoyer} />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  titre: { ...typography.h1, color: colors.blue[900], marginBottom: spacing[1] },
  sous: { ...typography.body, color: colors.ink[700], marginBottom: spacing[5] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text, marginBottom: spacing[3] },
  info: { ...typography.bodyStrong, color: colors.success.text, marginBottom: spacing[3] },
  actions: { marginTop: spacing[4] },
  sep: { height: spacing[3] },
});
