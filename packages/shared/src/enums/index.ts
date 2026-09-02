/**
 * Enums d'état métier — SOURCE UNIQUE (CDC_01 §6.3, CDC_04 §5.2, CDC_06 §4.2).
 * Ces états sont FOURNIS PAR LE BACKEND ; l'interface les affiche, ne les déduit jamais.
 */

// Permissions RBAC (P11.0) — liste gardée contre la divergence par un test PHP.
export * from './permissions';

/**
 * États d'un rendez-vous (`rendez_vous.statut`) — B1-a.
 *
 * ═══ CET ENUM REMPLACE UNE CLÉ MORTE ═══
 *
 * `RendezVousStatut` existait depuis P0 avec sept valeurs (`PREVALIDE_SECRETAIRE` compris) et
 * n'était importé NULLE PART dans le monorepo — le G0 de B1 l'a confirmé par recherche
 * exhaustive. Pendant ce temps, le vrai contrat (cinq valeurs, aucune pré-validation) était
 * dupliqué INDÉPENDAMMENT trois fois : `RendezVousValidationService::STATUTS` (PHP),
 * `apps/web/src/lib/rdv-types.ts`, `apps/mobile/src/types/structure.ts`. Même défaut que
 * `TypeAccesDossier` avant l'incrément D2 (P7) : une source unique qui n'est consommée par
 * personne n'est pas une source unique.
 *
 * Miroir PHP : `App\Services\RendezVousValidationService::STATUTS`.
 */
export const RendezVousStatut = {
  /** Créé par le patient, pas encore traité. */
  EN_ATTENTE: 'en_attente',
  /** Pré-validé par l'accueil (CDC_11 §9.1) — reste à confirmer par le médecin. */
  PREVALIDE: 'prevalide',
  /** Confirmé par le médecin : validation finale. */
  CONFIRME: 'confirme',
  /** Refusé par l'accueil (d'emblée) ou par le médecin (au dernier moment). */
  REFUSE: 'refuse',
  /** Annulé par le patient. */
  ANNULE: 'annule',
  /** Le patient s'est présenté et a été pris en charge. */
  HONORE: 'honore',
} as const;
export type RendezVousStatut = (typeof RendezVousStatut)[keyof typeof RendezVousStatut];

/** Libellés destinés au patient et au staff (mêmes mots des deux côtés — aucun jargon métier ici). */
export const LIBELLE_RDV: Record<RendezVousStatut, string> = {
  [RendezVousStatut.EN_ATTENTE]: 'En attente',
  [RendezVousStatut.PREVALIDE]: 'Pré-validé',
  [RendezVousStatut.CONFIRME]: 'Confirmé',
  [RendezVousStatut.REFUSE]: 'Refusé',
  [RendezVousStatut.ANNULE]: 'Annulé',
  [RendezVousStatut.HONORE]: 'Honoré',
};

/** États d'une transaction de paiement (machine à états stricte — CDC_06 §4.2). */
export const PaiementStatut = {
  INITIATED: 'INITIATED',
  PENDING: 'PENDING',
  PROCESSING: 'PROCESSING',
  SUCCESS: 'SUCCESS',
  FAILED: 'FAILED',
  CANCELLED: 'CANCELLED',
  REFUNDED: 'REFUNDED',
} as const;
export type PaiementStatut = (typeof PaiementStatut)[keyof typeof PaiementStatut];

/** États d'une facture (CDC_06 §7). Fournis par le backend (microservice paiement), jamais déduits. */
export const FactureStatut = {
  EMISE: 'EMISE',
  PARTIELLEMENT_PAYEE: 'PARTIELLEMENT_PAYEE',
  PAYEE: 'PAYEE',
  ANNULEE: 'ANNULEE',
  REMPLACEE: 'REMPLACEE',
} as const;
export type FactureStatut = (typeof FactureStatut)[keyof typeof FactureStatut];

/** États d'un portefeuille Wallet (CDC_06 §6). Fourni par le backend, jamais déduit. */
export const WalletStatut = {
  ACTIF: 'ACTIF',
  GELE: 'GELE',
  CLOTURE: 'CLOTURE',
} as const;
export type WalletStatut = (typeof WalletStatut)[keyof typeof WalletStatut];

