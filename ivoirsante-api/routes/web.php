<?php

use App\Http\Controllers\Portail\ActivationController;
use App\Http\Controllers\Portail\AuthController;
use App\Http\Controllers\Portail\DashboardController;
use App\Http\Controllers\Portail\EtablissementController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Module 4 / 4.1 — Portail administratif (web, à sessions)
|--------------------------------------------------------------------------
| Auth navigateur email + mot de passe (guard `web`), RBAC spatie (3 rôles).
| DISTINCT de l'API mobile stateless. Les fonctions métier arrivent en 4.2 → 4.6.
*/
Route::prefix('portail')->name('portail.')->group(function () {
    // Connexion (invités). Anti-bruteforce via le limiteur `login` (AppServiceProvider).
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.attempt')->middleware('throttle:login');

    // 4.2 — Activation d'un compte staff (PUBLIC : le titulaire n'a pas encore de mot de passe).
    Route::get('activation/{token}', [ActivationController::class, 'show'])->name('activation.show');
    Route::post('activation/{token}', [ActivationController::class, 'activate'])
        ->name('activation.attempt')->middleware('throttle:login');

    // Espace authentifié.
    Route::middleware('auth')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        // 4.2 — Établissements (ADMIN IVOIRSANTÉ uniquement, permission etablissement.manage).
        Route::middleware('permission:etablissement.manage')->group(function () {
            Route::get('etablissements', [EtablissementController::class, 'index'])->name('etablissements.index');
            Route::get('etablissements/creer', [EtablissementController::class, 'create'])->name('etablissements.create');
            Route::post('etablissements', [EtablissementController::class, 'store'])->name('etablissements.store');
            Route::get('etablissements/{etablissement}/editer', [EtablissementController::class, 'edit'])->name('etablissements.edit');
            Route::put('etablissements/{etablissement}', [EtablissementController::class, 'update'])->name('etablissements.update');
            Route::patch('etablissements/{etablissement}/actif', [EtablissementController::class, 'toggleActif'])->name('etablissements.toggle');
            Route::post('etablissements/{etablissement}/lien', [EtablissementController::class, 'regenererLien'])->name('etablissements.lien');
        });
    });
});

// Page de démonstration/test du Module 1 (Triage), servie par le backend lui-même.
// Même origine que l'API => aucun problème CORS, testable directement au navigateur.
// Outil de DEV uniquement (utile pour la soutenance / vérification visuelle).
Route::get('/triage-demo', function () {
    return view('triage-demo');
});

// Page de démonstration/test du Module 2A.1 (Authentification téléphone + OTP).
// Même origine que l'API => aucun CORS, testable directement au navigateur (localhost ou Ngrok).
// Outil de DEV uniquement : le code OTP renvoyé par l'API n'est exposé qu'en environnement local.
Route::get('/auth-demo', function () {
    return view('auth-demo');
});

// Page de démonstration/test du Module 2 (Carnet de santé 2A.2 + QR dynamique 2A.3).
// Même origine que l'API => aucun CORS. Le QR est rendu côté navigateur (le token ne sort
// jamais de la page). Outil de DEV uniquement (vérification visuelle / soutenance).
Route::get('/carnet-demo', function () {
    return view('carnet-demo');
});
