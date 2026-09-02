# ADR-052 — Partage temporaire d'accès (30 min) + présence temps réel (B1-c)

- **Statut** : **Accepté — B1-c VALIDÉ (G5, 2026-09-02).** G4 propriétaire OK.
- **Date** : 2026-09-02
- **Module** : B1-c — troisième sous-incrément du lot RDV (suite de B1-a/ADR-050, B1-b/ADR-051)
- **Corpus** : CDC_11 §9 (RDV) · D8/D9 du plan `docs/PLAN_G1_B1_Parcours_RDV.md`

---

## 1. Contexte

B1-b a enrichi la fiche RDV (photo, référent, tarif, triage). B1-c ouvre le dernier mécanisme du
workflow patient : un accès temporaire de 30 minutes vers le médecin d'UN rendez-vous précis, avec
une présence en direct que le patient peut suivre sur son téléphone pendant la consultation
(D8/D9). C'est la **première utilisation de Reverb dans le projet** — annoncée « approuvée, jamais
installée » depuis P11.0 (ADR-047).

## 2. Décisions

### D8 — Sixième voie d'accès : `rdv_partage`

`TypeAccesDossier` (PHP natif + `@masante/shared`) gagne une sixième valeur, aux côtés de
`qr_scan`/`referent`/`delegation`/`bris_de_glace`/`admin`. Miroir du mécanisme d'ouverture de
`ReferentService::ouvrir()` + `SessionDossierService` (même durée de 30 min, aucune nouvelle
constante), mais **jamais permanent** : la désignation ne vaut que pour LE rendez-vous qui l'a
rendue possible.

**Ce que « initiée par l'accueil » veut dire en pratique — précision trouvée en implémentant, pas
supposée au plan.** `SessionDossierService` porte son état dans la session PHP du compte qui
appelle `ouvrir()` : c'est SA session qui doit porter la fenêtre, parce que
`EcritureSoignantService::ecrire()` vérifie l'habilitation du compte CONNECTÉ. Ouvrir depuis le
compte de l'accueil créerait un accès que l'accueil ne peut pas exercer (il n'a pas
`dossier.ecrire`). `PartageRdvService::ouvrir()` est donc appelée par le compte du **médecin**, sur
SON rendez-vous — exactement le geste référent, juste borné dans le temps et dans la portée.
Le rôle de l'accueil reste réel, mais antérieur : c'est lui qui a mené le RDV à `confirme`
(workflow B1-a) et qui a **enregistré le patient à son arrivée**
(`RendezVous::estEnregistre()`, Module 4 / `ScanController::checkIn`, déjà existant) — ce check-in
EST la désignation du plan, sans rien construire de neuf pour l'obtenir.

**Quatre refus, chacun pour une raison distincte** (`PartageRdvService::ouvrir()`) :

1. permission `rdv.validate` — vérifiée dans le SERVICE, pas seulement par la route (précédent
   `RendezVousValidationService`, piège P4) ;
