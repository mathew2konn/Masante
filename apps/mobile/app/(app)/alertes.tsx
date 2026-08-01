import React from 'react';
import { router } from 'expo-router';
import { AlertesEcran } from '../../src/screens/AlertesEcran';

/** FN3 — Détail des alertes épidémiques de la commune de l'utilisateur. */
export default function Alertes() {
  return <AlertesEcran onFermer={() => router.back()} />;
}
