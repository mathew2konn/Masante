import React from 'react';
import { useLocalSearchParams } from 'expo-router';
import { CarteCmuEcran } from '../../../../src/screens/CarteCmuEcran';

/** Route de la carte CMU numérique d'un membre (F2.3). */
export default function CarteCmuRoute() {
  const { id, nom } = useLocalSearchParams<{ id: string; nom?: string }>();
  return <CarteCmuEcran membreId={Number(id)} nomMembre={nom} />;
}
