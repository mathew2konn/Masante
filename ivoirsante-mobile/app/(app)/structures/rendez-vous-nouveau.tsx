import React, { useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { Screen } from '../../../src/components/Screen';
import { Card } from '../../../src/components/Card';
import { ScreenHeader } from '../../../src/components/ScreenHeader';
import { Chip } from '../../../src/components/Chip';
import { TextField } from '../../../src/components/TextField';
import { PrimaryButton } from '../../../src/components/PrimaryButton';
import { getStructure } from '../../../src/api/structures';
import { listerMembres } from '../../../src/api/membres';
import { demanderRendezVous } from '../../../src/api/rendezvous';
import { messageErreur } from '../../../src/utils/erreurs';
import { isoVersDateInput } from '../../../src/utils/dates';
import type { Service } from '../../../src/types/structure';
import type { Membre } from '../../../src/types/membre';
import { colors, spacing, typography } from '../../../src/theme/theme';

/**
 * Demande de rendez-vous dans une structure (Module 3 / 3B.4, F3.6).
 *
 * Choix d'un membre du compte + d'un service de la structure + motif + date souhaitée. Le RDV
 * est créé au statut « en attente » : la confirmation par l'agent relève du Module 4. L'isolation
 * anti-IDOR (membre du compte, service de la structure) est revalidée côté serveur.
 */
export default function RendezVousForm() {
  const { id, nom } = useLocalSearchParams<{ id: string; nom: string }>();
  const structureId = Number(id);

  const [membres, setMembres] = useState<Membre[]>([]);
  const [services, setServices] = useState<Service[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreurChargement, setErreurChargement] = useState<string | null>(null);

  const [membreId, setMembreId] = useState<number | null>(null);
  const [serviceId, setServiceId] = useState<number | null>(null);
  const [motif, setMotif] = useState('');
  const [date, setDate] = useState('');
  const [envoi, setEnvoi] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      setChargement(true);
      setErreurChargement(null);
      try {
        const [ms, structure] = await Promise.all([listerMembres(), getStructure(structureId)]);
        setMembres(ms);
        setServices((structure.services ?? []).filter((s) => s.actif));
      } catch (e) {
        setErreurChargement(messageErreur(e));
      } finally {
        setChargement(false);
      }
    })();
  }, [structureId]);

  function dateValide(saisie: string): string | null {
    const v = saisie.trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return 'Format attendu : AAAA-MM-JJ.';
    const d = new Date(`${v}T00:00:00`);
    if (Number.isNaN(d.getTime()) || isoVersDateInput(d.toISOString()) !== v) return "Cette date n'existe pas.";
    const aujourdhui = new Date();
    aujourdhui.setHours(0, 0, 0, 0);
    if (d.getTime() < aujourdhui.getTime()) return 'La date ne peut pas être dans le passé.';
    return null;
  }

  async function soumettre() {
    if (!membreId) return setErreur('Choisissez le patient concerné.');
    if (!serviceId) return setErreur('Choisissez un service.');
    if (motif.trim().length < 3) return setErreur('Précisez le motif de la consultation.');
    const errDate = dateValide(date);
    if (errDate) return setErreur(errDate);

    setEnvoi(true);
    setErreur(null);
    try {
      await demanderRendezVous({
        membre_id: membreId,
        structure_id: structureId,
        service_id: serviceId,
        motif: motif.trim(),
        date_souhaitee: date.trim(),
      });
      Alert.alert('Demande envoyée', 'Votre demande de rendez-vous est en attente de confirmation.', [
        { text: 'Voir mes rendez-vous', onPress: () => router.replace('/(app)/structures/mes-rendez-vous') },
      ]);
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setEnvoi(false);
    }
  }

  if (chargement) {
    return (
      <Screen scroll={false}>
        <ScreenHeader title="Demander un RDV" subtitle={nom} onBack={() => router.back()} />
        <View style={styles.centre}>
          <ActivityIndicator color={colors.blue[600]} />
        </View>
      </Screen>
    );
  }

  if (erreurChargement) {
    return (
      <Screen scroll={false}>
        <ScreenHeader title="Demander un RDV" subtitle={nom} onBack={() => router.back()} />
        <View style={styles.centre}>
          <Text style={styles.etatTxt}>{erreurChargement}</Text>
        </View>
      </Screen>
    );
  }

  if (membres.length === 0) {
    return (
      <Screen scroll={false}>
        <ScreenHeader title="Demander un RDV" subtitle={nom} onBack={() => router.back()} />
        <View style={styles.centre}>
          <Text style={styles.etatTxt}>Ajoutez d'abord un membre à votre carnet pour prendre un rendez-vous.</Text>
          <Pressable onPress={() => router.push('/(app)/membres/nouveau')} accessibilityRole="button">
            <Text style={styles.lien}>Ajouter un membre</Text>
          </Pressable>
        </View>
      </Screen>
    );
  }

  return (
    <Screen footer={<PrimaryButton label="Envoyer la demande" onPress={soumettre} loading={envoi} />}>
      <ScreenHeader title="Demander un RDV" subtitle={nom} onBack={() => router.back()} />

      <Card style={styles.bloc}>
        <Text style={styles.label}>Patient</Text>
        <View style={styles.chips}>
          {membres.map((m) => (
            <Chip
              key={m.id}
              label={`${m.prenom} ${m.nom}`}
              selected={membreId === m.id}
              onPress={() => setMembreId(m.id)}
            />
          ))}
        </View>
      </Card>

      <Card style={styles.bloc}>
        <Text style={styles.label}>Service</Text>
        {services.length === 0 ? (
          <Text style={styles.muted}>Aucun service disponible pour cette structure.</Text>
        ) : (
          <View style={styles.chips}>
            {services.map((s) => (
              <Chip
                key={s.id}
                label={s.nom_service}
                selected={serviceId === s.id}
                onPress={() => setServiceId(s.id)}
              />
            ))}
          </View>
        )}
      </Card>

      <TextField
        label="Motif"
        value={motif}
        onChangeText={setMotif}
        placeholder="Ex. consultation, suivi, douleur…"
        multiline
        numberOfLines={3}
        maxLength={2000}
        autoCapitalize="sentences"
      />

      <TextField
        label="Date souhaitée"
        value={date}
        onChangeText={setDate}
        placeholder="AAAA-MM-JJ"
        keyboardType="numbers-and-punctuation"
        erreur={erreur}
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  centre: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: spacing[3], paddingHorizontal: spacing[4] },
  bloc: { marginBottom: spacing[4], gap: spacing[3] },
  label: { ...typography.bodyStrong, color: colors.ink[700] },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing[2] },
  muted: { ...typography.body, color: colors.ink[500] },
  etatTxt: { ...typography.body, color: colors.ink[700], textAlign: 'center' },
  lien: { ...typography.button, color: colors.blue[600] },
});
