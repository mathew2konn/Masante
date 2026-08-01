import React from 'react';
import { Stack } from 'expo-router';

/**
 * Pile de navigation des structures (hors barre d'onglets, Module 3) : fiche détaillée ->
 * itinéraire. En-têtes masqués : chaque écran pose son propre ScreenHeader (dégradé + retour).
 */
export default function StructuresLayout() {
  return <Stack screenOptions={{ headerShown: false }} />;
}
