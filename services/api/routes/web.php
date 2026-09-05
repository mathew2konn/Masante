<?php

use App\Http\Controllers\Portail\ActivationController;
use App\Http\Controllers\Portail\AgentController;
use App\Http\Controllers\Portail\AlerteEpidemiqueController as PortailAlerteEpidemiqueController;
use App\Http\Controllers\Portail\AuthController;
use App\Http\Controllers\Portail\BesoinSangController;
use App\Http\Controllers\Portail\BrisDeGlaceController;
use App\Http\Controllers\Portail\CommandeClientController;
use App\Http\Controllers\Portail\CompteController;
use App\Http\Controllers\Portail\ConsultationController;
use App\Http\Controllers\Portail\DashboardController;
use App\Http\Controllers\Portail\DelivranceController;
use App\Http\Controllers\Portail\DisponibiliteController;
use App\Http\Controllers\Portail\DossierController;
use App\Http\Controllers\Portail\EtablissementController;
use App\Http\Controllers\Portail\GouvernanceModeleIaController;
use App\Http\Controllers\Portail\LaboratoireController;
use App\Http\Controllers\Portail\MedecinController as PortailMedecinController;
use App\Http\Controllers\Portail\MesPatientsController;
use App\Http\Controllers\Portail\ModerationController;
use App\Http\Controllers\Portail\PrixOfficineController;
use App\Http\Controllers\Portail\ProtocoleValidationController;
use App\Http\Controllers\Portail\ReferentielAnalyseController;
use App\Http\Controllers\Portail\ReferentielAssuranceController;
use App\Http\Controllers\Portail\ReferentielMaladieController;
use App\Http\Controllers\Portail\ReferentielMedicamentController;
use App\Http\Controllers\Portail\ReferentielNumeroUrgenceController;
use App\Http\Controllers\Portail\ReferentielSpecialiteController;
use App\Http\Controllers\Portail\ReferentielVaccinController;
use App\Http\Controllers\Portail\RendezVousController;
use App\Http\Controllers\Portail\ScanController;
use App\Http\Controllers\Portail\ServiceController;
use App\Http\Controllers\Portail\SignatureController;
use App\Http\Controllers\Portail\StatistiqueController;
use App\Http\Controllers\Portail\StockOfficineController;
use App\Http\Controllers\Portail\ValidationApprentissageController;
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

            // P6.4d — Images de l'établissement (CDC_11 §3.1 « formulaire dédié »). Les gardes ne
            // sont pas réécrites ici : elles vivent dans `ImagesEtablissement`, le service que
            // l'API mobile utilise déjà (motif de P4, source unique Blade + API).
            Route::post('etablissements/{etablissement}/images', [EtablissementController::class, 'ajouterImage'])->name('etablissements.images.store');
            Route::delete('etablissements/{etablissement}/images/{image}', [EtablissementController::class, 'supprimerImage'])->name('etablissements.images.destroy');

            // P6.7b — analyses realisees par un laboratoire (§7.2). Les gardes vivent dans
            // `CatalogueDuLaboratoire` : habilitation a deux chemins, et refus de tout
            // etablissement qui n'est pas un laboratoire.
            Route::post('etablissements/{etablissement}/analyses', [EtablissementController::class, 'ajouterAnalyse'])->name('etablissements.analyses.store');
            Route::delete('etablissements/{etablissement}/analyses/{ligne}', [EtablissementController::class, 'retirerAnalyse'])->name('etablissements.analyses.destroy');
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

        // 4.4 — Validation des rendez-vous, workflow à deux étapes (B1-a, CDC_11 §9.1). Le groupe
        // accepte L'UNE OU L'AUTRE permission (lecture commune) ; `previsalider`/`confirmer`
        // exigent chacune LA SIENNE, vérifiée dans le service, pas ici.
        Route::middleware('permission:rdv.prevalider|rdv.validate')->group(function () {
            Route::get('rendez-vous', [RendezVousController::class, 'index'])->name('rdv.index');
            Route::get('rendez-vous/{rdv}', [RendezVousController::class, 'show'])->name('rdv.show');
            Route::patch('rendez-vous/{rdv}/previsalider', [RendezVousController::class, 'previsalider'])->name('rdv.previsalider');
            Route::patch('rendez-vous/{rdv}/confirmer', [RendezVousController::class, 'confirmer'])->name('rdv.confirmer');
            Route::patch('rendez-vous/{rdv}/refuser', [RendezVousController::class, 'refuser'])->name('rdv.refuser');

            // B1-c (D8) — partage temporaire d'accès (30 min) vers le médecin de CE rendez-vous.
            // Dans le même groupe que ce qui précède : l'autorisation RÉELLE (médecin de CE rdv,
            // rdv confirmé, patient enregistré) vit dans {@see \App\Services\PartageRdvService},
            // pas ici — précédent `rdv.prevalider|rdv.validate` juste au-dessus.
            Route::post('rendez-vous/{rdv}/partage', [RendezVousController::class, 'ouvrirPartage'])->name('rdv.partage.ouvrir');
            Route::delete('rendez-vous/{rdv}/partage', [RendezVousController::class, 'fermerPartage'])->name('rdv.partage.fermer');

            // B1-d (D10) — clôture du RDV lui-même (`confirme → honore`), distincte de la clôture
            // de l'accès partagé juste au-dessus : un médecin peut refermer SON accès (D8) sans que
            // la consultation soit terminée (D13 laisse la porte ouverte à d'autres intervenants,
            // limite dite). L'autorisation réelle vit dans le service, pas ici.
            Route::patch('rendez-vous/{rdv}/terminer', [RendezVousController::class, 'terminer'])->name('rdv.terminer');
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

            // B1-b — Photo de profil (D5). Dépôt/retrait seulement : la diffusion est publique,
            // voir routes/api.php (source unique de lecture, comme les images d'établissement).
            Route::post('medecins/{medecin}/photo', [PortailMedecinController::class, 'uploaderPhoto'])
                ->name('medecins.photo.store');
            Route::delete('medecins/{medecin}/photo', [PortailMedecinController::class, 'retirerPhoto'])
                ->name('medecins.photo.destroy');

            // P6.5a — lieux d'exercice (§5.2, « établissements d'exercice »). Gardées par
            // `professionnel.habiliter` EN PLUS de `medecin.manage`, et vérifiées dans le
            // contrôleur : déclarer qu'un praticien exerce ailleurs est une affirmation NATIONALE
            // sur quelqu'un, pas la description de son propre annuaire. Un hôpital ne revendique
            // pas seul le médecin d'un autre.
            Route::post('medecins/{medecin}/exercices', [PortailMedecinController::class, 'ajouterExercice'])
                ->name('medecins.exercices.store');
            Route::delete('medecins/{medecin}/exercices/{exercice}', [PortailMedecinController::class, 'retirerExercice'])
                ->name('medecins.exercices.destroy');
        });

        // P6.5b — Signature électronique (CDC_09 §5.3, CDC_10 §4.5). « Ma signature » : le
        // praticien demande son certificat, choisit son secret, consulte son journal. La
        // permission n'ouvre rien à elle seule — les cinq contrôles du §5.4 restent devant.
        Route::middleware('permission:document.signer')->prefix('signature')->name('signature.')->group(function () {
            Route::get('/', [SignatureController::class, 'index'])->name('index');
            Route::post('certificat', [SignatureController::class, 'creer'])->name('creer');
            Route::post('certificat/revoquer', [SignatureController::class, 'revoquer'])->name('revoquer');
            Route::get('journal', [SignatureController::class, 'journal'])->name('journal');
            Route::get('historique', [SignatureController::class, 'historique'])->name('historique');
            // Déclarée en dernier : sans cela, `journal` et `historique` seraient captés par
            // `{type}/{id}` — le piège de l'ordre des routes déjà rencontré en P7-D0 avec
            // `dossier/fermer` avant `dossier/{section}`.
            Route::get('{type}/{id}', [SignatureController::class, 'verifier'])
                ->whereNumber('id')->name('verifier');
        });

        // 5.8 — Prix & stock d'une PHARMACIE partenaire (FN7/FN8, « modèle freemium » du CdC).
        // Le pharmacien fait autorité sur SA officine : sa déclaration prime sur les relevés des
        // patients. Réservé aux structures de type `pharmacie` (revérifié dans le contrôleur).
        Route::middleware('permission:medicament.manage')->group(function () {
            // B3-b — RENOMMÉ : ces routes déclarent un PRIX, pas un stock. Garder le mot
            // « stock » ici ferait chercher l'inventaire au mauvais endroit, maintenant qu'il
            // existe vraiment.
            // B3-b — L'INVENTAIRE, distinct du relevé de prix ci-dessous. Aucun identifiant
            // d'officine dans l'URL : on tient le stock de SA structure, celle que porte le compte.
            Route::get('officine/stock', [StockOfficineController::class, 'index'])->name('stock-officine.index');
            Route::post('officine/stock', [StockOfficineController::class, 'ajouter'])->name('stock-officine.ajouter');
            Route::post('officine/stock/{article}/mouvement', [StockOfficineController::class, 'mouvement'])->name('stock-officine.mouvement');
            Route::post('officine/stock/{article}/parametres', [StockOfficineController::class, 'parametrer'])->name('stock-officine.parametrer');

            Route::get('prix-officine', [PrixOfficineController::class, 'index'])->name('prix-officine.index');
            Route::post('prix-officine/{medicament}', [PrixOfficineController::class, 'declarer'])->name('prix-officine.declarer');
        });

        // B3-a — LE COMPTOIR : servir une ordonnance présentée par un patient (CDC_11 §7.1).
        //
        // PERMISSION DISTINCTE de `medicament.manage` juste au-dessus : celle-ci appartient AUSSI
        // au gestionnaire d'établissement (P6.6a), donc la réutiliser laisserait un gestionnaire de
        // CHU délivrer des ordonnances. Servir une prescription est un acte de dispensation.
        //
        // AUCUNE SESSION DE DOSSIER : le pharmacien atteint l'ordonnance par son JETON et ne voit
        // qu'elle. Ce n'est pas une garde qu'on vérifie, c'est une porte qui n'existe pas.
        Route::middleware('permission:ordonnance.delivrer')->group(function () {
            Route::get('delivrance', [DelivranceController::class, 'index'])->name('delivrance.index');
            Route::get('delivrance/ordonnance', [DelivranceController::class, 'montrer'])
                ->name('delivrance.montrer')->middleware('throttle:30,1');
            Route::post('delivrance', [DelivranceController::class, 'servir'])->name('delivrance.servir');
        });

        // B3-d — le pharmacien reçoit et traite les commandes de SON officine (CDC_11 §9.5).
        // PERMISSION DISTINCTE de `ordonnance.delivrer` : accepter une commande est un acte de
        // relation client, dispenser un acte pharmaceutique. La remise d'une commande PORTANT UNE
        // ORDONNANCE exige les DEUX (vérifié dans `ServiceTraitementCommande`, pas ici).
        Route::middleware('permission:commande.traiter')->group(function () {
            Route::get('commandes', [CommandeClientController::class, 'index'])->name('commandes.index');
            Route::get('commandes/{commande}', [CommandeClientController::class, 'show'])->name('commandes.show');
            Route::post('commandes/{commande}/accepter', [CommandeClientController::class, 'accepter'])->name('commandes.accepter');
            Route::post('commandes/{commande}/refuser', [CommandeClientController::class, 'refuser'])->name('commandes.refuser');
            Route::post('commandes/{commande}/preparer', [CommandeClientController::class, 'preparer'])->name('commandes.preparer');
            Route::post('commandes/{commande}/remettre', [CommandeClientController::class, 'remettre'])->name('commandes.remettre');
        });

        // B5-b — LE LABORATOIRE : lire une demande par jeton, enregistrer et suivre un
        // prélèvement (CDC_09 §7.4, CDC_04 §109).
        //
        // AUCUNE SESSION DE DOSSIER, même posture que `delivrance` juste au-dessus (L3) : le
        // laboratoire atteint la demande par son JETON, jamais par un accès au carnet.
        Route::middleware('permission:analyse.executer')->prefix('laboratoire')->name('laboratoire.')
            ->group(function () {
                Route::get('/', [LaboratoireController::class, 'index'])->name('index');
                Route::get('demande', [LaboratoireController::class, 'montrer'])
                    ->name('montrer')->middleware('throttle:30,1');
                Route::post('demande', [LaboratoireController::class, 'enregistrer'])->name('enregistrer');
                Route::get('travail', [LaboratoireController::class, 'travail'])->name('travail');
                Route::get('prelevements/{prelevement}', [LaboratoireController::class, 'prelevement'])
                    ->name('prelevement');
                Route::get('prelevements/{prelevement}/etiquette', [LaboratoireController::class, 'etiquette'])
                    ->name('etiquette');
                Route::post('prelevements/{prelevement}/expedier', [LaboratoireController::class, 'expedier'])
                    ->name('expedier');
                Route::post('prelevements/{prelevement}/recevoir', [LaboratoireController::class, 'recevoir'])
                    ->name('recevoir');
                Route::post('prelevements/{prelevement}/mettre-en-analyse', [LaboratoireController::class, 'mettreEnAnalyse'])
                    ->name('mettre-en-analyse');
            });

        // P6.6a — Référentiel NATIONAL des médicaments (CDC_09 §6.2).
        //
        // PERMISSION DISTINCTE de `medicament.manage` ci-dessus, et ce n'est pas de la prudence
        // décorative : `medicament.manage` appartient au gestionnaire d'établissement pour les prix
        // et les ruptures de SA pharmacie. La réutiliser ici laisserait une officine écrire les
        // indications et les interactions du catalogue national — un laboratoire fabricant serait
        // juge et partie sur son propre produit. `medicament.referentiel` n'est portée par AUCUN
        // rôle : elle s'accorde nominativement.
        // P6.7a — Catalogue NATIONAL des analyses (CDC_09 §7.3). Permission distincte de tout ce
        // qui precede : un laboratoire ne fixe pas les valeurs de reference nationales.
        Route::middleware('permission:analyse.referentiel')
            ->prefix('analyses')->name('analyses.')->group(function () {
                Route::get('/', [ReferentielAnalyseController::class, 'index'])->name('index');
                Route::get('{analyse}/editer', [ReferentielAnalyseController::class, 'edit'])->name('edit');
                Route::put('{analyse}', [ReferentielAnalyseController::class, 'update'])->name('update');
                Route::post('{analyse}/strates', [ReferentielAnalyseController::class, 'ajouterStrate'])
                    ->name('strates.ajouter');
                Route::delete('{analyse}/strates/{reference}', [ReferentielAnalyseController::class, 'retirerStrate'])
                    ->name('strates.retirer');
            });

        Route::middleware('permission:medicament.referentiel')
            ->prefix('medicaments')->name('medicaments.')->group(function () {
                Route::get('/', [ReferentielMedicamentController::class, 'index'])->name('index');
                Route::get('{medicament}/editer', [ReferentielMedicamentController::class, 'edit'])->name('edit');
                Route::put('{medicament}', [ReferentielMedicamentController::class, 'update'])->name('update');
                Route::post('{medicament}/interactions', [ReferentielMedicamentController::class, 'declarerInteraction'])
                    ->name('interactions.declarer');
                Route::delete('{medicament}/interactions/{interaction}', [ReferentielMedicamentController::class, 'retirerInteraction'])
                    ->name('interactions.retirer');
            });

        // P6.8a — Vocabulaire NATIONAL des spécialités (CDC_09 §8). Permission distincte de
        // `service.manage` : un établissement décrit SES services, il ne décide pas de la liste
        // nationale des spécialités — sinon « combien de services de cardiologie dans ce
        // district ? » n'aurait plus de réponse, « cardio » et « cardiologie » y coexistant.
        Route::middleware('permission:specialite.referentiel')
            ->prefix('specialites')->name('specialites.')->group(function () {
                Route::get('/', [ReferentielSpecialiteController::class, 'index'])->name('index');
                // Déclarée AVANT `{specialite}/editer` : sans cela, « nouveau » serait capté comme
                // un identifiant de terme (piège de P7-D0 et de P6.5b).
                Route::get('nouveau', [ReferentielSpecialiteController::class, 'create'])->name('create');
                Route::post('/', [ReferentielSpecialiteController::class, 'store'])->name('store');
                Route::get('{specialite}/editer', [ReferentielSpecialiteController::class, 'edit'])->name('edit');
                Route::put('{specialite}', [ReferentielSpecialiteController::class, 'update'])->name('update');
            });

        // P6.8b — Vaccins et calendrier vaccinal NATIONAL (CDC_09 §8). Permission distincte, pour
        // une raison qui lui est propre : un centre de vaccination serait juge et partie sur ce
        // qu'il administre, et le caractère obligatoire d'une dose engage l'État.
        Route::middleware('permission:vaccin.referentiel')
            ->prefix('vaccins')->name('vaccins.')->group(function () {
                Route::get('/', [ReferentielVaccinController::class, 'index'])->name('index');
                // Déclarée AVANT `{vaccin}/editer` : sans cela, « nouveau » serait capté comme un
                // identifiant (piège de P7-D0, puis de P6.5b, puis de P6.8a).
                Route::get('nouveau', [ReferentielVaccinController::class, 'create'])->name('create');
                Route::post('/', [ReferentielVaccinController::class, 'store'])->name('store');
                Route::get('{vaccin}/editer', [ReferentielVaccinController::class, 'edit'])->name('edit');
                Route::put('{vaccin}', [ReferentielVaccinController::class, 'update'])->name('update');
                Route::post('{vaccin}/echeances', [ReferentielVaccinController::class, 'enregistrerEcheance'])
                    ->name('echeances.store');
                Route::delete('{vaccin}/echeances/{echeance}', [ReferentielVaccinController::class, 'supprimerEcheance'])
                    ->name('echeances.destroy');
            });

        // P6.8c — Référentiel NATIONAL des maladies (CDC_09 §8). Permission distincte de
        // `sante_publique.manage`, qui sert à publier les ALERTES : les confondre ferait de
        // l'auteur d'une alerte celui qui décide de ce qu'est une maladie, et de ce que le pays
        // surveille.
        Route::middleware('permission:maladie.referentiel')
            ->prefix('maladies')->name('maladies.')->group(function () {
                Route::get('/', [ReferentielMaladieController::class, 'index'])->name('index');
                // Déclarée AVANT `{maladie}/editer` : sans cela, « nouveau » serait capté comme un
                // identifiant (piège de P7-D0, puis P6.5b, puis P6.8a, puis P6.8b).
                Route::get('nouveau', [ReferentielMaladieController::class, 'create'])->name('create');
                Route::post('/', [ReferentielMaladieController::class, 'store'])->name('store');
                Route::get('{maladie}/editer', [ReferentielMaladieController::class, 'edit'])->name('edit');
                Route::put('{maladie}', [ReferentielMaladieController::class, 'update'])->name('update');
                Route::post('{maladie}/libelles', [ReferentielMaladieController::class, 'enregistrerLibelle'])
                    ->name('libelles.store');
                Route::delete('{maladie}/libelles/{libelle}', [ReferentielMaladieController::class, 'supprimerLibelle'])
                    ->name('libelles.destroy');
                Route::post('{maladie}/surveillance', [ReferentielMaladieController::class, 'enregistrerSurveillance'])
                    ->name('surveillance.store');
                Route::delete('{maladie}/surveillance/{surveillance}', [ReferentielMaladieController::class, 'supprimerSurveillance'])
                    ->name('surveillance.destroy');
            });

        // P6.8d — Registre NATIONAL des organismes d'assurance agréés (CDC_09 §8). Permission
        // portée par AUCUN rôle : le rôle `assurance` désigne précisément les organismes que ce
        // registre recense — la lui donner ferait décider de la liste des agréés par un assureur.
        Route::middleware('permission:assurance.referentiel')
            ->prefix('assurances')->name('assurances.')->group(function () {
                Route::get('/', [ReferentielAssuranceController::class, 'index'])->name('index');
                // Déclarée AVANT `{organisme}/editer` : sans cela, « nouveau » serait capté comme un
                // identifiant (piège de P7-D0, puis P6.5b, P6.8a, P6.8b et P6.8c).
                Route::get('nouveau', [ReferentielAssuranceController::class, 'create'])->name('create');
                Route::post('/', [ReferentielAssuranceController::class, 'store'])->name('store');
                Route::get('{organisme}/editer', [ReferentielAssuranceController::class, 'edit'])->name('edit');
                Route::put('{organisme}', [ReferentielAssuranceController::class, 'update'])->name('update');
            });

        // P6.8e — Numéros d'urgence NATIONAUX (CDC_09 §8). Permission portée par AUCUN rôle : un
        // numéro d'urgence est attribué par un plan national de numérotation, et l'erreur ne se
        // rattrape pas — un code de spécialité faux produit une liste vide, un numéro d'urgence
        // faux produit un appel qui n'aboutit nulle part, composé devant un blessé.
        Route::middleware('permission:urgence.referentiel')
            ->prefix('numeros-urgence')->name('numeros-urgence.')->group(function () {
                Route::get('/', [ReferentielNumeroUrgenceController::class, 'index'])->name('index');
                // Déclarée AVANT `{numero}/editer` : sans cela, « nouveau » serait capté comme un
                // identifiant (piège de P7-D0, puis P6.5b, P6.8a, P6.8b, P6.8c et P6.8d).
                Route::get('nouveau', [ReferentielNumeroUrgenceController::class, 'create'])->name('create');
                Route::post('/', [ReferentielNumeroUrgenceController::class, 'store'])->name('store');
                Route::get('{numero}/editer', [ReferentielNumeroUrgenceController::class, 'edit'])->name('edit');
                Route::put('{numero}', [ReferentielNumeroUrgenceController::class, 'update'])->name('update');
            });

        // ═══ P10c-2-i (F4) — LA REVUE DU JEU D'APPRENTISSAGE, SANS DESIGN, COMME K1 DE P6.4D ═══
        //
        // `apprentissage.valider` n'est portée par AUCUN rôle métier (TREIZIÈME occurrence, motif
        // dans PortailRolesSeeder). La garde qui fait autorité est celle du service, vérifiée à
        // l'intérieur — ce middleware n'évite qu'un écran inutile à qui n'est pas habilité.
        Route::middleware('permission:apprentissage.valider')
            ->prefix('apprentissage')->name('apprentissage.')->group(function () {
                Route::get('/', [ValidationApprentissageController::class, 'index'])->name('index');
                Route::post('{jeu}/valider', [ValidationApprentissageController::class, 'valider'])->name('valider');
                Route::post('{jeu}/rejeter', [ValidationApprentissageController::class, 'rejeter'])->name('rejeter');
            });

        // ═══ P10c-3-i (F19) — GOUVERNANCE DES MODÈLES IA : EXPORT, ENTRAÎNEMENT, VALIDATION ═══
        //
        // `ia_triage.valider` n'est portée par AUCUN rôle métier (QUATORZIÈME occurrence, motif
        // dans PortailRolesSeeder). Même garde que ci-dessus : le middleware n'évite qu'un écran
        // inutile, l'habilitation qui fait autorité est celle des services.
        Route::middleware('permission:ia_triage.valider')
            ->prefix('modeles-ia')->name('modeles-ia.')->group(function () {
                Route::get('/', [GouvernanceModeleIaController::class, 'index'])->name('index');
                Route::post('exporter', [GouvernanceModeleIaController::class, 'exporter'])->name('exporter');
                Route::post('{export}/entrainer', [GouvernanceModeleIaController::class, 'entrainer'])->name('entrainer');
                Route::post('{version}/valider', [GouvernanceModeleIaController::class, 'valider'])->name('valider');

                // P10c-3-ii — mise en service (et rollback : c'est le même geste, §8).
                Route::post('{version}/activer', [GouvernanceModeleIaController::class, 'activer'])->name('activer');

                // Lot B — la confrontation après coup et la surveillance de dérive.
                //
                // `derive` est déclarée AVANT `{version}/comparaison` : sans cela, « derive »
                // serait pris pour un identifiant de version par la route paramétrée. Piège déjà
                // payé en P7-D0, P6.5b et P6.6b — il se rejoue à chaque route littérale ajoutée à
                // côté d'une route à paramètre.
                Route::post('derive', [GouvernanceModeleIaController::class, 'analyserDerive'])->name('derive');
                Route::get('{version}/comparaison', [GouvernanceModeleIaController::class, 'comparaison'])->name('comparaison');
            });

        // ═══ P10b-3-ii — L'ÉCRAN DES QUATRE VALIDATIONS DU §7 : LIRE ET SIGNER ═══
        //
        // Les cinq permissions §7/§10 ne sont portées par AUCUN rôle métier (P10b-1) : elles
        // s'accordent nominativement. La garde du groupe accepte l'une quelconque d'entre elles —
        // la garde FAISANT AUTORITÉ reste celle du service, qui exige la permission EXACTE du type
        // signé. Sans cela, un relecteur clinique pourrait apposer la signature technique.
        Route::middleware('permission:protocole.valider.clinique|protocole.valider.reglementaire'
            .'|protocole.valider.scientifique|protocole.valider.technique|protocole.publier')
            ->prefix('protocoles')->name('protocoles.')->group(function () {
                Route::get('/', [ProtocoleValidationController::class, 'index'])->name('index');
                Route::get('{protocole}/versions/{version}', [ProtocoleValidationController::class, 'show'])->name('show');
                Route::post('{protocole}/versions/{version}/valider', [ProtocoleValidationController::class, 'valider'])->name('valider');
                Route::post('{protocole}/versions/{version}/publier', [ProtocoleValidationController::class, 'publier'])->name('publier');
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

            // D0 — écriture du soignant. MÊME RÈGLE QUE LA LECTURE : aucun identifiant de membre
            // dans l'URL. On écrit dans le dossier que porte la session, jamais dans un dossier
            // qu'on aurait nommé. L'anti-IDOR reste par CONSTRUCTION.
            //
            // Trois gardes cumulatives, dont deux ici : `dossier.actif` (une fenêtre est ouverte)
            // et `permission:dossier.ecrire` (ce compte est habilité). La troisième — la VOIE
            // d'accès, qui refuse le bris de glace — est dans le service, parce qu'elle relève du
            // consentement du patient, pas du rôle de l'agent.
            //
            // P10c-2-i — le retour clinique sur une orientation (CDC_05 §5.5.4, §9.1). Déclarée
            // AVANT `dossier/{section}` : `dossier/triage/...` serait sinon capté par la route
            // paramétrée, qui prendrait « triage » pour une section à écrire. Même piège que
            // `dossier/fermer` ci-dessus, et que la route `signature/{type}/{id}` de P6.5b.
            //
            // La permission est vérifiée ICI **et** dans le service. Celle qui fait autorité est
            // celle du service : les permissions spatie sont sur le guard `web`, et le middleware
            // au mauvais guard laisse passer (piège de P4 sur `rdv.validate`).
            Route::post('dossier/triage/{triage}/retour', [DossierController::class, 'retourTriage'])
                ->middleware('permission:triage.retour')
                ->name('dossier.triage.retour');

            // B2-a — la consultation (CDC_11 §5.2). Déclarées AVANT `dossier/{section}`, comme
            // `dossier/fermer` et `dossier/triage/...` : « consultation » serait sinon pris pour
            // une section à écrire. Aucun identifiant dans l'URL : la consultation est celle de la
            // session, l'anti-IDOR reste structurel.
            //
            // AUCUNE PERMISSION NEUVE : mener une consultation, c'est consigner un acte dans le
            // carnet — ce que `dossier.ecrire` dit déjà. Deux clés pour une seule porte laisseraient
            // « qui peut poser un acte de soin ? » avoir deux réponses (refus de P11.1-D5).
            Route::middleware('permission:dossier.ecrire')->group(function () {
                Route::post('dossier/consultation', [ConsultationController::class, 'ouvrir'])
                    ->name('dossier.consultation.ouvrir');
                Route::post('dossier/consultation/observation', [ConsultationController::class, 'observer'])
                    ->name('dossier.consultation.observer');
                Route::post('dossier/consultation/cloturer', [ConsultationController::class, 'cloturer'])
                    ->name('dossier.consultation.cloturer');

                // B2-b — le diagnostic. La promotion vers les antécédents porte l'identifiant du
                // DIAGNOSTIC, pas celui du patient : le service vérifie qu'il appartient bien à la
                // consultation de la session, donc l'anti-IDOR reste porté par la session.
                Route::post('dossier/consultation/diagnostic', [ConsultationController::class, 'diagnostiquer'])
                    ->name('dossier.consultation.diagnostiquer');
                Route::post('dossier/consultation/diagnostic/{diagnostic}/antecedent', [ConsultationController::class, 'promouvoir'])
                    ->name('dossier.consultation.promouvoir');
            });

            // Déclarée AVANT `dossier/{section}` : sans cela, `enregistrer` serait capté.
            Route::post('dossier/{section}', [DossierController::class, 'enregistrer'])
                ->middleware('permission:dossier.ecrire')
                ->name('dossier.enregistrer');

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
