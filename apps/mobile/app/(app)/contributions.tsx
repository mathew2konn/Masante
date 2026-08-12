import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../src/components/Screen';
import { ScreenHeader } from '../../src/components/ScreenHeader';
import { Card } from '../../src/components/Card';
import { PrimaryButton } from '../../src/components/PrimaryButton';
import { SecondaryButton } from '../../src/components/SecondaryButton';
import {
  contributionsEnAttente,
  rejeterContribution,
  validerContribution,
} from '../../src/api/contributions';
import { sectionParSlug } from '../../src/carnet/registre';
import { messageErreur } from '../../src/utils/erreurs';
import type { Contribution } from '../../src/types/contribution';
import { colors, spacing, typography } from '../../src/theme/theme';

/**
 * File du responsable (carnet familial partagé, incrément C).
 *
 * LE SCÉNARIO : la personne restée à la maison a emmené un enfant à l'hôpital et noté ce qui
 * s'est passé. Ici, un responsable relit, vérifie — en appelant l'auteur si besoin — puis valide
 * ou rejette. Ce n'est qu'à la validation que l'ajout entre au dossier.
 *
 * FRONTIÈRE : qui peut décider, et ce que devient une contribution validée, sont décidés par le
 * serveur. Cet écran affiche, demande confirmation, et rapporte la réponse.
 */
export default function ContributionsScreen() {
  const [contributions, setContributions] = useState<Contribution[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);
  const [enCours, setEnCours] = useState<number | null>(null);

  const charger = useCallback(async () => {
    setErreur(null);
    try {
      setContributions(await contributionsEnAttente());
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      void charger();
    }, [charger]),
  );

  const traiter = async (id: number, action: () => Promise<unknown>) => {
    setEnCours(id);
    try {
      await action();
      await charger();
    } catch (e) {
      Alert.alert('Impossible', messageErreur(e));
    } finally {
      setEnCours(null);
    }
  };

  const confirmerValidation = (c: Contribution) => {
    const auteur = `${c.auteur?.prenom ?? ''} ${c.auteur?.nom ?? ''}`.trim() || 'un proche';

    Alert.alert(
      'Valider cet ajout ?',
      `Il entrera définitivement au carnet de ${c.membre?.prenom ?? ''} ${c.membre?.nom ?? ''}.\n\nVérifiez auprès de ${auteur} que la consultation a bien eu lieu.`,
      [
        { text: 'Annuler', style: 'cancel' },
        { text: 'Valider', onPress: () => void traiter(c.id, () => validerContribution(c.id)) },
      ],
    );
  };

  const confirmerRejet = (c: Contribution) => {
    Alert.alert('Rejeter cet ajout ?', "Rien ne sera écrit au carnet. L'ajout restera consultable.", [
      { text: 'Annuler', style: 'cancel' },
      {
        text: 'Rejeter',
        style: 'destructive',
        onPress: () => void traiter(c.id, () => rejeterContribution(c.id)),
      },
    ]);
  };

  return (
    <Screen>
      <ScreenHeader
        title="Ajouts à valider"
        subtitle="Proposés par vos proches"
        onBack={() => router.back()}
      />

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      ) : erreur ? (
        <Text style={styles.erreur}>{erreur}</Text>
      ) : contributions.length === 0 ? (
        <Card style={styles.vide}>
          <Ionicons name="checkmark-done-outline" size={28} color={colors.blue[400]} />
          <Text style={styles.videTxt}>Aucun ajout en attente.</Text>
          <Text style={styles.videSous}>
            Quand un proche note quelque chose dans l&apos;un de vos carnets, cela apparaît ici.
          </Text>
        </Card>
      ) : (
        contributions.map((c) => {
          const section = sectionParSlug(c.section);
          const occupe = enCours === c.id;

          return (
            <Card key={c.id} style={styles.item}>
              <Text style={styles.itemMembre}>
                {c.membre?.prenom} {c.membre?.nom}
              </Text>
              <Text style={styles.itemSection}>{section?.titre ?? c.section}</Text>
              <Text style={styles.itemAuteur}>
                Proposé par {c.auteur?.prenom} {c.auteur?.nom}
              </Text>

              <View style={styles.donnees}>
                {Object.entries(c.donnees)
                  // `source` et `added_by` sont imposés par le serveur : les afficher n'apprend rien.
                  .filter(([cle, v]) => !['source', 'added_by'].includes(cle) && v !== null && v !== '')
                  .map(([cle, valeur]) => (
                    <Text key={cle} style={styles.ligne}>
                      <Text style={styles.cle}>{cle.replace(/_/g, ' ')} : </Text>
                      {typeof valeur === 'object' ? JSON.stringify(valeur) : String(valeur)}
                    </Text>
                  ))}
              </View>

              <PrimaryButton
                label="Valider"
                onPress={() => confirmerValidation(c)}
                loading={occupe}
                disabled={occupe}
              />
              <View style={styles.sep} />
              <SecondaryButton label="Rejeter" onPress={() => confirmerRejet(c)} disabled={occupe} />
            </Card>
          );
        })
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[5] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text },
  vide: { alignItems: 'center' },
  videTxt: { ...typography.bodyStrong, color: colors.ink[700], marginTop: spacing[2] },
  videSous: { ...typography.caption, color: colors.ink[500], textAlign: 'center', marginTop: spacing[1] },
  item: { marginBottom: spacing[3] },
  itemMembre: { ...typography.h2, color: colors.blue[900] },
  itemSection: { ...typography.bodyStrong, color: colors.ink[700], marginTop: 2 },
  itemAuteur: { ...typography.caption, color: colors.blue[700], marginTop: 2 },
  donnees: { marginTop: spacing[3], marginBottom: spacing[3], gap: 2 },
  ligne: { ...typography.caption, color: colors.ink[700] },
  cle: { fontWeight: '700' },
  sep: { height: spacing[3] },
});
