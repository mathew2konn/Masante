import React from 'react';
import { useLocalSearchParams } from 'expo-router';
import { MesuresEcran } from '../../../../src/screens/MesuresEcran';

/** Route de l'écran « Journal de santé » d'un membre (FN5 — maladies chroniques). */
export default function MesuresRoute() {
  const { id, nom } = useLocalSearchParams<{ id: string; nom?: string }>();
  return <MesuresEcran membreId={Number(id)} nomMembre={nom} />;
}
