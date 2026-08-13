import React, { useState } from 'react';
import { Image, StyleSheet, View, type ImageStyle, type StyleProp } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { API_URL } from '../config/api';
import { colors, radius } from '../theme/theme';
import type { ImageEtablissement } from '../types/structure';

/**
 * components/ImageEtablissementView.tsx — Affichage d'une image d'établissement (P6.4c).
 *
 * ═══ UN SEUL ENDROIT SAIT RÉSOUDRE L'URL ═══
 *
 * Le serveur renvoie une adresse RELATIVE (`/api/v1/structures/12/images/34`) : une adresse absolue
 * serait bâtie sur l'URL Ngrok du moment et deviendrait fausse au prochain redémarrage du tunnel,
 * y compris dans les fiches déjà en cache. La composition avec `API_URL` se fait ici, et nulle part
 * ailleurs — trois écrans qui la referaient chacun de leur côté finiraient par diverger.
 *
 * ═══ L'ÉCHEC EST PRÉVU, PAS SUBI ═══
 *
 * Hors ligne, une image n'est pas disponible : le cache chiffré de P2 stocke du JSON, pas du
 * binaire. `onError` ramène alors l'icône de repli plutôt qu'un rectangle vide ou une croix — la
 * fiche reste lisible, et l'absence d'image ne ressemble pas à une panne.
 */

interface Props {
  image: ImageEtablissement | undefined;
  /** Icône affichée quand il n'y a pas d'image, ou qu'elle n'a pas pu être chargée. */
  repli: keyof typeof Ionicons.glyphMap;
  taille: number;
  style?: StyleProp<ImageStyle>;
  /** Libellé lu par les lecteurs d'écran (§6 : jamais d'image muette). */
  description: string;
}

export default function ImageEtablissementView({ image, repli, taille, style, description }: Props) {
  const [echec, setEchec] = useState(false);

  if (!image || echec) {
    return (
      <View style={[styles.repli, { width: taille, height: taille }, style]}>
        <Ionicons name={repli} size={Math.round(taille * 0.5)} color={colors.blue[600]} />
      </View>
    );
  }

  return (
    <Image
      source={{ uri: `${API_URL}${image.url}` }}
      style={[{ width: taille, height: taille, borderRadius: radius.md }, style]}
      resizeMode="cover"
      accessible
      accessibilityRole="image"
      accessibilityLabel={description}
      onError={() => setEchec(true)}
    />
  );
}

const styles = StyleSheet.create({
  repli: {
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: radius.md,
    backgroundColor: colors.blue[50],
  },
});
