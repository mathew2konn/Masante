import React from 'react';
import { Stack } from 'expo-router';

/** Pile d'écrans d'authentification (déconnecté). En-têtes masqués : chaque écran gère le sien. */
export default function AuthLayout() {
  return <Stack screenOptions={{ headerShown: false }} />;
}
