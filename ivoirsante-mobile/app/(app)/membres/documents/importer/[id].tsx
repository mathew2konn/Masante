import React from 'react';
import { useLocalSearchParams } from 'expo-router';
import { ImportDocumentEcran } from '../../../../../src/screens/ImportDocumentEcran';

/** Route du formulaire d'import d'un document médical (F2.10). */
export default function ImportDocumentRoute() {
  const { id, nom } = useLocalSearchParams<{ id: string; nom?: string }>();
  return <ImportDocumentEcran membreId={Number(id)} nomMembre={nom} />;
}
