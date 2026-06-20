<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Carnet\AntecedentController;
use App\Http\Controllers\Api\V1\Carnet\OrdonnanceController;
use App\Http\Controllers\Api\V1\Carnet\RappelController;
use App\Http\Controllers\Api\V1\Carnet\ResultatAnalyseController;
use App\Http\Controllers\Api\V1\Carnet\VaccinationController;
use App\Http\Controllers\Api\V1\MembreController;
use App\Http\Controllers\Api\V1\QrController;
use App\Http\Controllers\Api\V1\StructureController;
use App\Http\Controllers\Api\V1\TriageController;
use App\Http\Controllers\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes API (préfixe /api) — MaSante / IVOIRSANTÉ
|--------------------------------------------------------------------------
| API stateless (aucune session web) : pas d'erreur 419/CSRF côté mobile (§4.1).
| Toutes les réponses sont en JSON (configuré dans bootstrap/app.php, §4.2).
| Les routes seront versionnées (/api/v1/...) à partir du Module 1 ; le socle
| n'expose que le health-check de diagnostic.
*/

// Limitation de débit générale de l'API : 100 req/min (§9 doc Sécurité).
Route::middleware('throttle:api')->group(function () {

    // §4.10 — Health-check : première vérification de la chaîne mobile -> Ngrok -> Laravel.
    Route::get('/health', HealthController::class)->name('health');

    // Route d'exemple Sanctum (renvoie l'utilisateur authentifié). Conservée pour
    // vérifier l'auth par token Bearer ; sera remplacée par les vraies routes au Module Auth.
    Route::get('/user', function (Request $request) {
        return $request->user();
    })->middleware('auth:sanctum');

    /*
    |----------------------------------------------------------------------
    | API v1
    |----------------------------------------------------------------------
    | Module 1 — Triage et orientation médicale (F1.1 → F1.8).
    | Endpoints publics pour l'instant (auth téléphone+OTP non encore branchée).
    */
    Route::prefix('v1')->group(function () {
        /*
        |------------------------------------------------------------------
        | Module 2 / 2A.1 — Authentification téléphone + OTP + Sanctum.
        |------------------------------------------------------------------
        | Endpoints sensibles (inscription/connexion/OTP) sous limiteur strict
        | « login » (5/min/IP, §9 Sécurité) en plus du limiteur « api » global.
        */
        Route::prefix('auth')->group(function () {
            Route::middleware('throttle:login')->group(function () {
                Route::post('/register', [AuthController::class, 'register']);
                Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
                Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
                Route::post('/login', [AuthController::class, 'login']);
            });

            Route::middleware('auth:sanctum')->group(function () {
                Route::post('/logout', [AuthController::class, 'logout']);
                Route::get('/me', [AuthController::class, 'me']);
            });
        });

        /*
        |------------------------------------------------------------------
        | Module 2 / 2A.2 — Carnet : membres de la famille (F2.1).
        |------------------------------------------------------------------
        | Routes authentifiées (token Bearer). L'isolation entre comptes
        | (anti-IDOR, §4.3 Sécurité) est assurée par MembreFamillePolicy.
        */
        Route::middleware('auth:sanctum')->group(function () {
            Route::apiResource('membres', MembreController::class)->parameters(['membres' => 'membre']);

            /*
            |--------------------------------------------------------------
            | Module 2 / 2A.3 — QR dynamique + journal d'accès (côté patient).
            |--------------------------------------------------------------
            | Génération d'un QR à usage unique pour un membre, et consultation
            | par le patient de l'historique d'accès à son dossier (§5, §10.3).
            | Le scan (consommation) côté agent arrive au Module 3.
            */
            Route::post('membres/{membre}/qr', [QrController::class, 'generer']);
            Route::get('membres/{membre}/acces', [MembreController::class, 'acces']);

            /*
            |--------------------------------------------------------------
            | Module 2 / 2A.4 — Sections du carnet (nichées sous un membre).
            |--------------------------------------------------------------
            | CRUD générique (CarnetSectionController). L'isolation anti-IDOR
            | passe par le membre parent (Policy) + requêtes scopées à la relation.
            | Élément enfant adressé par {id} brut (jamais résolu hors du membre).
            */
            $sections = [
                'antecedents'        => AntecedentController::class,
                'vaccinations'       => VaccinationController::class,
                'ordonnances'        => OrdonnanceController::class,
                'resultats-analyses' => ResultatAnalyseController::class,
                'rappels'            => RappelController::class,
            ];

            Route::prefix('membres/{membre}')->group(function () use ($sections) {
                foreach ($sections as $chemin => $controleur) {
                    Route::get($chemin, [$controleur, 'index']);
                    Route::post($chemin, [$controleur, 'store']);
                    Route::get($chemin.'/{id}', [$controleur, 'show']);
                    Route::match(['put', 'patch'], $chemin.'/{id}', [$controleur, 'update']);
                    Route::delete($chemin.'/{id}', [$controleur, 'destroy']);
                }
            });
        });

        /*
        |------------------------------------------------------------------
        | Module 3 / 3A.1 — Structures sanitaires géolocalisées (F3.1→F3.5, F3.8).
        |------------------------------------------------------------------
        | Endpoints PUBLICS en lecture : trouver un hôpital/une pharmacie ne demande
        | aucune formalité d'identité (doc Identification — accès léger). Aucune donnée
        | médicale. Les disponibilités sont seedées (écriture agents + Firebase → Module 4).
        */
        Route::get('/pharmacies-garde', [StructureController::class, 'pharmaciesGarde']); // F3.8
        Route::get('/structures', [StructureController::class, 'index']);                 // F3.1/F3.2/F3.3
        Route::get('/structures/{structure}', [StructureController::class, 'show']);       // F3.5

        // Module 1 — Triage et orientation médicale (F1.1 → F1.8). Endpoints publics.
        Route::get('/symptomes', [TriageController::class, 'symptomes']);              // F1.1
        Route::post('/triage/analyser', [TriageController::class, 'analyser']);        // F1.3
        Route::get('/triage/historique', [TriageController::class, 'historique']);     // F1.6
        Route::get('/triage/{triage}/fiche', [TriageController::class, 'fiche']);      // F1.8
    });
});
