import React, { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import Svg, { Circle, Line, Polyline, Rect } from 'react-native-svg';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * CourbeMesure (FN5 « graphiques d'évolution ») — courbe d'un type de mesure dans le temps.
 *
 * Volontairement minimale : pas de librairie de graphes (une dépendance de plus à maintenir pour
 * une polyligne), pas d'axes chiffrés qui surchargeraient un écran de téléphone. Ce que le patient
 * doit lire d'un coup d'œil : est-ce que ça monte, est-ce que ça descend, et est-ce que je suis
 * DANS la bande normale — d'où la bande verte, tracée à partir des seuils du SERVEUR (jamais
 * recalculés ici). Les points hors norme sont en orange, les critiques en rouge.
 *
 * Une seule mesure ne fait pas une courbe : on affiche alors juste le point.
 */

export interface PointCourbe {
  /** Horodatage de la mesure (ms) — l'axe X. */
  t: number;
  valeur: number;
  statut: 'normal' | 'eleve' | 'bas' | 'critique';
}

const HAUTEUR = 140;
const MARGE = 8;

export function CourbeMesure({
  points,
  normalMin,
  normalMax,
  unite,
  legendeDebut,
  legendeFin,
}: {
  points: PointCourbe[];
  normalMin: number;
  normalMax: number;
  unite: string;
  legendeDebut: string;
  legendeFin: string;
}) {
  const [largeur, setLargeur] = useState(0);

  if (points.length === 0) return null;

  // L'échelle verticale englobe les valeurs ET la bande normale : sans quoi, un patient toujours
  // hors norme verrait une bande invisible et croirait la courbe « normale ».
  const valeurs = points.map((p) => p.valeur);
  const bas = Math.min(...valeurs, normalMin);
  const haut = Math.max(...valeurs, normalMax);
  const amplitude = haut - bas || 1;
  // 10 % d'air en haut et en bas, pour que les points ne collent pas au bord.
  const min = bas - amplitude * 0.1;
  const max = haut + amplitude * 0.1;

  const y = (v: number) => MARGE + (1 - (v - min) / (max - min)) * (HAUTEUR - 2 * MARGE);
  const x = (i: number) =>
    points.length === 1
      ? largeur / 2
      : MARGE + (i / (points.length - 1)) * Math.max(0, largeur - 2 * MARGE);

  const teinte = (statut: PointCourbe['statut']) =>
    statut === 'critique' ? colors.danger.solid : statut === 'normal' ? colors.blue[600] : colors.warning.solid;

  return (
    <View style={styles.wrap} onLayout={(e) => setLargeur(e.nativeEvent.layout.width)}>
      {largeur > 0 ? (
        <Svg width={largeur} height={HAUTEUR}>
          {/* Bande normale (seuils du serveur) : le repère visuel principal. */}
          <Rect
            x={0}
            y={y(normalMax)}
            width={largeur}
            height={Math.max(1, y(normalMin) - y(normalMax))}
            fill={colors.success.bg}
          />
          <Line x1={0} y1={y(normalMax)} x2={largeur} y2={y(normalMax)} stroke={colors.success.solid} strokeWidth={1} strokeDasharray="4 4" />
          <Line x1={0} y1={y(normalMin)} x2={largeur} y2={y(normalMin)} stroke={colors.success.solid} strokeWidth={1} strokeDasharray="4 4" />

          {points.length > 1 ? (
            <Polyline
              points={points.map((p, i) => `${x(i)},${y(p.valeur)}`).join(' ')}
              fill="none"
              stroke={colors.blue[600]}
              strokeWidth={2}
            />
          ) : null}

          {points.map((p, i) => (
            <Circle key={`${p.t}-${i}`} cx={x(i)} cy={y(p.valeur)} r={4} fill={teinte(p.statut)} />
          ))}
        </Svg>
      ) : (
        <View style={{ height: HAUTEUR }} />
      )}

      <View style={styles.legende}>
        <Text style={styles.legendeTxt}>{legendeDebut}</Text>
        <Text style={styles.legendeNorme}>
          Norme {normalMin}–{normalMax} {unite}
        </Text>
        <Text style={styles.legendeTxt}>{legendeFin}</Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    backgroundColor: colors.surface,
    borderRadius: radius.sm,
    borderWidth: 1,
    borderColor: colors.line,
    paddingVertical: spacing[2],
    marginTop: spacing[3],
  },
  legende: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingHorizontal: spacing[2],
    marginTop: spacing[1],
  },
  legendeTxt: { ...typography.caption, color: colors.ink[500] },
  legendeNorme: { ...typography.caption, color: colors.success.text },
});
