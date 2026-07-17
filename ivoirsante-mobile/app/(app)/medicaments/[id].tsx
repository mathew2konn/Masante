import React from 'react';
import { useLocalSearchParams } from 'expo-router';
import { ComparateurEcran } from '../../../src/screens/ComparateurEcran';

/** Route du comparateur d'UN médicament : prix par pharmacie, génériques, contribution du patient. */
export default function ComparateurRoute() {
  const { id, nom } = useLocalSearchParams<{ id: string; nom?: string }>();
  return <ComparateurEcran medicamentId={Number(id)} nom={nom} />;
}
