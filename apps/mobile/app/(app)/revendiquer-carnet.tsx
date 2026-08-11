import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../src/components/Screen';
import { ScreenHeader } from '../../src/components/ScreenHeader';
import { Card } from '../../src/components/Card';
import { PrimaryButton } from '../../src/components/PrimaryButton';
import { SecondaryButton } from '../../src/components/SecondaryButton';
import { listerCarnetsRevendicables, revendiquerCarnet } from '../../src/api/revendication';
import { messageErreur } from '../../src/utils/erreurs';
import { calculerAge } from '../../src/utils/dates';
import type { CarnetRevendicable } from '../../src/types/delegation';
import { colors, spacing, typography } from '../../src/theme/theme';

/**
 * Reconnaître son carnet (carnet familial partagé, incrément B).
 *
 * CET ÉCRAN PASSE AVANT LA CRÉATION, JAMAIS APRÈS. Une fois le dossier titulaire créé, la personne
 * a deux carnets et deux numéros nationaux — et un NIS ne se libère jamais (P6.1). L'ordre n'est
 * pas une préférence d'ergonomie : c'est ce qui empêche le doublon d'exister.
 *
 * FRONTIÈRE : la décision « ce carnet est-il revendicable » appartient au backend, qui exige
 * l'assertion du responsable au moment du partage. L'écran affiche et confirme.
 */
export default function RevendiquerCarnetScreen() {
  const [carnets, setCarnets] = useState<CarnetRevendicable[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);
  const [enCours, setEnCours] = useState<number | null>(null);

  useFocusEffect(
    useCallback(() => {
      let actif = true;
      (async () => {
        setErreur(null);
        try {
          const liste = await listerCarnetsRevendicables();
          if (actif) setCarnets(liste);
        } catch (e) {
          if (actif) setErreur(messageErreur(e));
        } finally {
          if (actif) setChargement(false);
        }
      })();
      return () => {
        actif = false;
      };
    }, []),
  );

  const confirmer = (c: CarnetRevendicable) => {
    const proche = `${c.propose_par.prenom ?? ''} ${c.propose_par.nom ?? ''}`.trim();

    Alert.alert(
      'Ce carnet est bien le vôtre ?',
      `Il deviendra votre dossier de santé personnel, avec son historique et son numéro national.\n\n${proche} pourra continuer à le consulter — vous pourrez lui retirer cet accès à tout moment.`,
      [
        { text: 'Annuler', style: 'cancel' },
        { text: "Oui, c'est le mien", onPress: () => void revendiquer(c) },
      ],
    );
  };

  const revendiquer = async (c: CarnetRevendicable) => {
    setEnCours(c.membre.id);
    setErreur(null);
    try {
      await revendiquerCarnet(c.membre.id);
      router.replace('/(app)/carnet');
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setEnCours(null);
    }
  };

  return (
    <Screen>
      <ScreenHeader
        title="Est-ce votre carnet ?"
        subtitle="Un proche a créé un dossier à votre nom"
        onBack={() => router.back()}
      />

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      ) : carnets.length === 0 ? (
        <Card style={styles.vide}>
          <Ionicons name="document-outline" size={28} color={colors.blue[400]} />
          <Text style={styles.videTxt}>Aucun carnet à reconnaître.</Text>
          <Text style={styles.videSous}>
            Vous pouvez créer votre dossier de santé personnel.
          </Text>
        </Card>
      ) : (
        <>
          <Text style={styles.intro}>
            Reconnaître ce carnet évite de créer un <Text style={styles.gras}>second dossier</Text>{' '}
            à votre nom. Vous en devenez propriétaire, avec tout son historique.
          </Text>

          {carnets.map((c) => {
            const age = calculerAge(c.membre.date_naissance);
            const occupe = enCours === c.membre.id;

            return (
              <Card key={c.delegation_id} style={styles.item}>
                <Text style={styles.itemNom}>
                  {c.membre.prenom} {c.membre.nom}
                </Text>
                <Text style={styles.itemSous}>
                  {age !== null ? `${age} ans` : '—'} ·{' '}
                  {c.membre.sexe === 'M' ? 'Masculin' : 'Féminin'}
                  {c.membre.groupe_sanguin ? ` · ${c.membre.groupe_sanguin}` : ''}
                </Text>
                <Text style={styles.itemOrigine}>
                  Créé par {c.propose_par.prenom} {c.propose_par.nom}
                </Text>
                <PrimaryButton
                  label="C'est mon carnet"
                  onPress={() => confirmer(c)}
                  loading={occupe}
                  disabled={occupe}
                />
              </Card>
            );
          })}
        </>
      )}

      {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}

      <View style={styles.alternative}>
        <Text style={styles.alternativeTxt}>
          {carnets.length === 0
            ? ' '
            : "Aucun de ces carnets n'est le vôtre ? Créez le vôtre — les autres resteront ceux de leur propriétaire."}
        </Text>
        <SecondaryButton
          label="Créer mon dossier de santé"
          onPress={() => router.replace('/(app)/profil-titulaire')}
        />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[5] },
  intro: { ...typography.body, color: colors.ink[700], marginBottom: spacing[4] },
  gras: { fontWeight: '700' },
  vide: { alignItems: 'center', marginBottom: spacing[4] },
  videTxt: { ...typography.bodyStrong, color: colors.ink[700], marginTop: spacing[2] },
  videSous: { ...typography.caption, color: colors.ink[500], textAlign: 'center', marginTop: spacing[1] },
  item: { marginBottom: spacing[3], gap: spacing[1] },
  itemNom: { ...typography.h2, color: colors.blue[900] },
  itemSous: { ...typography.caption, color: colors.ink[700] },
  itemOrigine: { ...typography.caption, color: colors.blue[700], marginBottom: spacing[3] },
  erreur: { ...typography.body, color: colors.danger.text, marginBottom: spacing[3] },
  alternative: { marginTop: spacing[4] },
  alternativeTxt: {
    ...typography.caption,
    color: colors.ink[500],
    marginBottom: spacing[2],
  },
});
