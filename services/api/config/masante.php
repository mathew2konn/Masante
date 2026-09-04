<?php

/**
 * Paramètres métier MaSante / IVOIRSANTÉ centralisés (modifiables sans redéployer, via .env).
 *
 * F2.10 — Documents médicaux importés :
 *  - `antivirus.enabled` : en prod, branche ClamAV (scan asynchrone) ; en dev, stub « simulé »
 *    (même logique que l'OTP simulé) → le document est marqué `sain` sans binaire externe.
 *  - `upload.max_ko` : taille maximale par fichier (garde-fou quota + 3G).
 *  - `upload.mimetypes` : LISTE BLANCHE des types MIME RÉELS acceptés (validés serveur via finfo,
 *    jamais l'extension déclarée par le client). Liste large mais fermée (OWASP — pas de « tout accepter »).
 */
return [

    'antivirus' => [
        'enabled' => env('MASANTE_ANTIVIRUS_ENABLED', false),
    ],

    'upload' => [
        // 20 Mo par défaut (en Ko, unité de la règle `max:` de Laravel).
        'max_ko' => (int) env('MASANTE_UPLOAD_MAX_KO', 20480),

        'mimetypes' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/heic',
            'image/heif',
            'application/msword',                                                        // .doc
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',   // .docx
            'text/csv',
            'application/vnd.ms-excel',                                                  // .xls
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',         // .xlsx
            'application/json',
            'application/dicom',                                                         // imagerie médicale
        ],
    ],

    /*
     * F2.3 — Carte CMU numérique (couche de présentation).
     *  - `exiger_palier_verifie` : en prod, la carte n'est présentable comme justificatif qu'au
     *    palier « vérifié » (User::compteEstVerifie, identité CMU/CNI). En dev, l'auth/OTP n'existe
     *    pas encore → `false` = carte présentable pour tester (stub, même esprit que l'OTP simulé).
     *  - `code_ttl_minutes` : durée de vie du code de présentation (QR CMU signé).
     *  - `alerte_expiration_jours` : fenêtre du rappel « expiration proche » (cohérent alerte 30 j).
     */
    'cmu' => [
        'exiger_palier_verifie' => env('MASANTE_CMU_EXIGER_PALIER_VERIFIE', false),
        'code_ttl_minutes' => (int) env('MASANTE_CMU_CODE_TTL_MIN', 10),
        'alerte_expiration_jours' => (int) env('MASANTE_CMU_ALERTE_JOURS', 30),
    ],

    /*
     * Photo de profil (membre) — image compressée côté client, chiffrée au repos côté serveur.
     * Liste blanche de MIME réels (validés via finfo) ; taille max (l'image est déjà réduite à l'upload).
     */
    'photo' => [
        'max_ko' => (int) env('MASANTE_PHOTO_MAX_KO', 5120), // 5 Mo
        'mimetypes' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'],
    ],

    /*
     * P6.4c — Images publiques des établissements (CDC_11 §3.1 « formulaire dédié »).
     *
     * Liste blanche plus ÉTROITE que celle des photos de profil : `heic`/`heif` en sont absents à
     * dessein. Une photo de profil vient du téléphone du patient (donc souvent en HEIC sous iOS) et
     * n'est affichée qu'à lui ; une vitrine d'établissement est servie à tous les navigateurs et à
     * l'application, or HEIC n'est pas rendu partout. Accepter un format qu'une partie des lecteurs
     * ne sait pas afficher reviendrait à publier une image invisible.
     */
    'etablissement_images' => [
        'max_ko' => (int) env('MASANTE_ETAB_IMAGE_MAX_KO', 4096), // 4 Mo
        'mimetypes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    /*
     * B1-b — Photo de profil d'un médecin de l'annuaire (D5). UNE photo par praticien (pas une
     * galerie) : même liste blanche que les images d'établissement, plafond plus bas — un
     * portrait, pas une vitrine.
     */
    'medecin_photo' => [
        'max_ko' => (int) env('MASANTE_MEDECIN_PHOTO_MAX_KO', 2048), // 2 Mo
        'mimetypes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    /*
     * B3 — Délégation d'accès (Note_Continuite chap. 4).
     *  - `exiger_titulaire_verifie` : en prod, seul un compte « vérifié » (CMU/CNI) peut déléguer.
     *    En dev, aucun flux de vérification n'existe encore → `false` = testable (stub, même esprit
     *    que `cmu.exiger_palier_verifie`). Le gate est codé mais dormant tant que le flag est faux.
     */
    'delegation' => [
        'exiger_titulaire_verifie' => env('MASANTE_DELEGATION_EXIGER_VERIFIE', false),
    ],

    /*
     * 5.6 — Médecin référent (voie 2, Sécurité §4.4).
     *  - `exiger_titulaire_verifie` : désigner un référent ouvre un accès PERMANENT au dossier —
     *    en prod, seul un compte « vérifié » (CMU/CNI) doit pouvoir le faire, comme pour la
     *    délégation. Dormant en dev (`false`) faute de flux de vérification. La RÉVOCATION, elle,
     *    n'est jamais gated : reprendre le contrôle de ses données doit rester plus facile que de
     *    le céder.
     */
    'referent' => [
        'exiger_titulaire_verifie' => env('MASANTE_REFERENT_EXIGER_VERIFIE', false),
    ],

    /*
     * 5.7 — Don de sang (CdC FN6). Règles de POLITIQUE de collecte, elles : les bornes d'âge et le
     * délai entre deux dons varient d'un pays et d'une époque à l'autre (le CNTS ivoirien retient
     * 18-65 ans et un intervalle de 3 mois). Elles sont donc configurables — à la différence de la
     * compatibilité ABO/Rhésus, figée dans {@see App\Services\DonSangService}, qui relève de
     * l'immunologie et non d'une politique.
     */
    'don_sang' => [
        'age_min' => (int) env('MASANTE_DON_AGE_MIN', 18),
        'age_max' => (int) env('MASANTE_DON_AGE_MAX', 65),
        'delai_jours' => (int) env('MASANTE_DON_DELAI_JOURS', 90),

        /*
         * P6.8a — le terme du vocabulaire (CDC_09 §8) qui fait d'une structure un centre de
         * collecte. Il vivait EN DUR dans `apps/mobile/src/api/donSang.ts` : récidive du constat
         * G-a de P6.4b, où des valeurs du domaine recopiées côté client avaient déjà divergé de la
         * base sans qu'aucun typecheck ne puisse le voir. Le serveur le dit désormais au client.
         */
        'specialite_centre' => env('MASANTE_DON_SPECIALITE', 'don_sang'),
    ],

    /*
     * 5.8 — Comparateur de prix (FN7) et ruptures (FN8).
     *
     * `facteur_min`/`facteur_max` : bornes de plausibilité d'un prix crowdsourcé, en multiples du
     * prix de référence CENAME. Larges à dessein — une officine privée vend plus cher qu'une
     * pharmacie publique, ce n'est pas à nous d'en juger : on n'écarte que l'ABSURDE (un
     * paracétamol à 50 000 F est une faute de frappe, pas un scandale tarifaire).
     * `fraicheur_jours` : au-delà, un relevé n'est plus affiché. Un prix sans date ne vaut rien.
     */
    'prix' => [
        'plancher_cfa' => (int) env('MASANTE_PRIX_PLANCHER', 50),
        'plafond_cfa' => (int) env('MASANTE_PRIX_PLAFOND', 500000),
        'facteur_min' => (float) env('MASANTE_PRIX_FACTEUR_MIN', 0.2),
        'facteur_max' => (float) env('MASANTE_PRIX_FACTEUR_MAX', 5.0),
        'fraicheur_jours' => (int) env('MASANTE_PRIX_FRAICHEUR_JOURS', 90),
    ],

    /*
     * 5.8 — OCR du reçu de pharmacie (FN7 « scan de reçu »).
     *
     * Tesseract AUTO-HÉBERGÉ : un reçu dit quels médicaments une personne a achetés — donnée de
     * santé (loi n°2013-450). L'envoyer à un OCR en ligne l'exporterait chez un tiers étranger.
     * Même logique que le choix d'OpenStreetMap contre Google Maps (Module 3).
     * Les fichiers de langue vivent DANS le projet (`storage/app/tessdata`) : le serveur de prod
     * n'a pas à dépendre d'un dossier système.
     */
    /*
     * D1 — Notifications (carnet familial partagé).
     *
     * `push.enabled` est FAUX par défaut, et ce n'est pas de la prudence de façade : le push distant
     * est indisponible dans Expo Go sur Android depuis le SDK 53 (doc Expo v54), et le G4 de ce
     * projet se tient sur Expo Go. Tant qu'aucun *development build* n'existe, activer le canal
     * n'enverrait rien d'utile. Le relais est écrit et prouvé côté serveur ; il est « prêt à
     * activer », au même titre que MFA, Keycloak et PostgreSQL ailleurs dans le projet.
     *
     * `url` est paramétrable pour que le G2 puisse pointer un serveur local et prouver l'appel sans
     * dépendre d'Internet ni polluer le service d'Expo.
     */
    'notifications' => [
        'push' => [
            'enabled' => env('MASANTE_PUSH_ENABLED', false),
            'url' => env('MASANTE_PUSH_URL', 'https://exp.host/--/api/v2/push/send'),
            'timeout_s' => (float) env('MASANTE_PUSH_TIMEOUT', 5),
            // Expo refuse au-delà de 100 messages par requête (PUSH_TOO_MANY_NOTIFICATIONS).
            'lot_max' => 100,
        ],
    ],

    /*
     * D2 — Fiche de parcours (carnet familial partagé).
     *
     * La profondeur d'historique est une DONNÉE, pas une constante enfouie dans une requête : ce
     * qu'un parent veut revoir après le passage aux urgences n'est pas ce qu'un pays voudra
     * conserver, et la valeur doit pouvoir bouger sans toucher au code (frontière CDC_01 §0.1).
     *
     * 90 jours par défaut : assez pour couvrir un épisode de soin et sa contribution en attente,
     * assez court pour qu'une fiche reste lisible sur un téléphone.
     */
    'parcours' => [
        'fenetre_jours' => (int) env('MASANTE_PARCOURS_JOURS', 90),
    ],

    'ocr' => [
        'binaire' => env('MASANTE_TESSERACT_BIN', 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe'),
        'tessdata' => env('MASANTE_TESSDATA_DIR', storage_path('app/tessdata')),
        'langue' => env('MASANTE_TESSERACT_LANG', 'fra'),
        'timeout_s' => (float) env('MASANTE_TESSERACT_TIMEOUT', 20),
        'max_ko' => (int) env('MASANTE_RECU_MAX_KO', 8192),   // 8 Mo : une photo de ticket
    ],

    /*
     * Lot 6 (v2) — canal interne paiement-service (Java) ↔ Laravel.
     *
     * CORRECTION DU 2026-09-04 (B4, ADR-056) — ce bloc affirmait jusqu'ici un SENS UNIQUE délibéré
     * (« rien n'initie de paiement depuis Laravel », client d'émission RETIRÉ en Phase 0 du lot 6
     * plutôt que laissé appeler dans le vide, l'endpoint côté Java n'existant pas). Le G0 de B4 a
     * vérifié le contraire : `POST /api/v1/interne/geniuspay/paiements` EXISTE depuis le lot 7
     * (GeniusPay, P5.6b) — ce n'est pas le même endpoint que celui qui manquait alors, mais Laravel
     * a désormais un chemin d'émission réel. Laravel devient ÉMETTEUR pour la première fois :
     * `base_url` et les délais reviennent, réservés au canal GeniusPay (montage A) — jamais au
     * mobile money ni à la carte, qui n'ont pas de pendant Laravel-initié.
     *
     * `principal_secret` INCHANGÉ : partagé tel quel avec `apps/web` et le microservice Java, jamais
     * un secret distinct par consommateur (interdiction n°2, tenue). Aucune valeur par défaut : un
     * secret manquant doit faire échouer bruyamment, jamais silencieusement accepter une chaîne
     * vide — vrai à l'entrée (déjà le cas) comme à la sortie (S4).
     *
     * `SigneurPrincipalSortant` (S4) en est la QUATRIÈME implémentation du format (Java vérifie,
     * Node et Python mintent) : une garde d'exécution (`PrincipalSigneSourceUniqueTest`) vérifie
     * qu'un principal minté ici est accepté par le vérificateur PHP, sous la forme exacte des trois
     * autres — jamais un sous-ensemble.
     */
    'paiement_service' => [
        'principal_secret' => env('MASANTE_PAYMENT_PRINCIPAL_SECRET', ''),
        'base_url' => env('MASANTE_PAYMENT_SERVICE_URL', 'http://localhost:8080'),
        'timeout_connexion_s' => (float) env('MASANTE_PAYMENT_TIMEOUT_CONNEXION', 3),
        'timeout_lecture_s' => (float) env('MASANTE_PAYMENT_TIMEOUT_LECTURE', 5),
    ],

    /*
     * P10c-2-i (F7/F8) — socle d'intégration vers `triage-service` (CDC_05, CDC_03 §10.1).
     *
     * GATÉ OFF PAR DÉFAUT (`enabled` = false). Appeler à chaque triage un service qui répondra
     * toujours « pas de modèle » (F5/F6) ajouterait une latence pour rien tant que P10c-3 n'a livré
     * aucun modèle — régime « prêt à activer » déjà utilisé pour le cashback (P5.3b-3) et le push
     * (P7-D1). Activer ce réglage ne fait AUCUNE promesse clinique de plus : le service refusera
     * toujours honnêtement (503) jusqu'à ce qu'un modèle existe réellement.
     *
     * SEUILS DU DISJONCTEUR EN DONNÉE, JAMAIS EN DUR (F8) : un service qui reste injoignable ne
     * doit pas être re-sollicité à chaque triage — trois états (fermé/ouvert/demi-ouvert), état
     * PARTAGÉ dans le cache (store `database`, F5 de P6.3), jamais une variable de processus PHP
     * qui ne survivrait pas à la requête suivante.
     */
    'triage_ia' => [
        'enabled' => (bool) env('TRIAGE_IA_ENABLED', false),
        'base_url' => env('TRIAGE_IA_BASE_URL', 'http://triage-service:8095'),
        'timeout_connexion_s' => (float) env('TRIAGE_IA_TIMEOUT_CONNEXION', 2),
        'timeout_lecture_s' => (float) env('TRIAGE_IA_TIMEOUT_LECTURE', 3),
        'disjoncteur_seuil_echecs' => (int) env('TRIAGE_IA_DISJONCTEUR_SEUIL', 3),
        'disjoncteur_duree_ouverture_s' => (int) env('TRIAGE_IA_DISJONCTEUR_DUREE', 60),

        /*
         * P10c-3-i (F15/F17/F20) — l'export anonymisant + l'entraînement réel.
         *
         * `seuil_min_entrainement` : arbitraire, et c'est dit (le corpus n'en fixe aucun — Y9 du
         * plan G1). Vérifié ici AVANT tout appel réseau, ET indépendamment par `triage-service`
         * (défense en profondeur, motif « dédoublé, une couche un vecteur » de P6.6b).
         *
         * `timeout_entrainement_s` : distinct du timeout de `/score` (un entraînement XGBoost+SHAP
         * prend largement plus qu'un scoring unitaire) — un seul `base_url`, deux usages, deux
         * horloges.
         *
         * `bandes_age` : un paramètre de CONFIDENTIALITÉ (généralisation d'un quasi-identifiant,
         * CDC_13 §12), pas une donnée clinique — à la différence des référentiels gouvernés du
         * projet (P6.x), ce n'est délibérément PAS un référentiel à quatre-yeux : changer une
         * bande ne modifie aucune règle médicale, seulement le degré de généralisation d'un export.
         */
        'seuil_min_entrainement' => (int) env('TRIAGE_IA_SEUIL_MIN_ENTRAINEMENT', 30),

        // ═══ P10c-3-ii lot B — LA DÉRIVE (CDC_05 §8) ═══
        //
        // Les deux seuils PSI sont les repères USUELS de la littérature (0,1 « à surveiller »,
        // 0,25 « a changé »), pas une vérité mesurée sur cette population — et c'est pour cela
        // qu'ils sont des données : le jour où l'exploitation aura de quoi les calibrer, c'est la
        // donnée qui changera, pas le code.
        'seuil_psi_leger' => (float) env('TRIAGE_IA_PSI_LEGER', 0.1),
        'seuil_psi_fort' => (float) env('TRIAGE_IA_PSI_FORT', 0.25),

        // Une chute de rappel sur `sous_triage` au-delà de ce point est signalée. Plus bas que les
        // seuils PSI, délibérément : rater davantage le seul cas dangereux se remarque plus tôt
        // qu'un déplacement de population.
        'seuil_chute_rappel' => (float) env('TRIAGE_IA_CHUTE_RAPPEL', 0.15),

        // La fenêtre d'observation. Trop courte, elle prendrait un creux de week-end pour une
        // dérive ; trop longue, elle noierait un changement réel dans des semaines de normalité.
        'fenetre_derive_jours' => (int) env('TRIAGE_IA_FENETRE_DERIVE_JOURS', 30),
        'timeout_entrainement_s' => (float) env('TRIAGE_IA_TIMEOUT_ENTRAINEMENT', 30),
        'bandes_age' => [
            ['label' => '0-1', 'min' => 0, 'max' => 1],
            ['label' => '2-4', 'min' => 2, 'max' => 4],
            ['label' => '5-14', 'min' => 5, 'max' => 14],
            ['label' => '15-24', 'min' => 15, 'max' => 24],
            ['label' => '25-44', 'min' => 25, 'max' => 44],
            ['label' => '45-64', 'min' => 45, 'max' => 64],
            ['label' => '65+', 'min' => 65, 'max' => 130],
        ],
    ],

];
