<?php

use App\Http\Controllers\Api\V1\AlerteSosController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvisController;
use App\Http\Controllers\Api\V1\Carnet\AntecedentController;
use App\Http\Controllers\Api\V1\Carnet\ContactUrgenceController;
use App\Http\Controllers\Api\V1\Carnet\DocumentMedicalController;
use App\Http\Controllers\Api\V1\Carnet\NoteObservationController;
use App\Http\Controllers\Api\V1\Carnet\OrdonnanceController;
use App\Http\Controllers\Api\V1\Carnet\RappelController;
use App\Http\Controllers\Api\V1\Carnet\ResultatAnalyseController;
use App\Http\Controllers\Api\V1\Carnet\VaccinationController;
use App\Http\Controllers\Api\V1\CarteCmuController;
use App\Http\Controllers\Api\V1\DelegationController;
use App\Http\Controllers\Api\V1\FicheVitaleController;
use App\Http\Controllers\Api\V1\MembreController;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Http\Controllers\Api\V1\PhotoMembreController;
use App\Http\Controllers\Api\V1\QrController;
use App\Http\Controllers\Api\V1\RecuRdvController;
use App\Http\Controllers\Api\V1\RendezVousController;
use App\Http\Controllers\Api\V1\SignalementController;
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

                // Phase B / B1 — Mot de passe oublié (flux OTP 3 étapes durci).
                Route::post('/password/forgot', [PasswordController::class, 'forgot']);
                Route::post('/password/verify-otp', [PasswordController::class, 'verifyOtp']);
                Route::post('/password/reset', [PasswordController::class, 'reset']);
            });

            Route::middleware('auth:sanctum')->group(function () {
                Route::post('/logout', [AuthController::class, 'logout']);
                Route::get('/me', [AuthController::class, 'me']);

                // Changement volontaire par l'utilisateur connecté (ancien + nouveau, pas d'OTP).
                Route::post('/password/change', [PasswordController::class, 'change']);
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

            // B3 — Délégation d'accès (voie 3) : le titulaire invite un délégué sur un membre ;
            // le délégué accepte/refuse ; révocable. Le droit se limite à la génération de QR.
            Route::get('delegations', [DelegationController::class, 'index']);
            Route::post('membres/{membre}/delegations', [DelegationController::class, 'store']);
            Route::post('delegations/{delegation}/accepter', [DelegationController::class, 'accepter']);
            Route::delete('delegations/{delegation}', [DelegationController::class, 'destroy']);

            // F2.3 — Carte CMU numérique (couche de présentation) : n° masqué + code signé,
            // gated par le palier « vérifié » (stub dev). N'ouvre PAS le dossier (distinct du QR).
            Route::get('membres/{membre}/carte-cmu', [CarteCmuController::class, 'show']);

            // Module 5 / FN2 — Fiche vitale d'urgence : sous-ensemble vital minimal du membre,
            // destiné à être mis en cache chiffré sur le téléphone puis affiché hors connexion.
            Route::get('membres/{membre}/fiche-vitale', [FicheVitaleController::class, 'show']);

            // Module 5 / FN1 — Alertes SOS. L'appel SAMU et le SMS partent du TÉLÉPHONE (tel:/sms:) :
            // ces routes ne font que journaliser l'alerte a posteriori, en best-effort.
            Route::post('sos', [AlerteSosController::class, 'store']);
            Route::get('sos', [AlerteSosController::class, 'index']);

            // Profil — Photo de profil : upload/lecture/suppression. Chiffrée au repos, disque privé,
            // servie uniquement déchiffrée par le contrôleur (jamais d'URL publique).
            Route::post('membres/{membre}/photo', [PhotoMembreController::class, 'store']);
            Route::get('membres/{membre}/photo', [PhotoMembreController::class, 'show']);
            Route::delete('membres/{membre}/photo', [PhotoMembreController::class, 'destroy']);

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
                'contacts-urgence'   => ContactUrgenceController::class,   // F2.11
            ];

            Route::prefix('membres/{membre}')->group(function () use ($sections) {
                foreach ($sections as $chemin => $controleur) {
                    Route::get($chemin, [$controleur, 'index']);
                    Route::post($chemin, [$controleur, 'store']);
                    Route::get($chemin.'/{id}', [$controleur, 'show']);
                    Route::match(['put', 'patch'], $chemin.'/{id}', [$controleur, 'update']);
                    Route::delete($chemin.'/{id}', [$controleur, 'destroy']);
                }

                // F2.12 — Notes & observations : append-only (aucune route update),
                // suppression = soft-delete. Enregistrées à part, hors CRUD générique.
                Route::get('notes-observations', [NoteObservationController::class, 'index']);
                Route::post('notes-observations', [NoteObservationController::class, 'store']);
                Route::get('notes-observations/{id}', [NoteObservationController::class, 'show']);
                Route::delete('notes-observations/{id}', [NoteObservationController::class, 'destroy']);

                // F2.10 — Documents médicaux importés : upload multipart chiffré + antivirus.
                // Immuables (pas d'update) ; `show` renvoie le fichier déchiffré (si `sain`) ;
                // `destroy` = soft-delete (rétention médicale, blob conservé).
                Route::get('documents', [DocumentMedicalController::class, 'index']);
                Route::post('documents', [DocumentMedicalController::class, 'store']);
                Route::get('documents/{id}', [DocumentMedicalController::class, 'show']);
                Route::delete('documents/{id}', [DocumentMedicalController::class, 'destroy']);
            });

            /*
            |--------------------------------------------------------------
            | Module 3 / 3A.2 — Avis (dépôt) et rendez-vous (côté patient).
            |--------------------------------------------------------------
            | Dépôt d'avis réservé aux comptes (F3.9). Demande/suivi/annulation de RDV
            | (F3.6) : isolation anti-IDOR par les membres du compte ; validation agent → M4.
            */
            Route::post('structures/{structure}/avis', [AvisController::class, 'store']);   // F3.9

            Route::get('rendez-vous', [RendezVousController::class, 'index']);              // F3.6 (mes RDV)
            Route::post('rendez-vous', [RendezVousController::class, 'store']);             // F3.6
            Route::patch('rendez-vous/{rendezVous}/annuler', [RendezVousController::class, 'annuler']);

            // Paiement (simulé) + reçu de RDV avec QR de check-in (N1/N2/N3).
            Route::post('rendez-vous/{rendezVous}/paiement', [RecuRdvController::class, 'store']);
            Route::get('rendez-vous/{rendezVous}/recu', [RecuRdvController::class, 'show']);
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

        /*
        |------------------------------------------------------------------
        | Module 3 / 3A.2 — Avis (F3.9) et signalements (F3.10) — lecture publique.
        |------------------------------------------------------------------
        | Avis visibles et historique des signalements validés : consultables sans compte.
        | Le dépôt d'un signalement est ANONYME (token lu s'il est présent). Le dépôt d'un
        | avis exige un compte (plus bas, groupe auth:sanctum).
        */
        Route::get('/structures/{structure}/avis', [AvisController::class, 'index']);                 // F3.9
        Route::get('/structures/{structure}/signalements', [SignalementController::class, 'index']);  // F3.10
        Route::post('/structures/{structure}/signalements', [SignalementController::class, 'store']); // F3.10

        // Module 1 — Triage et orientation médicale (F1.1 → F1.8). Endpoints publics.
        Route::get('/symptomes', [TriageController::class, 'symptomes']);              // F1.1
        Route::post('/triage/analyser', [TriageController::class, 'analyser']);        // F1.3
        Route::get('/triage/historique', [TriageController::class, 'historique']);     // F1.6
        Route::get('/triage/{triage}/fiche', [TriageController::class, 'fiche']);      // F1.8
    });
});
