import React from 'react';
import { useLocalSearchParams } from 'expo-router';
import { CouverturesEcran } from '../../../../src/screens/CouverturesEcran';

/** Route des couvertures santé d'un membre (P6.8d, CDC_09 §8). */
export default function CouverturesRoute() {
  const { id, nom, proprietaire } = useLocalSearchParams<{
    id: string;
    nom?: string;
    proprietaire?: string;
  }>();

  return (
    <CouverturesEcran
      membreId={Number(id)}
      nomMembre={nom}
      // `!== '0'` et non `=== '1'` : sans le paramètre (navigation directe, lien ancien), on ne
      // prive pas le propriétaire de ses actions — le serveur reste juge (403 sur écriture).
      proprietaire={proprietaire !== '0'}
    />
  );
}