2. anti-IDOR — pas LE médecin de ce rendez-vous précis → **404**, pas 403 (même famille que
   `assertPerimetre()` : un compte hors périmètre ne doit pas apprendre qu'un autre RDV existe) ;
3. `rdv.statut !== 'confirme'` → 409 ;
4. `! $rdv->estEnregistre()` → 409, avec le message qui nomme le check-in.

`rendez_vous_id` est posé sur l'`AccesDossier` — un **identifiant, sans clé étrangère** (précédent
`triage_id`, ADR-042 D1 : le journal d'audit survit à un RDV supprimé), sur les DEUX lignes d'un
accès (ouverture et clôture, comme `etablissement`). `EcritureSoignantService::VOIES_ECRITURE`
gagne `rdv_partage` : le patient a consenti en prenant et en honorant ce rendez-vous précis.

### D9 — Présence temps réel via Reverb

Canal privé `rdv.{id}.presence`, autorisation stricte au **seul titulaire du membre concerné**
(pas les délégués en lecture du carnet familial partagé — décision, pas oubli : la présence en
direct d'un soignant est plus sensible qu'une lecture de dossier). Trois événements, tous
`ShouldBroadcastNow` (aucun worker de file active en développement — `QUEUE_CONNECTION=database`
sans `queue:work` réel dans le flux de ce projet ; un événement mis en file mais jamais traité ne
serait jamais diffusé, l'inverse de « temps réel ») :

- `PartageRdvOuvert` (médecin, horodatage) ;
- `PartageRdvEcriture` — **aucune section, aucun identifiant** : plus strict que la règle stricte
  de P7-D1 (« aucun contenu médical »), parce que ce canal est plus exposé qu'un push (une
  WebSocket ouverte toute la consultation) ;
- `PartageRdvFerme`.

**Le jugement « faut-il diffuser ? » vit dans `EcritureSoignantService::ecrire()`, pas dans le
contrôleur** — première implémentation avait mis l'appel dans `DossierController::enregistrer()`,
déplacée après relecture de la propre règle de la classe (« tout le jugement est ici, le
contrôleur traduit en HTTP »). `EcritureSoignantService` reçoit `SessionDossierService` en
dépendance pour lire `rdvDeclare()`.

**`DiffusionPresence` — la diffusion ne casse jamais l'appelant** (précédent P7-D1 sur le push,
transposé à un tiers SYNCHRONE cette fois : `ShouldBroadcastNow` appelle Reverb DANS la requête).
Un `try/catch` autour de chaque `broadcast()`, qui journalise en `warning` et avale l'exception —
si Reverb est injoignable, ni l'ouverture, ni la clôture, ni surtout l'écriture d'une ordonnance ne
doivent en pâtir. La présence est un confort, jamais une condition de l'acte de soin.

**Autorisation du canal extraite en classe testable** (`AutorisationCanalPresenceRdv::verifier()`) :
`routes/channels.php` ne fait que la brancher. Nécessaire parce que les seuls pilotes de diffusion
utilisables en test (`null`/`log`) **n'implémentent pas** la vérification d'authentification d'un
canal privé (`NullBroadcaster::auth()` est un no-op) — un test passant par la route HTTP réelle ne
prouverait rien sous ces pilotes.

## 3. Défaut réel amont trouvé (et contourné, jamais patché dans `vendor/`)

`composer require laravel/reverb` (v1.11.1, dernière résolue) casse **toute** commande Artisan
(`migrate`, `serve`, `test`, `package:discover`…) contre `laravel/framework` ^13.8 : le service
provider de Reverb appelle inconditionnellement `Reverb::registerDevCommands()` →
`DevCommands::artisan('reverb:start', …)`, une API très récente de Laravel qui refuse tout
enregistrement dont la pile d'appel ne traverse QUE du code `vendor/` (« DevCommands should be
registered in application code, not within vendor packages »). Le `class_exists(DevCommands::class)`
de Reverb protège contre les anciennes versions de Laravel qui n'ont pas cette API — pas contre
celles qui l'ont et la protègent.

**Correctif, en code applicatif uniquement** : `App\Providers\ReverbServiceProvider extends
\Laravel\Reverb\ReverbServiceProvider`, qui répète l'appel EXACTEMENT identique — mais depuis un
fichier sous `app/`. La garde ne juge que l'emplacement du fichier appelant, pas l'identité du
paquet : la même API réussit. `composer.json` retire le fournisseur auto-découvert
(`extra.laravel.dont-discover`), `bootstrap/providers.php` enregistre le remplaçant. Survit à tout
`composer install`/`update` sur n'importe quel poste, sans jamais toucher `vendor/`.

**SUITE TROUVÉE AU PREMIER DÉMARRAGE RÉEL DE `reverb:start`, PAS AU G3** : `laravel/reverb` déclare
DEUX fournisseurs dans son `composer.json` (`extra.laravel.providers`) —
`Laravel\Reverb\ReverbServiceProvider` (celui qui plantait) ET
`Laravel\Reverb\ApplicationManagerServiceProvider` (lie `Contracts\ApplicationProvider`, sans
lequel `reverb:start` échoue avec `BindingResolutionException`). Retirer le PAQUET de la
découverte automatique (`dont-discover` vise le paquet entier, pas un fournisseur précis)
supprime les deux d'un coup ; n'en réenregistrer qu'un seul manuellement laisse le serveur
inutilisable. Le second, lui, n'appelle jamais `DevCommands` — réenregistré tel quel dans
`bootstrap/providers.php`, rien à contourner pour lui. Un G3 qui ne fait jamais tourner
`reverb:start` ne pouvait pas le révéler ; seul le premier démarrage réel l'a fait.

