import React, { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { Screen } from '../../src/components/Screen';
import { TextField } from '../../src/components/TextField';
import { PrimaryButton } from '../../src/components/PrimaryButton';
import { SecondaryButton } from '../../src/components/SecondaryButton';
import { passwordForgot } from '../../src/api/auth';
import { messageErreur } from '../../src/utils/erreurs';
import { colors, spacing, typography } from '../../src/theme/theme';

/**
 * Mot de passe oublié — étape 1 : saisie du téléphone. Le serveur répond de façon identique
 * que le numéro existe ou non (anti-énumération) ; on enchaîne toujours vers l'étape 2.
 */
export default function MotDePasseOublieScreen() {
  const [telephone, setTelephone] = useState('+225');
  const [erreur, setErreur] = useState<string | null>(null);
  const [chargement, setChargement] = useState(false);

  const envoyer = async () => {
    setErreur(null);
    setChargement(true);
    try {
      const res = await passwordForgot(telephone.trim());
      router.push({
        pathname: '/(auth)/reinitialiser',
        params: { telephone: telephone.trim(), devCode: res.dev_code_otp ?? '' },
      });
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  };

  return (
    <Screen>
      <Text style={styles.titre}>Mot de passe oublié</Text>
      <Text style={styles.sous}>
        Saisissez votre numéro de téléphone. Si un compte y est associé, un code de réinitialisation
        vous sera envoyé par SMS.
      </Text>

      <TextField
        label="Téléphone"
        value={telephone}
        onChangeText={setTelephone}
        keyboardType="phone-pad"
        placeholder="+225XXXXXXXXXX"
        maxLength={14}
      />

      {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}

      <View style={styles.actions}>
        <PrimaryButton label="Recevoir le code" onPress={envoyer} loading={chargement} />
        <View style={styles.sep} />
        <SecondaryButton label="Retour à la connexion" onPress={() => router.back()} />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  titre: { ...typography.h1, color: colors.blue[900], marginBottom: spacing[1] },
  sous: { ...typography.body, color: colors.ink[700], marginBottom: spacing[5] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text, marginBottom: spacing[3] },
  actions: { marginTop: spacing[4] },
  sep: { height: spacing[3] },
});
