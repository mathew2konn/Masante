import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../../src/components/Screen';
import { ScreenHeader } from '../../../src/components/ScreenHeader';
import { Card } from '../../../src/components/Card';
import { PrimaryButton } from '../../../src/components/PrimaryButton';
import {
  annulerCommande,
  disponibiliteEnLignePaiementCommande,
  obtenirCommande,
  payerCommandeEnLigne,
} from '../../../src/api/commandes';
import { messageErreur } from '../../../src/utils/erreurs';
import { colors, radius, spacing, typography } from '../../../src/theme/theme';
import type { Commande } from '../../../src/types/commande';

/**
 * Détail d'une commande (B3-d) — statut fourni par le serveur, jamais déduit.
 *
 * F6 (réécrit, B4) — le règlement en ligne emprunte le canal réel de GeniusPay, exactement comme
 * le rendez-vous : checkout ouvert dans le NAVIGATEUR (jamais une WebView, S8), et le retour dans
 * l'app ne suppose aucun résultat — seule « Actualiser » relit la commande.
 */
export default function CommandeDetailEcran() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const commandeId = Number(id);

  const [commande, setCommande] = useState<Commande | null>(null);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);
  const [enLigneDisponible, setEnLigneDisponible] = useState(false);
  const [ouvertureEnCours, setOuvertureEnCours] = useState(false);

  const charger = useCallback(async () => {
    try {
      setCommande(await obtenirCommande(commandeId));
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, [commandeId]);

  useFocusEffect(
    useCallback(() => {
      void charger();
    }, [charger]),
  );

  useEffect(() => {
    if (commande?.statut === 'acceptee' && !commande.regle_le) {
      void disponibiliteEnLignePaiementCommande(commandeId)
        .then(setEnLigneDisponible)
        .catch(() => setEnLigneDisponible(false));
    } else {
      setEnLigneDisponible(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [commande?.statut, commandeId]);

  async function payerEnLigne() {
    setOuvertureEnCours(true);
    setErreur(null);
    try {
      const { checkout_url: url } = await payerCommandeEnLigne(commandeId);
      if (url) {
        await Linking.openURL(url);
      } else {
        setErreur('Le prestataire n\'a pas renvoyé de lien de paiement.');
      }
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setOuvertureEnCours(false);
    }
  }

  function annuler() {
    Alert.alert('Annuler cette commande ?', 'Cette action est définitive.', [
      { text: 'Non', style: 'cancel' },
      {
        text: 'Oui, annuler',
        style: 'destructive',
        onPress: async () => {
          try {
            setCommande(await annulerCommande(commandeId));
          } catch (e) {
            Alert.alert('Annulation impossible', messageErreur(e));
          }
        },
      },
    ]);
  }

  if (chargement) {
    return (
      <Screen scroll={false}>
        <ScreenHeader title="Commande" onBack={() => router.back()} />
        <View style={styles.centre}>
          <ActivityIndicator color={colors.blue[600]} />
        </View>
      </Screen>
    );
  }

  if (!commande) {
    return (
      <Screen>
        <ScreenHeader title="Commande" onBack={() => router.back()} />
        <Text style={styles.erreur}>{erreur ?? 'Commande introuvable.'}</Text>
      </Screen>
    );
  }

  const peutAnnuler = commande.statut !== 'remise' && commande.statut !== 'annulee';

  return (
    <Screen>
      <ScreenHeader title={commande.reference} subtitle={commande.structure?.nom} onBack={() => router.back()} />

      <Card style={styles.bloc}>
        <View style={[styles.badge, { backgroundColor: COULEUR_BADGE[commande.statut] }]}>
          <Text style={styles.badgeTxt}>{LIBELLE_STATUT[commande.statut]}</Text>
        </View>
        {commande.statut === 'refusee' && commande.motif_refus ? (
          <Text style={styles.motif}>Motif : {commande.motif_refus}</Text>
        ) : null}
        {commande.mode_retrait === 'livraison' && commande.adresse_livraison ? (
          <Text style={styles.meta}>
            <Ionicons name="location-outline" size={14} /> {commande.adresse_livraison}
          </Text>
        ) : null}
      </Card>

      <Card style={styles.bloc}>
        <Text style={styles.label}>Articles</Text>
        {commande.lignes.map((l) => (
          <View key={l.id} style={styles.ligne}>
            <Text style={styles.ligneNom}>{l.nom} × {l.quantite}</Text>
            <Text style={styles.lignePrix}>
              {l.prix_unitaire_indicatif_cfa !== null ? `${l.prix_unitaire_indicatif_cfa * l.quantite} F` : '—'}
            </Text>
          </View>
        ))}
        <View style={styles.totalLigne}>
          <Text style={styles.totalLbl}>Total indicatif</Text>
          <Text style={styles.totalVal}>
            {commande.montant_indicatif_cfa !== null ? `${commande.montant_indicatif_cfa} F` : 'non connu'}
          </Text>
        </View>
      </Card>

      {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}

      {enLigneDisponible ? (
        <Card style={styles.bloc}>
          <Text style={styles.label}>Payer en ligne</Text>
          <Pressable onPress={payerEnLigne} disabled={ouvertureEnCours} accessibilityRole="button" style={styles.boutonEnLigne}>
            {ouvertureEnCours ? (
              <ActivityIndicator color="#FFFFFF" />
            ) : (
              <Text style={styles.boutonEnLigneTxt}>Payer en ligne (GeniusPay)</Text>
            )}
          </Pressable>
          <Text style={styles.avertTxt}>
            Vous serez redirigé vers votre navigateur pour finaliser le paiement, puis reviendrez
            ici. Une fois le paiement effectué, appuyez sur « Actualiser ».
          </Text>
          <Pressable onPress={() => void charger()} accessibilityRole="button" style={styles.reessayer}>
            <Text style={styles.reessayerTxt}>Actualiser</Text>
          </Pressable>
        </Card>
      ) : null}

      {peutAnnuler ? <PrimaryButton label="Annuler la commande" onPress={annuler} /> : null}
    </Screen>
  );
}

const LIBELLE_STATUT: Record<string, string> = {
  en_attente: 'En attente', acceptee: 'Acceptée', refusee: 'Refusée',
  prete: 'Prête', remise: 'Remise', annulee: 'Annulée',
};
const COULEUR_BADGE: Record<string, string> = {
  en_attente: colors.blue[100], acceptee: colors.blue[100], refusee: colors.danger.bg,
  prete: colors.warning.bg, remise: colors.success.bg, annulee: colors.surfaceMuted,
};

const styles = StyleSheet.create({
  centre: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  erreur: { ...typography.bodyStrong, color: colors.danger.text, padding: spacing[4] },
  bloc: { marginBottom: spacing[4] },
  label: { ...typography.caption, color: colors.ink[700], marginBottom: spacing[2] },
  meta: { ...typography.caption, color: colors.ink[500], marginTop: spacing[2] },
  motif: { ...typography.body, color: colors.danger.text, marginTop: spacing[2] },
  badge: { alignSelf: 'flex-start', paddingVertical: spacing[1], paddingHorizontal: spacing[3], borderRadius: radius.pill },
  badgeTxt: { ...typography.bodyStrong, color: colors.ink[900] },

  ligne: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: spacing[1] },
  ligneNom: { ...typography.body, color: colors.ink[900] },
  lignePrix: { ...typography.body, color: colors.ink[900] },
  totalLigne: {
    flexDirection: 'row', justifyContent: 'space-between', marginTop: spacing[3],
    paddingTop: spacing[3], borderTopWidth: 1, borderTopColor: colors.line,
  },
  totalLbl: { ...typography.bodyStrong, color: colors.ink[900] },
  totalVal: { ...typography.h2, color: colors.blue[900] },

  avertTxt: { ...typography.caption, color: colors.ink[500], marginTop: spacing[2] },
  reessayer: {
    marginTop: spacing[2], paddingHorizontal: spacing[5], height: 44, justifyContent: 'center',
    borderRadius: radius.pill, borderWidth: 1.5, borderColor: colors.blue[600],
  },
  reessayerTxt: { ...typography.button, color: colors.blue[600] },
  boutonEnLigne: {
    height: 48, borderRadius: radius.pill, backgroundColor: colors.blue[600],
    alignItems: 'center', justifyContent: 'center',
  },
  boutonEnLigneTxt: { ...typography.button, color: '#FFFFFF' },
});
