import React from 'react';
import { Stack } from 'expo-router';

/**
 * Pile « Paramètres » (hors barre d'onglets) : mot de passe, sécurité (verrou applicatif).
 * En-têtes masqués — chaque écran pose son propre ScreenHeader. Le `_layout` fait de `parametres`
 * une route de navigation unique (comme `membres`/`structures`), attendue par le Tabs racine.
 */
export default function ParametresLayout() {
  return <Stack screenOptions={{ headerShown: false }} />;
}
