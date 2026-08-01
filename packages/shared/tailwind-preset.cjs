/**
 * Preset Tailwind partagé MaSanté — SOURCE UNIQUE consommée par le mobile (NativeWind)
 * ET le web (Tailwind). Les valeurs proviennent toutes de `palette.json` : aucune couleur
 * n'est réécrite ici. Ajouter une couleur = la déclarer dans palette.json, jamais ici.
 */
const palette = require('./src/tokens/palette.json');

const toPx = (obj) =>
  Object.fromEntries(Object.entries(obj).map(([k, v]) => [k, `${v}px`]));

/** @type {import('tailwindcss').Config} */
module.exports = {
  theme: {
    extend: {
      colors: {
        primary: palette.semantic.primary,
        secondary: palette.semantic.secondary,
        blue: palette.blue,
        orange: palette.orange,
        ink: palette.ink,
        triage: palette.triage,
        success: palette.semantic.successSolid,
        warning: palette.semantic.warningSolid,
        danger: palette.semantic.dangerSolid,
        info: palette.semantic.infoSolid,
        surface: palette.semantic.surface,
        'surface-muted': palette.semantic.surfaceMuted,
        background: palette.semantic.background,
        line: palette.semantic.line,
        disabled: palette.semantic.disabled,
      },
      spacing: toPx(palette.spacing),
      borderRadius: {
        sm: `${palette.radius.sm}px`,
        md: `${palette.radius.md}px`,
        lg: `${palette.radius.lg}px`,
        card: `${palette.radius.card}px`,
        pill: `${palette.radius.pill}px`,
      },
      fontSize: toPx(palette.fontSize),
      fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
    },
  },
};
