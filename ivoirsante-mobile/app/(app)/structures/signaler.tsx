import React, { useState } from 'react';
import { Alert, StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { Screen } from '../../../src/components/Screen';
import { Card } from '../../../src/components/Card';
import { ScreenHeader } from '../../../src/components/ScreenHeader';
import { Chip } from '../../../src/components/Chip';
import { TextField } from '../../../src/components/TextField';
import { PrimaryButton } from '../../../src/components/PrimaryButton';
import { signalerStructure } from '../../../src/api/structures';
import { messageErreur } from '../../../src/utils/erreurs';
import { LIBELLE_SIGNALEMENT, type TypeSignalement } from '../../../src/types/structure';
import { colors, spacing, typography } from '../../../src/theme/theme';

const TYPES = Object.keys(LIBELLE_SIGNALEMENT) as TypeSignalement[];

/**
 * Signalement citoyen d'un problème sur une structure (Module 3 / 3B.4, F3.10).
 *
 * Le signalement part au statut « en attente » et sera examiné par la modération (Module 4) ;
 * il n'apparaît pas publiquement tant qu'il n'est pas validé. Type + description obligatoires.
 */
export default function SignalerForm() {
  const { id, nom } = useLocalSearchParams<{ id: string; nom: string }>();
  const structureId = Number(id);

  const [type, setType] = useState<TypeSignalement | null>(null);
  const [description, setDescription] = useState('');
  const [envoi, setEnvoi] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);

  async function soumettre() {
    if (!type) {
      setErreur('Choisissez le type de problème.');
      return;
    }
    if (description.trim().length < 3) {
      setErreur('Décrivez brièvement le problème.');
      return;
    }
    setEnvoi(true);
    setErreur(null);
    try {
      const message = await signalerStructure(structureId, { type, description: description.trim() });
      Alert.alert('Signalement envoyé', message, [{ text: 'OK', onPress: () => router.back() }]);
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setEnvoi(false);
    }
  }

  return (
    <Screen footer={<PrimaryButton label="Envoyer le signalement" onPress={soumettre} loading={envoi} />}>
      <ScreenHeader title="Signaler un problème" subtitle={nom} onBack={() => router.back()} />

      <Card style={styles.bloc}>
        <Text style={styles.label}>Type de problème</Text>
        <View style={styles.chips}>
          {TYPES.map((t) => (
            <Chip key={t} label={LIBELLE_SIGNALEMENT[t]} selected={type === t} onPress={() => setType(t)} />
          ))}
        </View>
      </Card>

      <TextField
        label="Description"
        value={description}
        onChangeText={setDescription}
        placeholder="Expliquez ce que vous avez constaté"
        multiline
        numberOfLines={5}
        maxLength={2000}
        autoCapitalize="sentences"
        erreur={erreur}
      />

      <Text style={styles.note}>
        Votre signalement est confidentiel et sera examiné avant toute publication.
      </Text>
    </Screen>
  );
}

const styles = StyleSheet.create({
  bloc: { marginBottom: spacing[4], gap: spacing[3] },
  label: { ...typography.bodyStrong, color: colors.ink[700] },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing[2] },
  note: { ...typography.caption, color: colors.ink[500] },
});
