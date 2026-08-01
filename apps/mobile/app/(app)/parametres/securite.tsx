import React, { useEffect, useState } from 'react';
import { Alert, StyleSheet, Switch, Text, View } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../../src/components/Screen';
import { ScreenHeader } from '../../../src/components/ScreenHeader';
import { Card } from '../../../src/components/Card';
import { PrimaryButton } from '../../../src/components/PrimaryButton';
import { SecondaryButton } from '../../../src/components/SecondaryButton';
import { SaisiePin } from '../../../src/components/SaisiePin';
import { useVerrou } from '../../../src/auth/VerrouContext';
import {
  activerVerrou,
  definirBiometrie,
  definirPin,
  desactiverVerrou,
  PIN_LONGUEUR,
} from '../../../src/auth/verrou';
import { colors, spacing, typography } from '../../../src/theme/theme';

/**
 * Sécurité — verrou applicatif (note Securite_2, chap. 3). Opt-in : l'utilisateur active le verrou,
 * définit un PIN à 6 chiffres, et choisit d'utiliser la biométrie si l'appareil la propose.
 */
export default function SecuriteScreen() {
  const { config, rafraichirConfig } = useVerrou();
  const [mode, setMode] = useState<'apercu' | 'definir'>('apercu');
  const [enCours, setEnCours] = useState(false);

  // Définition / changement du PIN : saisie puis confirmation.
  const [etape, setEtape] = useState<'saisie' | 'confirme'>('saisie');
  const [pin1, setPin1] = useState('');
  const [pin2, setPin2] = useState('');
  const [erreur, setErreur] = useState<string | null>(null);
  const activation = !config.actif; // définit-on le PIN dans le cadre d'une première activation ?

  const ouvrirDefinition = () => {
    setPin1('');
    setPin2('');
    setEtape('saisie');
    setErreur(null);
    setMode('definir');
  };

  // Enchaînement saisie -> confirmation -> enregistrement.
  useEffect(() => {
    if (mode !== 'definir') return;
    if (etape === 'saisie' && pin1.length === PIN_LONGUEUR) {
      setEtape('confirme');
    }
  }, [mode, etape, pin1]);

  useEffect(() => {
    if (mode !== 'definir' || etape !== 'confirme' || pin2.length !== PIN_LONGUEUR) return;
    (async () => {
      if (pin2 !== pin1) {
        setErreur('Les deux codes ne correspondent pas.');
        setPin1('');
        setPin2('');
        setEtape('saisie');
        return;
      }
      setEnCours(true);
      try {
        await definirPin(pin1);
        if (activation) await activerVerrou(false);
        await rafraichirConfig();
        setMode('apercu');
        Alert.alert('Verrou activé', 'Votre carnet est maintenant protégé par un code PIN.');
      } finally {
        setEnCours(false);
      }
    })();
  }, [mode, etape, pin2, pin1, activation, rafraichirConfig]);

  const basculerBiometrie = async (valeur: boolean) => {
    await definirBiometrie(valeur);
    await rafraichirConfig();
  };

  const desactiver = () => {
    Alert.alert('Désactiver le verrou', 'Vos sections sensibles ne seront plus protégées par un code PIN.', [
      { text: 'Annuler', style: 'cancel' },
      {
        text: 'Désactiver',
        style: 'destructive',
        onPress: async () => {
          await desactiverVerrou();
          await rafraichirConfig();
        },
      },
    ]);
  };

  if (mode === 'definir') {
    return (
      <Screen>
        <ScreenHeader
          title={activation ? 'Créer un code PIN' : 'Changer le code PIN'}
          subtitle={etape === 'saisie' ? 'Choisissez un code à 6 chiffres' : 'Confirmez votre code'}
          onBack={() => setMode('apercu')}
        />
        {etape === 'saisie' ? (
          <SaisiePin valeur={pin1} onChange={(v) => { setErreur(null); setPin1(v); }} />
        ) : (
          <SaisiePin valeur={pin2} onChange={(v) => { setErreur(null); setPin2(v); }} erreur={!!erreur} />
        )}
        {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}
        {enCours ? <Text style={styles.info}>Enregistrement…</Text> : null}
      </Screen>
    );
  }

  return (
    <Screen>
      <ScreenHeader title="Sécurité" subtitle="Verrou de l'application" onBack={() => router.back()} />

      <Card style={styles.carte}>
        <View style={styles.ligne}>
          <Ionicons
            name={config.actif ? 'lock-closed' : 'lock-open-outline'}
            size={22}
            color={config.actif ? colors.success.solid : colors.ink[500]}
          />
          <View style={styles.ligneTexte}>
            <Text style={styles.titreLigne}>Verrou applicatif</Text>
            <Text style={styles.sousLigne}>
              {config.actif
                ? 'Actif — un code PIN protège vos fiches membres et vos rendez-vous.'
                : 'Inactif — vos sections sensibles ne sont pas protégées par un second code.'}
            </Text>
          </View>
        </View>
      </Card>

      {config.actif ? (
        <>
          <Card style={styles.carte}>
            <View style={styles.ligne}>
              <Ionicons name="finger-print" size={22} color={colors.ink[700]} />
              <View style={styles.ligneTexte}>
                <Text style={styles.titreLigne}>Déverrouillage biométrique</Text>
                <Text style={styles.sousLigne}>
                  {config.biometrieDispo
                    ? 'Empreinte ou reconnaissance faciale, avec repli sur le PIN.'
                    : 'Indisponible sur cet appareil (aucune biométrie enrôlée).'}
                </Text>
              </View>
              <Switch
                value={config.biometrie}
                onValueChange={basculerBiometrie}
                disabled={!config.biometrieDispo}
                trackColor={{ true: colors.blue[600] }}
              />
            </View>
          </Card>

          <View style={styles.actions}>
            <SecondaryButton label="Changer le code PIN" onPress={ouvrirDefinition} />
            <View style={styles.sep} />
            <SecondaryButton label="Désactiver le verrou" onPress={desactiver} />
          </View>
        </>
      ) : (
        <View style={styles.actions}>
          <PrimaryButton label="Activer le verrou" onPress={ouvrirDefinition} loading={enCours} />
          <Text style={styles.aide}>
            Recommandé : une seconde barrière protège vos données de santé même si votre téléphone
            est déverrouillé et laissé sans surveillance.
          </Text>
        </View>
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  carte: { marginBottom: spacing[3] },
  ligne: { flexDirection: 'row', alignItems: 'center', gap: spacing[3] },
  ligneTexte: { flex: 1 },
  titreLigne: { ...typography.bodyStrong, color: colors.blue[900] },
  sousLigne: { ...typography.caption, color: colors.ink[700], marginTop: 2 },
  actions: { marginTop: spacing[4] },
  sep: { height: spacing[3] },
  aide: { ...typography.caption, color: colors.ink[500], marginTop: spacing[3], textAlign: 'center' },
  erreur: { ...typography.bodyStrong, color: colors.danger.text, textAlign: 'center', marginTop: spacing[2] },
  info: { ...typography.caption, color: colors.ink[500], textAlign: 'center', marginTop: spacing[3] },
});
