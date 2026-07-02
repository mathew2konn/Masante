import React, { useState } from 'react';
import { Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../../src/components/Screen';
import { Card } from '../../../src/components/Card';
import { ScreenHeader } from '../../../src/components/ScreenHeader';
import { TextField } from '../../../src/components/TextField';
import { PrimaryButton } from '../../../src/components/PrimaryButton';
import { deposerAvis } from '../../../src/api/structures';
import { messageErreur } from '../../../src/utils/erreurs';
import { colors, spacing, typography } from '../../../src/theme/theme';

/**
 * Dépôt d'un avis sur une structure (Module 3 / 3B.4, F3.9).
 *
 * Note obligatoire (1-5) + commentaire facultatif. Un seul avis par utilisateur et par structure
 * (le serveur met à jour l'avis existant). La modération automatique masque les commentaires
 * inappropriés côté serveur. Réservé aux comptes (toutes les routes (app) sont authentifiées).
 */
export default function AvisForm() {
  const { id, nom } = useLocalSearchParams<{ id: string; nom: string }>();
  const structureId = Number(id);

  const [note, setNote] = useState(0);
  const [commentaire, setCommentaire] = useState('');
  const [envoi, setEnvoi] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);

  async function soumettre() {
    if (note < 1) {
      setErreur('Choisissez une note de 1 à 5 étoiles.');
      return;
    }
    setEnvoi(true);
    setErreur(null);
    try {
      await deposerAvis(structureId, { note, commentaire: commentaire.trim() || undefined });
      Alert.alert('Merci !', 'Votre avis a bien été enregistré.', [{ text: 'OK', onPress: () => router.back() }]);
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setEnvoi(false);
    }
  }

  return (
    <Screen footer={<PrimaryButton label="Publier mon avis" onPress={soumettre} loading={envoi} />}>
      <ScreenHeader title="Donner mon avis" subtitle={nom} onBack={() => router.back()} />

      <Card style={styles.bloc}>
        <Text style={styles.label}>Votre note</Text>
        <View style={styles.etoiles}>
          {[1, 2, 3, 4, 5].map((n) => (
            <Pressable
              key={n}
              onPress={() => setNote(n)}
              hitSlop={6}
              accessibilityRole="button"
              accessibilityLabel={`${n} étoile${n > 1 ? 's' : ''}`}
              accessibilityState={{ selected: n <= note }}
            >
              <Ionicons
                name={n <= note ? 'star' : 'star-outline'}
                size={40}
                color={n <= note ? colors.warning.solid : colors.disabled}
              />
            </Pressable>
          ))}
        </View>
      </Card>

      <TextField
        label="Commentaire (facultatif)"
        value={commentaire}
        onChangeText={setCommentaire}
        placeholder="Partagez votre expérience (accueil, délais, soins…)"
        multiline
        numberOfLines={5}
        maxLength={2000}
        autoCapitalize="sentences"
        erreur={erreur}
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  bloc: { marginBottom: spacing[4], gap: spacing[3] },
  label: { ...typography.bodyStrong, color: colors.ink[700] },
  etoiles: { flexDirection: 'row', justifyContent: 'space-between', paddingHorizontal: spacing[4] },
});
