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

];
