import React from 'react';
import { Stack } from 'expo-router';
import { VerrouGate } from '../../../src/components/VerrouGate';

/**
 * Pile de navigation des écrans « Membres » (hors barre d'onglets) : liste -> détail ->
 * formulaire. En-têtes masqués : chaque écran pose son propre ScreenHeader (dégradé + retour).
 *
 * Zone SENSIBLE (note Securite_2, chap. 3.2) : dossiers, documents, contacts, QR, carte CMU.
 * Le VerrouGate exige le déverrouillage (biométrie / PIN) avant de monter la pile.
 */
export default function MembresLayout() {
  return (
    <VerrouGate>
      <Stack screenOptions={{ headerShown: false }} />
    </VerrouGate>
  );
}
