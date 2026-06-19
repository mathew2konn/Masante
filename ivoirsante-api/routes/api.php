<?php

use App\Http\Controllers\Api\V1\AuthController;
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

        // Module 1 — Triage et orientation médicale (F1.1 → F1.8). Endpoints publics.
        Route::get('/symptomes', [TriageController::class, 'symptomes']);              // F1.1
        Route::post('/triage/analyser', [TriageController::class, 'analyser']);        // F1.3
        Route::get('/triage/historique', [TriageController::class, 'historique']);     // F1.6
        Route::get('/triage/{triage}/fiche', [TriageController::class, 'fiche']);      // F1.8
    });
});
