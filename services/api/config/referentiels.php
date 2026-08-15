<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Durée de vie du cache de diffusion (CDC_09 §10, CDC_04 §165)
    |--------------------------------------------------------------------------
    |
    | §10 impose un « TTL long ». Il peut l'être ici sans risque de servir une donnée périmée :
    | la clé de cache porte le NUMÉRO DE VERSION publié. Publier une nouvelle version change la
    | clé, donc sert immédiatement le nouveau contenu — l'ancienne entrée expire toute seule.
    | Le TTL n'est donc pas le mécanisme de fraîcheur, seulement celui du ménage.
    |
    | 30 jours, la valeur donnée en exemple par le §10.
    |
    */
    'cache_ttl' => (int) env('REFERENTIELS_CACHE_TTL', 60 * 60 * 24 * 30),

    /*
    |--------------------------------------------------------------------------
    | Pays par défaut (CDC_09 §1.2 principe 5)
    |--------------------------------------------------------------------------
    |
    | Ajouter un pays = enregistrer ses référentiels avec un autre `pays_code`. Aucune ligne de
    | code à modifier — même principe que le préfixe du NIS en P6.1.
    |
    */
    'pays_defaut' => env('REFERENTIELS_PAYS_DEFAUT', 'CI'),

    /*
    |--------------------------------------------------------------------------
    | Langue pivot des référentiels (CDC_09 §8, « libellés multilingues »)
    |--------------------------------------------------------------------------
    |
    | La langue dont le libellé vit SUR LA LIGNE du référentiel (`maladies.libelle`) et non dans la
    | table des libellés alternatifs. C'est cette asymétrie qui rend la seconde vérité inexprimable :
    | il ne peut pas exister deux libellés officiels concurrents pour la langue pivot, puisque la
    | table des alternatifs n'en porte aucun.
    |
    | Conséquence tenue par le contrôle qualité : une ligne de `maladie_libelles` dans la langue
    | pivot est forcément un SYNONYME (« palu »), donc jamais `principal`.
    |
    */
    'langue_pivot' => env('REFERENTIELS_LANGUE_PIVOT', 'fr'),

];
