<?php

use App\Http\Controllers\Portail\ActivationController;
use App\Http\Controllers\Portail\AgentController;
use App\Http\Controllers\Portail\AuthController;
use App\Http\Controllers\Portail\DashboardController;
use App\Http\Controllers\Portail\DisponibiliteController;
use App\Http\Controllers\Portail\DossierController;
use App\Http\Controllers\Portail\EtablissementController;
use App\Http\Controllers\Portail\RendezVousController;
use App\Http\Controllers\Portail\ScanController;
use App\Http\Controllers\Portail\ServiceController;
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

        // 4.3 — Services de MON établissement (GESTIONNAIRE, permission service.manage, cloisonné).
        Route::middleware('permission:service.manage')->group(function () {
            Route::get('services', [ServiceController::class, 'index'])->name('services.index');
            Route::get('services/creer', [ServiceController::class, 'create'])->name('services.create');
            Route::post('services', [ServiceController::class, 'store'])->name('services.store');
            Route::get('services/{service}/editer', [ServiceController::class, 'edit'])->name('services.edit');
            Route::put('services/{service}', [ServiceController::class, 'update'])->name('services.update');
            Route::patch('services/{service}/actif', [ServiceController::class, 'toggleActif'])->name('services.toggle');
        });

        // 4.3 — Agents de garde de MON établissement (GESTIONNAIRE, permission agent.manage, cloisonné).
        Route::middleware('permission:agent.manage')->group(function () {
            Route::get('agents', [AgentController::class, 'index'])->name('agents.index');
            Route::get('agents/creer', [AgentController::class, 'create'])->name('agents.create');
            Route::post('agents', [AgentController::class, 'store'])->name('agents.store');
            Route::get('agents/{agent}/editer', [AgentController::class, 'edit'])->name('agents.edit');
            Route::put('agents/{agent}', [AgentController::class, 'update'])->name('agents.update');
            Route::patch('agents/{agent}/actif', [AgentController::class, 'toggleActif'])->name('agents.toggle');
            Route::post('agents/{agent}/lien', [AgentController::class, 'regenererLien'])->name('agents.lien');
        });

        // 4.4 — Disponibilité des services (AGENT / GESTIONNAIRE, permission disponibilite.manage, cloisonné).
        Route::middleware('permission:disponibilite.manage')->group(function () {
            Route::get('disponibilites', [DisponibiliteController::class, 'index'])->name('disponibilites.index');
            Route::get('disponibilites/{service}/editer', [DisponibiliteController::class, 'edit'])->name('disponibilites.edit');
            Route::put('disponibilites/{service}', [DisponibiliteController::class, 'update'])->name('disponibilites.update');
        });

        // 4.4 — Validation des rendez-vous (AGENT / GESTIONNAIRE, permission rdv.validate, cloisonné).
        Route::middleware('permission:rdv.validate')->group(function () {
            Route::get('rendez-vous', [RendezVousController::class, 'index'])->name('rdv.index');
            Route::get('rendez-vous/{rdv}', [RendezVousController::class, 'show'])->name('rdv.show');
            Route::patch('rendez-vous/{rdv}/confirmer', [RendezVousController::class, 'confirmer'])->name('rdv.confirmer');
            Route::patch('rendez-vous/{rdv}/refuser', [RendezVousController::class, 'refuser'])->name('rdv.refuser');
        });

        // 4.5 — Scan des QR à l'accueil (AGENT DE GARDE, permission qr.scan).
        // Deux flux volontairement séparés : le QR carnet ouvre le dossier médical ; le QR de
        // reçu enregistre l'arrivée du patient. `throttle` : un token QR est un secret, on borne
        // les tentatives de devinette (Sécurité §5.1).
        Route::middleware('permission:qr.scan')->group(function () {
            Route::get('scan', [ScanController::class, 'index'])->name('scan.index');
            Route::post('scan', [ScanController::class, 'scanner'])->name('scan.carnet')->middleware('throttle:20,1');

            Route::get('scan/rendez-vous', [ScanController::class, 'indexRdv'])->name('scan.rdv');
            Route::post('scan/rendez-vous', [ScanController::class, 'checkIn'])->name('scan.checkin')->middleware('throttle:20,1');

            // Dossier ouvert par un scan : aucun identifiant de membre dans l'URL (anti-IDOR).
            Route::middleware('dossier.actif')->group(function () {
                Route::get('dossier', [DossierController::class, 'show'])->name('dossier.show');
                Route::post('dossier/fermer', [DossierController::class, 'fermer'])->name('dossier.fermer');
                Route::get('dossier/{section}', [DossierController::class, 'section'])->name('dossier.section');
            });
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
