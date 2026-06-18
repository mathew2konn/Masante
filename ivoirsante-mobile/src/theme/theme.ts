/**
 * theme.ts — SOURCE UNIQUE DE VÉRITÉ du Design System MaSante (IVOIRSANTÉ).
 *
 * Tokens repris à l'identique du document DesignSystem_IVOIRSANTE (chap. 2 à 7).
 * RÈGLE D'OR : aucune couleur, espacement ou taille n'est jamais écrit en dur
 * dans un écran — tout passe par ces tokens (cohérence sur les 5 modules).
 */

export const colors = {
  // 2.1 — Échelle tonale du bleu de marque (clair -> foncé)
  blue: {
    50: '#F2F8FD', 100: '#DCEAF8', 200: '#BCD6F0', 300: '#93BBE6', 400: '#6BA0DD',
    500: '#2E83CC', 600: '#1E6BB8', 700: '#17569A', 800: '#124680', 900: '#0C3463',
  },

  // 2.2 — Dégradé signature : du HAUT (clair) vers le BAS (bleu pur). 8 arrêts.
  gradient: [
    '#F2F8FD', '#DCEAF8', '#BCD6F0', '#93BBE6',
    '#6BA0DD', '#4684CE', '#2E6FBE', '#1E5BAA',
  ],

  // 2.3 — Couleurs sémantiques santé (réservées au sens, jamais décoratives)
  success: { solid: '#1F9D57', bg: '#E4F5EA', text: '#146C3A' }, // VERT — léger / disponible
  warning: { solid: '#E8911F', bg: '#FDF0DD', text: '#9A5A07' }, // ORANGE — modéré / avertissement
  danger:  { solid: '#DC2626', bg: '#FCE8E8', text: '#A11616' }, // ROUGE — urgent / SOS / danger

  // 2.4 — Neutres
  ink: { 900: '#11212E', 700: '#33475A', 500: '#62768A' },
  line: '#D9E2EA',
  surface: '#FFFFFF',
  surfaceMuted: '#F3F7FB',
  disabled: '#AEBDCB',
} as const;

// 4.1 — Espacement (grille base 4)
export const spacing = { 1: 4, 2: 8, 3: 12, 4: 16, 5: 20, 6: 24, 8: 32, 10: 40 } as const;

// 4.2 — Arrondis
export const radius = { sm: 8, md: 12, lg: 16, card: 24, pill: 999 } as const;

// 3 — Typographie (police système ; la hiérarchie passe par graisse + couleur)
export const typography = {
  display:    { fontSize: 48, fontWeight: '800', lineHeight: 56 }, // score de triage 0-100
  h1:         { fontSize: 24, fontWeight: '800', lineHeight: 30 },
  h2:         { fontSize: 20, fontWeight: '700', lineHeight: 26 },
  body:       { fontSize: 16, fontWeight: '400', lineHeight: 24 },
  bodyStrong: { fontSize: 16, fontWeight: '600', lineHeight: 24 },
  button:     { fontSize: 16, fontWeight: '700' },
  caption:    { fontSize: 13, fontWeight: '400', lineHeight: 18 },
} as const;

// 4.3 — Élévation (ombre douce niveau 1, cartes)
export const shadow = {
  card: {
    shadowColor: '#0C3463',
    shadowOpacity: 0.06,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 2 },
    elevation: 2,
  },
} as const;
