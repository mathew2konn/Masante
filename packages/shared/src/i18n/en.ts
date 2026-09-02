/** English translations. Same shape as `fr` (typed by Traductions). */
import type { Traductions } from './fr';

export const en: Traductions = {
  app: {
    nom: 'MaSanté',
    signature: 'The health-guardian elephant',
    slogan: 'Your Health, Our Priority',
  },
  commun: {
    continuer: 'Continue',
    annuler: 'Cancel',
    reessayer: 'Retry',
    chargement: 'Loading…',
    horsLigne: 'Offline',
    erreurReseau: 'Connection problem. Check your network.',
  },
  auth: {
    connexion: 'Sign in',
    inscription: 'Sign up',
    telephone: 'Phone number',
    codeOtp: 'Verification code',
    motDePasseOublie: 'Forgot password',
    seDeconnecter: 'Sign out',
    changerMotDePasse: 'Change my password',
  },
  triage: {
    avertissement:
      'This document is a guidance aid and does not replace a medical diagnosis.',
    // P10b-1 — voir `fr.ts` : les clés sont les valeurs de l'enum, et les trois niveaux hérités
    // du Module 1 restent lisibles pour l'historique.
    niveau: {
      faible: 'Low priority',
      recommandee: 'Consultation recommended',
      rapide: 'Prompt consultation (24 h)',
      urgence: 'Emergency',
      leger: 'Mild',
      modere: 'Moderate',
      urgent: 'Urgent',
    },
  },
  urgence: {
    // P6.8e — voir `fr.ts` : le numéro est sorti de la chaîne, il vient du référentiel national.
    sos: 'SOS',
    mesAlertes: 'My emergency alerts',
  },
  roles: {
    patient: 'Patient',
    medecin: 'Doctor',
    infirmier: 'Nurse',
    personnel_accueil: 'Front-desk staff',
    pharmacien: 'Pharmacist',
    laborantin: 'Lab technician',
    radiologue: 'Radiologist',
    gestionnaire_etablissement: 'Facility manager',
    admin_ivoirsante: 'MaSanté administrator',
    ministere: 'Ministry',
    assurance: 'Insurer',
  },
};
