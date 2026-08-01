import { fr } from './fr';
import { en } from './en';

export type { Traductions } from './fr';
export const langues = { fr, en } as const;
export type LangueCode = keyof typeof langues;
export const langueParDefaut: LangueCode = 'fr';
export { fr, en };
