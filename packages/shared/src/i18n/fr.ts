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
    // P10b-1 — LES CLÉS SONT LES VALEURS DE L'ENUM, pas ses noms de constante.
    // Un écran reçoit `niveau: 'faible'` du backend et cherche son libellé : indexer par
    // `FAIBLE` l'obligerait à convertir, donc à porter une table de correspondance de plus.
    niveau: {
      faible: 'Faible priorité',
      recommandee: 'Consultation recommandée',
      rapide: 'Consultation rapide (24 h)',
      urgence: 'Urgence',
      // Les trois du Module 1 : plus rien ne les produit, l'historique les porte encore.
      leger: 'Léger',
      modere: 'Modéré',
      urgent: 'Urgent',
    },
  },
  urgence: {
    // P6.8e — LE NUMÉRO EST SORTI DE LA CHAÎNE. Il y était collé (« SOS 185 »), dans le paquet même
    // qui sert de source unique : une traduction qui porte une donnée a l'apparence d'une source
    // unique et se périme en silence. Le numéro vient désormais du référentiel national et
    // s'accole à ce libellé au moment de l'affichage.
    sos: 'SOS',
    mesAlertes: "Mes alertes d'urgence",
  },
  // Libellés des rôles RBAC (clés = valeurs de l'enum Role). Fournis par le backend, AFFICHÉS ici.
  roles: {
    patient: 'Patient',
    medecin: 'Médecin',
    infirmier: 'Infirmier',
    personnel_accueil: "Personnel d'accueil",
    pharmacien: 'Pharmacien',
    laborantin: 'Laborantin',
    radiologue: 'Radiologue',
    gestionnaire_etablissement: "Gestionnaire d'établissement",
    admin_ivoirsante: 'Administrateur MaSanté',
    ministere: 'Ministère',
    assurance: 'Assurance',
  },
};

export type Traductions = typeof fr;
