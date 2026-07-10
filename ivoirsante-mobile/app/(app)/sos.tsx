import React from 'react';
import { router } from 'expo-router';
import { SosEcran } from '../../src/screens/SosEcran';

/** FN1 — Écran d'urgence, atteint depuis le bouton SOS de l'accueil. */
export default function Sos() {
  return <SosEcran onFermer={() => router.back()} />;
}
