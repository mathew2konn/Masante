import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { Screen } from '../../../src/components/Screen';
import { ScreenHeader } from '../../../src/components/ScreenHeader';
import { Card } from '../../../src/components/Card';
import { PrimaryButton } from '../../../src/components/PrimaryButton';
import { SecondaryButton } from '../../../src/components/SecondaryButton';
import { obtenirMembre, supprimerMembre } from '../../../src/api/membres';
import { messageErreur } from '../../../src/utils/erreurs';
import { LIBELLE_CMU_STATUT, type Membre } from '../../../src/types/membre';
import { calculerAge, formatDateFr } from '../../../src/utils/dates';
import { colors, radius, spacing, typography } from '../../../src/theme/theme';

/** Détail d'un membre : informations + actions Modifier / Supprimer (F2.1). */
export default function DetailMembreScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const membreId = Number(id);

  const [membre, setMembre] = useState<Membre | null>(null);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);
  const [suppression, setSuppression] = useState(false);

  // Rechargé à chaque retour sur l'écran (ex. après édition).
  useFocusEffect(
    useCallback(() => {
      let actif = true;
      (async () => {
        setErreur(null);
        try {
          const m = await obtenirMembre(membreId);
          if (actif) setMembre(m);
        } catch (e) {
          if (actif) setErreur(messageErreur(e));
        } finally {
          if (actif) setChargement(false);
        }
      })();
      return () => {
        actif = false;
      };
    }, [membreId]),
  );

  const confirmerSuppression = () => {
    if (!membre) return;
    Alert.alert(
      'Supprimer ce membre ?',
      `Le dossier de ${membre.prenom} ${membre.nom} sera définitivement supprimé.`,
      [
        { text: 'Annuler', style: 'cancel' },
        { text: 'Supprimer', style: 'destructive', onPress: supprimer },
      ],
    );
  };

  const supprimer = async () => {
    setSuppression(true);
    try {
      await supprimerMembre(membreId);
      router.back(); // retour à la liste (rechargée au focus).
    } catch (e) {
      setSuppression(false);
      Alert.alert('Suppression impossible', messageErreur(e));
    }
  };

  if (chargement) {
    return (
      <Screen>
        <ScreenHeader title="Membre" onBack={() => router.back()} />
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      </Screen>
    );
  }

  if (erreur || !membre) {
    return (
      <Screen>
        <ScreenHeader title="Membre" onBack={() => router.back()} />
        <Text style={styles.erreur}>{erreur ?? 'Membre introuvable.'}</Text>
      </Screen>
    );
  }

  const age = calculerAge(membre.date_naissance);
  const initiales = `${membre.prenom?.[0] ?? ''}${membre.nom?.[0] ?? ''}`.toUpperCase();

  return (
    <Screen>
      <ScreenHeader title="Fiche du membre" onBack={() => router.back()} />

      <Card style={styles.entete}>
        <View style={styles.avatar}>
          <Text style={styles.avatarTxt}>{initiales}</Text>
        </View>
        <Text style={styles.nom}>
          {membre.prenom} {membre.nom}
        </Text>
        <Text style={styles.sous}>
          {age !== null ? `${age} ans` : '—'} · {membre.sexe === 'M' ? 'Masculin' : 'Féminin'}
        </Text>
        {membre.groupe_sanguin ? (
          <View style={styles.badge}>
            <Text style={styles.badgeTxt}>Groupe {membre.groupe_sanguin}</Text>
          </View>
        ) : null}
      </Card>

      <Card style={styles.bloc}>
        <Ligne libelle="Date de naissance" valeur={formatDateFr(membre.date_naissance)} />
        <Ligne libelle="Groupe sanguin" valeur={membre.groupe_sanguin ?? 'Non renseigné'} />
      </Card>

      <Card style={styles.bloc}>
        <Text style={styles.blocTitre}>CMU (assurance santé)</Text>
        <Ligne libelle="Numéro" valeur={membre.cmu_numero ?? 'Non renseigné'} />
        <Ligne
          libelle="Statut"
          valeur={membre.cmu_statut ? LIBELLE_CMU_STATUT[membre.cmu_statut] : 'Non renseigné'}
        />
        <Ligne libelle="Validité" valeur={formatDateFr(membre.cmu_validite)} />
      </Card>

      <Card style={styles.bloc}>
        <Text style={styles.blocTitre}>Partage sécurisé</Text>
        <Text style={styles.blocAide}>
          Donnez un accès temporaire au dossier via un QR à usage unique, et consultez qui y a accédé.
        </Text>
        <PrimaryButton
          label="Générer un QR de partage"
          onPress={() =>
            router.push({
              pathname: '/(app)/membres/qr/[id]',
              params: { id: membreId, prenom: membre.prenom, nom: membre.nom },
            })
          }
        />
        <View style={styles.sep} />
        <SecondaryButton
          label="Journal d'accès"
          onPress={() =>
            router.push({
              pathname: '/(app)/membres/acces/[id]',
              params: { id: membreId, prenom: membre.prenom, nom: membre.nom },
            })
          }
        />
      </Card>

      <View style={styles.actions}>
        <PrimaryButton
          label="Modifier"
          onPress={() => router.push({ pathname: '/(app)/membres/modifier/[id]', params: { id: membreId } })}
        />
        <View style={styles.sep} />
        <SecondaryButton label="Supprimer" onPress={confirmerSuppression} disabled={suppression} />
      </View>
    </Screen>
  );
}

/** Ligne « libellé / valeur » réutilisée dans la fiche. */
function Ligne({ libelle, valeur }: { libelle: string; valeur: string }) {
  return (
    <View style={styles.ligne}>
      <Text style={styles.ligneLib}>{libelle}</Text>
      <Text style={styles.ligneVal}>{valeur}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text },
  entete: { alignItems: 'center', marginBottom: spacing[5] },
  avatar: {
    width: 72,
    height: 72,
    borderRadius: radius.pill,
    backgroundColor: colors.blue[100],
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing[3],
  },
  avatarTxt: { ...typography.h1, color: colors.blue[700] },
  nom: { ...typography.h2, color: colors.blue[900] },
  sous: { ...typography.body, color: colors.ink[700], marginTop: spacing[1] },
  badge: {
    marginTop: spacing[3],
    borderRadius: radius.pill,
    backgroundColor: colors.danger.bg,
    paddingHorizontal: spacing[3],
    paddingVertical: spacing[1],
  },
  badgeTxt: { ...typography.caption, fontWeight: '700', color: colors.danger.text },
  bloc: { marginBottom: spacing[5] },
  blocTitre: { ...typography.h2, color: colors.blue[900], marginBottom: spacing[3] },
  blocAide: { ...typography.body, color: colors.ink[700], marginTop: -spacing[1], marginBottom: spacing[4] },
  ligne: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: spacing[2] },
  ligneLib: { ...typography.body, color: colors.ink[500], flexShrink: 0, marginRight: spacing[4] },
  ligneVal: { ...typography.bodyStrong, color: colors.ink[900], flex: 1, textAlign: 'right' },
  actions: { marginTop: spacing[1] },
  sep: { height: spacing[3] },
});
