import React, { useEffect, useState } from 'react';
import { Alert, StyleSheet, Text, TextInput, View } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../../src/components/Screen';
import { ScreenHeader } from '../../../src/components/ScreenHeader';
import { Card } from '../../../src/components/Card';
import { Chip } from '../../../src/components/Chip';
import { PrimaryButton } from '../../../src/components/PrimaryButton';
import { SecondaryButton } from '../../../src/components/SecondaryButton';
import { usePanier } from '../../../src/store/panier';
import { listerMembres } from '../../../src/api/membres';
import { listerSection } from '../../../src/api/carnet';
import { passerCommande } from '../../../src/api/commandes';
import { messageErreur } from '../../../src/utils/erreurs';
import { colors, spacing, typography } from '../../../src/theme/theme';
import type { CarnetItem } from '../../../src/types/carnet';
import type { Membre } from '../../../src/types/membre';

/**
 * Écran Panier (B3-d, F1/F3/F4/F7) — revue de ce qui a été ajouté depuis le comparateur, puis
 * passage de la commande. Le panier lui-même reste local (store `panier.ts`) ; cet écran n'envoie
 * au serveur QUE l'acte final.
 *
 * F3 — LA GARDE QUI COMPTE EST CELLE DU SERVEUR : ce formulaire exige une ordonnance dès qu'un
 * produit du panier en réclame une, mais c'est `ServiceCommande::passer()` qui refuse réellement
 * si elle ne convient pas. Un mobile modifié ne peut rien contourner.
 */
