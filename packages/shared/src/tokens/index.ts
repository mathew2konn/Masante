/** Tokens du Design System MaSanté (partagés mobile + web) — source unique `palette.json`. */
import palette from './palette.json';

export * from './colors';

/** Espacements — échelle imposée (CDC_01 §4.4 / CDC_02 §6). Aucune valeur hors échelle. */
export const spacing = palette.spacing;

/** Arrondis. */
export const radius = palette.radius;

/** Échelle typographique imposée — police Inter (CDC_01 §4.3). */
export const fontSize = palette.fontSize;

export const fontFamily = { sans: 'Inter' } as const;

/** Ombre douce (cartes). */
export const shadow = {
  card: {
    shadowColor: '#0C3463',
    shadowOpacity: 0.06,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 2 },
    elevation: 2,
  },
} as const;

/** Accès direct à la palette brute (pour l'outillage : presets Tailwind, tests). */
export { palette };
