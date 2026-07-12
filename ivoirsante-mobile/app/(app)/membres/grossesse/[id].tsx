import React from 'react';
import { useLocalSearchParams } from 'expo-router';
import { GrossesseEcran } from '../../../../src/screens/GrossesseEcran';

/** Route de l'écran dédié « Suivi de grossesse » d'un membre (FN4). */
export default function GrossesseRoute() {
  const { id, nom } = useLocalSearchParams<{ id: string; nom?: string }>();
  return <GrossesseEcran membreId={Number(id)} nomMembre={nom} />;
}
