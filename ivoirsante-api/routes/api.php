<?php

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
});