export default function PanierEcran() {
  const panier = usePanier();
  const [membreId, setMembreId] = useState<number | null>(null);
  const [membres, setMembres] = useState<Membre[]>([]);
  const [modeRetrait, setModeRetrait] = useState<'retrait' | 'livraison'>('retrait');
  const [adresse, setAdresse] = useState('');
  const [ordonnances, setOrdonnances] = useState<CarnetItem[]>([]);
  const [ordonnanceId, setOrdonnanceId] = useState<number | null>(null);
  const [envoi, setEnvoi] = useState(false);

  const necessiteOrdonnance = panier.lignes.some((l) => l.ordonnanceRequise);

  useEffect(() => {
    void listerMembres().then(setMembres).catch(() => setMembres([]));
  }, []);

  useEffect(() => {
    if (membreId === null || !necessiteOrdonnance) {
      setOrdonnances([]);
      return;
    }
    void listerSection(membreId, 'ordonnances')
      .then(setOrdonnances)
      .catch(() => setOrdonnances([]));
  }, [membreId, necessiteOrdonnance]);

  const total = panier.lignes.reduce(
    (somme, l) => somme + (l.prixUnitaireCfa ?? 0) * l.quantite,
    0,
  );
  const totalConnu = panier.lignes.every((l) => l.prixUnitaireCfa !== null);

  const soumettre = async () => {
    if (panier.structureId === null || panier.lignes.length === 0) return;
    if (membreId === null) return Alert.alert('Patient', 'Choisissez le patient concerné.');
    if (modeRetrait === 'livraison' && adresse.trim() === '') {
      return Alert.alert('Adresse', 'Indiquez une adresse de livraison.');
    }
    if (necessiteOrdonnance && ordonnanceId === null) {
      return Alert.alert(
        'Ordonnance requise',
        'Un des produits de votre panier nécessite une ordonnance. Désignez-en une.',
      );
    }

    setEnvoi(true);
    try {
      const commande = await passerCommande({
        membre_id: membreId,
        structure_id: panier.structureId,
        ordonnance_id: ordonnanceId,
        lignes: panier.lignes.map((l) => ({ medicament_id: l.medicamentId, quantite: l.quantite })),
        mode_retrait: modeRetrait,
        adresse_livraison: modeRetrait === 'livraison' ? adresse.trim() : null,
      });
      panier.vider();
      Alert.alert('Commande envoyée', `Référence ${commande.reference}. L'officine va la traiter.`);
      router.replace(`/(app)/commandes/${commande.id}`);
    } catch (e) {
      Alert.alert('Commande impossible', messageErreur(e));
    } finally {
      setEnvoi(false);
    }
  };

  if (panier.lignes.length === 0) {
    return (
      <Screen>
        <ScreenHeader title="Panier" onBack={() => router.back()} />
        <Text style={styles.vide}>
          Votre panier est vide. Ajoutez un médicament depuis le comparateur de prix.
        </Text>
      </Screen>
    );
  }

  return (
    <Screen footer={<PrimaryButton label="Passer la commande" onPress={soumettre} loading={envoi} />}>
      <ScreenHeader title="Panier" subtitle={panier.structureNom ?? undefined} onBack={() => router.back()} />

      <Card style={styles.bloc}>
        {panier.lignes.map((ligne) => (
          <View key={ligne.medicamentId} style={styles.ligne}>
            <View style={styles.ligneInfos}>
              <Text style={styles.ligneNom}>{ligne.nom}</Text>
              {ligne.ordonnanceRequise ? <Text style={styles.badge}>Sur ordonnance</Text> : null}
            </View>
            <View style={styles.quantite}>
              <Ionicons
                name="remove-circle-outline"
                size={22}
                color={colors.blue[600]}
                onPress={() => panier.modifierQuantite(ligne.medicamentId, ligne.quantite - 1)}
              />
              <Text style={styles.quantiteTxt}>{ligne.quantite}</Text>
              <Ionicons
                name="add-circle-outline"
                size={22}
                color={colors.blue[600]}
                onPress={() => panier.modifierQuantite(ligne.medicamentId, ligne.quantite + 1)}
              />
            </View>
            <Text style={styles.lignePrix}>
              {ligne.prixUnitaireCfa !== null ? `${ligne.prixUnitaireCfa * ligne.quantite} F` : '—'}
            </Text>
          </View>
        ))}
        <View style={styles.totalLigne}>
          <Text style={styles.totalLbl}>Total indicatif</Text>
          <Text style={styles.totalVal}>{totalConnu ? `${total} F` : 'non connu'}</Text>
        </View>
      </Card>

      <Card style={styles.bloc}>
        <Text style={styles.label}>Patient</Text>
        <View style={styles.chips}>
          {membres.map((m) => (
            <Chip key={m.id} label={`${m.prenom} ${m.nom}`} selected={membreId === m.id} onPress={() => setMembreId(m.id)} />
          ))}
        </View>
      </Card>

      {necessiteOrdonnance ? (
        <Card style={styles.bloc}>
          <Text style={styles.label}>Ordonnance (requise pour au moins un produit)</Text>
          {membreId === null ? (
            <Text style={styles.muted}>Choisissez d'abord le patient.</Text>
          ) : ordonnances.length === 0 ? (
            <Text style={styles.muted}>Aucune ordonnance dans ce carnet.</Text>
          ) : (
            <View style={styles.chips}>
              {ordonnances.map((o) => (
                <Chip
                  key={o.id}
                  label={`${String(o.medecin_nom ?? 'Ordonnance')} · ${String(o.date_prescription ?? '')}`}
                  selected={ordonnanceId === o.id}
                  onPress={() => setOrdonnanceId(o.id)}
                />
              ))}
            </View>
          )}
        </Card>
      ) : null}

      <Card style={styles.bloc}>
        <Text style={styles.label}>Retrait ou livraison</Text>
        <View style={styles.chips}>
          <Chip label="Retrait en officine" selected={modeRetrait === 'retrait'} onPress={() => setModeRetrait('retrait')} />
          <Chip label="Livraison" selected={modeRetrait === 'livraison'} onPress={() => setModeRetrait('livraison')} />
        </View>
        {modeRetrait === 'livraison' ? (
          <TextInput
            style={styles.adresse}
            value={adresse}
            onChangeText={setAdresse}
            placeholder="Adresse de livraison"
            multiline
          />
        ) : null}
      </Card>

      <SecondaryButton label="Vider le panier" onPress={() => panier.vider()} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  vide: { ...typography.body, color: colors.ink[500], padding: spacing[5], textAlign: 'center' },
  bloc: { marginBottom: spacing[4] },
  label: { ...typography.caption, color: colors.ink[700], marginBottom: spacing[2] },
  muted: { ...typography.body, color: colors.ink[500] },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing[2] },

  ligne: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing[2] },
  ligneInfos: { flex: 1 },
  ligneNom: { ...typography.bodyStrong, color: colors.ink[900] },
  badge: { ...typography.caption, color: colors.warning.text, marginTop: 2 },
  quantite: { flexDirection: 'row', alignItems: 'center', gap: spacing[2], marginHorizontal: spacing[3] },
  quantiteTxt: { ...typography.bodyStrong, minWidth: 20, textAlign: 'center' },
  lignePrix: { ...typography.bodyStrong, color: colors.blue[900], minWidth: 70, textAlign: 'right' },

  totalLigne: {
    flexDirection: 'row', justifyContent: 'space-between', marginTop: spacing[3],
    paddingTop: spacing[3], borderTopWidth: 1, borderTopColor: colors.line,
  },
  totalLbl: { ...typography.bodyStrong, color: colors.ink[900] },
  totalVal: { ...typography.h2, color: colors.blue[900] },

  adresse: {
    ...typography.body, borderWidth: 1, borderColor: colors.line, borderRadius: 8,
    padding: spacing[3], marginTop: spacing[3], minHeight: 60,
  },
});
