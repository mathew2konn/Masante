/**
 * alertes.ts — Présentation des alertes épidémiques (FN3). Source unique de la couleur, du libellé
 * et de l'icône par niveau, pour que la bannière et l'écran de détail restent cohérents.
 */
import { colors } from '../theme/theme';
import type { NiveauAlerte } from '../types/urgence';

export interface StyleNiveau {
  libelle: string;
  couleur: string;
  fond: string;
  icone: 'information-circle' | 'alert-circle' | 'warning';
}

const STYLES: Record<NiveauAlerte, StyleNiveau> = {
  information: { libelle: 'Information', couleur: colors.blue[700], fond: colors.blue[100], icone: 'information-circle' },
  vigilance: { libelle: 'Vigilance', couleur: colors.warning.text, fond: colors.warning.bg, icone: 'warning' },
  alerte: { libelle: 'Alerte', couleur: colors.danger.text, fond: colors.danger.bg, icone: 'alert-circle' },
};

export function styleNiveau(niveau: NiveauAlerte): StyleNiveau {
  return STYLES[niveau] ?? STYLES.information;
}
