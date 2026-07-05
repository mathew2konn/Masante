import React, { useEffect, useMemo, useRef } from 'react';
import {
  Animated,
  NativeScrollEvent,
  NativeSyntheticEvent,
  ScrollView,
  StyleSheet,
  Text,
} from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { colors, spacing, typography } from '../theme/theme';

/**
 * DateWheelPicker — sélecteur de date « molette » à 3 colonnes (jour / mois / année).
 * `ScrollView` natif (inertie + snap) + fondus haut/bas + cadre de sélection central ; l'élément
 * centré est mis en avant (agrandi + net) via `scale`/`opacity` pilotés en NATIF par le défilement.
 *
 * Perf : on évite volontairement le transform 3D `rotateX` (couche 3D par élément = coût GPU lourd
 * sur entrée de gamme). On garde des transforms 2D (scale + opacité) légers. Chaque élément est
 * mémoïsé et les éléments hors écran sont détachés (`removeClippedSubviews`) → défilement fluide.
 */

const ITEM_HEIGHT = 40;
const VISIBLE_ITEMS = 5;
const CENTER_OFFSET = Math.floor(VISIBLE_ITEMS / 2) * ITEM_HEIGHT; // 80

function joursDansMois(annee: number, mois: number): number {
  return new Date(annee, mois + 1, 0).getDate();
}

function nomsDeMois(locale: string): string[] {
  const fmt = new Intl.DateTimeFormat(locale, { month: 'long' });
  return Array.from({ length: 12 }, (_, i) => {
    const nom = fmt.format(new Date(2000, i, 1));
    return nom.charAt(0).toUpperCase() + nom.slice(1); // « Janvier » plutôt que « janvier »
  });
}

/** Un élément de roue (mémoïsé) : transforms 3D pilotés par le défilement natif. */
const WheelItem = React.memo(function WheelItem({
  item,
  index,
  scrollY,
  actif,
}: {
  item: string | number;
  index: number;
  scrollY: Animated.Value;
  actif: boolean;
}) {
  const centre = index * ITEM_HEIGHT;
  const scale = scrollY.interpolate({
    inputRange: [centre - 2 * ITEM_HEIGHT, centre, centre + 2 * ITEM_HEIGHT],
    outputRange: [0.8, 1, 0.8],
    extrapolate: 'clamp',
  });
  const opacity = scrollY.interpolate({
    inputRange: [centre - 2 * ITEM_HEIGHT, centre - ITEM_HEIGHT, centre, centre + ITEM_HEIGHT, centre + 2 * ITEM_HEIGHT],
    outputRange: [0.25, 0.55, 1, 0.55, 0.25],
    extrapolate: 'clamp',
  });
  return (
    <Animated.View style={[styles.item, { opacity, transform: [{ scale }] }]}>
      <Text style={[styles.itemTxt, actif && styles.itemTxtActif]} numberOfLines={1}>
        {item}
      </Text>
    </Animated.View>
  );
});

/** Une colonne défilante (roue). Émet l'index calé au centre. */
function WheelColumn({
  items,
  value,
  onChange,
  width,
  ariaLabel,
}: {
  items: (string | number)[];
  value: number;
  onChange: (index: number) => void;
  width: number;
  ariaLabel: string;
}) {
  const scrollRef = useRef<ScrollView>(null);
  const scrollY = useRef(new Animated.Value(value * ITEM_HEIGHT)).current;

  // Recale la roue quand la valeur change de l'extérieur (ex. jour replafonné au changement de mois).
  useEffect(() => {
    scrollRef.current?.scrollTo({ y: value * ITEM_HEIGHT, animated: true });
  }, [value]);

  const onMomentumEnd = (e: NativeSyntheticEvent<NativeScrollEvent>) => {
    const brut = e.nativeEvent.contentOffset.y / ITEM_HEIGHT;
    const index = Math.max(0, Math.min(items.length - 1, Math.round(brut)));
    if (index !== value) onChange(index);
  };

  return (
    <Animated.ScrollView
      ref={scrollRef as never}
      style={{ width, height: ITEM_HEIGHT * VISIBLE_ITEMS }}
      showsVerticalScrollIndicator={false}
      snapToInterval={ITEM_HEIGHT}
      decelerationRate="fast"
      scrollEventThrottle={16}
      removeClippedSubviews
      contentOffset={{ x: 0, y: value * ITEM_HEIGHT }}
      contentContainerStyle={{ paddingVertical: CENTER_OFFSET }}
      accessibilityLabel={ariaLabel}
      onScroll={Animated.event([{ nativeEvent: { contentOffset: { y: scrollY } } }], { useNativeDriver: true })}
      onMomentumScrollEnd={onMomentumEnd}
    >
      {items.map((item, index) => (
        <WheelItem key={`${item}-${index}`} item={item} index={index} scrollY={scrollY} actif={index === value} />
      ))}
    </Animated.ScrollView>
  );
}