/**
 * Niveaux de triage côté patient (4, CDC_05 §5.3) — couleur + texte + icône obligatoires.
 *
 * ═══ P10b-1 — CET ENUM EXISTAIT DEPUIS P0 ET N'ÉTAIT CONSOMMÉ PAR PERSONNE ═══
 *
 * Le backend rendait trois niveaux (`leger`/`modere`/`urgent`) et le mobile les REDÉFINISSAIT
 * localement dans `types/triage.ts` — une infraction à la règle de source unique, de la même
 * famille que les communes d'Abidjan en dur (P6.4b) et les libellés de statut vaccinal (P6.8b).
 *
 * C'est **la première clé dormante du projet dont la version endormie était la JUSTE** : `loinc`,
 * les codes CIM, `numero_agrement` ou `specialites_json` étaient vides ou morts, alors qu'ici la
 * bonne réponse attendait dans la source unique pendant que le code en appliquait une autre.
 *
 * ═══ LES VALEURS SONT EN MINUSCULES ═══
 *
 * Comme `Role` ci-dessous (« valeurs = noms spatie côté backend »). La colonne `triages.niveau`
 * porte déjà `leger`/`modere`/`urgent` en minuscules depuis le Module 1 : y ajouter `FAIBLE` en
 * majuscules ferait cohabiter deux conventions dans la MÊME colonne, et rendrait toute comparaison
 * dépendante de la collation — le défaut trouvé au G2 de P6.8c.
 */
export const TriageNiveauPatient = {
  FAIBLE: 'faible',
  RECOMMANDEE: 'recommandee',
  RAPIDE: 'rapide',
  URGENCE: 'urgence',
} as const;
export type TriageNiveauPatient = (typeof TriageNiveauPatient)[keyof typeof TriageNiveauPatient];

/**
 * Les trois niveaux du Module 1, CONSERVÉS pour l'historique (P10b-1).
 *
 * Plus rien ne les produit ; tout doit encore savoir les lire. Convertir les triages passés
 * changerait ce qu'un patient a réellement lu sur son écran — un mensonge d'archive, refusé pour
 * la même raison que `mesures_sante.referentiel_version` laissée `NULL` en L1+L2.
 */
export const TriageNiveauHerite = {
  LEGER: 'leger',
  MODERE: 'modere',
  URGENT: 'urgent',
} as const;
export type TriageNiveauHerite = (typeof TriageNiveauHerite)[keyof typeof TriageNiveauHerite];

/** Ce que la colonne `triages.niveau` peut porter : les quatre actuels et les trois hérités. */
export type TriageNiveau = TriageNiveauPatient | TriageNiveauHerite;

/** Niveaux de triage hospitalier (5) — Manchester / ESI, paramétrables par pays. */
export const TriageNiveauHospitalier = {
  ROUGE: 'ROUGE',
  ORANGE: 'ORANGE',
  JAUNE: 'JAUNE',
  VERT: 'VERT',
  BLEU: 'BLEU',
} as const;
export type TriageNiveauHospitalier =
  (typeof TriageNiveauHospitalier)[keyof typeof TriageNiveauHospitalier];

/**
 * Rôles RBAC (CDC_10 §3.6). Valeurs = noms spatie côté backend (snake_case minuscule,
 * guard `web`), tels que renvoyés par `getRoleNames()`. Le mobile n'utilise que `patient` ;
 * les autres servent au portail web (ADR-011).
 *
 * ═══ P11.0 — ONZE RÔLES, UN NOM PAR MÉTIER, TROIS DOUBLONS RETIRÉS ═══
 *
 * Cette liste et celle de Laravel avaient divergé, et la divergence était structurelle : cet
 * enum énumérait onze rôles dont **trois n'ont jamais rien porté**, pendant que les trois rôles
 * qui font réellement tourner le portail (`admin_ivoirsante`, `gestionnaire_etablissement`,
 * `agent_garde`) **n'y figuraient pas du tout**. Une source unique qui ignore les seuls acteurs
 * en service n'est pas une source unique.
 *
 * Trois réconciliations, et le principe est toujours le même — on ADOPTE le nom qui porte déjà
 * quelque chose, on n'en réinvente aucun (précédent P6.8a) :
 *
 *  1. `secretaire` (0 permission) ⟶ **`personnel_accueil`**, qui est l'ancien `agent_garde`
 *     renommé. Ce n'était pas deux métiers mais un seul écrit deux fois : le commentaire de
 *     `Portail\AuthController` désignait déjà `agent_garde` comme « l'identité d'un agent
 *     d'accueil ». Le terme retenu est celui du propriétaire (décision B1), parce que « agent de
 *     garde » évoque une astreinte alors que ce rôle vérifie une fiche de rendez-vous au guichet.
 *
 *  2. `admin_etablissement` (0 permission, **aucun consommateur**) ⟶
 *     **`gestionnaire_etablissement`** (8 permissions, portail, seeders, suites de tests).
 *
 *  3. `super_admin` (0 permission) ⟶ **`admin_ivoirsante`** (40 permissions). Celui-là avait un
 *     consommateur réel — la garde du module fraude (ADR-020 §B2), qui l'avait choisi faute de
 *     mieux : `admin_finance` n'existait pas dans cet enum. La garde nomme désormais le rôle
 *     survivant ; le contrôleur indépendant reste `ministere`, comme ADR-017 §7 l'exige.
 *
 * Les comptes déjà porteurs d'un nom retiré sont **transférés** vers son survivant par migration,
 * jamais laissés orphelins.
 */
