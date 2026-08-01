import React from 'react';
import { useLocalSearchParams } from 'expo-router';
import { DocumentsEcran } from '../../../../src/screens/DocumentsEcran';

/** Route de la liste des documents médicaux d'un membre (F2.10). */
export default function DocumentsRoute() {
  const { id, nom } = useLocalSearchParams<{ id: string; nom?: string }>();
  return <DocumentsEcran membreId={Number(id)} nomMembre={nom} />;
}
