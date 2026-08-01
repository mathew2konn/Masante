/**
 * Palette MaSanté — dérivée de la SOURCE UNIQUE `palette.json` (ADR-000 : Bleu Santé + Orange).
 * `palette.json` est la seule liste de valeurs brutes ; Tailwind (JS) et les composants (TS)
 * la consomment tous les deux → aucune divergence possible (CDC_01 §4.6, CDC_02 §4.2).
 */
import palette from './palette.json';

export const blue = palette.blue;
export const orange = palette.orange;
export const gradient = palette.gradient;
export const ink = palette.ink;

/** Couleurs sémantiques santé (réservées au sens, jamais décoratives). */
export const semantic = {
  primary: palette.semantic.primary,
  secondary: palette.semantic.secondary,
  success: {
    solid: palette.semantic.successSolid,
    bg: palette.semantic.successBg,
    text: palette.semantic.successText,
  },
  warning: {
    solid: palette.semantic.warningSolid,
    bg: palette.semantic.warningBg,
    text: palette.semantic.warningText,
  },
  danger: {
    solid: palette.semantic.dangerSolid,
    bg: palette.semantic.dangerBg,
    text: palette.semantic.dangerText,
  },
  info: {
    solid: palette.semantic.infoSolid,
    bg: palette.semantic.infoBg,
    text: palette.semantic.infoText,
  },
  surface: palette.semantic.surface,
  surfaceMuted: palette.semantic.surfaceMuted,
  background: palette.semantic.background,
  line: palette.semantic.line,
  disabled: palette.semantic.disabled,
} as const;

/**
 * Couleurs de triage — TOUJOURS couleur + texte + icône (jamais la couleur seule,
 * WCAG 2.2 AA — CDC_01 §13). 4 niveaux patient, 5 niveaux hospitaliers.
 */
export const triage = palette.triage;
