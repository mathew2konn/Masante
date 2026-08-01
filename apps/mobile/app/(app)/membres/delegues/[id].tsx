import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../../../src/components/Screen';
import { ScreenHeader } from '../../../../src/components/ScreenHeader';
import { Card } from '../../../../src/components/Card';
import { TextField } from '../../../../src/components/TextField';
import { PrimaryButton } from '../../../../src/components/PrimaryButton';
import { SecondaryButton } from '../../../../src/components/SecondaryButton';
import { inviterDelegue, listerDelegations, revoquerDelegation } from '../../../../src/api/delegations';
import { messageErreur } from '../../../../src/utils/erreurs';
import type { Delegation } from '../../../../src/types/delegation';
import { colors, radius, spacing, typography } from '../../../../src/theme/theme';

/**
 * Délégués d'un membre (voie 3, B3). Le titulaire invite un adulte de confiance (par téléphone) à
 * pouvoir générer le QR de CE membre, voit le statut (en attente / actif) et peut révoquer.
 */
export default function DeleguesScreen() {
  const { id, prenom, nom } = useLocalSearchParams<{ id: string; prenom?: string; nom?: string }>();
  const membreId = Number(id);

  const [delegations, setDelegations] = useState<Delegation[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);
  const [telephone, setTelephone] = useState('+225');
  const [envoi, setEnvoi] = useState(false);

  const charger = useCallback(async () => {
    setErreur(null);
    try {
      const { accordees } = await listerDelegations();
      setDelegations(accordees.filter((d) => d.membre.id === membreId));
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, [membreId]);

  useFocusEffect(
    useCallback(() => {
      void charger();
    }, [charger]),
  );

  const inviter = async () => {
    setEnvoi(true);
    try {
      await inviterDelegue(membreId, telephone.trim());
      setTelephone('+225');
      await charger();
    } catch (e) {
      Alert.alert('Invitation impossible', messageErreur(e));
    } finally {
      setEnvoi(false);
    }
  };

  const revoquer = (d: Delegation) => {
    const nomD = `${d.delegue?.prenom ?? ''} ${d.delegue?.nom ?? ''}`.trim() || 'ce délégué';
    Alert.alert('Révoquer la délégation ?', `${nomD} ne pourra plus générer le QR de ce membre.`, [
      { text: 'Annuler', style: 'cancel' },
      {
        text: 'Révoquer',
        style: 'destructive',
        onPress: async () => {
          try {
            await revoquerDelegation(d.id);
            await charger();
          } catch (e) {
            Alert.alert('Erreur', messageErreur(e));
          }
        },
      },
    ]);
  };

  return (
    <Screen>
      <ScreenHeader
        title="Délégués"
        subtitle={`Partage du QR de ${prenom ?? ''} ${nom ?? ''}`.trim()}
        onBack={() => router.back()}
      />

      <Card style={styles.carte}>
        <Text style={styles.intro}>
          Autorisez un proche (disposant d'un compte MaSanté) à générer le QR de ce membre en votre
          absence. Il ne pourra ni consulter ni modifier le dossier.
        </Text>
        <TextField
          label="Téléphone du délégué"
          value={telephone}
          onChangeText={setTelephone}
          keyboardType="phone-pad"
          placeholder="+225XXXXXXXXXX"
          maxLength={14}
        />
        <PrimaryButton label="Inviter" onPress={inviter} loading={envoi} disabled={telephone.trim().length < 13} />
      </Card>

      <Text style={styles.section}>Délégués de ce membre</Text>

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      ) : erreur ? (
        <Text style={styles.erreur}>{erreur}</Text>
      ) : delegations.length === 0 ? (
        <Card style={styles.vide}>
          <Ionicons name="people-outline" size={28} color={colors.blue[400]} />
          <Text style={styles.videTxt}>Aucun délégué pour l'instant.</Text>
        </Card>
      ) : (
        delegations.map((d) => {
          const actif = d.acceptee_at !== null;
          return (
            <Card key={d.id} style={styles.item}>
              <View style={styles.itemTexte}>
                <Text style={styles.itemNom}>
                  {d.delegue?.prenom} {d.delegue?.nom}
                </Text>
                <Text style={styles.itemTel}>{d.delegue?.telephone}</Text>
                <View style={[styles.badge, { backgroundColor: actif ? colors.success.bg : colors.warning.bg }]}>
                  <Text style={[styles.badgeTxt, { color: actif ? colors.success.text : colors.warning.text }]}>
                    {actif ? '✓ Actif' : '● En attente d\'acceptation'}
                  </Text>
                </View>
              </View>
              <SecondaryButton label="Révoquer" onPress={() => revoquer(d)} />
            </Card>
          );
        })
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  carte: { marginBottom: spacing[5] },
  intro: { ...typography.caption, color: colors.ink[700], marginBottom: spacing[3] },
  section: { ...typography.h2, color: colors.blue[900], marginBottom: spacing[3] },
  loader: { marginTop: spacing[4] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text },
  vide: { alignItems: 'center' },
  videTxt: { ...typography.bodyStrong, color: colors.ink[700], marginTop: spacing[2] },
  item: { marginBottom: spacing[3] },
  itemTexte: { marginBottom: spacing[3] },
  itemNom: { ...typography.bodyStrong, color: colors.blue[900] },
  itemTel: { ...typography.caption, color: colors.ink[700], marginTop: 2 },
  badge: { alignSelf: 'flex-start', borderRadius: radius.pill, paddingHorizontal: spacing[3], paddingVertical: spacing[1], marginTop: spacing[2] },
  badgeTxt: { ...typography.caption, fontWeight: '700' },
});
