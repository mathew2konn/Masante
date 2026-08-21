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

        // ═══ P10b-1 — MÊME RAISON, POUR LE PROTOCOLE QUI DÉCIDE DU NIVEAU ═══
        //
        // `TriageService` applique le protocole puis le contrôleur estampille le triage avec son
        // numéro de version. En liaison ordinaire, une publication survenant entre les deux ferait
        // écrire dans le dossier « version 3 » sous une décision rendue par la version 2 — c'est
        // exactement l'inverse de ce que le §6.1 exige (« chaque décision conserve la version
        // exacte du protocole utilisée », exigence médico-légale).
        $this->app->scoped(\App\Services\Triage\ServiceNiveauTriage::class);

        // ═══ P10c-1 — MÊME RAISON, POUR LES SEUILS QUI BORNENT LES CONSTANTES ═══
        //
        // Trois consommateurs le lisent dans la même requête : le contrôleur (qui propose les
        // valeurs du carnet), le service (qui refuse une valeur hors bornes) et l'écriture (qui
        // estampille chaque constante avec le numéro de version). En liaison ordinaire, chacun
        // recevrait sa propre lecture — et une publication survenant au milieu ferait accepter une
        // valeur selon une version puis l'estampiller d'une autre.
        //
        // `ControleQualiteProtocole` l'injecte aussi : le contrôle qui refuse `constante.spo2` doit
        // juger sur la même version que celle qui l'exécuterait.
        $this->app->scoped(\App\Services\Triage\ServiceConstantesTriage::class);
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
