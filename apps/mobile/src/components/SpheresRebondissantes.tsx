import React from 'react';
import { Animated, Easing, StyleSheet, useWindowDimensions } from 'react-native';
import { tokens } from '@masante/shared';

/**
 * Fond animé « sphères qui tombent et rebondissent » (identité MaSanté — ADR-000).
 * Utilisé sur le splash et les écrans d'authentification, par-dessus le dégradé.
 *
 * Implémenté avec l'API Animated NATIVE de React Native (pas reanimated) :
 * reanimated 4 / worklets n'est pas chargeable dans Expo Go (module natif désaligné).
 * useNativeDriver garde l'animation sur le thread natif. Décoratif → non accessible.
 */
type SphereConfig = {
  size: number;
  left: number;
  color: string;
  delay: number;
  duration: number;
};

const TEINTES = [
  tokens.blue[300],
  tokens.blue[400],
  tokens.blue[500],
  tokens.blue[600],
  tokens.semantic.primary,
];

const Sphere = React.memo(function Sphere({
  size,
  left,
  color,
  delay,
  duration,
  floor,
}: SphereConfig & { floor: number }) {
  const y = React.useRef(new Animated.Value(-size)).current;

  React.useEffect(() => {
    // Chute + rebonds amortis (Easing.bounce), répétée à l'infini.
    const anim = Animated.loop(
      Animated.timing(y, {
        toValue: floor,
        duration,
        delay,
        easing: Easing.bounce,
        useNativeDriver: true,
      }),
    );
    anim.start();
    return () => anim.stop();
  }, [y, floor, duration, delay]);

  return (
    <Animated.View
      pointerEvents="none"
      accessibilityElementsHidden
      importantForAccessibility="no-hide-descendants"
      style={[
        {
          position: 'absolute',
          left,
          width: size,
          height: size,
          borderRadius: size / 2,
          backgroundColor: color,
          opacity: 0.75,
        },
        { transform: [{ translateY: y }] },
      ]}
    />
  );
});

export const SpheresRebondissantes = React.memo(function SpheresRebondissantes() {
  const { width, height } = useWindowDimensions();

  const spheres = React.useMemo<SphereConfig[]>(() => {
    const n = 6;
    return Array.from({ length: n }, (_, i) => {
      const size = 28 + ((i * 13) % 44); // 28→~68 px, tailles variées
      return {
        size,
        left: Math.round(((i + 0.5) / n) * (width - size)),
        color: TEINTES[i % TEINTES.length]!,
        delay: (i % n) * 420,
        duration: 2600 + (i % 3) * 700,
      };
    });
  }, [width]);

  const floor = height * 0.62;

  return (
    <Animated.View pointerEvents="none" style={StyleSheet.absoluteFill}>
      {spheres.map((s, i) => (
        <Sphere key={i} {...s} floor={floor} />
      ))}
    </Animated.View>
  );
});
