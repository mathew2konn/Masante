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
        'exiger_palier_verifie'   => env('MASANTE_CMU_EXIGER_PALIER_VERIFIE', false),
        'code_ttl_minutes'        => (int) env('MASANTE_CMU_CODE_TTL_MIN', 10),
        'alerte_expiration_jours' => (int) env('MASANTE_CMU_ALERTE_JOURS', 30),
    ],

    /*
     * Photo de profil (membre) — image compressée côté client, chiffrée au repos côté serveur.
     * Liste blanche de MIME réels (validés via finfo) ; taille max (l'image est déjà réduite à l'upload).
     */
    'photo' => [
        'max_ko'    => (int) env('MASANTE_PHOTO_MAX_KO', 5120), // 5 Mo
        'mimetypes' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'],
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
        'age_min'     => (int) env('MASANTE_DON_AGE_MIN', 18),
        'age_max'     => (int) env('MASANTE_DON_AGE_MAX', 65),
        'delai_jours' => (int) env('MASANTE_DON_DELAI_JOURS', 90),
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
        'plancher_cfa'    => (int) env('MASANTE_PRIX_PLANCHER', 50),
        'plafond_cfa'     => (int) env('MASANTE_PRIX_PLAFOND', 500000),
        'facteur_min'     => (float) env('MASANTE_PRIX_FACTEUR_MIN', 0.2),
        'facteur_max'     => (float) env('MASANTE_PRIX_FACTEUR_MAX', 5.0),
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
            'enabled'   => env('MASANTE_PUSH_ENABLED', false),
            'url'       => env('MASANTE_PUSH_URL', 'https://exp.host/--/api/v2/push/send'),
            'timeout_s' => (float) env('MASANTE_PUSH_TIMEOUT', 5),
            // Expo refuse au-delà de 100 messages par requête (PUSH_TOO_MANY_NOTIFICATIONS).
            'lot_max'   => 100,
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
        'binaire'   => env('MASANTE_TESSERACT_BIN', 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe'),
        'tessdata'  => env('MASANTE_TESSDATA_DIR', storage_path('app/tessdata')),
        'langue'    => env('MASANTE_TESSERACT_LANG', 'fra'),
        'timeout_s' => (float) env('MASANTE_TESSERACT_TIMEOUT', 20),
        'max_ko'    => (int) env('MASANTE_RECU_MAX_KO', 8192),   // 8 Mo : une photo de ticket
    ],

];
