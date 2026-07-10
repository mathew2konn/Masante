import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Alert, StyleSheet, Switch, Text, View } from 'react-native';
import { router } from 'expo-router';
import { Screen } from '../../../src/components/Screen';
import { Card } from '../../../src/components/Card';
import { ScreenHeader } from '../../../src/components/ScreenHeader';
import { SecondaryButton } from '../../../src/components/SecondaryButton';
import { listerMembres } from '../../../src/api/membres';
import { activer, desactiver, membresActives, rafraichir } from '../../../src/urgence/carteVitale';
import { messageErreur } from '../../../src/utils/erreurs';
import type { Membre } from '../../../src/types/membre';
import { colors, spacing, typography } from '../../../src/theme/theme';

/**
 * Gestion de la carte vitale d'urgence (FN2) — écran du TITULAIRE, sous session.
 *
 * Rien n'est exposé par défaut : le titulaire active membre par membre. Activer télécharge la fiche
 * vitale et la met en cache chiffré ; elle devient alors lisible depuis l'écran de connexion, sans
 * mot de passe — c'est tout l'objet de la fonctionnalité, et il faut que l'utilisateur le comprenne
 * avant de basculer l'interrupteur.
 */
export default function GestionCarteVitale() {
  const [membres, setMembres] = useState<Membre[]>([]);
  const [actives, setActives] = useState<number[]>([]);
  const [chargement, setChargement] = useState(true);
  const [enCours, setEnCours] = useState<number | null>(null);

  const charger = useCallback(async () => {
    try {
      const [liste, ids] = await Promise.all([listerMembres(), membresActives()]);
      setMembres(liste);
      setActives(ids);
    } catch (e) {
      Alert.alert('Erreur', messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, []);

  useEffect(() => {
    void charger();
  }, [charger]);

  const basculer = async (membre: Membre, valeur: boolean) => {
    setEnCours(membre.id);
    try {
      if (valeur) {
        await activer(membre.id);
        setActives((a) => [...a, membre.id]);
      } else {
        await desactiver(membre.id);
        setActives((a) => a.filter((id) => id !== membre.id));
      }
    } catch (e) {
      Alert.alert('Erreur', messageErreur(e));
    } finally {
      setEnCours(null);
    }
  };

  const mettreAJour = async () => {
    setEnCours(-1);
    try {
      const n = await rafraichir();
      Alert.alert('Cartes à jour', `${n} carte(s) vitale(s) mise(s) à jour depuis votre carnet.`);
    } catch (e) {
      Alert.alert('Erreur', messageErreur(e));
    } finally {
      setEnCours(null);
    }
  };

  return (
    <Screen>
      <ScreenHeader
        title="Carte vitale d'urgence"
        subtitle="Consultable sans connexion, en cas d'accident"
        onBack={() => router.back()}
      />

      <Card style={styles.bloc}>
        <Text style={styles.corps}>
          La carte vitale affiche le groupe sanguin, les allergies, les maladies chroniques et les
          contacts d'urgence d'un membre.
        </Text>
        <Text style={styles.avertissement}>
          Elle est lisible depuis l'écran de connexion, <Text style={styles.gras}>sans mot de passe</Text>,
          pour qu'un secouriste puisse agir. Le reste du carnet demeure protégé.
        </Text>
      </Card>

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.centre} />
      ) : (
        <Card style={styles.bloc}>
          {membres.map((m, i) => (
            <View key={m.id} style={[styles.ligne, i > 0 && styles.separateur]}>
              <View style={styles.identite}>
                <Text style={styles.nom}>
                  {m.prenom} {m.nom}
                </Text>
                <Text style={styles.muted}>
                  {actives.includes(m.id) ? 'Visible en cas d\'urgence' : 'Non exposée'}
                </Text>
              </View>
              {enCours === m.id ? (
                <ActivityIndicator color={colors.blue[600]} />
              ) : (
                <Switch
                  value={actives.includes(m.id)}
                  onValueChange={(v) => void basculer(m, v)}
                  trackColor={{ true: colors.success.solid, false: colors.disabled }}
                  accessibilityLabel={`Carte vitale de ${m.prenom}`}
                />
              )}
            </View>
          ))}
          {membres.length === 0 && <Text style={styles.muted}>Aucun membre dans votre carnet.</Text>}
        </Card>
      )}

      {actives.length > 0 && (
        <View style={styles.actions}>
          <SecondaryButton
            label="Mettre à jour depuis mon carnet"
            onPress={() => void mettreAJour()}
            disabled={enCours !== null}
          />
          <View style={styles.sep} />
          <SecondaryButton label="Aperçu de la carte" onPress={() => router.push('/(app)/parametres/apercu-carte-vitale')} />
        </View>
      )}

      <Text style={styles.pied}>
        Les fiches sont stockées chiffrées sur cet appareil (Keychain / Keystore). Désactiver un membre
        efface immédiatement sa fiche du téléphone.
      </Text>
    </Screen>
  );
}

const styles = StyleSheet.create({
  bloc: { marginBottom: spacing[4], gap: spacing[2] },
  centre: { marginTop: spacing[6] },
  corps: { ...typography.body, color: colors.ink[900] },
  avertissement: { ...typography.body, color: colors.warning.text },
  gras: { ...typography.bodyStrong, color: colors.warning.text },
  ligne: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: spacing[3] },
  separateur: { borderTopWidth: 1, borderTopColor: colors.line },
  identite: { flex: 1, gap: 2 },
  nom: { ...typography.bodyStrong, color: colors.ink[900] },
  muted: { ...typography.caption, color: colors.ink[500] },
  actions: { marginTop: spacing[2] },
  sep: { height: spacing[3] },
  pied: { ...typography.caption, color: colors.ink[500], marginTop: spacing[4], textAlign: 'center' },
});
