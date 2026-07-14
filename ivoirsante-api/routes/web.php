<?php

use App\Http\Controllers\Portail\ActivationController;
use App\Http\Controllers\Portail\AgentController;
use App\Http\Controllers\Portail\AlerteEpidemiqueController as PortailAlerteEpidemiqueController;
use App\Http\Controllers\Portail\AuthController;
use App\Http\Controllers\Portail\BesoinSangController;
use App\Http\Controllers\Portail\BrisDeGlaceController;
use App\Http\Controllers\Portail\CompteController;
use App\Http\Controllers\Portail\DashboardController;
use App\Http\Controllers\Portail\DisponibiliteController;
use App\Http\Controllers\Portail\DossierController;
use App\Http\Controllers\Portail\EtablissementController;
use App\Http\Controllers\Portail\MedecinController as PortailMedecinController;
use App\Http\Controllers\Portail\MesPatientsController;
use App\Http\Controllers\Portail\ModerationController;
use App\Http\Controllers\Portail\RendezVousController;
use App\Http\Controllers\Portail\ScanController;
use App\Http\Controllers\Portail\ServiceController;
use App\Http\Controllers\Portail\StatistiqueController;
use App\Http\Controllers\Portail\StockPharmacieController;
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

        // 5.3 — Bris de glace (AGENT DES URGENCES habilité individuellement). Voie 4 d'accès au
        // dossier : périmètre vital minimal, 15 min, justification obligatoire, audit renforcé.
        Route::middleware('permission:urgence.bris_de_glace')->prefix('urgence')->name('urgence.')->group(function () {
            Route::get('bris-de-glace', [BrisDeGlaceController::class, 'index'])->name('bris');
            Route::post('bris-de-glace', [BrisDeGlaceController::class, 'ouvrir'])
                ->name('bris.ouvrir')->middleware('throttle:10,1');
            Route::get('dossier', [BrisDeGlaceController::class, 'dossier'])->name('dossier');
            Route::post('dossier/fermer', [BrisDeGlaceController::class, 'fermer'])->name('fermer');
        });

        // 5.4 — Alertes épidémiques (ADMIN, sante_publique.manage). L'admin reporte les bulletins OMS.
        Route::middleware('permission:sante_publique.manage')->group(function () {
            Route::get('sante-publique', [PortailAlerteEpidemiqueController::class, 'index'])->name('sante-publique.index');
            Route::get('sante-publique/creer', [PortailAlerteEpidemiqueController::class, 'create'])->name('sante-publique.create');
            Route::post('sante-publique', [PortailAlerteEpidemiqueController::class, 'store'])->name('sante-publique.store');
            Route::get('sante-publique/{alerte}/editer', [PortailAlerteEpidemiqueController::class, 'edit'])->name('sante-publique.edit');
            Route::put('sante-publique/{alerte}', [PortailAlerteEpidemiqueController::class, 'update'])->name('sante-publique.update');
            Route::patch('sante-publique/{alerte}/actif', [PortailAlerteEpidemiqueController::class, 'toggleActif'])->name('sante-publique.toggle');
        });

        // 4.7 — Comptes du portail (ADMIN). Staff seulement : les comptes patients n'y figurent pas.
        Route::middleware('permission:compte.manage')->group(function () {
            Route::get('comptes', [CompteController::class, 'index'])->name('comptes.index');
            Route::patch('comptes/{compte}/actif', [CompteController::class, 'toggleActif'])->name('comptes.toggle');
            Route::post('comptes/{compte}/lien', [CompteController::class, 'regenererLien'])->name('comptes.lien');
        });

        // 4.8 — Statistiques : globales (ADMIN) et par établissement (GESTIONNAIRE, cloisonné).
        Route::get('statistiques', [StatistiqueController::class, 'global'])
            ->middleware('permission:stats.global')->name('statistiques.global');
        Route::get('statistiques/mon-etablissement', [StatistiqueController::class, 'etablissement'])
            ->middleware('permission:stats.etablissement')->name('statistiques.etablissement');

        // 4.6 — Modération des avis et signalements (ADMIN IVOIRSANTÉ uniquement).
        Route::middleware('permission:moderation.manage')->group(function () {
            Route::get('moderation', [ModerationController::class, 'index'])->name('moderation.index');
            Route::patch('moderation/avis/{avis}', [ModerationController::class, 'basculerAvis'])->name('moderation.avis');
            Route::patch('moderation/signalements/{signalement}', [ModerationController::class, 'trancher'])->name('moderation.trancher');
            Route::patch('moderation/signalements/{signalement}/publication', [ModerationController::class, 'basculerPublication'])->name('moderation.publication');
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
            // 5.3 — habilitation individuelle au bris de glace (agents des urgences uniquement).
            Route::patch('agents/{agent}/bris-de-glace', [AgentController::class, 'toggleBrisDeGlace'])->name('agents.bris');
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
        });

        // 5.6 — Annuaire des praticiens de MON établissement (gestionnaire). Le CdC renvoyait cette
        // configuration « au portail admin » sans qu'elle soit jamais construite : sans elle, un
        // établissement créé après le seed n'a aucun praticien — donc aucune fiche à relier à un
        // compte, donc pas de médecin référent possible. Pas de suppression : on désactive.
        Route::middleware('permission:medecin.manage')->group(function () {
            Route::get('medecins', [PortailMedecinController::class, 'index'])->name('medecins.index');
            Route::get('medecins/creer', [PortailMedecinController::class, 'create'])->name('medecins.create');
            Route::post('medecins', [PortailMedecinController::class, 'store'])->name('medecins.store');
            Route::get('medecins/{medecin}/editer', [PortailMedecinController::class, 'edit'])->name('medecins.edit');
            Route::put('medecins/{medecin}', [PortailMedecinController::class, 'update'])->name('medecins.update');
            Route::patch('medecins/{medecin}/actif', [PortailMedecinController::class, 'toggleActif'])->name('medecins.toggle');
        });

        // 5.8 — Prix & stock d'une PHARMACIE partenaire (FN7/FN8, « modèle freemium » du CdC).
        // Le pharmacien fait autorité sur SA officine : sa déclaration prime sur les relevés des
        // patients. Réservé aux structures de type `pharmacie` (revérifié dans le contrôleur).
        Route::middleware('permission:medicament.manage')->group(function () {
            Route::get('stock', [StockPharmacieController::class, 'index'])->name('stock.index');
            Route::post('stock/{medicament}', [StockPharmacieController::class, 'declarer'])->name('stock.declarer');
        });

        // 5.7 — Don de sang (FN6) : l'ÉTABLISSEMENT publie ses besoins — lui seul sait qu'il manque
        // de O− ce matin. Seul le niveau « urgent » alerte les donneurs compatibles. L'écran ne montre
        // qu'un COMPTEUR de donneurs mobilisables, jamais leur identité (minimisation).
        Route::middleware('permission:don_sang.manage')->prefix('don-sang')->name('don-sang.')->group(function () {
            Route::get('/', [BesoinSangController::class, 'index'])->name('index');
            Route::get('creer', [BesoinSangController::class, 'create'])->name('create');
            Route::post('/', [BesoinSangController::class, 'store'])->name('store');
            Route::get('{besoin}/editer', [BesoinSangController::class, 'edit'])->name('edit');
            Route::put('{besoin}', [BesoinSangController::class, 'update'])->name('update');
            Route::patch('{besoin}/actif', [BesoinSangController::class, 'toggleActif'])->name('toggle');
        });

        // 5.6 — Voie 2 « médecin référent » : mes patients suivis (permission dossier.referent).
        // Le compte doit AUSSI être relié à une fiche de l'annuaire par son gestionnaire : la
        // permission dit « ce rôle peut être référent », le lien dit « ce compte EST ce médecin ».
        Route::middleware('permission:dossier.referent')->group(function () {
            Route::get('mes-patients', [MesPatientsController::class, 'index'])->name('patients.index');
            Route::post('mes-patients/{membre}/ouvrir', [MesPatientsController::class, 'ouvrir'])
                ->name('patients.ouvrir')->middleware('throttle:20,1');
        });

        // Dossier ouvert — par un scan QR (4.5) OU par la voie référent (5.6). Aucun identifiant de
        // membre dans l'URL (anti-IDOR) : le dossier consulté est celui que porte la session, dont
        // l'ouverture est déjà journalisée. C'est la SESSION qui autorise, pas une permission de
        // plus : elle n'existe que si le patient a présenté son QR ou désigné son référent.
        Route::middleware('dossier.actif')->group(function () {
            Route::get('dossier', [DossierController::class, 'show'])->name('dossier.show');
            Route::post('dossier/fermer', [DossierController::class, 'fermer'])->name('dossier.fermer');
            Route::get('dossier/{section}', [DossierController::class, 'section'])->name('dossier.section');
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
