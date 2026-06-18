import React from 'react';
import { Image, ImageStyle, StyleProp } from 'react-native';

/**
 * Logo — affiche le logo MaSante depuis la SOURCE UNIQUE assets/images/logo.png.
 * Aucune autre copie du fichier ailleurs (§3). Utilisé dans l'en-tête d'accueil.
 */
export function Logo({ size = 96, style }: { size?: number; style?: StyleProp<ImageStyle> }) {
  return (
    <Image
      source={require('../../assets/images/logo.png')}
      style={[{ width: size, height: size, resizeMode: 'contain' }, style]}
      accessibilityLabel="Logo MaSante"
    />
  );
}
