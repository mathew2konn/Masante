import React, { useState } from 'react';
import { router } from 'expo-router';
import { Screen } from '../../../src/components/Screen';
import { ScreenHeader } from '../../../src/components/ScreenHeader';
import { MembreForm } from '../../../src/screens/MembreForm';
import { creerMembre } from '../../../src/api/membres';
import { messageErreur } from '../../../src/utils/erreurs';
import type { MembrePayload } from '../../../src/types/membre';

/** Écran de création d'un membre de la famille (F2.1). */
export default function NouveauMembreScreen() {
  const [chargement, setChargement] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);

  const creer = async (payload: MembrePayload) => {
    setErreur(null);
    setChargement(true);
    try {
      await creerMembre(payload);
      router.back(); // retour à la liste (rechargée au focus).
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  };

  return (
    <Screen>
      <ScreenHeader title="Nouveau membre" subtitle="Ajouter un proche au carnet familial" onBack={() => router.back()} />
      <MembreForm submitLabel="Ajouter le membre" submitting={chargement} erreurServeur={erreur} onSubmit={creer} />
    </Screen>
  );
}
