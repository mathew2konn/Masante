import React, { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { Screen } from '../../src/components/Screen';
import { TextField } from '../../src/components/TextField';
import { PrimaryButton } from '../../src/components/PrimaryButton';
import { SecondaryButton } from '../../src/components/SecondaryButton';
import { register } from '../../src/api/auth';
import { MotDePasseForce } from '../../src/components/MotDePasseForce';
import { motDePasseValide } from '../../src/auth/motDePasse';
import { messageErreur } from '../../src/utils/erreurs';
import { colors, spacing, typography } from '../../src/theme/theme';

/** Inscription : crée le compte puis dirige vers la vérification OTP. */
export default function InscriptionScreen() {
  const [prenom, setPrenom] = useState('');
  const [nom, setNom] = useState('');
  const [telephone, setTelephone] = useState('+225');
  const [password, setPassword] = useState('');
  const [erreur, setErreur] = useState<string | null>(null);
  const [chargement, setChargement] = useState(false);

  const sInscrire = async () => {
    setErreur(null);
    setChargement(true);
    try {
      const res = await register({ telephone: telephone.trim(), nom: nom.trim(), prenom: prenom.trim(), password });
      // En dev, le code OTP est renvoyé : on le pré-remplit sur l'écran suivant.
      router.push({
        pathname: '/(auth)/verification',
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
      <Text style={styles.titre}>Créer un compte</Text>
      <Text style={styles.sous}>Un code de vérification vous sera envoyé par SMS.</Text>

      <TextField label="Prénom" value={prenom} onChangeText={setPrenom} autoCapitalize="words" placeholder="Awa" />
      <TextField label="Nom" value={nom} onChangeText={setNom} autoCapitalize="words" placeholder="Kouadio" />
      <TextField
        label="Téléphone"
        value={telephone}
        onChangeText={setTelephone}
        keyboardType="phone-pad"
        placeholder="+225XXXXXXXXXX"
        maxLength={14}
      />
      <TextField
        label="Mot de passe"
        value={password}
        onChangeText={setPassword}
        secureTextEntry
        placeholder="Choisissez un mot de passe robuste"
      />
      <MotDePasseForce valeur={password} />

      {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}

      <View style={styles.actions}>
        <PrimaryButton
          label="Continuer"
          onPress={sInscrire}
          loading={chargement}
          disabled={!prenom.trim() || !nom.trim() || !motDePasseValide(password)}
        />
        <View style={styles.sep} />
        <SecondaryButton label="J'ai déjà un compte" onPress={() => router.back()} />
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
