import React, { useState } from 'react';
import { Alert, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { Screen } from '../../../src/components/Screen';
import { ScreenHeader } from '../../../src/components/ScreenHeader';
import { TextField } from '../../../src/components/TextField';
import { PrimaryButton } from '../../../src/components/PrimaryButton';
import { MotDePasseForce } from '../../../src/components/MotDePasseForce';
import { motDePasseValide } from '../../../src/auth/motDePasse';
import { passwordChange } from '../../../src/api/auth';
import { messageErreur } from '../../../src/utils/erreurs';
import { colors, spacing, typography } from '../../../src/theme/theme';

/**
 * Changer mon mot de passe (utilisateur connecté). L'ancien mot de passe fait office de preuve
 * (pas d'OTP). Côté serveur, les AUTRES sessions sont révoquées ; la session courante est conservée.
 */
export default function ChangerMotDePasseScreen() {
  const [ancien, setAncien] = useState('');
  const [nouveau, setNouveau] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [erreur, setErreur] = useState<string | null>(null);
  const [chargement, setChargement] = useState(false);

  const mdpConforme = motDePasseValide(nouveau);
  const confirmationOk = nouveau.length > 0 && nouveau === confirmation;
  const differentAncien = nouveau.length > 0 && nouveau !== ancien;

  const enregistrer = async () => {
    setErreur(null);
    setChargement(true);
    try {
      await passwordChange({ current_password: ancien, password: nouveau });
      Alert.alert('Mot de passe modifié', 'Vos autres sessions ont été déconnectées par sécurité.', [
        { text: 'OK', onPress: () => router.back() },
      ]);
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  };

  return (
    <Screen>
      <ScreenHeader
        title="Mot de passe"
        subtitle="Modifier le mot de passe de votre compte"
        onBack={() => router.back()}
      />

      <TextField
        label="Mot de passe actuel"
        value={ancien}
        onChangeText={setAncien}
        secureTextEntry
        placeholder="Votre mot de passe actuel"
      />
      <TextField
        label="Nouveau mot de passe"
        value={nouveau}
        onChangeText={setNouveau}
        secureTextEntry
        placeholder="Votre nouveau mot de passe"
      />
      <MotDePasseForce valeur={nouveau} />
      <TextField
        label="Confirmer le nouveau mot de passe"
        value={confirmation}
        onChangeText={setConfirmation}
        secureTextEntry
        placeholder="Retapez le nouveau mot de passe"
        erreur={confirmation.length > 0 && !confirmationOk ? 'Les mots de passe ne correspondent pas.' : null}
      />

      {nouveau.length > 0 && !differentAncien ? (
        <Text style={styles.avert}>Le nouveau mot de passe doit être différent de l'actuel.</Text>
      ) : null}
      {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}

      <View style={styles.actions}>
        <PrimaryButton
          label="Enregistrer"
          onPress={enregistrer}
          loading={chargement}
          disabled={ancien.length === 0 || !mdpConforme || !confirmationOk || !differentAncien}
        />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  avert: { ...typography.caption, color: colors.warning.text, marginBottom: spacing[3] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text, marginBottom: spacing[3] },
  actions: { marginTop: spacing[4] },
});
