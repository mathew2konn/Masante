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
    secretaire: 'Secrétaire',
    pharmacien: 'Pharmacien',
    laborantin: 'Laborantin',
    radiologue: 'Radiologue',
    admin_etablissement: "Admin. d'établissement",
    super_admin: 'Super administrateur',
    ministere: 'Ministère',
    assurance: 'Assurance',
  },
};

export type Traductions = typeof fr;
