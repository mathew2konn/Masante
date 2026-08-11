/**
 * @masante/shared — SOURCE UNIQUE partagée mobile + web (CDC_01 §4, CDC_02 §2.2).
 * Tokens du Design System, enums d'état métier, schémas Zod, i18n.
 * Aucune redéfinition locale de ces éléments dans une app : on importe toujours d'ici.
 */
export * as tokens from './tokens';
export * from './enums';
export * from './schemas';
export * from './nis';
export * as i18n from './i18n';
