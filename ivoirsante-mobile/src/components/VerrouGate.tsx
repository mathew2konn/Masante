import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from './Screen';
import { SaisiePin } from './SaisiePin';
import { SecondaryButton } from './SecondaryButton';
import { useVerrou } from '../auth/VerrouContext';
import { useSession } from '../auth/SessionContext';
import { authentifierBiometrie, etatBlocage, PIN_LONGUEUR, verifierPin } from '../auth/verrou';
import { colors, spacing, typography } from '../theme/theme';

/**
 * VerrouGate — enveloppe une zone sensible. Si le verrou est actif et la période de grâce expirée,
 * affiche l'écran de déverrouillage à la place du contenu ; sinon rend les enfants. La navigation
 * active prolonge la grâce (toucherSiOuvert au focus).
 */
export function VerrouGate({ children }: { children: React.ReactNode }) {
  const { config, pretConfig, estOuvert, ouvrir, toucherSiOuvert } = useVerrou();

  useFocusEffect(
    useCallback(() => {
      toucherSiOuvert();
    }, [toucherSiOuvert]),
  );

  if (!pretConfig) {
    return (
      <Screen>
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      </Screen>
    );
  }

  if (!config.actif || estOuvert()) {
    return <>{children}</>;
  }

  return <EcranVerrou onSucces={ouvrir} />;
}

/** Écran de déverrouillage : biométrie (si activée) + repli PIN, avec blocage anti-force brute. */
function EcranVerrou({ onSucces }: { onSucces: () => void }) {
  const { config } = useVerrou();
  const { signOut } = useSession();

  const [pin, setPin] = useState('');
  const [erreur, setErreur] = useState<string | null>(null);
  const [secondesBlocage, setSecondesBlocage] = useState(0);
  const biometrieTentee = useRef(false);

  const lancerBiometrie = useCallback(async () => {
    try {
      if (await authentifierBiometrie()) onSucces();
    } catch {
      /* l'utilisateur peut se rabattre sur le PIN */
    }
  }, [onSucces]);

  // Propose la biométrie automatiquement à l'ouverture (une seule fois).
  useEffect(() => {
    if (config.biometrie && config.biometrieDispo && !biometrieTentee.current) {
      biometrieTentee.current = true;
      lancerBiometrie();
    }
  }, [config.biometrie, config.biometrieDispo, lancerBiometrie]);

  // Décompte du blocage progressif.
  useEffect(() => {
    if (secondesBlocage <= 0) return;
    const t = setInterval(() => setSecondesBlocage((s) => (s <= 1 ? 0 : s - 1)), 1000);
    return () => clearInterval(t);
  }, [secondesBlocage]);

  // Vérifie dès que 6 chiffres sont saisis.
  useEffect(() => {
    if (pin.length !== PIN_LONGUEUR) return;
    (async () => {
      if (await verifierPin(pin)) {
        onSucces();
        return;
      }
      const { bloque, secondes } = await etatBlocage();
      setPin('');
      if (bloque) {
        setSecondesBlocage(secondes);
        setErreur(`Trop de tentatives. Réessayez dans ${secondes} s.`);
      } else {
        setErreur('Code incorrect. Réessayez.');
      }
    })();
  }, [pin, onSucces]);

  const oubliPin = () => {
    Alert.alert(
      'Code PIN oublié',
      'Pour votre sécurité, vous devez vous reconnecter avec votre téléphone et votre mot de passe.',
      [
        { text: 'Annuler', style: 'cancel' },
        { text: 'Se déconnecter', style: 'destructive', onPress: () => signOut() },
      ],
    );
  };

  const bloque = secondesBlocage > 0;

  return (
    <Screen>
      {router.canGoBack() ? (
        <Pressable onPress={() => router.back()} accessibilityRole="button" accessibilityLabel="Retour" hitSlop={8} style={styles.retour}>
          <Ionicons name="chevron-back" size={24} color={colors.ink[700]} />
        </Pressable>
      ) : null}

      <View style={styles.entete}>
        <View style={styles.rond}>
          <Ionicons name="lock-closed" size={30} color={colors.blue[700]} />
        </View>
        <Text style={styles.titre}>Carnet verrouillé</Text>
        <Text style={styles.sous}>Saisissez votre code PIN pour accéder à vos données de santé.</Text>
      </View>

      <SaisiePin valeur={pin} onChange={(v) => { setErreur(null); setPin(v); }} erreur={!!erreur} editable={!bloque} />

      {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}
      {bloque ? <Text style={styles.blocage}>Nouvel essai possible dans {secondesBlocage} s.</Text> : null}

      <View style={styles.actions}>
        {config.biometrie && config.biometrieDispo ? (
          <SecondaryButton label="Utiliser la biométrie" onPress={lancerBiometrie} />
        ) : null}
        <View style={styles.sep} />
        <SecondaryButton label="Code PIN oublié ?" onPress={oubliPin} />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[8] },
  retour: { alignSelf: 'flex-start', padding: spacing[1] },
  entete: { alignItems: 'center', marginTop: spacing[6], marginBottom: spacing[5] },
  rond: {
    width: 64,
    height: 64,
    borderRadius: 32,
    backgroundColor: colors.blue[100],
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing[3],
  },
  titre: { ...typography.h1, color: colors.blue[900] },
  sous: { ...typography.body, color: colors.ink[700], textAlign: 'center', marginTop: spacing[1] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text, textAlign: 'center', marginTop: spacing[2] },
  blocage: { ...typography.caption, color: colors.ink[500], textAlign: 'center', marginTop: spacing[1] },
  actions: { marginTop: spacing[6] },
  sep: { height: spacing[3] },
});
