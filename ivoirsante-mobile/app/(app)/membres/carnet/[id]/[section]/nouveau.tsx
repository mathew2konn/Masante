import React from 'react';
import { useLocalSearchParams } from 'expo-router';
import { CarnetSectionForm } from '../../../../../../src/screens/CarnetSectionForm';

/** Création d'un élément dans une section du carnet. */
export default function SectionNouveauRoute() {
  const { id, section, nom } = useLocalSearchParams<{ id: string; section: string; nom?: string }>();
  return <CarnetSectionForm membreId={Number(id)} slug={section} nomMembre={nom || undefined} />;
}