export function DateWheelPicker({
  value,
  onChange,
  minYear = 1920,
  // Par défaut on autorise le futur proche (validité CMU, rappels…). Les champs « pas de futur »
  // (naissance, diagnostic) passent un maxYear explicite et sont replafonnés par DateField.
  maxYear = new Date().getFullYear() + 10,
  locale = 'fr-FR',
}: {
  value: Date;
  onChange: (date: Date) => void;
  minYear?: number;
  maxYear?: number;
  locale?: string;
}) {
  const mois = useMemo(() => nomsDeMois(locale), [locale]);
  const annees = useMemo(() => {
    const arr: number[] = [];
    for (let y = maxYear; y >= minYear; y--) arr.push(y);
    return arr;
  }, [minYear, maxYear]);

  const jour = value.getDate();
  const indexMois = value.getMonth();
  const annee = value.getFullYear();

  const jours = useMemo(
    () => Array.from({ length: joursDansMois(annee, indexMois) }, (_, i) => i + 1),
    [annee, indexMois],
  );
  const indexAnnee = Math.max(0, annees.indexOf(annee));

  const majJour = (i: number) => onChange(new Date(annee, indexMois, i + 1));
  const majMois = (i: number) => onChange(new Date(annee, i, Math.min(jour, joursDansMois(annee, i))));
  const majAnnee = (i: number) => {
    const na = annees[i];
    onChange(new Date(na, indexMois, Math.min(jour, joursDansMois(na, indexMois))));
  };

  return (
    <Animated.View style={styles.rangee} accessibilityRole="adjustable" accessibilityLabel="Sélecteur de date">
      <WheelColumn items={jours} value={jour - 1} onChange={majJour} width={64} ariaLabel="Jour" />
      <WheelColumn items={mois} value={indexMois} onChange={majMois} width={132} ariaLabel="Mois" />
      <WheelColumn items={annees} value={indexAnnee} onChange={majAnnee} width={84} ariaLabel="Année" />

      {/* Fondus haut/bas + cadre de sélection central, superposés (non interactifs). */}
      <LinearGradient pointerEvents="none" colors={[colors.surface, 'transparent']} style={[styles.fondu, { top: 0 }]} />
      <LinearGradient pointerEvents="none" colors={['transparent', colors.surface]} style={[styles.fondu, { bottom: 0 }]} />
      <Animated.View pointerEvents="none" style={styles.cadre} />
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  rangee: { flexDirection: 'row', justifyContent: 'center', gap: spacing[3], paddingVertical: spacing[2] },
  item: { height: ITEM_HEIGHT, alignItems: 'center', justifyContent: 'center' },
  itemTxt: { ...typography.body, color: colors.ink[500] },
  itemTxtActif: { ...typography.bodyStrong, color: colors.ink[900] },
  // Fondus et cadre couvrent toute la rangée (les 3 colonnes) : hauteur = paddingVertical + roue.
  fondu: { position: 'absolute', left: 0, right: 0, height: CENTER_OFFSET + spacing[2], zIndex: 10 },
  cadre: {
    position: 'absolute',
    left: spacing[2],
    right: spacing[2],
    top: CENTER_OFFSET + spacing[2],
    height: ITEM_HEIGHT,
    borderTopWidth: 1,
    borderBottomWidth: 1,
    borderColor: colors.line,
    zIndex: 5,
  },
});