## 4. Découverte en cours de route — l'infrastructure de test elle-même

Le run complet de la suite (désormais > 1500 tests dans un seul processus PHP CLI non isolé) a
échoué deux fois de suite avec un **memory_limit de 128 M épuisé à un point différent à chaque
tentative** (`ProtocoleSelecteurTest` puis `QuestionnaireAdaptatifTest`, aucun lien avec ce
qu'elles testent — un plafond mémoire qui déborde au hasard selon l'accumulation des fixtures
précédentes, pas un défaut de ces tests). `php -d memory_limit=…` sur la commande externe n'a
**aucun effet** : `artisan test` ré-exécute PHPUnit dans son propre processus, qui n'hérite pas du
flag. Seule une directive `<ini name="memory_limit" value="512M"/>` DANS `phpunit.xml` atteint le
processus qui compte réellement — corrigé, avec la raison écrite dans le fichier lui-même.

## 5. Mobile — client Pusher-protocole écrit à la main, zéro dépendance neuve

Reverb parle Pusher sur une WebSocket brute ; React Native expose déjà `WebSocket` globalement.
`pusher-js` + `laravel-echo` n'apportent rien pour n'écouter qu'UN canal privé et TROIS
événements — et `pusher-js` n'est pas conçue pour React Native sans polyfill. Ajouter deux
dépendances pour ce périmètre aurait été un confort, pas une nécessité (§2.6 ; précédent P11.2 :
clé + HMAC plutôt qu'OAuth2). `SuiviPresenceRdv` (`src/services/presenceRdv.ts`) fait la
poignée de main Pusher (`pusher:connection_established` → `POST /v1/broadcasting/auth` → 
`pusher:subscribe`) et retransmet les trois événements à l'écran `suivi-rdv/[id].tsx`.

**Portée assumée, dite plutôt que déguisée** : aucune reconnexion automatique — un décrochage
réseau referme le suivi, le patient rouvre l'écran. La fiche de parcours (P7-D2) reste la source
de vérité après coup ; ce canal n'est qu'un confort pendant la consultation. Reverb écoute un
**port différent** de l'API HTTP : un test réel sur téléphone via Ngrok exigera un **second
tunnel** — dit en limite, pas masqué.

**DÉFAUT RÉEL TROUVÉ AU G2 LIVE, DANS LE CODE RÉELLEMENT LIVRÉ — PAS DANS UN SCRIPT DE TEST** : le
protocole Pusher exige qu'un client réponde à chaque `pusher:ping` par un `pusher:pong` ; sans
cette réponse, Reverb referme la connexion au bout de `activity_timeout` (30 s par défaut) avec le
code `4201` (« Pong reply not received in time »). Ni `SuiviPresenceRdv` ni le client de test G2
ne répondaient au ping — trouvé en observant un premier client de test se faire fermer par le
serveur ~30 s après un abonnement pourtant réussi, PENDANT le G2 live, avant d'avoir pu observer un
seul événement réel. Corrigé dans `presenceRdv.ts` (le fichier réellement livré, pas seulement le
script de test) : `pusher:ping` reçu → `pusher:pong` renvoyé immédiatement, sans passer par
`onEvenement` (ce n'est pas un événement de présence, c'est un accusé de vie de la connexion).
Sans ce correctif, un patient qui laisse l'écran de suivi ouvert plus de 30 secondes perdrait la
connexion en silence — exactement le genre de défaut qu'un G3 sans serveur réel ne peut pas voir.

## 6. Preuve

**G3** : suite Laravel complète **1529/1529, 17 437 assertions, 0 échec** (y compris le flake
Tesseract connu de `PrixMedicamentTest`, vert cette fois) ; Pint propre sur tout le code neuf,
baseline `HEAD` inchangée sur les fichiers pré-existants touchés ; `tsc --noEmit` propre pour
`@masante/shared` et `@masante/mobile` ; `expo-doctor` 18/18 ; garde anti-divergence PHP↔TS neuve
pour `TypeAccesDossier` (motif `RendezVousStatutSourceUniqueTest`). **Mutation : 8/8 gardes
tuées**, dont la stratification en deux vecteurs du contrôle voie/session — un premier vecteur ne
prouvait que l'absence de session active, pas la garde `voie === RDV_PARTAGE` elle-même (précédent
« le vecteur prouve autre chose ») ; corrigé en ouvrant une VRAIE session `rdv_partage` puis en
mentant sur la voie passée à `ecrire()`. Chaque mutation confirmée appliquée (le test échoue) puis
fichier restauré et vérifié **octet pour octet** (hash SHA-256) contre sa copie pré-mutation.

