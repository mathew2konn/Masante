import React from 'react';
import { router } from 'expo-router';
import { CarteVitaleEcran } from '../../../src/screens/CarteVitaleEcran';

/**
 * Aperçu de la carte vitale telle qu'un secouriste la verra. Même écran, mêmes données de cache :
 * le titulaire vérifie ainsi exactement ce qu'il expose avant de fermer l'application.
 */
export default function ApercuCarteVitale() {
  return <CarteVitaleEcran onFermer={() => router.back()} />;
}
