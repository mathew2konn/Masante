<?php

use App\Http\Controllers\Api\V1\AlerteEpidemiqueController;
use App\Http\Controllers\Api\V1\AlerteSosController;
use App\Http\Controllers\Api\V1\AnalyseController;
use App\Http\Controllers\Api\V1\AppareilPushController;
use App\Http\Controllers\Api\V1\AssuranceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvisController;
use App\Http\Controllers\Api\V1\Backoffice\FacturationController as BackofficeFacturationController;
use App\Http\Controllers\Api\V1\Carnet\CalendrierVaccinalController;
use App\Http\Controllers\Api\V1\Carnet\ContactUrgenceController;
use App\Http\Controllers\Api\V1\Carnet\DocumentMedicalController;
use App\Http\Controllers\Api\V1\Carnet\GrossesseController;
use App\Http\Controllers\Api\V1\Carnet\MesureSanteController;
use App\Http\Controllers\Api\V1\Carnet\NoteObservationController;
use App\Http\Controllers\Api\V1\CarnetsPartagesController;
use App\Http\Controllers\Api\V1\CarteCmuController;
use App\Http\Controllers\Api\V1\ContributionCarnetController;
use App\Http\Controllers\Api\V1\CouvertureMembreController;
use App\Http\Controllers\Api\V1\DelegationController;
use App\Http\Controllers\Api\V1\DemandeInscriptionController;
use App\Http\Controllers\Api\V1\DonSangController;
use App\Http\Controllers\Api\V1\DossierTitulaireController;
use App\Http\Controllers\Api\V1\Etablissement\FacturationController as EtablissementFacturationController;
use App\Http\Controllers\Api\V1\FicheParcoursController;
use App\Http\Controllers\Api\V1\FicheVitaleController;
use App\Http\Controllers\Api\V1\GouvernanceReferentielController;
use App\Http\Controllers\Api\V1\ImageEtablissementController;
use App\Http\Controllers\Api\V1\Integration\StockOfficineController;
use App\Http\Controllers\Api\V1\Interne\PaiementNotificationController;
use App\Http\Controllers\Api\V1\MaladieController;
use App\Http\Controllers\Api\V1\MedecinController;
use App\Http\Controllers\Api\V1\MedicamentController;
use App\Http\Controllers\Api\V1\MembreController;
use App\Http\Controllers\Api\V1\MfaController;
use App\Http\Controllers\Api\V1\NisController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NumeroUrgenceController;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Http\Controllers\Api\V1\PhotoMedecinController;
use App\Http\Controllers\Api\V1\PhotoMembreController;
use App\Http\Controllers\Api\V1\Portail\DemandeInscriptionController as PortailDemandeInscriptionController;
use App\Http\Controllers\Api\V1\Portail\RendezVousController as PortailRendezVousController;
use App\Http\Controllers\Api\V1\ProtocoleController;
use App\Http\Controllers\Api\V1\QrController;
use App\Http\Controllers\Api\V1\RecuRdvController;
use App\Http\Controllers\Api\V1\ReferentController;
use App\Http\Controllers\Api\V1\ReferentielController;
use App\Http\Controllers\Api\V1\RendezVousController;
use App\Http\Controllers\Api\V1\ResponsableFamilleController;
use App\Http\Controllers\Api\V1\RevendicationCarnetController;
use App\Http\Controllers\Api\V1\SignalementController;
use App\Http\Controllers\Api\V1\SpecialiteController;
use App\Http\Controllers\Api\V1\StructureController;
use App\Http\Controllers\Api\V1\TriageController;
use App\Http\Controllers\Api\V1\VaccinController;
use App\Http\Controllers\Api\V1\VilleController;
use App\Http\Controllers\HealthController;
use App\Support\RegistreSectionsCarnet;
use Illuminate\Broadcasting\BroadcastController;
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
    | Canal interne — lot 6 (Java → Laravel)
    |----------------------------------------------------------------------
    | Authentifié par principal signé (VerificateurPrincipalSigne), JAMAIS par Sanctum : cet
    | appelant est le microservice de paiement, sans session utilisateur. Préfixe SIBLING de
    | `v1` (et non nichée dedans), conformément au chemin exact du lot 6.
    */
    Route::prefix('interne/v1')->group(function () {
        Route::post('paiements/notification', PaiementNotificationController::class);
    });

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
        | P6.1 — Identifiant National de Santé : vérification (CDC_09 §3.4).
        |------------------------------------------------------------------
        | Public à dessein : le CDC_09 §3.4 impose un retour immédiat à la saisie,
        | y compris avant connexion (un agent qui saisit le NIS d'un patient).
        |
        | ANTI-ÉNUMÉRATION : l'endpoint ne consulte JAMAIS la base. Il valide le
        | format et la clé, jamais l'existence — sinon il devient un oracle
        | permettant de balayer la population (CDC_10 §5). Limiteur resserré
        | (30/min/IP) en plus du limiteur « api » global.
        */
        Route::middleware('throttle:30,1')
            ->get('nis/{nis}/verifier', [NisController::class, 'verifier'])
            ->where('nis', '[A-Za-z0-9]{1,32}');

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

                // P1 — 2e étape de connexion (vérification du second facteur, MFA prêt à activer).
                Route::post('/mfa/verify', [AuthController::class, 'verifyMfa']);

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

                // P1 — Second facteur (TOTP), géré par l'utilisateur connecté. Prêt à activer.
                Route::prefix('mfa')->group(function () {
                    Route::get('/status', [MfaController::class, 'status']);
                    Route::post('/enroll', [MfaController::class, 'enroll']);
                    Route::post('/confirm', [MfaController::class, 'confirm']);
                    Route::delete('/', [MfaController::class, 'destroy']);
                });
            });
        });

        /*
        |------------------------------------------------------------------
        | Module 4 / 4.4 — Portail pro : validation des RDV (workflow staff, deux étapes — B1-a).
        |------------------------------------------------------------------
        | API du portail Next.js. Mêmes règles/transitions que le Blade (service
        | partagé). Garde : token Bearer + permission (`rdv.prevalider` en lecture ET pour
        | `previsalider`, `rdv.validate` pour `confirmer`) vérifiée DANS le contrôleur/service (le
        | middleware spatie viserait le guard web/session, pas Sanctum). `refuser` accepte l'une
        | ou l'autre. Le périmètre (services gérés) est appliqué côté service.
        */
        Route::middleware('auth:sanctum')->prefix('portail')->group(function () {
            Route::get('rendez-vous', [PortailRendezVousController::class, 'index']);
            Route::get('rendez-vous/{rdv}', [PortailRendezVousController::class, 'show']);
            Route::patch('rendez-vous/{rdv}/previsalider', [PortailRendezVousController::class, 'previsalider']);
            Route::patch('rendez-vous/{rdv}/confirmer', [PortailRendezVousController::class, 'confirmer']);
            Route::patch('rendez-vous/{rdv}/refuser', [PortailRendezVousController::class, 'refuser']);
            // B1-d (D10) — parité avec le Blade, aucune UI Next construite pour cette action (le
            // plan ne l'exige pas, précédent B1-c : `rdv_partage` est resté Blade-seul).
            Route::patch('rendez-vous/{rdv}/terminer', [PortailRendezVousController::class, 'terminer']);

            /*
             * P11.1 — Traitement des candidatures (CDC_11 §3, méthode 2). Aucune permission
             * neuve : approuver une demande, c'est créer un établissement, donc
             * `etablissement.manage`. Elle est vérifiée DANS le contrôleur — ces routes sont
             * authentifiées par Sanctum alors que les permissions vivent sur le guard `web`
             * (piège de P4).
             */
            Route::get('demandes-inscription', [PortailDemandeInscriptionController::class, 'index']);
            Route::get('demandes-inscription/{demande}', [PortailDemandeInscriptionController::class, 'show']);
            Route::post('demandes-inscription/{demande}/approuver', [PortailDemandeInscriptionController::class, 'approuver']);
            Route::post('demandes-inscription/{demande}/rejeter', [PortailDemandeInscriptionController::class, 'rejeter']);
        });

        /*
        |------------------------------------------------------------------
        | P6.3 — Gouvernance des référentiels nationaux (CDC_09 §10).
        |------------------------------------------------------------------
        | Écriture STRICTEMENT réservée aux habilités. Comme pour le portail ci-dessus, la
        | permission est vérifiée dans le SERVICE et non par le middleware `permission:` de
        | spatie : ces routes sont authentifiées par Sanctum alors que les permissions vivent
        | sur le guard `web` — le middleware refuserait sur un désaccord de guard plutôt que
        | sur un défaut de droit (piège rencontré en P4 sur `rdv.validate`).
        |
        | `referentiels/{code}/versions` (historique, authentifié) est déclaré ici, et
        | `referentiels/{code}/versions/{numero}` (instantané, public) plus bas : deux segments
        | de plus, donc aucun risque de capture entre les deux.
        */
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('referentiels-journal', [GouvernanceReferentielController::class, 'journal']);
            Route::get('referentiels/{code}/versions', [GouvernanceReferentielController::class, 'versions']);
            Route::get('referentiels/{code}/controle', [GouvernanceReferentielController::class, 'controle']);
            Route::post('referentiels/{code}/propositions', [GouvernanceReferentielController::class, 'proposer']);
            Route::post('referentiels/{code}/publication', [GouvernanceReferentielController::class, 'publier']);
            Route::post('referentiels/{code}/rejet', [GouvernanceReferentielController::class, 'rejeter']);
        });

        /*
        |------------------------------------------------------------------
        | P10b-1 — Registre des protocoles médicaux (CDC_08 §9.1, §10).
        |------------------------------------------------------------------
        | Même principe que la gouvernance des référentiels ci-dessus : l'habilitation est
        | vérifiée dans le SERVICE et non par le middleware `permission:` de spatie (guard
        | `web` contre Sanctum — piège P4).
        |
        | ORDRE DES DÉCLARATIONS : `protocoles/journal/integrite` est LITTÉRALE et doit précéder
        | `protocoles/{code}` (déclarée plus bas, en public), sinon elle serait capturée comme un
        | code de protocole nommé « journal ». Piège rencontré en P7-D0 sur `fermer`, en P6.5b sur
        | `signature/{type}/{id}` et en P6.6b sur `medicaments/interactions`.
        |
        | §10 exige en plus « MFA obligatoire » sur ces routes : le MFA TOTP existe depuis P1
        | derrière la porte `MFA_ENFORCE`, aujourd'hui fermée. Classé « prêt à activer », et dit
        | comme tel plutôt que présenté comme une garantie active.
        */
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('protocoles/journal/integrite', [ProtocoleController::class, 'integrite']);

            // P10b-2 — Évaluation (§9.1) et journal d'exécution (§10).
            //
            // MÊME PIÈGE D'ORDRE, DEUX FOIS : `protocoles/applications` est littérale et doit
            // précéder `protocoles/{code}` (publique, déclarée plus bas), sinon « applications »
            // serait lu comme un code de protocole ; et `applications/integrite` doit précéder
            // `applications/{trace}`, sinon « integrite » serait lu comme un identifiant de
            // trace. Le second cas est le plus sournois : il ne casse pas, il répond 404.
            Route::get('protocoles/applications/integrite', [ProtocoleController::class, 'integriteApplications']);
            Route::get('protocoles/applications/{trace}', [ProtocoleController::class, 'application']);
            Route::get('protocoles/applications', [ProtocoleController::class, 'applications']);
            Route::post('protocoles/evaluer', [ProtocoleController::class, 'evaluer']);
            Route::post('protocoles', [ProtocoleController::class, 'store']);
            Route::post('protocoles/{code}/versions', [ProtocoleController::class, 'ouvrirBrouillon']);
            Route::post('protocoles/{code}/versions/{numero}/valider', [ProtocoleController::class, 'valider']);
            Route::post('protocoles/{code}/versions/{numero}/publier', [ProtocoleController::class, 'publier']);
            Route::get('protocoles/{code}/versions/{numero}/validations', [ProtocoleController::class, 'dossierValidation']);
        });

        /*
        |------------------------------------------------------------------
        | Module 2 / 2A.2 — Carnet : membres de la famille (F2.1).
        |------------------------------------------------------------------
        | Routes authentifiées (token Bearer). L'isolation entre comptes
        | (anti-IDOR, §4.3 Sécurité) est assurée par MembreFamillePolicy.
        */
        // `journal.delegue` : trace nominative de toute lecture faite par un DÉLÉGUÉ. Posé sur le
        // GROUPE et non route par route — une route `{membre}` ajoutée plus tard est journalisée
        // sans que personne ait à y penser (carnet familial partagé, incrément A).
        Route::middleware(['auth:sanctum', 'journal.delegue'])->group(function () {
            // P6.1 — Dossier de santé du titulaire (ADR-021 §2.1, variante (c)).
            // DÉCLARÉ AVANT l'apiResource : sinon `/membres/titulaire` serait capté par
            // `/membres/{membre}` et le model binding échouerait en 404.
            Route::get('membres/titulaire', [DossierTitulaireController::class, 'show']);
            Route::post('membres/titulaire', [DossierTitulaireController::class, 'store']);

            // Carnet familial partagé (A) — les carnets qu'on m'a partagés. MÊME PIÈGE que
            // `titulaire` : déclaré avant l'apiResource, sinon capté par `/membres/{membre}`.
            Route::get('membres/partages', [CarnetsPartagesController::class, 'index']);

            // Carnet familial partagé (B) — reconnaître un carnet comme le sien, AVANT que
            // l'écran de complétion de P6.1 n'en crée un second (et un second NIS).
            Route::get('membres/revendicables', [RevendicationCarnetController::class, 'index']);

            Route::apiResource('membres', MembreController::class)->parameters(['membres' => 'membre']);

            Route::post('membres/{membre}/revendiquer', [RevendicationCarnetController::class, 'store']);

            /*
            |--------------------------------------------------------------
            | Carnet familial partagé (C) — contributions au brouillon.
            |--------------------------------------------------------------
            | Un délégué propose ; un responsable valide. Ce que le MÉDECIN
            | écrit ne passe jamais par ici : une ordonnance ne peut pas
            | attendre l'accord d'un parent en voyage.
            */
            Route::get('membres/{membre}/contributions', [ContributionCarnetController::class, 'index']);
            Route::post('membres/{membre}/contributions', [ContributionCarnetController::class, 'store']);

            // La file du responsable — déclarée avant `contributions/{contribution}`.
            Route::get('contributions', [ContributionCarnetController::class, 'enAttente']);
            Route::post('contributions/{contribution}/valider', [ContributionCarnetController::class, 'valider']);
            Route::post('contributions/{contribution}/rejeter', [ContributionCarnetController::class, 'rejeter']);

            // Responsables de famille : le propriétaire l'est de droit, il peut en désigner un second.
            Route::get('responsables', [ResponsableFamilleController::class, 'index']);
            Route::post('responsables', [ResponsableFamilleController::class, 'store']);
            Route::delete('responsables/{responsable}', [ResponsableFamilleController::class, 'destroy']);

            /*
            |--------------------------------------------------------------
            | Carnet familial partagé (D1) — notifications en application.
            |--------------------------------------------------------------
            | Sans elles, l'incrément C ne sert à rien : le responsable
            | devrait deviner qu'un ajout l'attend. Toutes les routes sont
            | scopées au compte connecté — il n'existe aucun chemin
            | permettant de désigner un autre destinataire.
            |
            | `non-lues` et `tout-lu` sont DÉCLARÉES AVANT `{notification}`,
            | sinon elles seraient captées comme des identifiants.
            */
            Route::get('notifications', [NotificationController::class, 'index']);
            Route::get('notifications/non-lues', [NotificationController::class, 'nonLues']);
            Route::post('notifications/tout-lu', [NotificationController::class, 'toutMarquerLu']);
            Route::post('notifications/{notification}/lu', [NotificationController::class, 'marquerLu']);

            // Jeton de push du téléphone. Le canal est gaté OFF (`masante.notifications.push`) :
            // l'enregistrement fonctionne, l'envoi attend un development build (dette D1).
            Route::post('appareils-push', [AppareilPushController::class, 'store']);
            Route::delete('appareils-push', [AppareilPushController::class, 'destroy']);

            // B1-c (D9) — autorisation du canal de présence temps réel. Contrôleur NATIF de
            // Laravel (`illuminate/broadcasting`, présent avec `laravel/framework` — indépendant
            // du pilote Reverb) : il lit `routes/channels.php` et applique la garde qui y est
            // écrite (D9, « le seul titulaire/patient concerné »). Route délibérément PAS posée
            // via `Broadcast::routes()` (son défaut historique vise le guard `web`) : ici c'est
            // le compte MOBILE, authentifié par jeton Bearer, qui doit s'autoriser — même piège
            // de guard que `rdv.validate` en P4.
            Route::post('broadcasting/auth', [BroadcastController::class, 'authenticate']);

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
             * D2 — Fiche de parcours : la version lisible et assemblée de ce journal.
             *
             * Route SÉPARÉE de `/acces`, et c'est intentionnel. `/acces` est le droit d'accès
             * personnel du propriétaire (§10.3) : lignes brutes, adresse IP, lectures familiales
             * comprises. La fiche est ouverte à toute la famille (`viewParcours`) et ne montre
             * que les passages en établissement. Deux besoins, deux surfaces, deux gardes.
             */
            Route::get('membres/{membre}/parcours', [FicheParcoursController::class, 'show']);

            // P6.1 — Lecture du NIS d'un dossier (CDC_09 §3.5). Contrairement au
            // `matricule_ivs` interne, le NIS est destiné à être communiqué. L'isolation
            // reste assurée par MembreFamillePolicy::view (anti-IDOR, inchangée).
            Route::get('membres/{membre}/nis', [NisController::class, 'afficher']);

            /*
             * P6.8b — Calendrier vaccinal du membre (CDC_09 §8).
             *
             * DÉCLARÉE AVANT le groupe des sections du carnet : `vaccinations` y est un préfixe
             * littéral et ne recouvre pas `calendrier-vaccinal`, mais l'ordre reste explicite —
             * le piège de `dossier/fermer` (P7-D0) puis de `signature/{type}/{id}` (P6.5b) s'est
             * produit deux fois.
             *
             * LECTURE SEULE. Le calendrier répond « qu'est-ce qui est dû ? » ; il n'écrit ni dans
             * `vaccinations` ni dans `rappels` (décision W3). La garde est `view` — celle de P7-A,
             * ni élargie ni déplacée : le calendrier met en regard ce que le lecteur peut déjà lire
             * et un référentiel public.
             */
            Route::get('membres/{membre}/calendrier-vaccinal', [CalendrierVaccinalController::class, 'show']);

            // B3 — Délégation d'accès (voie 3) : le titulaire invite un délégué sur un membre ;
            // le délégué accepte/refuse ; révocable des deux côtés, effet immédiat.
            // Depuis l'incrément A, une invitation porte `lecture` : le délégué VOIT le carnet.
            Route::get('delegations', [DelegationController::class, 'index']);
            // Partage en masse — déclaré AVANT les routes `delegations/{delegation}`.
            Route::post('delegations/en-masse', [DelegationController::class, 'storeEnMasse']);
            Route::post('membres/{membre}/delegations', [DelegationController::class, 'store']);
            Route::post('delegations/{delegation}/accepter', [DelegationController::class, 'accepter']);
            Route::delete('delegations/{delegation}', [DelegationController::class, 'destroy']);

            // F2.3 — Carte CMU numérique (couche de présentation) : n° masqué + code signé,
            // gated par le palier « vérifié » (stub dev). N'ouvre PAS le dossier (distinct du QR).
            Route::get('membres/{membre}/carte-cmu', [CarteCmuController::class, 'show']);

            // P6.8d — Couvertures santé d'un membre (CDC_09 §8, CDC_06 §8). Ce qui remplace les
            // trois colonnes `cmu_*` : une couverture est un CONTRAT entre une personne et un
            // organisme, et il peut y en avoir plusieurs (« CNAM, PUIS assurances privées », §8).
            //
            // Lecture ouverte au délégué en lecture (Policy `view`, P7-A) ; écriture réservée au
            // PROPRIÉTAIRE (Policy `update`) — un délégué lit le carnet, il ne souscrit pas au nom
            // d'autrui.
            Route::get('membres/{membre}/couvertures', [CouvertureMembreController::class, 'index']);
            Route::post('membres/{membre}/couvertures', [CouvertureMembreController::class, 'store']);
            Route::put('membres/{membre}/couvertures/{couverture}', [CouvertureMembreController::class, 'update']);
            Route::delete('membres/{membre}/couvertures/{couverture}', [CouvertureMembreController::class, 'destroy']);

            // Module 5 / FN2 — Fiche vitale d'urgence : sous-ensemble vital minimal du membre,
            // destiné à être mis en cache chiffré sur le téléphone puis affiché hors connexion.
            Route::get('membres/{membre}/fiche-vitale', [FicheVitaleController::class, 'show']);

            // Module 5 / FN1 — Alertes SOS. L'appel SAMU et le SMS partent du TÉLÉPHONE (tel:/sms:) :
            // ces routes ne font que journaliser l'alerte a posteriori, en best-effort.
            Route::post('sos', [AlerteSosController::class, 'store']);
            Route::get('sos', [AlerteSosController::class, 'index']);

            // Module 5 / FN3 — Alertes épidémiques de MA commune (+ alertes nationales).
            Route::get('alertes-epidemiques', [AlerteEpidemiqueController::class, 'index']);

            // Module 5 / FN6 — Don de sang. Mes membres donneurs + les urgences auxquelles ils
            // peuvent répondre (ciblage serveur). L'inscription est un consentement, membre par
            // membre. Les CENTRES de collecte n'ont pas d'endpoint : ce sont les structures de
            // l'annuaire portant un service `don_sang` (GET /v1/structures?specialite=don_sang).
            // Module 5 / FN7-FN8 — Signaler un prix ou une rupture exige un COMPTE : un relevé
            // anonyme ne se conteste pas, et un comparateur ouvert à l'anonymat s'empoisonne en une
            // nuit. Le « scan de reçu » ne crée rien : il PROPOSE des montants (photo détruite aussitôt).
            Route::post('medicaments/{medicament}/prix', [MedicamentController::class, 'releverPrix']);
            Route::post('medicaments/{medicament}/rupture', [MedicamentController::class, 'signalerRupture']);
            Route::post('recus/lecture', [MedicamentController::class, 'lireRecu'])->middleware('throttle:10,1');

            Route::get('don-sang', [DonSangController::class, 'index']);
            Route::post('membres/{membre}/donneur', [DonSangController::class, 'inscrire']);
            Route::post('membres/{membre}/donneur/don', [DonSangController::class, 'declarerDon']);
            Route::delete('membres/{membre}/donneur', [DonSangController::class, 'retirer']);

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
            // Source unique (incrément C) : le CRUD générique et le dépôt de contributions lisent
            // la MÊME liste. `contacts-urgence` (F2.11) garde ses routes ici mais reste hors
            // contributions — son `store()` porte des règles propres qu'un chemin générique
            // contournerait.
            $sections = RegistreSectionsCarnet::SECTIONS
                + ['contacts-urgence' => ContactUrgenceController::class];

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

                // Module 5 / FN4 — Suivi de grossesse : déclaration, ajustement DDG/clôture (pas de
                // suppression : rétention médicale), consultations CPN en append-only. Le GET renvoie
                // aussi le calendrier des 8 contacts OMS (référentiel en base, cf. F1.3).
                Route::get('grossesse', [GrossesseController::class, 'index']);
                Route::post('grossesse', [GrossesseController::class, 'store']);
                Route::match(['put', 'patch'], 'grossesse/{id}', [GrossesseController::class, 'update']);
                Route::post('grossesse/{id}/consultations', [GrossesseController::class, 'ajouterConsultation']);

                // Module 5 / FN5 — Journal de bord des maladies chroniques. Pas d'`update` : une
                // mesure est un fait daté (on supprime et on ressaisit). Le GET renvoie le
                // référentiel des seuils : le mobile ne code aucune norme médicale.
                Route::get('mesures', [MesureSanteController::class, 'index']);
                Route::post('mesures', [MesureSanteController::class, 'store']);
                Route::delete('mesures/{id}', [MesureSanteController::class, 'destroy']);

                // Module 5 / 5.6 — Médecin référent (voie 2, Sécurité §4.4) : désignation (accès
                // PERMANENT au dossier, d'où le palier « compte vérifié ») et révocation immédiate.
                Route::get('referent', [ReferentController::class, 'index']);
                Route::post('referent', [ReferentController::class, 'store']);
                Route::delete('referent/{id}', [ReferentController::class, 'destroy']);

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

            /*
            |--------------------------------------------------------------
            | P6.4c — Images des établissements (CDC_11 §3.1 « formulaire dédié »).
            |--------------------------------------------------------------
            | Écriture sous Sanctum, puis re-gardée DANS le service : permission nationale
            | `etablissement.manage` OU gestionnaire de CET établissement. La vérification
            | n'est pas faite par le middleware `permission:` de spatie — les permissions
            | sont posées sur le guard `web` et ces routes sont Sanctum (piège de P4).
            |
            | La lecture, elle, est publique : voir plus bas, avec le reste de l'annuaire.
            */
            Route::post('structures/{structure}/images', [ImageEtablissementController::class, 'store']);
            Route::delete('structures/{structure}/images/{image}', [ImageEtablissementController::class, 'destroy']);

            Route::get('rendez-vous', [RendezVousController::class, 'index']);              // F3.6 (mes RDV)
            Route::post('rendez-vous', [RendezVousController::class, 'store']);             // F3.6
            Route::patch('rendez-vous/{rendezVous}/annuler', [RendezVousController::class, 'annuler']);

            // B1-b / D6 — Associer un triage APRÈS COUP (le lien `triage_id` existe depuis
            // toujours ; store() le posait déjà à la création, ceci le pose plus tard). Mêmes
            // vérifications anti-IDOR que store() : membre du compte, triage du compte.
            Route::patch('rendez-vous/{rendezVous}/triage', [RendezVousController::class, 'associerTriage']);

            // Paiement (simulé) + reçu de RDV avec QR de check-in (N1/N2/N3).
            Route::post('rendez-vous/{rendezVous}/paiement', [RecuRdvController::class, 'store']);
            Route::get('rendez-vous/{rendezVous}/recu', [RecuRdvController::class, 'show']);

            // B4-b — paiement en ligne réel (GeniusPay), à côté du chemin simulé ci-dessus (S7 :
            // aucun des deux n'est retiré). GET = disponibilité seule (zéro appel réseau si
            // l'établissement n'a pas d'identifiant national) ; POST = ouvre/réutilise le checkout.
            Route::get('rendez-vous/{rendezVous}/paiement-en-ligne', [RecuRdvController::class, 'disponibiliteEnLigne']);
            Route::post('rendez-vous/{rendezVous}/paiement-en-ligne', [RecuRdvController::class, 'payerEnLigne']);
        });

        /*
        |------------------------------------------------------------------
        | Lot 8 (post-facturation) — API de facturation partenaire.
        |------------------------------------------------------------------
        | Établissement : lecture seule, périmètre TOUJOURS celui de l'utilisateur authentifié
        | (`structure_id`), jamais un id reçu du client (sauf la ressource elle-même sur
        | `factures/{facture}`, vérifiée explicitement dans le contrôleur — anti-IDOR).
        | Back-office : `POST .../reglements` — jamais accessible à l'établissement, vérifié dans
        | le contrôleur via `can('recouvrement.manage')` et non par le middleware `permission:`
        | (piège de P4 : permissions posées sur le guard `web`, ces routes sont Sanctum).
        */
        Route::middleware('auth:sanctum')->prefix('etablissement/facturation')->group(function () {
            Route::get('tableau-bord', [EtablissementFacturationController::class, 'tableauBord']);
            Route::get('transactions', [EtablissementFacturationController::class, 'transactions']);
            Route::get('factures', [EtablissementFacturationController::class, 'factures']);
            Route::get('factures/{facture}', [EtablissementFacturationController::class, 'facture']);
        });

        Route::middleware('auth:sanctum')->prefix('backoffice/facturation')->group(function () {
            Route::post('factures/{facture}/reglements', [BackofficeFacturationController::class, 'enregistrerReglement']);
        });

        /*
        |------------------------------------------------------------------
        | Module 3 / 3A.1 — Structures sanitaires géolocalisées (F3.1→F3.5, F3.8).
        |------------------------------------------------------------------
        | Endpoints PUBLICS en lecture : trouver un hôpital/une pharmacie ne demande
        | aucune formalité d'identité (doc Identification — accès léger). Aucune donnée
        | médicale. Les disponibilités sont seedées (écriture agents + Firebase → Module 4).
        */
        /*
        |------------------------------------------------------------------
        | P6.4b — Villes couvertes et localisation de l'utilisateur.
        |------------------------------------------------------------------
        | PUBLICS : l'écran a besoin de la liste des villes AVANT toute connexion, pour
        | proposer le sélecteur de repli quand la localisation est refusée.
        |
        | `localiser` est la traduction de la règle de frontière : le mobile envoie sa
        | position, le BACKEND répond quelle ville la contient, si elle affiche des communes
        | et lesquelles. Le front affiche, il ne déduit pas.
        */
        Route::get('/villes', [VilleController::class, 'index']);
        Route::get('/villes/localiser', [VilleController::class, 'localiser']);

        /*
        |------------------------------------------------------------------
        | P11.1 — Demande d'inscription d'un établissement (CDC_11 §3, méthode 2).
        |------------------------------------------------------------------
        | PUBLICS, et c'est tout le point : « Clinique Saint Joseph souhaite rejoindre la
        | plateforme » vient de quelqu'un qui n'a ni compte ni contact préalable. Exiger un
        | jeton ici reviendrait à la méthode 1, celle où l'administrateur crée lui-même.
        |
        | Limiteur STRICT (5 dépôts par heure) : c'est un formulaire public qui écrit en base.
        | Le service ajoute « une seule demande en attente par adresse », et rien de ce qui est
        | déposé n'atteint `structures_sanitaires` avant qu'un humain habilité n'approuve.
        |
        | Le suivi rend le seul état de la décision, jamais le contenu déposé — et 404 sur une
        | référence inconnue, jamais 403 : un 403 confirmerait qu'une demande existe là.
        */
        /*
        |------------------------------------------------------------------
        | P11.2 — API D'INGESTION PARTENAIRE (CDC_11 §2/§7.7, ADR-030).
        |------------------------------------------------------------------
        | HORS `auth:sanctum`, et ce n'est pas un oubli : un logiciel de caisse n'a pas de
        | session, et un jeton de citoyen n'a rien à faire ici. C'est la TROISIÈME population
        | d'authentification du projet — clé de client + signature HMAC, vérifiée dans le
        | contrôleur par `AuthentificationClientApi` (ADR-030 : « trois populations d'auth,
        | jamais étirées en une »).
        |
        | Limiteur large : un partenaire pousse un catalogue entier, pas une requête d'écran.
        | L'anti-rejeu, la fraîcheur et l'idempotence font le reste.
        */
        Route::post('/integration/stock-officine', StockOfficineController::class)
            ->middleware('throttle:120,1');

        Route::post('/etablissements/demandes', [DemandeInscriptionController::class, 'store'])
            ->middleware('throttle:5,60');
        Route::get('/etablissements/demandes/{reference}', [DemandeInscriptionController::class, 'suivi'])
            ->middleware('throttle:30,1');

        /*
        |------------------------------------------------------------------
        | P6.8e — Numéros d'urgence nationaux (CDC_09 §8).
        |------------------------------------------------------------------
        | PUBLIC PAR NÉCESSITÉ, non par confort : l'écran SOS et la carte vitale d'urgence
        | sont atteignables DEPUIS L'ÉCRAN DE CONNEXION, pour un secouriste qui ramasse le
        | téléphone d'un inconscient (FN2). Exiger un jeton reviendrait à demander ses
        | identifiants à quelqu'un qui n'a pas de compte, devant un blessé.
        |
        | Répond 503 tant qu'aucune version n'est publiée : le serveur ne sert jamais la
        | table de travail en se faisant passer pour le référentiel. C'est le CLIENT qui
        | porte le repli (cache SecureStore, puis valeur livrée avec l'application).
        */
        Route::get('/numeros-urgence', [NumeroUrgenceController::class, 'index']);

        Route::get('/pharmacies-garde', [StructureController::class, 'pharmaciesGarde']); // F3.8
        Route::get('/structures', [StructureController::class, 'index']);                 // F3.1/F3.2/F3.3
        Route::get('/structures/{structure}', [StructureController::class, 'show']);       // F3.5

        // P6.4c — Diffusion PUBLIQUE d'une image. Une vitrine d'hôpital est faite pour être vue :
        // l'exiger authentifiée empêcherait un citoyen de reconnaître l'établissement avant sa
        // première connexion. Déclarée APRÈS `/structures/{structure}` : l'ordre importe peu ici,
        // les deux motifs ne se recouvrent pas, mais la proximité rend la lecture évidente.
        Route::get('/structures/{structure}/images/{image}', [ImageEtablissementController::class, 'show']);

        // Module 5 / FN7-FN8 — Catalogue, comparateur de prix et ruptures : PUBLICS en lecture.
        // Savoir où trouver un médicament et à quel prix ne demande aucune identité, et une
        // information de prix n'a d'utilité que largement diffusée.
        Route::get('/medicaments', [MedicamentController::class, 'index']);
        // P6.6b — DÉCLARÉE AVANT `{medicament}` : un segment littéral placé après un paramètre se
        // ferait capter par lui (piège rencontré en P7-D0 sur `dossier/fermer`, puis en P6.5b sur
        // `signature/{type}/{id}`). Le référentiel RAPPORTE les interactions déclarées ; il ne les
        // analyse pas et ne bloque rien — c'est le `interaction-service` de CDC_05 qui jugera.
        Route::get('/medicaments/interactions', [MedicamentController::class, 'interactions']);
        Route::get('/medicaments/{medicament}/prix', [MedicamentController::class, 'prix']);
        Route::get('/ruptures', [MedicamentController::class, 'ruptures']);

        // P6.7a — Catalogue national des analyses (CDC_09 §7.3), PUBLIC en lecture comme le reste
        // des référentiels d'annuaire. `references` est declaree APRES `{analyse}` sans risque :
        // elle est imbriquee sous le parametre, pas a cote de lui.
        //
        // CE QUE CES ROUTES NE FONT PAS : elles ne qualifient aucun resultat. Elles servent la
        // plage habituellement observee, et la phrase qui dit que ce n'est pas un diagnostic.
        Route::get('/analyses', [AnalyseController::class, 'index']);
        Route::get('/analyses/{analyse}/references', [AnalyseController::class, 'references']);

        // P6.8a — Vocabulaire national des spécialités (CDC_09 §8), PUBLIC en lecture. Il existe
        // pour qu'aucun client ne recopie un code : `don_sang` vivait EN DUR dans le mobile,
        // récidive du constat G-a de P6.4b. Un code recopié dans un client est un code qu'aucun
        // typecheck ne relie à la base.
        Route::get('/specialites', [SpecialiteController::class, 'index']);

        // P6.8b — Vaccins et calendrier vaccinal national (CDC_09 §8), PUBLIC en lecture : savoir
        // à quel âge une dose est prévue est une information de santé publique, et l'exiger
        // authentifiée en priverait précisément ceux qui n'ont pas encore de compte.
        //
        // SERVI DEPUIS LA VERSION PUBLIÉE, jamais depuis la table : un `UPDATE` direct n'a aucun
        // effet ici avant publication (leçon L1+L2, ADR-025 §6). Sans version en vigueur, la
        // réponse est un 503 explicite — jamais une liste vide, qui aurait ressemblé à « aucun
        // vaccin n'existe ».
        Route::get('/vaccins', [VaccinController::class, 'index']);

        // P6.8c — Référentiel national des maladies (CDC_09 §8), PUBLIC en lecture, même raison.
        //
        // `?q=` cherche dans le libellé officiel ET dans les libellés alternatifs — c'est le service
        // que rend le multilingue du §8 : « palu » retrouve « Paludisme ». Aucune distance n'est
        // mesurée, aucune maladie n'est devinée : deviner serait un diagnostic posé par une machine
        // (CDC_00 §4). SERVI DEPUIS LA VERSION PUBLIÉE, 503 explicite tant qu'il n'y en a aucune.
        Route::get('/maladies', [MaladieController::class, 'index']);

        // P6.8d — Registre national des organismes d'assurance agréés (CDC_09 §8), PUBLIC en
        // lecture : c'est au moment de créer son dossier qu'on cherche le nom de sa mutuelle, et
        // aucune donnée personnelle n'y figure. Sert aussi les LIBELLÉS des six familles du §8.2,
        // pour qu'aucun client ne les recopie (4ᵉ récidive évitée du constat G-a de P6.4b).
        //
        // SERVI DEPUIS LA VERSION PUBLIÉE, 503 explicite tant qu'il n'y en a aucune. La réponse dit
        // ce que cette liste NE prouve PAS : aucun agrément n'y a été vérifié.
        Route::get('/assurances', [AssuranceController::class, 'index']);

        // Module 5 / FN6 — Groupes sanguins les plus demandés (public : un appel au don n'a de sens
        // que largement visible). Les urgences remontent en tête. Les donneurs INSCRITS reçoivent en
        // plus une alerte personnelle et ciblée (GET /v1/don-sang, sous auth).
        Route::get('/don-sang/besoins', [DonSangController::class, 'besoins']);

        // Module 5 / 5.6 — Recherche par nom dans l'annuaire des praticiens (choix d'un référent).
        // Mêmes données que la fiche d'une structure (F3.5), avec une entrée par nom : public.
        Route::get('/medecins', [MedecinController::class, 'index']);

        // B1-b — Photo de profil d'un médecin (D5). Publique comme le reste de l'annuaire ; le
        // dépôt/retrait relèvent du portail (routes/web.php, permission:medecin.manage).
        Route::get('/medecins/{medecin}/photo', [PhotoMedecinController::class, 'show']);

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

        /*
        |------------------------------------------------------------------
        | P6.3 — Diffusion des référentiels nationaux (CDC_09 §10) : lecture PUBLIQUE.
        |------------------------------------------------------------------
        | « Les référentiels sont exposés en lecture à tous les services » : un socle
        | d'interopérabilité qu'il faut s'authentifier pour lire n'en est pas un. Aucune donnée
        | personnelle n'y figure — c'est la même logique que `/symptomes` et `/medicaments`
        | juste au-dessus, dont ces référentiels sont d'ailleurs la version gouvernée.
        |
        | Le contenu servi est celui de la VERSION PUBLIÉE, jamais la table métier en direct :
        | c'est ce décalage assumé qui permet à une décision de citer une version.
        */
        Route::get('/referentiels', [ReferentielController::class, 'index']);
        Route::get('/referentiels/{code}', [ReferentielController::class, 'show']);
        Route::get('/referentiels/{code}/versions/{numero}', [ReferentielController::class, 'version'])
            ->whereNumber('numero');

        /*
        |------------------------------------------------------------------
        | P10b-1 — Consultation des protocoles médicaux (CDC_08 §9.1) : lecture PUBLIQUE.
        |------------------------------------------------------------------
        | Même raisonnement qu'au-dessus : un protocole publié est un texte de référence, pas une
        | donnée personnelle. Le §1.1 en fait d'ailleurs un instrument d'harmonisation — le cacher
        | derrière une authentification le priverait de son objet.
        |
        | SEULES LES VERSIONS `actif` ET `archive` SORTENT PAR ICI. Un brouillon n'est jamais
        | servi : les protocoles thérapeutiques de démonstration (décision G1 N3) sont donc
        | invisibles ET inapplicables, et le §1.6 tient par construction.
        |
        | `protocoles/journal/integrite` est déclarée PLUS HAUT, dans le groupe authentifié : sans
        | cela `{code}` la capturerait. Le `whereNumber('numero')` joue le même rôle sur le second
        | segment.
        */
        Route::get('/protocoles', [ProtocoleController::class, 'index']);
        Route::get('/protocoles/{code}', [ProtocoleController::class, 'show']);
        Route::get('/protocoles/{code}/versions/{numero}', [ProtocoleController::class, 'version'])
            ->whereNumber('numero');

        // Module 1 — Triage et orientation médicale (F1.1 → F1.8). Endpoints publics.
        Route::get('/symptomes', [TriageController::class, 'symptomes']);              // F1.1
        // P10b-3-i — un tour de questionnaire adaptatif (CDC_08 §4.3b). Déclarée AVANT
        // `/triage/{triage}/fiche` : une route littérale placée après une route à paramètre se
        // ferait capter par elle (piège rencontré en P7-D0, P6.5b et P6.6b).
        Route::post('/triage/questions', [TriageController::class, 'questions']);      // F1.2
        // P10c-1 — les constantes cliniques collectables (§5.2) et ce que le carnet en propose.
        // Littérale elle aussi, donc AVANT `/triage/{triage}/fiche` — même piège.
        Route::get('/triage/constantes', [TriageController::class, 'constantes']);     // §5.2
        Route::post('/triage/analyser', [TriageController::class, 'analyser']);        // F1.3
        Route::get('/triage/historique', [TriageController::class, 'historique']);     // F1.6
        Route::get('/triage/{triage}/fiche', [TriageController::class, 'fiche']);      // F1.8
    });
});