export const Role = {
  PATIENT: 'patient',
  MEDECIN: 'medecin',
  INFIRMIER: 'infirmier',
  PERSONNEL_ACCUEIL: 'personnel_accueil',
  PHARMACIEN: 'pharmacien',
  LABORANTIN: 'laborantin',
  RADIOLOGUE: 'radiologue',
  GESTIONNAIRE_ETABLISSEMENT: 'gestionnaire_etablissement',
  ADMIN_IVOIRSANTE: 'admin_ivoirsante',
  MINISTERE: 'ministere',
  ASSURANCE: 'assurance',
} as const;
export type Role = (typeof Role)[keyof typeof Role];

/**
 * Niveau d'une alerte de fraude IA (CDC_05, routage B — ADR-020). Valeurs = celles produites
 * par le fraud-detection-service et persistées par le paiement (`ia_fraude_alertes.niveau`).
 * Seuls SUSPECT/TRES_SUSPECT donnent lieu à une alerte routée ; NORMAL n'est jamais persisté.
 * Le NIVEAU est calculé backend (règles + ML) — jamais déduit par le front (frontière CDC_02).
 */
export const NiveauFraudeIa = {
  NORMAL: 'NORMAL',
  SUSPECT: 'SUSPECT',
  TRES_SUSPECT: 'TRES_SUSPECT',
} as const;
export type NiveauFraudeIa = (typeof NiveauFraudeIa)[keyof typeof NiveauFraudeIa];

/**
 * Statut de traitement d'une alerte de fraude IA par le contrôleur plateforme (CDC_05, ADR-020).
 * OUVERTE à la création ; REVUE après revue humaine (trace, aucune action automatique — détection
 * seule, ADR-017). La transition est opérée par le backend paiement, jamais par le front.
 */
export const StatutAlerteFraudeIa = {
  OUVERTE: 'OUVERTE',
  REVUE: 'REVUE',
} as const;
export type StatutAlerteFraudeIa = (typeof StatutAlerteFraudeIa)[keyof typeof StatutAlerteFraudeIa];

/**
 * Types de notification en application (carnet familial partagé, incrément D1).
 *
 * Le TYPE est décidé par le backend et voyage tel quel jusqu'au mobile, qui s'en sert uniquement
 * pour choisir l'icône et l'écran de destination. Il ne le recalcule ni ne l'interprète : c'est
 * l'énumération miroir de `App\Support\TypeNotification` côté Laravel.
 *
 * `DOSSIER_CONSULTE` couvre les trois voies d'accès d'un soignant (scan QR à l'accueil, médecin
 * référent, bris de glace) — le niveau d'urgence est porté par la charge utile, pas par le type.
 */
