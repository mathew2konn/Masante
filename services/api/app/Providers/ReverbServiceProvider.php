<?php

namespace App\Providers;

use Laravel\Reverb\Contracts\Logger;
use Laravel\Reverb\Loggers\NullLogger;
use Laravel\Reverb\Reverb;
use Laravel\Reverb\ReverbServiceProvider as PaquetReverbServiceProvider;
use Laravel\Reverb\ServerProviderManager;

/**
 * B1-c — contourne un DÉFAUT RÉEL EN AMONT entre `laravel/reverb` v1.11.1 (dernière version
 * résolue, 2026-09-02) et `laravel/framework` ^13.8 (verrouillé côté projet).
 *
 * ═══ LE DÉFAUT, TROUVÉ AU G0 D'IMPLÉMENTATION, PAS SUPPOSÉ ═══
 *
 * `\Laravel\Reverb\ReverbServiceProvider::register()` appelle inconditionnellement
 * `Reverb::registerDevCommands()`, qui appelle `Illuminate\Foundation\DevCommands::artisan(...)`
 * — une API TRÈS récente de Laravel (l'intégration à `php artisan dev`). Cette API refuse
 * catégoriquement tout enregistrement dont la pile d'appel ne traverse QUE du code `vendor/`
 * (« DevCommands should be registered in application code, not within vendor packages ») — et
 * Reverb ne l'appelle QUE depuis son propre code vendor. Résultat constaté : TOUTE commande
 * Artisan (`migrate`, `serve`, `test`, `package:discover`…) plante dès que le paquet est installé,
 * y compris hors du contexte `artisan dev` que cette garde est censée protéger. Une simple
 * détection de classe (`class_exists(DevCommands::class)`) protège Reverb contre les anciennes
 * versions de Laravel qui n'ont pas cette API — pas contre celles qui l'ont et la protègent.
 *
 * ═══ LE CORRECTIF, ET POURQUOI IL RESTE APPLICATIF, JAMAIS DANS `vendor/` ═══
 *
 * La garde ne juge QUE l'emplacement du fichier appelant, pas l'identité du paquet qui a décidé
 * d'appeler. En répétant l'appel EXACTEMENT identique — mais DEPUIS ce fichier, sous `app/` — la
 * même API laravel réussit, parce que la pile d'appel traverse désormais du code applicatif.
 * `composer.json` retire `Laravel\Reverb\ReverbServiceProvider` de la découverte automatique
 * (`extra.laravel.dont-discover`) ; celui-ci le remplace dans `bootstrap/providers.php`.
 *
 * `boot()` est hérité SANS MODIFICATION (`parent::boot()`) : lui seul ne pose aucun problème —
 * son unique appel voisin, `$this->reloads(...)`, ne passe par aucune garde (vérifié dans
 * `Illuminate\Support\ServiceProvider::reloads()`, simple affectation statique).
 *
 * Survit à tout `composer install`/`update` sur n'importe quel poste, sans patch de `vendor/`
 * (précédent de méthode : jamais de correctif hors du dépôt suivi par Git).
 *
 * NE SUFFIT PAS SEUL : `laravel/reverb` déclare DEUX fournisseurs (`composer.json` du paquet,
 * `extra.laravel.providers`) — celui-ci ET `Laravel\Reverb\ApplicationManagerServiceProvider`
 * (lie `Contracts\ApplicationProvider`, nécessaire à `reverb:start`). Les retirer TOUS LES DEUX
 * de la découverte (`dont-discover` vise le PAQUET, pas un fournisseur précis) puis n'en
 * réenregistrer qu'un seul manuellement fait échouer `reverb:start` avec un
 * `BindingResolutionException` — trouvé au premier démarrage réel du serveur. Le second
 * fournisseur, lui, n'appelle jamais `DevCommands` : il est réenregistré à l'identique dans
 * `bootstrap/providers.php`, rien à contourner pour lui.
 */
class ReverbServiceProvider extends PaquetReverbServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('vendor/laravel/reverb/config/reverb.php'), 'reverb');

        $this->app->instance(Logger::class, new NullLogger);

        $this->app->singleton(ServerProviderManager::class);

        $this->app->make(ServerProviderManager::class)->register();

        // La seule ligne qui échouait, appelée depuis CE fichier : la garde de Laravel 13.8 voit
        // désormais une pile d'appel qui traverse du code applicatif, et laisse passer.
        Reverb::registerDevCommands();
    }
}
