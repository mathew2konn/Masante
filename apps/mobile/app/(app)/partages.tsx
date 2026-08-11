import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../src/components/Screen';
import { ScreenHeader } from '../../src/components/ScreenHeader';
import { Card } from '../../src/components/Card';
import { PrimaryButton } from '../../src/components/PrimaryButton';
import { SecondaryButton } from '../../src/components/SecondaryButton';
import { accepterDelegation, listerDelegations, revoquerDelegation } from '../../src/api/delegations';
import { messageErreur } from '../../src/utils/erreurs';
import { OUVRE_LE_CARNET, type Delegation } from '../../src/types/delegation';
import { colors, spacing, typography } from '../../src/theme/theme';

/**
 * Partages reçus (voie 3, B3 ; élargi par le carnet familial partagé, incrément A).
 *
 * Côté délégué : les carnets qu'un proche m'a confiés. On accepte (ou refuse) l'invitation ; une
 * fois acceptée, on peut ouvrir le carnet — s'il porte le droit de lecture — et générer le QR.
 *
 * Les invitations d'avant l'incrément A ne portent que `qr_generation` : elles n'ouvrent aucun
 * dossier, et l'écran ne propose donc pas de l'ouvrir. Ce que le droit permet est décidé par le
 * serveur ; on ne fait ici que refléter `droits`.
 */
export default function PartagesRecusScreen() {
  const [recues, setRecues] = useState<Delegation[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);
  const [action, setAction] = useState<number | null>(null); // id en cours de traitement

  const charger = useCallback(async () => {
    setErreur(null);
    try {
      const { recues } = await listerDelegations();
      setRecues(recues);
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

  const traiter = async (id: number, op: () => Promise<unknown>) => {
    setAction(id);
    try {
      await op();
      await charger();
    } catch (e) {
      Alert.alert('Erreur', messageErreur(e));
    } finally {
      setAction(null);
    }
  };

  const refuser = (d: Delegation) => {
    Alert.alert('Refuser ce partage ?', "Vous n'aurez pas accès au carnet de ce membre.", [
      { text: 'Annuler', style: 'cancel' },
      { text: 'Refuser', style: 'destructive', onPress: () => void traiter(d.id, () => revoquerDelegation(d.id)) },
    ]);
  };

  /** Retirer un partage déjà accepté — possible à tout moment, sans justification. */
  const retirer = (d: Delegation) => {
    Alert.alert('Retirer ce partage ?', "Vous perdrez l'accès au carnet de ce membre.", [
      { text: 'Annuler', style: 'cancel' },
      {
        text: 'Retirer',
        style: 'destructive',
        onPress: () => void traiter(d.id, () => revoquerDelegation(d.id)),
      },
    ]);
  };

  return (
    <Screen>
      <ScreenHeader
        title="Partages reçus"
        subtitle="Carnets confiés par vos proches"
        onBack={() => router.back()}
      />

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      ) : erreur ? (
        <Text style={styles.erreur}>{erreur}</Text>
      ) : recues.length === 0 ? (
        <Card style={styles.vide}>
          <Ionicons name="share-social-outline" size={28} color={colors.blue[400]} />
          <Text style={styles.videTxt}>Aucun partage reçu.</Text>
          <Text style={styles.videSous}>
            Quand un proche vous confie le carnet d&apos;un de ses membres, il apparaîtra ici.
          </Text>
        </Card>
      ) : (
        recues.map((d) => {
          const actif = d.acceptee_at !== null;
          const occupe = action === d.id;
          const ouvreLeCarnet = OUVRE_LE_CARNET.includes(d.droits);

          return (
            <Card key={d.id} style={styles.item}>
              <Text style={styles.itemNom}>
                {d.membre.prenom} {d.membre.nom}
              </Text>
              <Text style={styles.itemSous}>
                Partagé par {d.titulaire?.prenom} {d.titulaire?.nom}
              </Text>

              {actif ? (
                <View>
                  {ouvreLeCarnet ? (
                    <>
                      <PrimaryButton
                        label="Ouvrir le carnet"
                        onPress={() =>
                          router.push({
                            pathname: '/(app)/membres/[id]',
                            params: { id: d.membre.id },
                          })
                        }
                      />
                      <View style={styles.sep} />
                    </>
                  ) : null}
                  <SecondaryButton
                    label="Générer le QR"
                    onPress={() =>
                      router.push({
                        pathname: '/(app)/membres/qr/[id]',
                        params: { id: d.membre.id, prenom: d.membre.prenom ?? '', nom: d.membre.nom },
                      })
                    }
                  />
                  <View style={styles.sep} />
                  <SecondaryButton
                    label="Retirer ce partage"
                    onPress={() => retirer(d)}
                    disabled={occupe}
                  />
                </View>
              ) : (
                <View>
                  <Text style={styles.itemPortee}>
                    {ouvreLeCarnet
                      ? 'En acceptant, vous pourrez consulter ce carnet — sans pouvoir le modifier.'
                      : 'En acceptant, vous pourrez générer le QR de ce membre.'}
                  </Text>
                  <PrimaryButton
                    label="Accepter le partage"
                    onPress={() => void traiter(d.id, () => accepterDelegation(d.id))}
                    loading={occupe}
                  />
                  <View style={styles.sep} />
                  <SecondaryButton label="Refuser" onPress={() => refuser(d)} disabled={occupe} />
                </View>
              )}
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
  itemNom: { ...typography.bodyStrong, color: colors.blue[900] },
  itemSous: { ...typography.caption, color: colors.ink[700], marginTop: 2, marginBottom: spacing[3] },
  // Consentement éclairé : on dit ce que l'acceptation ouvre AVANT de la demander.
  itemPortee: { ...typography.caption, color: colors.ink[500], marginBottom: spacing[3] },
  sep: { height: spacing[3] },
});
