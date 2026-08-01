import React from 'react';
import { Image, ImageStyle, StyleProp } from 'react-native';

/**
 * Logo — affiche le logo MaSante depuis la SOURCE UNIQUE assets/images/logo.png.
 * Aucune autre copie du fichier ailleurs (§3). Utilisé dans l'en-tête d'accueil.
 */
export function Logo({
  size = 96,
  radius = size * 0.22,
  style,
}: {
  size?: number;
  /** Rayon des coins (défaut : arrondi type icône). */
  radius?: number;
  style?: StyleProp<ImageStyle>;
}) {
  return (
    <Image
      source={require('../../assets/images/logo.png')}
      style={[
        { width: size, height: size, resizeMode: 'cover', borderRadius: radius, overflow: 'hidden' },
        style,
      ]}
      accessibilityLabel="Logo MaSante"
    />
  );
}