**G2 live** (base MySQL dev réelle sauvegardée par `mysqldump --routines --triggers`, contre un
`php artisan reverb:start` et un `php artisan serve` réels, un abonné WebSocket réel écrit en Node
natif — aucune bibliothèque Pusher, exactement le protocole que parle `SuiviPresenceRdv`) :

- **Conséquence de déploiement découverte au premier essai** : les trois migrations de B1
  (a/b/c) étaient encore `Pending` sur cette base — les G2 live précédents (B1-a, B1-b) restaurent
  la base après chacun de leurs propres tests, ce qui annule aussi leurs propres migrations et
  leur reseeding RBAC. `php artisan migrate` (3 migrations) + `PortailRolesSeeder` (bascule
  `personnel_accueil`→`rdv.prevalider`) ont dû être rejoués avant de pouvoir tester quoi que ce
  soit — vérifié directement (`personnel_accueil` portait encore l'ancienne permission
  `rdv.validate` avant le reseed).
- **Ouverture refusée AVANT confirmation** → **409**, message exact « Ce rendez-vous doit être
  confirmé avant d'ouvrir un accès partagé. »
- **Ouverture refusée APRÈS confirmation mais AVANT le check-in** → **409**, message exact « Le
  patient doit d'abord être enregistré à l'accueil (scan du reçu de rendez-vous). »
- **Un médecin habilité mais qui n'est pas celui de ce RDV** → **404** (anti-énumération).
- **Le bon médecin ouvre** → 302, `AccesDossier` réel créé et vérifié en base
  (`type_acces=rdv_partage`, `agent_id`=le médecin, `rendez_vous_id`=le bon RDV,
  `etablissement` copié).
- **Le patient (compte titulaire réel, jeton Sanctum réel) reçoit l'événement `partage.ouvert` EN
  DIRECT sur sa WebSocket**, avec le nom du médecin et un horodatage, quelques secondes après
  l'action du médecin — aucun contenu médical.
- **Une écriture réelle au carnet** (`POST /portail/dossier/antecedents`) déclenche
  `partage.ecriture` **reçu en direct**, dont la charge ne contient **ni section, ni identifiant,
  ni contenu** — seul un horodatage.
- **Un AUTRE compte patient (jeton Sanctum réel, non titulaire du membre) tente de s'abonner au
  même canal** → `POST /v1/broadcasting/auth` répond **403** (`AccessDeniedHttpException`, via le
  vrai `PusherBroadcaster::verifyUserCanAccessChannel()` — pas un test de la seule classe
  `AutorisationCanalPresenceRdv` en isolation, le mécanisme HTTP complet).
- **Clôture** (« Terminer ») → 302, événement `partage.ferme` **reçu en direct**.
- **Résilience — Reverb ARRÊTÉ puis un nouvel accès ouvert ET une nouvelle écriture tentée** :
  les DEUX actions réussissent quand même (302 sur les deux), et `storage/logs/laravel.log`
  contient bien les deux lignes `warning` « Diffusion de présence échouée (accès/écriture non
  affectés) » — la garantie de `DiffusionPresence` n'est pas restée théorique, elle a été
  déclenchée et observée pour de vrai.
- Base restaurée : migrations B1 revenues à `Pending`, **zéro** compte/structure de test résiduel
  (`g2b1c-*`), vérifié par requête directe après restauration.

## 7. Ce qui n'est pas dans ce lot

Facture/vérification/pont GeniusPay/notification de clôture → B1-d (dernier sous-incrément de B1).

Voir `docs/PLAN_G1_B1_Parcours_RDV.md` ; guide `GUIDE_TEST_APPLICATIONS_METIER.md` **partie 6**.
