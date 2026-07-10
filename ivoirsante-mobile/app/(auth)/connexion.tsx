import React, { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../src/components/Screen';
import { Logo } from '../../src/components/Logo';
import { TextField } from '../../src/components/TextField';
import { PrimaryButton } from '../../src/components/PrimaryButton';
import { SecondaryButton } from '../../src/components/SecondaryButton';
import { useSession } from '../../src/auth/SessionContext';
import { login } from '../../src/api/auth';
import { messageErreur } from '../../src/utils/erreurs';
import { colors, spacing, typography } from '../../src/theme/theme';

/** Écran de connexion (téléphone + mot de passe). Point d'entrée déconnecté. */
export default function ConnexionScreen() {
  const { signIn } = useSession();
  const [telephone, setTelephone] = useState('+225');
  const [password, setPassword] = useState('');
  const [erreur, setErreur] = useState<string | null>(null);
  const [chargement, setChargement] = useState(false);

  const seConnecter = async () => {
    setErreur(null);
    setChargement(true);
    try {
      const res = await login({ telephone: telephone.trim(), password });
      await signIn(res.token, res.user);
      // La redirection vers (app) est automatique (Stack.Protected sur la session).
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  };

  return (
    <Screen>
      <View style={styles.entete}>
        <Logo size={84} />
        <Text style={styles.titre}>MaSante</Text>
        <Text style={styles.sous}>Connexion à votre carnet de santé</Text>
      </View>

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
        placeholder="Votre mot de passe"
      />

      <Pressable
        onPress={() => router.push('/(auth)/mot-de-passe-oublie')}
        accessibilityRole="button"
        hitSlop={8}
        style={styles.oublie}
      >
        <Text style={styles.oublieTxt}>Mot de passe oublié ?</Text>
      </Pressable>

      {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}

      <View style={styles.actions}>
        <PrimaryButton label="Se connecter" onPress={seConnecter} loading={chargement} />
        <View style={styles.sep} />
        <SecondaryButton label="Créer un compte" onPress={() => router.push('/(auth)/inscription')} />
      </View>

      {/* FN2 — Accès d'urgence : un secouriste doit atteindre la fiche vitale sans identifiants.
          Volontairement placé hors du bloc d'authentification, et signalé en rouge. */}
      <Pressable
        onPress={() => router.push('/(auth)/carte-vitale')}
        accessibilityRole="button"
        accessibilityLabel="Voir la carte vitale d'urgence, sans connexion"
        style={styles.urgence}
      >
        <Ionicons name="medkit" size={18} color={colors.danger.solid} />
        <Text style={styles.urgenceTxt}>Carte vitale d'urgence</Text>
      </Pressable>
    </Screen>
  );
}

const styles = StyleSheet.create({
  entete: { alignItems: 'center', marginBottom: spacing[8] },
  titre: { ...typography.h1, color: colors.blue[900], marginTop: spacing[3] },
  sous: { ...typography.body, color: colors.ink[700], marginTop: spacing[1], textAlign: 'center' },
  erreur: { ...typography.bodyStrong, color: colors.danger.text, marginBottom: spacing[3] },
  oublie: { alignSelf: 'flex-end', marginTop: -spacing[2], marginBottom: spacing[2], paddingVertical: spacing[1] },
  oublieTxt: { ...typography.bodyStrong, color: colors.blue[600] },
  actions: { marginTop: spacing[4] },
  sep: { height: spacing[3] },
  urgence: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing[2],
    minHeight: 48,
    marginTop: spacing[6],
  },
  urgenceTxt: { ...typography.bodyStrong, color: colors.danger.solid },
});
