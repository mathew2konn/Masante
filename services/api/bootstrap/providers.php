<?php

use App\Providers\AppServiceProvider;
use App\Providers\BroadcastServiceProvider;
use App\Providers\ReverbServiceProvider;
use Laravel\Reverb\ApplicationManagerServiceProvider;

return [
    AppServiceProvider::class,
    BroadcastServiceProvider::class,
    // B1-c — `laravel/reverb` déclare DEUX fournisseurs dans `extra.laravel.providers`
    // (composer.json du paquet) ; retirer le paquet de la découverte automatique
    // (`extra.laravel.dont-discover`, nécessaire pour contourner le défaut amont documenté sur
    // {@see \App\Providers\ReverbServiceProvider}) supprime LES DEUX, pas seulement celui qui
    // plantait. `ApplicationManagerServiceProvider` (lie `Contracts\ApplicationProvider`, sans
    // appel à `DevCommands`) n'a donc RIEN à contourner — il est simplement réenregistré tel quel.
    ApplicationManagerServiceProvider::class,
    ReverbServiceProvider::class,
];
