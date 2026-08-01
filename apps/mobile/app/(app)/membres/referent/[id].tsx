import React from 'react';
import { useLocalSearchParams } from 'expo-router';
import { ReferentEcran } from '../../../../src/screens/ReferentEcran';

/** Route de l'écran « Médecin référent » d'un membre (voie 2 d'accès au dossier). */
export default function ReferentRoute() {
  const { id, nom } = useLocalSearchParams<{ id: string; nom?: string }>();
  return <ReferentEcran membreId={Number(id)} nomMembre={nom} />;
}