export const TypeNotification = {
  CONTRIBUTION_DEPOSEE: 'CONTRIBUTION_DEPOSEE',
  CONTRIBUTION_VALIDEE: 'CONTRIBUTION_VALIDEE',
  CONTRIBUTION_REJETEE: 'CONTRIBUTION_REJETEE',
  DELEGATION_RECUE: 'DELEGATION_RECUE',
  RESPONSABLE_DESIGNE: 'RESPONSABLE_DESIGNE',
  DOSSIER_CONSULTE: 'DOSSIER_CONSULTE',
  /** Un soignant a consigné un acte dans le carnet, pendant une session ouverte (D0). */
  CARNET_ENRICHI: 'CARNET_ENRICHI',
  /**
   * Une échéance du calendrier vaccinal national est atteinte, ou dépassée (P6.8b, CDC_09 §8).
   *
   * La charge utile porte `nombre` et `en_retard` — COMBIEN, jamais QUOI. Un nom de vaccin désigne
   * une pathologie visée : il n'apparaît nulle part dans la notification, et le détail se lit dans
   * le carnet, après authentification.
   */
  ECHEANCE_VACCINALE: 'ECHEANCE_VACCINALE',
  /**
   * Lot 9 (post-facturation) — facture patient émise / relancée. Ni acte, ni service, ni
   * spécialité, ni établissement dans le corps (§2.7) : montant et libellé générique seulement.
   */
  FACTURE_PATIENT_EMISE: 'FACTURE_PATIENT_EMISE',
  FACTURE_PATIENT_RELANCE: 'FACTURE_PATIENT_RELANCE',
  /**
   * Alertes INTERNES au back-office MaSanté (lot 9) — jamais envoyées à un patient, jamais
   * affichées par le mobile citoyen. Mirroir gardé pour la source unique, sans écran consommateur.
   */
  STRUCTURE_SUSPENDUE_IMPAYE: 'STRUCTURE_SUSPENDUE_IMPAYE',
  STRUCTURE_REACTIVEE: 'STRUCTURE_REACTIVEE',
  /**
   * P10c-3-i — un modèle IA candidat attend une revue de gouvernance (CDC_05 §8/§9). Interne au
   * back-office, jamais envoyée à un patient, jamais affichée par le mobile citoyen — même mirroir
   * gardé pour la source unique que les deux ci-dessus, sans écran consommateur.
   */
  MODELE_IA_CANDIDAT: 'MODELE_IA_CANDIDAT',
  /** P10c-3-ii lot B — une dérive constatée sur le modèle en service. Prévient, ne décide pas. */
  DERIVE_MODELE_IA: 'DERIVE_MODELE_IA',
  /**
   * B1-d (D15) — le rendez-vous est clos (`honore`). N'annonce PAS une facture nouvelle : depuis
   * B1-c le règlement précède déjà le check-in, cette notification confirme la fin de la
   * consultation et rappelle le montant déjà réglé (§2.7 : ni acte, ni service, ni spécialité, ni
   * établissement).
   */
  RENDEZ_VOUS_TERMINE: 'RENDEZ_VOUS_TERMINE',
} as const;
export type TypeNotification = (typeof TypeNotification)[keyof typeof TypeNotification];

/**
 * Voies d'accès à un dossier (`acces_dossier.type_acces`) — incrément D2.
 *
 * POURQUOI CET ENUM ARRIVE ICI ET MAINTENANT. Les libellés vivaient en dur dans l'application
 * mobile depuis le Module 2 et avaient divergé de la base : trois des cinq voies n'y figuraient
 * pas, et un parent lisait littéralement « bris_de_glace » à l'écran de son journal d'accès. La
 * source unique n'est pas une élégance ici — c'est ce qui empêche la divergence de se reformer.
 *
 * Miroir PHP : `App\Support\TypeAccesDossier`.
 */
export const TypeAccesDossier = {
  /** Un agent a scanné le QR présenté par le patient (voie consentie, 30 min). */
  QR_SCAN: 'qr_scan',
  /** Le médecin désigné référent du membre a ouvert le dossier. */
  REFERENT: 'referent',
  /** Un proche à qui le carnet est partagé l'a consulté depuis son application (incrément A). */
  DELEGATION: 'delegation',
  /** Urgence vitale : ouverture SANS consentement, périmètre vital, 15 min, motif obligatoire. */
  BRIS_DE_GLACE: 'bris_de_glace',
  /** Accès exceptionnel d'un administrateur de la plateforme. */
  ADMIN: 'admin',
  /** B1-c — le médecin de CE rendez-vous a ouvert un accès de 30 min (jamais permanent). */
  RDV_PARTAGE: 'rdv_partage',
} as const;
export type TypeAccesDossier = (typeof TypeAccesDossier)[keyof typeof TypeAccesDossier];

/**
 * Libellés destinés au CITOYEN (décision propriétaire, 2026-08-12).
 *
 * « Bris de glace » est un terme métier (*break the glass*) : clair entre professionnels, opaque
 * pour une famille. « Accès d'urgence vitale » porte à lui seul la justification de l'absence de
 * consentement — c'est ce que le lecteur doit comprendre en une ligne, sans connaître le
 * mécanisme. Les valeurs techniques, elles, ne changent nulle part : elles sont dans l'ENUM de la
 * base, dans la permission `urgence.bris_de_glace` et dans des modules validés.
 */
