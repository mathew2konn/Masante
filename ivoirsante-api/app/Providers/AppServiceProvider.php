<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Enregistrement des services applicatifs.
     */
    public function register(): void
    {
        //
    }

    /**
     * Démarrage des services applicatifs.
     */
    public function boot(): void
    {
        $this->configurerLimitationDebit();
    }

    /**
     * Limitation de débit (anti-bruteforce / anti-abus) — §9 du document Sécurité.
     *
     * - « api »   : 100 requêtes / minute, comptées par utilisateur authentifié sinon par IP.
     * - « login » : 5 tentatives / minute / IP, pour contrer la force brute sur la connexion.
     */
    private function configurerLimitationDebit(): void
    {
        // Limite générale appliquée à toutes les routes de l'API.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(100)->by($request->user()?->id ?: $request->ip());
        });

        // Limite stricte réservée aux routes sensibles (connexion / OTP) — utilisée au module Auth.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
