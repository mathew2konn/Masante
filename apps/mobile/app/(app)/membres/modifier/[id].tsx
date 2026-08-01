import React, { useEffect, useState } from 'react';
import { ActivityIndicator, Text } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { Screen } from '../../../../src/components/Screen';
import { ScreenHeader } from '../../../../src/components/ScreenHeader';
import { MembreForm } from '../../../../src/screens/MembreForm';
import { modifierMembre, obtenirMembre } from '../../../../src/api/membres';
import { messageErreur } from '../../../../src/utils/erreurs';
import type { Membre, MembrePayload } from '../../../../src/types/membre';
import { colors, spacing, typography } from '../../../../src/theme/theme';

/** Écran d'édition d'un membre (F2.1). Précharge la fiche puis réutilise MembreForm. */
export default function ModifierMembreScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const membreId = Number(id);

  const [membre, setMembre] = useState<Membre | null>(null);
  const [chargement, setChargement] = useState(true);
  const [chargementErreur, setChargementErreur] = useState<string | null>(null);
  const [envoi, setEnvoi] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);

  useEffect(() => {
    let actif = true;
    (async () => {
      try {
        const m = await obtenirMembre(membreId);
        if (actif) setMembre(m);
      } catch (e) {
        if (actif) setChargementErreur(messageErreur(e));
      } finally {
        if (actif) setChargement(false);
      }
    })();
    return () => {
      actif = false;
    };
  }, [membreId]);

  const enregistrer = async (payload: MembrePayload) => {
    setErreur(null);
    setEnvoi(true);
    try {
      await modifierMembre(membreId, payload);
      router.back(); // retour à la fiche (rechargée au focus).
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setEnvoi(false);
    }
  };

  return (
    <Screen>
      <ScreenHeader title="Modifier le membre" onBack={() => router.back()} />
      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      ) : chargementErreur || !membre ? (
        <Text style={styles.erreur}>{chargementErreur ?? 'Membre introuvable.'}</Text>
      ) : (
        <MembreForm
          initial={membre}
          submitLabel="Enregistrer les modifications"
          submitting={envoi}
          erreurServeur={erreur}
          onSubmit={enregistrer}
        />
      )}
    </Screen>
  );
}

const styles = {
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text },
} as const;
