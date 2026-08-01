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
    niveau: {
      FAIBLE: 'Low priority',
      RECOMMANDEE: 'Consultation recommended',
      RAPIDE: 'Prompt consultation (24 h)',
      URGENCE: 'Emergency',
    },
  },
  urgence: {
    sos: 'SOS 185',
    mesAlertes: 'My emergency alerts',
  },
  roles: {
    patient: 'Patient',
    medecin: 'Doctor',
    infirmier: 'Nurse',
    secretaire: 'Secretary',
    pharmacien: 'Pharmacist',
    laborantin: 'Lab technician',
    radiologue: 'Radiologist',
    admin_etablissement: 'Facility admin',
    super_admin: 'Super administrator',
    ministere: 'Ministry',
    assurance: 'Insurer',
  },
};
