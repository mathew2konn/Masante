<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | B1-c (D9) — `log` reste le défaut de `.env.example` : aucun autre point du projet ne
    | diffuse encore d'événement, changer le défaut global n'aurait aucun sens tant que ce
    | n'est pas le cas. `.env` (dev réel) passe explicitement à `reverb` pour ce module.
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Seules les connexions RÉELLEMENT utilisées par ce projet : `reverb` (D9), `log` (défaut
    | dev/CI, écrit dans les logs sans serveur à démarrer) et `null` (désactivation totale, tests).
    | Aucune connexion Pusher/Ably : ce projet n'a pas de compte chez ces services et `reverb` en
    | est un remplaçant auto-hébergé, protocole compatible (précédent Tesseract auto-hébergé, une
    | donnée de santé — ici une présence en temps réel liée à un dossier — ne part pas chez un
    | tiers en ligne).
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Options Guzzle : https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
