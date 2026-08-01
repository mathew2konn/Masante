/**
 * Traductions françaises (langue par défaut — CDC_01 §1.4). Source unique mobile + web.
 * Pas de `as const` : les valeurs sont typées `string` pour que `en` (même forme) soit valide.
 */
export const fr = {
  app: {
    nom: 'MaSanté',
    signature: "L'éléphant sanitaire et protecteur",
    slogan: 'Votre Santé Notre Priorité',
  },
  commun: {
    continuer: 'Continuer',
    annuler: 'Annuler',
    reessayer: 'Réessayer',
    chargement: 'Chargement…',
    horsLigne: 'Hors ligne',
    erreurReseau: 'Problème de connexion. Vérifiez votre réseau.',
  },
  auth: {
    connexion: 'Connexion',
    inscription: 'Inscription',
    telephone: 'Numéro de téléphone',
    codeOtp: 'Code de vérification',
    motDePasseOublie: 'Mot de passe oublié',
    seDeconnecter: 'Se déconnecter',
    changerMotDePasse: 'Changer mon mot de passe',
  },
  triage: {
    avertissement:
      "Ce document constitue une aide à l'orientation et ne remplace pas un diagnostic médical.",
    niveau: {
      FAIBLE: 'Faible priorité',
      RECOMMANDEE: 'Consultation recommandée',
      RAPIDE: 'Consultation rapide (24 h)',
      URGENCE: 'Urgence',
    },
  },
  urgence: {
    sos: 'SOS 185',
    mesAlertes: "Mes alertes d'urgence",
  },
};

export type Traductions = typeof fr;
