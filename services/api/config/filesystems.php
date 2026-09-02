<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // F2.10 — Documents médicaux importés. Disque PRIVÉ : blobs chiffrés au repos,
        // jamais liés dans public/ ni servis directement (téléchargement uniquement via
        // le contrôleur, déchiffré, après contrôle de Policy et du statut antivirus).
        'documents' => [
            'driver' => 'local',
            'root' => storage_path('app/documents'),
            'throw' => true,   // une écriture/lecture ratée doit remonter (intégrité du dossier médical)
            'report' => false,
        ],

        // Profil (photo de membre). Disque PRIVÉ : avatar chiffré au repos, hors public/,
        // servi uniquement via le contrôleur (déchiffré, après contrôle de Policy).
        'avatars' => [
            'driver' => 'local',
            'root' => storage_path('app/avatars'),
            'throw' => true,
            'report' => false,
        ],

        // P6.4c — Images publiques des établissements (logo, accueil, salle d'attente…).
        //
        // PRIVÉ MAIS NON CHIFFRÉ, et c'est délibéré. Non chiffré parce qu'une vitrine d'hôpital
        // n'a rien à protéger : la déchiffrer à chaque affichage coûterait pour rien. Privé —
        // c'est-à-dire hors de `public/` et servi par un contrôleur — parce que le disque `public`
        // exige un lien symbolique (droits administrateur sous Windows) et bâtit ses URL sur
        // `APP_URL`, qui vaut ici l'URL Ngrok : elles casseraient à chaque redémarrage du tunnel.
        // La diffusion par contrôleur donne une URL relative, stable, et laisse la porte ouverte à
        // une garde si une image devait un jour cesser d'être publique.
        'etablissements' => [
            'driver' => 'local',
            'root' => storage_path('app/etablissements'),
            'throw' => true,
            'report' => false,
        ],

        // B1-b — Photo de profil des médecins (D5). Même raisonnement que le disque
        // `etablissements` juste au-dessus : privé mais non chiffré, servi par un contrôleur pour
        // une URL relative stable.
        'medecins' => [
            'driver' => 'local',
            'root' => storage_path('app/medecins'),
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
