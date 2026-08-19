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
        // ═══ P10a — UNE SEULE VERSION DU RÉFÉRENTIEL DE TRIAGE PAR REQUÊTE ═══
        //
        // Trois consommateurs le lisent dans la même requête : la validation
        // (`AnalyserTriageRequest`, qui n'accepte que les symptômes de la version en vigueur), le
        // contrôleur (qui estampille) et `TriageService` (qui calcule). En liaison ordinaire,
        // chacun recevrait sa propre instance, donc sa propre lecture — et une publication survenant
        // au milieu produirait un triage **jugé par une version et estampillé par une autre**.
        //
        // `scoped` fait vivre l'instance le temps d'une requête et pas plus : en `singleton`, un
        // serveur applicatif persistant (Octane) servirait indéfiniment une version périmée.
        //
        // Précédent : la mémoïsation de `MesureSanteService` en L1+L2, qui pinne une version pour
        // que les deux lignes d'une tension soient jugées par les mêmes seuils.
        $this->app->scoped(\App\Services\Triage\ServiceSymptomesTriage::class);
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
