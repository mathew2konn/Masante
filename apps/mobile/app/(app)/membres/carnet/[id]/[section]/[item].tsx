import React from 'react';
import { useLocalSearchParams } from 'expo-router';
import { CarnetSectionForm } from '../../../../../../src/screens/CarnetSectionForm';

/** Édition d'un élément existant d'une section du carnet. */
export default function SectionEditionRoute() {
  const { id, section, item, nom } = useLocalSearchParams<{
    id: string;
    section: string;
    item: string;
    nom?: string;
  }>();
  return (
    <CarnetSectionForm
      membreId={Number(id)}
      slug={section}
      itemId={Number(item)}
      nomMembre={nom || undefined}
    />
  );
}
