import React from 'react';
import { useLocalSearchParams } from 'expo-router';
import { ContactsUrgenceEcran } from '../../../../src/screens/ContactsUrgenceEcran';

/** Route de l'écran dédié « Contacts d'urgence » d'un membre (F2.11). */
export default function ContactsUrgenceRoute() {
  const { id, nom } = useLocalSearchParams<{ id: string; nom?: string }>();
  return <ContactsUrgenceEcran membreId={Number(id)} nomMembre={nom} />;
}
