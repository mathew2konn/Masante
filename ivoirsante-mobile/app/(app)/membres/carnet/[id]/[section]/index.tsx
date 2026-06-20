import React from 'react';
import { useLocalSearchParams } from 'expo-router';
import { CarnetSectionListe } from '../../../../../../src/screens/CarnetSectionListe';

/** Liste d'une section du carnet d'un membre (route paramétrée : id membre + slug section). */
export default function SectionListeRoute() {
  const { id, section, nom } = useLocalSearchParams<{ id: string; section: string; nom?: string }>();
  return <CarnetSectionListe membreId={Number(id)} slug={section} nomMembre={nom || undefined} />;
}
