import React from 'react';
import { router } from 'expo-router';
import { CarteVitaleEcran } from '../../src/screens/CarteVitaleEcran';

/**
 * FN2 — Carte vitale accessible depuis l'écran de CONNEXION, sans compte ni PIN.
 *
 * Écart assumé au CdC, qui demandait un affichage « depuis l'écran verrouillé du téléphone » :
 * c'est une fonction du système d'exploitation, hors de portée d'Expo. On tient la promesse au plus
 * près — un secouriste qui prend le téléphone atteint la fiche en deux touches, sans rien connaître
 * du patient. Les données viennent d'un cache chiffré local (Keychain/Keystore).
 */
export default function CarteVitalePublique() {
  return <CarteVitaleEcran onFermer={() => router.back()} />;
}
