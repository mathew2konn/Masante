import React from 'react';
import { Stack } from 'expo-router';

/**
 * Pile de navigation des écrans « Membres » (hors barre d'onglets) : liste -> détail ->
 * formulaire. En-têtes masqués : chaque écran pose son propre ScreenHeader (dégradé + retour).
 */
export default function MembresLayout() {
  return <Stack screenOptions={{ headerShown: false }} />;
}
