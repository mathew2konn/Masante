/**
 * Schémas Zod partagés — authentification téléphone + OTP (ADR-002, ADR-007).
 * Consommés par le mobile (RHF) et le web (RHF). Une seule définition (anti-divergence, CDC_02 §2.2).
 * NB : la validation métier fait autorité côté backend ; ces schémas ne font QUE la validation de saisie.
 */
import { z } from 'zod';

/** Téléphone ivoirien : 10 chiffres, préfixe 0 (format local ; le pays vient du référentiel). */
export const telephoneSchema = z
  .string()
  .trim()
  .regex(/^0\d{9}$/, 'Numéro invalide (10 chiffres, ex. 0700000000).');

/** Code OTP à 6 chiffres. */
export const otpSchema = z
  .string()
  .trim()
  .regex(/^\d{6}$/, 'Le code doit contenir 6 chiffres.');

export const connexionSchema = z.object({
  telephone: telephoneSchema,
});
export type ConnexionInput = z.infer<typeof connexionSchema>;

export const verificationOtpSchema = z.object({
  telephone: telephoneSchema,
  code: otpSchema,
});
export type VerificationOtpInput = z.infer<typeof verificationOtpSchema>;

export const inscriptionSchema = z.object({
  telephone: telephoneSchema,
  nom: z.string().trim().min(2, 'Nom requis.'),
  prenom: z.string().trim().min(2, 'Prénom requis.'),
});
export type InscriptionInput = z.infer<typeof inscriptionSchema>;

/** Connexion professionnelle (portail web) : téléphone + mot de passe (compte vérifié). */
export const connexionProSchema = z.object({
  telephone: telephoneSchema,
  password: z.string().min(1, 'Mot de passe requis.'),
});
export type ConnexionProInput = z.infer<typeof connexionProSchema>;
