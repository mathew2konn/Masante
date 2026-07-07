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
import type { Delegation } from '../../src/types/delegation';
import { colors, spacing, typography } from '../../src/theme/theme';

/**
 * Partages reçus (voie 3, B3). Côté délégué : liste des membres qu'un proche m'a délégués. On accepte
 * (ou refuse) une invitation, puis on peut générer le QR d'un membre actif (via l'écran QR, sous verrou).
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
    Alert.alert('Refuser ce partage ?', 'Vous ne pourrez pas générer le QR de ce membre.', [
      { text: 'Annuler', style: 'cancel' },
      { text: 'Refuser', style: 'destructive', onPress: () => void traiter(d.id, () => revoquerDelegation(d.id)) },
    ]);
  };

  return (
    <Screen>
      <ScreenHeader title="Partages reçus" subtitle="Membres délégués par vos proches" onBack={() => router.back()} />

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      ) : erreur ? (
        <Text style={styles.erreur}>{erreur}</Text>
      ) : recues.length === 0 ? (
        <Card style={styles.vide}>
          <Ionicons name="share-social-outline" size={28} color={colors.blue[400]} />
          <Text style={styles.videTxt}>Aucun partage reçu.</Text>
          <Text style={styles.videSous}>
            Quand un proche vous délègue l'accès au QR d'un de ses membres, il apparaîtra ici.
          </Text>
        </Card>
      ) : (
        recues.map((d) => {
          const actif = d.acceptee_at !== null;
          const occupe = action === d.id;
          return (
            <Card key={d.id} style={styles.item}>
              <Text style={styles.itemNom}>
                {d.membre.prenom} {d.membre.nom}
              </Text>
              <Text style={styles.itemSous}>
                Délégué par {d.titulaire?.prenom} {d.titulaire?.nom}
              </Text>

              {actif ? (
                <PrimaryButton
                  label="Générer le QR"
                  onPress={() =>
                    router.push({
                      pathname: '/(app)/membres/qr/[id]',
                      params: { id: d.membre.id, prenom: d.membre.prenom ?? '', nom: d.membre.nom },
                    })
                  }
                />
              ) : (
                <View>
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
  sep: { height: spacing[3] },
});
