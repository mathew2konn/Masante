<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fichier de configuration OpenSSL (P6.5b)
    |--------------------------------------------------------------------------
    |
    | Embarqué dans le dépôt, et passé explicitement à chaque appel X.509. Sans lui,
    | `openssl_pkey_new` échoue avec « configuration file routines::no such file » — constaté au
    | G0. S'appuyer sur celui de WAMP ferait dépendre l'émission des certificats d'un chemin
    | propre à un poste. Aucun secret dedans : ce sont des paramètres de format.
    |
    */
    'openssl_conf' => env('PKI_OPENSSL_CONF', base_path('config/pki/openssl.cnf')),

    /*
    |--------------------------------------------------------------------------
    | Phrase de passe de l'autorité racine (CDC_10 §5)
    |--------------------------------------------------------------------------
    |
    | JAMAIS DE VALEUR PAR DÉFAUT. Une valeur de repli serait un secret dans le dépôt — et pire :
    | un secret que tout le monde croirait avoir remplacé. Absente, l'émission échoue bruyamment,
    | ce qui est le comportement voulu (même principe que la commission sans seed en P5.5a).
    |
    | Elle protège la clé de la CA, pas les clés des praticiens : celles-ci sont scellées par le
    | secret de leur porteur, que le serveur ne connaît pas.
    |
    */
    'ca_passphrase' => env('PKI_CA_PASSPHRASE'),

    /*
    |--------------------------------------------------------------------------
    | Identité de l'autorité racine
    |--------------------------------------------------------------------------
    */
    'ca' => [
        'nom'          => env('PKI_CA_NOM', 'MaSante Autorite Racine'),
        'organisation' => env('PKI_CA_ORGANISATION', 'MaSante'),
        'pays'         => env('PKI_CA_PAYS', 'CI'),
        'validite_ans' => (int) env('PKI_CA_VALIDITE_ANS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificats de professionnels
    |--------------------------------------------------------------------------
    |
    | DURÉE = DONNÉE, jamais un littéral au fond d'un service. Deux ans : assez court pour qu'une
    | autorisation d'exercer périmée finisse par emporter le certificat, assez long pour ne pas
    | transformer la signature en corvée trimestrielle.
    |
    */
    'certificat' => [
        'validite_jours' => (int) env('PKI_CERT_VALIDITE_JOURS', 730),
        'taille_cle'     => (int) env('PKI_CERT_TAILLE_CLE', 2048),
    ],

    /*
    |--------------------------------------------------------------------------
    | Secret de signature du professionnel
    |--------------------------------------------------------------------------
    |
    | Seuils = données (motif du PIN wallet, P5.3b-1). Le verrouillage est temporaire : un
    | verrouillage définitif transformerait une faute de frappe répétée en perte de certificat,
    | donc en ordonnances non signables pour un praticien en exercice.
    |
    */
    'secret' => [
        'longueur_min'        => (int) env('PKI_SECRET_LONGUEUR_MIN', 8),
        'echecs_avant_verrou' => (int) env('PKI_SECRET_ECHECS', 5),
        'verrou_minutes'      => (int) env('PKI_SECRET_VERROU_MINUTES', 15),
    ],

];
