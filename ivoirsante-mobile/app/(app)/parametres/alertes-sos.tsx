import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../../src/components/Screen';
import { Card } from '../../../src/components/Card';
import { ScreenHeader } from '../../../src/components/ScreenHeader';
import { listerAlertesSos, type AlerteSos, type CanalSos } from '../../../src/api/sos';
import { messageErreur } from '../../../src/utils/erreurs';
import { formatDateHeureFr } from '../../../src/utils/dates';
import { colors, radius, spacing, typography } from '../../../src/theme/theme';

/** Ce que le téléphone a réellement fait au moment de l'alerte. */
const LIBELLE_CANAL: Record<CanalSos, string> = {
  appel: 'Appel au SAMU',
  sms: 'SMS à un proche',
  appel_sms: 'Appel au SAMU et SMS',
};

/**
 * Historique des alertes SOS (Module 5.2) — TRANSPARENCE.
 *
 * Le déclenchement d'un SOS enregistre une position GPS côté serveur : une donnée personnelle
 * sensible au sens de la loi n°2013-450. Le patient doit donc pouvoir consulter ce qui est conservé
 * sur lui, quand, et pour quel membre — même principe que le journal d'accès au dossier (FT6).
 *
 * Écran de consultation pure : les alertes ne sont ni modifiables ni supprimables (journal en ajout
 * seul). Requiert le réseau : ces données vivent sur le serveur, pas dans le cache d'urgence.
 */
export default function HistoriqueAlertesSos() {
  const [alertes, setAlertes] = useState<AlerteSos[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  const charger = useCallback(async () => {
    setChargement(true);
    setErreur(null);
    try {
      setAlertes(await listerAlertesSos());
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, []);

  useEffect(() => {
    void charger();
  }, [charger]);

  const ouvrirCarte = (a: AlerteSos) => {
    void Linking.openURL(
      `https://www.openstreetmap.org/?mlat=${a.latitude}&mlon=${a.longitude}#map=17/${a.latitude}/${a.longitude}`,
    );
  };

  return (
    <Screen>
      <ScreenHeader
        title="Mes alertes d'urgence"
        subtitle="Ce que MaSanté a enregistré lors de vos SOS"
        onBack={() => router.back()}
      />

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.centre} />
      ) : erreur ? (
        <Card style={styles.bloc}>
          <Text style={styles.corps}>{erreur}</Text>
          <Pressable onPress={() => void charger()} accessibilityRole="button" style={styles.reessayer}>
            <Text style={styles.lien}>Réessayer</Text>
          </Pressable>
        </Card>
      ) : alertes.length === 0 ? (
        <Card style={styles.bloc}>
          <Text style={styles.corps}>Vous n'avez déclenché aucune alerte d'urgence.</Text>
          <Text style={styles.muted}>
            Le bouton SOS de l'accueil permet d'appeler le SAMU et de prévenir un proche.
          </Text>
        </Card>
      ) : (
        alertes.map((a) => (
          <Card key={a.id} style={styles.bloc}>
            <View style={styles.entete}>
              <View style={styles.canal}>
                <Ionicons name="alert-circle" size={16} color={colors.danger.solid} />
                <Text style={styles.canalTxt}>{LIBELLE_CANAL[a.canal]}</Text>
              </View>
              <Text style={styles.muted}>{formatDateHeureFr(a.declenchee_le)}</Text>
            </View>

            {a.membre && (
              <Text style={styles.corps}>
                Pour {a.membre.prenom} {a.membre.nom}
              </Text>
            )}

            {a.contact_prevenu_nom && (
              <Text style={styles.muted}>
                Proche prévenu : {a.contact_prevenu_nom} ({a.contact_prevenu_tel})
              </Text>
            )}

            {a.latitude !== null && a.longitude !== null ? (
              <Pressable
                onPress={() => ouvrirCarte(a)}
                accessibilityRole="button"
                accessibilityLabel="Voir la position enregistrée sur une carte"
                style={styles.position}
              >
                <Ionicons name="location" size={16} color={colors.blue[700]} />
                <Text style={styles.lien}>
                  Position enregistrée
                  {a.precision_metres !== null ? ` (± ${a.precision_metres} m)` : ''}
                </Text>
              </Pressable>
            ) : (
              <Text style={styles.muted}>Aucune position enregistrée.</Text>
            )}
          </Card>
        ))
      )}

      <Text style={styles.pied}>
        Votre position n'est enregistrée qu'au moment où vous déclenchez un SOS. MaSanté ne vous suit
        jamais en dehors de ces alertes.
      </Text>
    </Screen>
  );
}

const styles = StyleSheet.create({
  bloc: { marginBottom: spacing[4], gap: spacing[2] },
  centre: { marginTop: spacing[8] },
  entete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing[2] },
  canal: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[1],
    backgroundColor: colors.danger.bg,
    borderRadius: radius.sm,
    paddingHorizontal: spacing[2],
    paddingVertical: 2,
  },
  canalTxt: { ...typography.caption, color: colors.danger.text, fontWeight: '600' },
  corps: { ...typography.body, color: colors.ink[900] },
  muted: { ...typography.caption, color: colors.ink[500] },
  position: { flexDirection: 'row', alignItems: 'center', gap: spacing[1], minHeight: 44 },
  lien: { ...typography.bodyStrong, color: colors.blue[700] },
  reessayer: { minHeight: 44, justifyContent: 'center' },
  pied: { ...typography.caption, color: colors.ink[500], marginTop: spacing[2], textAlign: 'center' },
});
