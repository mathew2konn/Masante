<?php

/*
|--------------------------------------------------------------------------
| Configuration CORS — §4.3 / §7.3 du document Sécurité
|--------------------------------------------------------------------------
| Le partage de ressources entre origines (CORS) ne concerne que les clients
| « navigateur ». L'app Expo en natif n'envoie pas d'en-tête Origin, mais on
| configure CORS proprement pour le portail web admin et les tests navigateur
| via le tunnel Ngrok.
|
| En DÉVELOPPEMENT : si FRONTEND_URL (URL Ngrok du moment) est renseigné, on
| n'autorise que cette origine ; sinon on retombe sur « * » (dev uniquement).
| En PRODUCTION : remplacer par une liste blanche stricte de domaines.
*/

$frontendUrl = env('FRONTEND_URL');

return [

    // On applique CORS aux routes API (et à l'endpoint CSRF de Sanctum, si portail web).
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Origine autorisée : l'URL Ngrok si fournie, sinon tout (dev local seulement).
    'allowed_origins' => $frontendUrl ? [$frontendUrl] : ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Auth mobile par token Bearer (pas de cookie) => credentials non requis.
    'supports_credentials' => false,

];
