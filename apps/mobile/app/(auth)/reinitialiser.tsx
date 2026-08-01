import React, { useState } from 'react';
import { Alert, StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { Screen } from '../../src/components/Screen';
import { TextField } from '../../src/components/TextField';
import { DateField } from '../../src/components/DateField';
import { PrimaryButton } from '../../src/components/PrimaryButton';
import { SecondaryButton } from '../../src/components/SecondaryButton';
import { MotDePasseForce } from '../../src/components/MotDePasseForce';
import { motDePasseValide } from '../../src/auth/motDePasse';
import { passwordReset, passwordVerifyOtp } from '../../src/api/auth';
import { messageErreur } from '../../src/utils/erreurs';
import { colors, spacing, typography } from '../../src/theme/theme';

/**
 * Mot de passe oublié — étapes 2 et 3. Phase « preuve » : code OTP + date de naissance →
 * jeton de réinitialisation (gardé en mémoire, jamais passé en paramètre de navigation).
 * Phase « nouveau » : saisie du nouveau mot de passe (barre de force) → réinitialisation.
 */
export default function ReinitialiserScreen() {
  const { telephone, devCode } = useLocalSearchParams<{ telephone: string; devCode?: string }>();

  const [phase, setPhase] = useState<'preuve' | 'nouveau'>('preuve');
  const [code, setCode] = useState(devCode ?? '');
  const [dateNaissance, setDateNaissance] = useState<string | null>(null);
  const [resetToken, setResetToken] = useState<string | null>(null);

  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');

  const [erreur, setErreur] = useState<string | null>(null);
  const [chargement, setChargement] = useState(false);

  const aujourdHui = new Date();
  const min1900 = new Date(1900, 0, 1);

  const verifier = async () => {
    setErreur(null);
    setChargement(true);
    try {
      const res = await passwordVerifyOtp({ telephone, code: code.trim(), date_naissance: dateNaissance });
      setResetToken(res.reset_token);
      setPhase('nouveau');
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  };

  const mdpConforme = motDePasseValide(password);
  const confirmationOk = password.length > 0 && password === confirmation;

  const definir = async () => {
    if (!resetToken) return;
    setErreur(null);
    setChargement(true);
    try {
      await passwordReset({ reset_token: resetToken, password });
      Alert.alert(
        'Mot de passe réinitialisé',
        'Vous pouvez maintenant vous connecter avec votre nouveau mot de passe.',
        [{ text: 'Se connecter', onPress: () => router.replace('/(auth)/connexion') }],
      );
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  };

  return (
    <Screen>
      <Text style={styles.titre}>Réinitialisation</Text>

      {phase === 'preuve' ? (
        <>
          <Text style={styles.sous}>
            Saisissez le code à 6 chiffres envoyé au {telephone}, puis confirmez votre date de
            naissance enregistrée (pour votre sécurité).
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
          <DateField
            label="Date de naissance"
            value={dateNaissance}
            onChange={setDateNaissance}
            min={min1900}
            max={aujourdHui}
            placeholder="JJ/MM/AAAA"
          />

          {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}

          <View style={styles.actions}>
            <PrimaryButton label="Vérifier" onPress={verifier} loading={chargement} disabled={code.trim().length < 6} />
            <View style={styles.sep} />
            <SecondaryButton label="Retour" onPress={() => router.back()} />
          </View>
        </>
      ) : (
        <>
          <Text style={styles.sous}>Choisissez un nouveau mot de passe robuste.</Text>

          <TextField
            label="Nouveau mot de passe"
            value={password}
            onChangeText={setPassword}
            secureTextEntry
            placeholder="Votre nouveau mot de passe"
          />
          <MotDePasseForce valeur={password} />
          <TextField
            label="Confirmer le mot de passe"
            value={confirmation}
            onChangeText={setConfirmation}
            secureTextEntry
            placeholder="Retapez le mot de passe"
            erreur={confirmation.length > 0 && !confirmationOk ? 'Les mots de passe ne correspondent pas.' : null}
          />

          {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}

          <View style={styles.actions}>
            <PrimaryButton
              label="Définir le mot de passe"
              onPress={definir}
              loading={chargement}
              disabled={!mdpConforme || !confirmationOk}
            />
          </View>
        </>
      )}
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
