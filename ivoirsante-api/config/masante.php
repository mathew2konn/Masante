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

];