export const LIBELLE_TYPE_ACCES: Record<TypeAccesDossier, string> = {
  [TypeAccesDossier.QR_SCAN]: 'Consultation après scan de votre QR',
  [TypeAccesDossier.REFERENT]: 'Consultation par votre médecin référent',
  [TypeAccesDossier.DELEGATION]: 'Consultation par un proche',
  [TypeAccesDossier.BRIS_DE_GLACE]: "Accès d'urgence vitale",
  [TypeAccesDossier.ADMIN]: 'Accès administrateur MaSanté',
  [TypeAccesDossier.RDV_PARTAGE]: 'Consultation pour votre rendez-vous',
};

/**
 * Rend lisible une voie d'accès. Le repli sur la valeur brute est CONSERVÉ à dessein : si la base
 * gagnait une sixième voie sans que cette table suive, mieux vaut afficher un mot inconnu que
 * masquer un accès au dossier. Mais le repli ne doit plus jamais être le comportement normal —
 * c'était le défaut trouvé au G0 de D2.
 */
export function libelleTypeAcces(type: string): string {
  return LIBELLE_TYPE_ACCES[type as TypeAccesDossier] ?? type;
}

/**
 * Statut d'une ligne du carnet de vaccination (P6.8b, CDC_09 §8).
 *
 * ═══ POURQUOI CET ENUM ARRIVE ICI ET MAINTENANT ═══
 *
 * Les libellés et les tons vivaient EN DUR dans `apps/mobile/src/carnet/registre.ts` — troisième
 * récidive du constat G-a de P6.4b, après les communes d'Abidjan et les catégories d'établissement.
 * Ils y alimentaient surtout un `select` OBLIGATOIRE : on demandait au citoyen de DÉCLARER son
 * statut vaccinal.
 *
 * Ce statut est désormais **calculé par le serveur** (frontière CDC_01 §0.1 : un état métier est
 * fourni, jamais déduit ni déclaré). Le champ de saisie a donc disparu ; ces valeurs ne servent
 * plus qu'à AFFICHER ce que le backend a décidé.
 *
 * Miroir PHP : `App\Support\ReglesCalendrierVaccinal`.
 */
export const StatutVaccination = {
  /** La dose a été administrée — un fait, que nulle échéance ne remet en cause. */
  FAIT: 'fait',
  /** Elle est due (ou pas encore datée), et le délai de grâce publié court encore. */
  A_FAIRE: 'a_faire',
  /** Le délai de grâce publié au calendrier national est écoulé. */
  EN_RETARD: 'en_retard',
} as const;
export type StatutVaccination = (typeof StatutVaccination)[keyof typeof StatutVaccination];

/**
 * Statut d'une ÉCHÉANCE du calendrier vaccinal — surensemble du précédent.
 *
 * Une LIGNE du carnet et une ÉCHÉANCE du calendrier ne sont pas la même chose, et les confondre
 * produirait des réponses fausses dans les deux sens : ranger `A_VENIR` parmi les statuts d'une
 * ligne ferait apparaître comme « en attente » des vaccinations qui ne concernent pas encore
 * l'enfant ; l'omettre du calendrier afficherait « en retard » à un nourrisson de cinq semaines
 * pour une échéance prévue à six.
 */
export const StatutEcheanceVaccinale = {
  FAIT: 'fait',
  A_FAIRE: 'a_faire',
  EN_RETARD: 'en_retard',
  /** L'enfant est trop jeune : ce n'est pas un retard. */
  A_VENIR: 'a_venir',
  /** La fenêtre de rattrapage publiée au calendrier national est passée. */
  HORS_DELAI: 'hors_delai',
} as const;
export type StatutEcheanceVaccinale =
  (typeof StatutEcheanceVaccinale)[keyof typeof StatutEcheanceVaccinale];

/** Libellés citoyens d'un statut d'échéance. Présentation seule — aucune règle. */
export const LIBELLE_STATUT_ECHEANCE: Record<StatutEcheanceVaccinale, string> = {
  [StatutEcheanceVaccinale.FAIT]: 'Fait',
  [StatutEcheanceVaccinale.A_FAIRE]: 'À faire',
  [StatutEcheanceVaccinale.EN_RETARD]: 'En retard',
  [StatutEcheanceVaccinale.A_VENIR]: 'À venir',
  [StatutEcheanceVaccinale.HORS_DELAI]: 'Fenêtre de rattrapage passée',
};
