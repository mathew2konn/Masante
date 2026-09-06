# Handoff — MaSanté (IVOIRSANTÉ)

> **Point de reprise.** Écrit pour quelqu'un qui reprendrait le projet demain sans rien en savoir.
> Dernière mise à jour : **2026-09-06**. Branche : **`feat/masante-p0-socle`**. Dernier commit
> poussé au moment d'écrire ces lignes : **`ceb3be1`** (B5-a + B5-b) — **B5-c vient d'être terminé
> et validé dans cette session, pas encore commité/poussé** : voir §4 pour l'état exact du dépôt.
>
> **Dernier incrément clos** : **B5-c** (résultats, automates, validation biologique, publication —
> troisième et dernier morceau du circuit du laboratoire) — **✅ VALIDÉ (G5, 2026-09-06)** — G3/G2
> menés le 2026-09-05, G4
> propriétaire OK. **LE LOT B5 (LE CIRCUIT DU LABORATOIRE) EST COMPLET (a, b, c) — L'ÉTAPE 9 DE
> L'ORDRE CDC_11 §12 EST ACHEVÉE.** Le brouillon d'un résultat vit **hors du carnet**, sur le
> prélèvement lui-même (chiffré), tant qu'un biologiste ne l'a pas validé ; la publication
> (`source='structure'`) referme **pour de bon** K5/K11 ouvert par B5-a ; **un automate ne valide
> jamais** — prouvé en réel contre un vrai envoi HMAC signé ; les automates se déclarent **par
> commande**, jamais par écran. **Décision la plus délicate du lot** : la publication copie le
> résultat **verbatim** depuis le brouillon figé à la saisie, plutôt que de repasser par le point
> d'accroche partagé des trois autres chemins d'écriture du carnet — qui aurait re-résolu le
> résultat contre un catalogue susceptible d'avoir changé entre-temps, sans qu'aucun biologiste ne
> revoie rien. **Défaut de migration réel, trouvé par la suite complète et non par les 42 vecteurs
> dédiés** : étendre une liste de valeurs internes du journal du laboratoire faisait, **sous SQLite
> seulement**, disparaître silencieusement sa protection contre la modification (vérifié que MySQL
> n'est jamais concerné). Détail : `plan.md` PLAN 4 §14, `docs/adr/ADR-059…`, guide partie 19.
> Voir §7.
>
> **Aucun incrément en cours.** Le lot B5 étant complet, la prochaine étape de CDC_11 §12 (au-delà
> de la radiologie, hors périmètre de B5) reste à identifier avec le propriétaire — voir §7.
>
> Ce fichier dit **où l'on en est**. Le **journal exhaustif** de chaque module vit dans `CLAUDE.md`.
> Les **plans de conception** vivent dans `plan.md`. Les **décisions d'architecture** vivent dans
> `docs/adr/`.

---

## 1. Ce qu'est ce projet

Plateforme numérique nationale de santé pour la **Côte d'Ivoire** (conçue multi-pays). Le corpus qui
fait autorité est constitué des **14 cahiers des charges** de `CDC.md/` (CDC_00 → CDC_13). Ordre de
résolution des conflits : **CDC_10 Sécurité > CDC_08 Protocoles > CDC_09 Données nationales > ADR
validé par le propriétaire**. On n'invente jamais : toute ambiguïté devient un ADR.

### Architecture (monorepo, ADR-003)

```text
apps/mobile/              Expo SDK 54 — React Native, TS strict, NativeWind, Expo Router
apps/web/                 Next.js 15 App Router — portail professionnel moderne
packages/shared/          @masante/shared — SOURCE UNIQUE (tokens, enums, Zod, i18n)
services/api/             Laravel 13 / PHP 8.3 / MySQL — le cœur
services/payment/         Java Spring Boot — paiement (ADR-013), Postgres + Redis, Docker
services/fraud-detection/ Python FastAPI — fraude IA (ADR-017), détection seule
services/triage-service/  Python FastAPI — triage IA (ADR-043/045/046), mode observation
CDC.md/                   cahiers des charges (lecture seule)
```

Gestionnaire **pnpm 9**, `node-linker=hoisted`. MySQL conservé en MVP. Auth **Sanctum + OTP**.

### Les règles qui ne se négocient pas

| | |
|---|---|
| **Frontière** | **Aucune logique métier dans le front.** Scores, tarifs, plafonds, éligibilité, transitions d'état : **backend uniquement**. Test de fin de module : « quelles règles métier ce module calcule-t-il ? » → réponse obligatoire **« aucune »** |
| **Source unique** | Tokens, schémas Zod, enums, i18n : définis **une fois** dans `@masante/shared`. Aucune redéfinition locale, aucune couleur en dur |
| **Interdits absolus** (CDC_00 §4) | Règle médicale en dur · triage présenté comme diagnostic · IA décidant seule · secret dans le code · fichier médical en base · accès dossier sans lien de prise en charge (hors bris de glace audité) · sortie IA sans explication + confiance + limites. **SAMU 185**, jamais le 15 |
| **Dépendances** | **Aucune dépendance sans accord écrit du propriétaire** (§2.6). Mobile : `npx expo install` uniquement |

---

## 2. La méthode de travail (gates bloquantes, CDC_01 §2.4)

**G0** Audit — lire réellement le code, ne rien supposer →
**G1** Plan validé **par écrit** par le propriétaire →
**G2** Backend prouvé **en direct** contre la vraie base MySQL →
**G3** Qualité : tests, Pint, typecheck, campagne de mutation →
**G4** **Test réel par le propriétaire** →
**G5** « validé » **écrit par le propriétaire**.

> **Le G5 n'est JAMAIS auto-déclaré.** Il attend le mot du propriétaire. Aucun module suivant avant
> le G5 du précédent. Corrections chirurgicales uniquement.

### Les trois fichiers de suivi (règle propriétaire du 2026-09-03)

| Fichier | Rôle | Quand |
|---|---|---|
| **`CLAUDE.md`** | Toute décision d'architecture et de conception (nom de classe, raison d'un choix, contrainte) | **AVANT d'écrire la moindre ligne de code** |
| **`plan.md`** | Le plan de travail et d'exécution détaillé, un bloc par réflexion sous `# PLAN n : …` | Après la décision, avant l'exécution |
| **`handoff.md`** | Ce fichier : fait, pourquoi, état exact, prochaines étapes | Après l'exécution |

**Ordre contraignant** : décision → `CLAUDE.md` → `plan.md` → exécution → `handoff.md`.

### Habitudes de test qui ont trouvé de vrais défauts

- **Campagne de mutation manuelle** : sauvegarder → muter une garde → **asserter la mutation
  appliquée** → asserter le **rouge** → restaurer → **vérifier par `diff`**. Toujours **un témoin
  volontairement vert** : *un harnais qui ne prévoit que des tueuses ne se teste jamais lui-même.*
- **Vérifier un refus PAR SON MOTIF**, jamais par son seul code HTTP — plusieurs gardes partagent
  le même 409.
- **Le G2 live trouve ce que la relecture ne voit pas** — il l'a prouvé une dizaine de fois.
- **Baseline Pint établie AVANT** de formater : plusieurs fichiers du dépôt échouent **déjà**
  (style d'alignement délibéré), il ne faut pas les reformater.

### Guides de test

Un guide par module, `GUIDE_TEST_<SUJET>.md` à la racine, indexé par `GUIDE_TEST_INDEX.md`. **Un
module sans guide ne peut pas être déclaré validé.** Un domaine à incréments ajoute une **partie**
au guide existant.

---

## 3. Ce qui a été fait, et pourquoi

Le détail exhaustif est dans `CLAUDE.md`. Vue d'ensemble :

| Domaine | État |
|---|---|
| **P0 → P4** — socle, identité (RBAC + MFA), dossier médical hors ligne chiffré, annuaire + carte, rendez-vous | ✅ validés |
| **P5** — Paiement, **microservice Java** (ADR-013) : passerelle OCP, facturation, wallet en partie double, fraude, cartes + 3DS, mandats, reversements, rapprochement, **intégration GeniusPay réelle** | ✅ 21 incréments validés |
| **Fraude IA** — microservice Python (ADR-017), **détection seule**, jamais de gel | ✅ validé (+ extraction réelle, routage, écran) |
| **P6** — Données nationales CDC_09 : NIS, socle référentiel gouverné, établissements, professionnels + PKI, médicaments, laboratoires, transverses | ✅ **étapes 1 à 8 complètes** |
| **P7** — Carnet familial partagé (la fusion MPI a été **abandonnée** au profit de deux actes humains) | ✅ complet, 6 incréments |
| **P10** — Triage : orientation gouvernée, **protocoles médicaux CDC_08**, microservice IA en **mode observation** | ✅ complet dans son découpage annoncé |
| **P11** — Applications métier CDC_11 : portes du portail, onboarding méthode 2, API d'ingestion partenaire | ✅ validés |
| **B1** — Refonte du parcours Rendez-vous | ✅ complet (a, b, c, d) |
| **B2** — Consultation, diagnostic, prescription électronique | ✅ complet (a, b, c) |
| **B3** — **Pharmacie** | ✅ **complet (a, b, c, d)** — d validé G5 le 2026-09-05 |
| **B4** — Paiement en ligne réel (GeniusPay) : canal, commission, rendez-vous | ✅ complet (a, b) |
| **B5** — **Le circuit du laboratoire** (étape 9 de CDC_11 §12) | ✅ **COMPLET (a, b, c)** |

### Deux principes qui reviennent partout, et qu'il faut comprendre pour reprendre

1. **On ne devine jamais.** Le serveur ne rapproche pas un texte libre d'une entrée de référentiel
   (ce serait un diagnostic posé par une machine) ; il ne déduit pas une provenance ; il ne
   complète pas une réponse incomplète. **Une absence se dit** — et elle se **compte** à l'écran.
2. **Une valeur recalculable n'est jamais stockée.** Le solde d'un wallet, le stock d'une officine,
   le statut d'une vaccination : des **sommes** et des **calculs**, jamais des colonnes — *une
   valeur stockée finit par diverger de ce qu'elle résume*.

---

## 4. État exact du système, aujourd'hui

### Dépôt

- Branche **`feat/masante-p0-socle`**. **B5-a et B5-b (VALIDÉS G5, 2026-09-05) sont commités et
  poussés** (commit `ceb3be1`). **B5-c (VALIDÉ G5, 2026-09-06 — G3/G2 menés le 2026-09-05) vient
  d'être commité et poussé sur demande explicite du propriétaire** (« G4 validé, fais les commit et
  le push »).
  Fichiers neufs de B5-a : migration `demandes_analyses`, modèles
  `DemandeAnalyse`/`DemandeAnalyseLigne`, `ProjecteurLignesDemande`, `DemandeAnalyseController`,
  `DocumentPrescriptionBiologique`, `StatutDemandeAnalyse`, `DemandeAnalyseTest`. Fichiers
  modifiés par B5-a : `RegistreSectionsCarnet`, `RegistreDocumentsSignables`, `ServiceLienAnalyse`
  (généralisée), `AntecedentController`/`OrdonnanceController`/`ResultatAnalyseController` (retrait
  de `source`), `MembreFamille`, `Consultation`, `Portail\DossierController`, les deux vues Blade du
  dossier, `CarnetSectionTest`, `SignatureElectroniqueTest`. Fichiers de B5-b : migration
  `prelevements_laboratoire` (`prelevements` + `journal_laboratoire`, 6 déclencheurs),
  `StatutPrelevement`, `ReglesCode128`, `GenerateurIdentifiantPrelevement`, modèles
  `Prelevement`/`JournalLaboratoire`, `ServiceCircuitPrelevement`, `Portail\LaboratoireController`,
  4 vues Blade (`portail/laboratoire/*`), `CircuitPrelevementTest` (34 vecteurs), plus
  `StructureSanitaire`/`DemandeAnalyse`/`ProjecteurLignesDemande`/`PortailRolesSeeder`/
  `packages/shared/src/enums/permissions.ts`/`routes/web.php` modifiés.
  **Fichiers neufs de B5-c, non commités** : migration `resultats_laboratoire`
  (`validations_biologiques` + `automates`, colonnes sur `prelevements`/`resultats_analyses`,
  extension de `journal_laboratoire.action`), `VerdictValidationBiologique`, modèles
  `ValidationBiologique`/`Automate`, `ServiceValidationBiologique`,
  `IngestionResultatsLaboratoire`, `ResultatsLaboratoireController`, `DeclarerAutomateCommand`,
  `ResultatBiologiqueTest` (42 vecteurs). **Fichiers modifiés par B5-c, non commités** :
  `ClientApi` (+domaine `resultats_laboratoire`), `Prelevement` (+cast, +relation, +`aUnBrouillon()`),
  `ResultatAnalyse` (+`origine`, +relation `prelevement()`), `ServiceCircuitPrelevement`
  (+`assertOuverte()`, `travailPour()` étendue à `valide`), `LaboratoireController` (+4 actions),
  `ServiceNotification` (+`resultatAnalysePublie()`), `TypeNotification` (+cas),
  `PortailRolesSeeder` (+`analyse.valider`), `routes/web.php`/`routes/api.php`,
  `packages/shared/src/enums/{index,permissions}.ts`,
  `apps/mobile/src/types/notification.ts`, une vue Blade
  (`portail/laboratoire/prelevement.blade.php`). Détail complet : `plan.md` PLAN 4 §11-§14.
- Suite Laravel au dernier passage complet (avec B5-a, B5-b et B5-c) : **1868 tests / 18 357
  assertions / 0 échec**, confirmée deux fois indépendamment ; mutation manuelle 8 tueuses + 1
  témoin vert pour B5-c seul (4 + 1 pour B5-b), toutes conformes.
- **Aucun secret suivi par git** (vérifié : 0 correspondance sur les `.env`).

### Base de données de développement — 0 migration `Pending` côté B5-a/b, B5-c revenue à `Pending`

Toutes les migrations de B5-a sont **`Ran`**. **Les migrations de B5-b
(`2026_09_05_000003_prelevements_laboratoire`) ET de B5-c
(`2026_09_05_000004_resultats_laboratoire`) sont revenues à `Pending`** : chaque G2 live a
sauvegardé la base **avant** de jouer sa propre migration (`mysqldump --routines --triggers`,
stderr redirigé séparément), puis restauré ce dump après le test — le dump de B5-c, pris avant SES
DEUX migrations, ne connaissait ni `prelevements`/`journal_laboratoire` (B5-b) ni
`validations_biologiques`/`automates` (B5-c) : un `DROP TABLE` explicite des **quatre** tables a
été nécessaire pour revenir à un état réellement pré-migration (145 tables, comme avant). **Avant
tout prochain travail sur le portail laboratoire (G4 du propriétaire sur B5-c, ou reprise d'un
futur module qui en dépendrait), il faut rejouer les DEUX migrations** :

```bash
cd services/api
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan migrate --force
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan db:seed --class=PortailRolesSeeder --force
```

Aucune donnée de scénario B5-a, B5-b NI B5-c ne subsiste (comptes médecin/laborantin/biologiste de
test, patients, laboratoires de test, demandes/prélèvements/résultats créés pour les G2 live —
tous disparus avec la restauration). **Ce qui persiste, délibérément** : le catalogue national des
analyses (`CatalogueAnalysesSeeder` + `masante:analyses:backfill`, exécuté AVANT chaque
sauvegarde — 8 analyses codées) — c'est une donnée de déploiement, pas une donnée de test. **Pour
le G4 du propriétaire sur B5-a** : compte médecin réel existant, guide partie 17. **Pour B5-b** :
compte réel rattaché à `Laboratoire BIOSMOSE` (structure `laboratoire`) avec le rôle `laborantin`,
guide partie 18. **Pour B5-c** : le même compte laborantin, plus un second compte portant le rôle
`laborantin` ET la permission nominative `analyse.valider` (aucun rôle « biologiste » n'existe),
guide partie 19.

Si un futur G2 restaure de nouveau une base ancienne et fait réapparaître des migrations
`Pending`, la commande reste :

```bash
cd services/api
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan migrate
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan db:seed --class=PortailRolesSeeder
```

Le seeder de rôles est **nécessaire** : une restauration efface aussi les permissions posées par
l'incrément.

### Environnement — pièges de poste vérifiés

| | |
|---|---|
| **PATH Bash cassé** | `export PATH="/usr/bin:/bin:$PATH"` en tête de chaque commande ; git est dans `/c/Program Files/Git/cmd` |
| **Python** | `/c/Users/HP/AppData/Local/Programs/Python/Python314` (3.14.7) |
| **PHP** | `C:/wamp64/bin/php/php8.3.28/php.exe`, toujours préfixé `XDEBUG_MODE=off` |
| **MySQL** | WAMP 8.4.7, base `ivoirsante` |
| **`Write` écrit en CRLF** | `Edit` préserve les fins de ligne — plusieurs fichiers ont échoué Pint pour cette seule raison |
| **PHPUnit** | `memory_limit` doit être dans `phpunit.xml` ; un flag `php -d` n'atteint pas le bon processus |
| **Mesures de durée** | ne jamais chronométrer pendant qu'une autre exécution tourne |

---

## 5. Où en est le lot B3 (Pharmacie), précisément

Le G0 du lot avait relevé **neuf manques**. État :

| Sous-lot | Contenu | État |
|---|---|---|
| **B3-a** | Lignes d'ordonnance, jeton de partage, **délivrance** (§7.1, §7.2 partiel) | ✅ **G5, 2026-09-03** |
| **B3-b** | Fiche officine, **stock réel**, mouvements, seuils (§7.3, §7.5) + renommage | ✅ **G5, 2026-09-03** |
| **B3-c** | **Code-barres + traçabilité nationale (§7.6)** | ✅ **G5, 2026-09-04** |
| **B3-d** | **Panier + commande** (§9.5, §10.5) — *le renouvellement en SORT, voir ci-dessous* | ✅ **VALIDÉ (G5, 2026-09-05)** |

### Ce que B3-a, B3-b et B3-c ont acquis, et qu'il ne faut pas défaire

- Le pharmacien **ne voit que l'ordonnance**, jamais le dossier : le jeton de partage porté par
  l'ordonnance remplace une session de dossier. **Le vecteur central du lot est une absence** :
  servir ne crée **aucune ligne de journal d'accès**, parce qu'aucun accès n'a lieu.
- Le **stock est une somme**, jamais une colonne ; le **signe est déduit du type** de mouvement ;
  l'inventaire **alimente** le relevé public sans le doubler.
- Une **délivrance passe même si l'officine ne tient pas l'article** : refuser priverait un patient
  de son traitement pour une raison qui ne le concerne pas.
- Le §7.2 est atteint **aux trois quarts** : authenticité ✅, disponibilité ✅, interactions ✅ (en
  consultation explicite), **contre-indications ❌ impossibles** — les allergies sont du texte libre
  chiffré, et une vérification partielle dirait « aucune contre-indication » à un patient qui en a
  une : **plus dangereux que pas de vérification du tout**.
- **B3-c referme §7.6** (falsifiés, consommation, statistiques) : chaque médicament peut porter un
  code-barres (vide tant que personne ne le saisit, et l'absence est comptée) ; chaque délivrance
  alimente un **registre national dénominalisé** (`traces_dispensation`) qui **survit** à la
  suppression de l'ordonnance dans le carnet du patient — sans jamais porter de donnée nominative.
  Un code-barres reconnu **ne prouve jamais l'authenticité**, seulement que le code est connu.
- **Défaut réel corrigé pendant l'écriture de B3-c, à retenir pour la suite** : ne jamais mettre une
  clé étrangère `nullOnDelete` sur une colonne d'une table **append-only** — la nullification
  déclenchée par le moteur à la suppression du parent est elle-même un `UPDATE`, qu'un déclencheur
  append-only bloquant tout refuse, empêchant la suppression du parent. Toujours un identifiant
  **sans contrainte** dans ce cas (ADR-042 D1). Détail : ADR-055 §10.10.
- **B3-d referme §9.5/§10.5** côté patient : le panier vit sur le téléphone, jamais au serveur ; la
  garde `ordonnance_requise` (§10.5, jamais lue jusqu'ici) vit au serveur et porte sur le PRODUIT
  désigné, pas seulement sur la présence d'une ordonnance quelconque ; le règlement en ligne
  emprunte le MÊME canal réel que le rendez-vous (B4), avec son propre préfixe de corrélation
  `commande:` — **jamais** `factures_patient`, qui est une facturation DE SOINS. **La commission
  suit automatiquement, sans nouvel appel au service de commission.** Détail : ADR-055 §11.

---

## 6. Prochaines étapes

### B4-a — le canal GeniusPay : ✅ VALIDÉ (G5, 2026-09-04)

> « on va brancher les paiements sur GeniusPay et aussi le brancher au rendez-vous »
> « je valide le G1 de B4-a » · « G4 validé » · « c'est bon pour le G5 »

**B3-d est REPRIS** (voir §7 ci-dessous) : son règlement en ligne se branche sur ce canal, maintenant réel et validé G5.

**Ce qui a été FAIT, dans l'ordre du `plan.md` PLAN 3 §6** : Java
(`etablissementRef`/`factureId`/`fraisPasserelle`/`canal`/`paiementId` portés par l'événement,
`GET /marchands/{ref}`, commentaires obsolètes corrigés) · Laravel (`SigneurPrincipalSortant`,
`ClientPaiementGeniusPay`, `ResolveurEtablissementRef`, `PaiementNotificationController` qui appelle
enfin `CommissionService` — le TODO du lot 6 a disparu) · **G3** (tests + campagne de mutation, tous
verts, détail `plan.md` PLAN 3 §8.2) · **G2 live réel** (checkout GeniusPay réel en bac à sable,
webhook réel signé, commission réelle créée en base MySQL réelle — détail complet `plan.md` PLAN 3
§8.3) · **G4** (test réel du propriétaire, validé) · **G5** (son mot écrit) · documentation
(`docs/adr/ADR-056-paiement-en-ligne-geniuspay.md`, guide `GUIDE_TEST_APPLICATIONS_METIER.md`
partie 15).

**État laissé en place pour qui reprend** : Java (Docker, `docker compose up -d` dans
`services/payment`) et Laravel (`artisan serve --host=0.0.0.0 --port=8000`) sont **laissés
démarrés** — s'ils ont été arrêtés entre-temps, les relancer suffit (le volume Postgres et la base
MySQL portent déjà l'état du G2 : établissement `CI-ETS900010` id 18, marchand enregistré avec son
secret webhook déposé, 3 factures Java, 1 commission réelle de 450 FCFA — données conservées,
aucune instruction de nettoyage). **Piège à connaître si le service redémarre à froid sur ce
poste** : sous charge, le démarrage de Spring Boot a mis jusqu'à 5 minutes (contre ~15 s
normalement) — ne pas conclure trop vite à une panne, vérifier `docker compose logs payment` avant
d'intervenir.

**Deux défauts réels trouvés PAR LE G2, invisibles aux tests, à retenir pour la suite du projet** :
un `baremes_commission` vide en base de dev réelle (corrigé par le seeder), et **ma propre migration
`frais_connus` jamais rejouée sur la vraie base MySQL** — seulement sur SQLite via les tests
(corrigée par `artisan migrate --force`). *Aucune des deux n'était visible en test parce que la base
de test part toujours neuve — c'est précisément ce que le G2 live existe pour attraper, et il l'a
fait deux fois de plus.*

### B4-b (le rendez-vous) — clos

**✅ VALIDÉ (G5, 2026-09-05)** — G1 validé, G3 fait, G2 LIVE FAIT ET RÉEL, G4 propriétaire OK
(« G4 validé »), G5 « c'est bon pour le G5 ». **Le lot B4 est COMPLET (a, b).** Détail complet
(constats, décisions S1-S13, écart trouvé, vecteurs, résultats G2 live) : `plan.md` PLAN 3 §9-10.

**Ce que le G0 a trouvé, en relisant S5 contre le code réel** : poser une `FacturePatient`
`A_REGLER` au moment où le checkout s'ouvre (ce que S5 prescrit) cassait un invariant qui ne tenait
que par accident — `RecuRdvService::estRegle()` comptait l'**existence** d'une `FacturePatient`,
pas son **statut**, parce que jusqu'ici `payer()` en était l'unique créatrice et la crée toujours
`PAYEE`. Sans correction, `RendezVousValidationService::terminer()` (B1-d) aurait clôturé un
rendez-vous jamais réglé. **Corrigé** : `estRegle()` filtre sur `statut ∈ {PAYEE,
PRISE_EN_CHARGE_TOTALE}`, avec son vecteur de régression — les vecteurs B1 existants (qui ne
construisent que du `PAYEE`) passent sans modification.

**Second défaut, trouvé EN LISANT LE CODE JAVA avant le G2 (pas au G1)** : le plan initial prévoyait
un `factureId` envoyé à Java **opaque** (dérivé d'un hachage, jamais une vraie `Facture` Java).
C'était incomplet : `ServiceWebhookGeniusPay::appliquer()` appelle inconditionnellement
`ServiceFacturation::enregistrerReglement(factureId, …)` sur un succès, **dans la MÊME transaction**
que la transition vers `SUCCESS` — un `factureId` opaque aurait fait échouer cet appel et
**annulé toute la transition**, donc jamais de notification vers Laravel, sur le tout premier
paiement réel. **Corrigé** : `ouvrirPaiementEnLigne()` crée une vraie `Facture` Java minimale
(`POST /api/v1/invoices`, une ligne, TVA 0 %) avant le checkout, stockée sur
`factures_patient.facture_geniuspay_id` (migration additive), réutilisée aux appels suivants.
`correlationId` reste `'facture-patient:{id}'` (générique, pas `'rdv:{id}'`, pour que B3-d
réutilise le même mécanisme demain) ; `ouvrirPaiementEnLigne()`/`confirmerReglementEnLigne()`
vivent dans `RecuRdvService`, pas un second service.

**G2 live réel** : checkout GeniusPay réellement ouvert en bac à sable, vraie `Facture` Java créée
et réellement soldée (`enregistrerReglement()` a réellement réussi — la garantie même du second
défaut, prouvée en direct), webhook réellement signé avec un secret redéposé, notification
réellement relayée Java→Laravel, `FacturePatient`/`Paiement`/`RecuRdv` réels côté Laravel,
commission B4-a réellement déclenchée en conséquence (`montant_commission=300`), idempotence
prouvée à deux niveaux réels (rejeu du webhook signé, et rejeu direct de la notification à
Laravel). Java et Laravel laissés démarrés, données de test conservées (RDV id 2, structure 18
réutilisée). **Piège rencontré pendant cette session** : un second arrêt d'environnement (Docker
Desktop + `artisan serve` tous deux tombés en cours de session) a exigé un redémarrage complet
avant de pouvoir continuer le G2 — même famille que le premier gap documenté pour B4-a.

**Contexte du G0 de B4 (douze constats vérifiés dans le code Java, le code PHP et la base réelle),
toujours valable** :

**Le constat qui renverse tout (R2), à comprendre avant de reprendre** : depuis le lot 6, la
commission n'a jamais été déclenchée parce que, dit le code, « le domaine ne porte pas
d'identifiant d'établissement ». **C'est inexact** : l'agrégat `Paiement` **porte**
`etablissementRef` (colonne réelle) **et** `factureId`, et c'est cette même classe qui construit
l'événement — elle ne les recopie simplement pas. Ce qui était vrai, c'est qu'ils pouvaient être
**nuls faute d'émetteur**, Laravel n'initiant aucun paiement. *Le champ existe ; en devenir
l'émetteur, c'est le remplir.* Le commentaire du lot 6 n'était donc pas absurde — rattacher une
commission à un `etablissementRef` nul aurait été pire que ne rien faire. **B4 supprime la cause,
pas seulement le symptôme.**

Le reste tient en quatre points :

- **Le corpus est déjà satisfait par le montage A** : §9.6 dit que le paiement va au prestataire de
  l'établissement et que « la plateforme ne manipule jamais les fonds » — c'est exactement ce que
  P5.6b réalise (compte marchand par établissement). La commission est **facturée séparément**,
  jamais prélevée.
- **Le point d'entrée Java existe, complet** (`POST /api/v1/interne/geniuspay/paiements`). Ce qui
  manque est **côté Laravel** : le client sortant avait été **délibérément retiré** en P5.6a.
- **Le rendez-vous change de temporalité, pas de mécanisme** : sa facture naîtra `A_REGLER` (statut
  qui existe déjà) et ne deviendra `PAYEE` qu'à la notification. Effet secondaire heureux : le
  check-in exigeant un reçu, **il devient impossible avant confirmation sans écrire une garde**.
- **Deux prérequis de déploiement repérés en base réelle** : `identifiant_national` **0 sur 12**
  (backfill P6.4a) et `baremes_commission` **0 palier** (`BaremesCommissionSeeder`).

**Découpage** : **B4-a** le canal, prouvé seul ; **B4-b** le rendez-vous ; **puis B3-d** s'y branche.

**Les trois arbitrages du propriétaire sont rendus** (2026-09-04) : découpage **a puis b** ·
frais inconnus → **commission calculée à 0 et la ligne le dit** (refuser laisserait des paiements
réels sans aucune commission) · **pas de drapeau, actif d'emblée** — *contre ma recommandation, et
le résultat est meilleur* : un interrupteur global aurait été binaire pour tous les établissements
alors que la réalité du montage A est **par établissement**. La disponibilité devient donc une
**propriété de l'établissement** (identifiant national + compte marchand déclaré), la liste des
marchands **restant côté microservice** pour ne pas créer deux réponses à la même question.
**Assumé et dit** : sans interrupteur, un défaut du canal se verra immédiatement par les patients ;
la contrepartie est que le règlement d'aujourd'hui reste intact pour un établissement non configuré.

### B3-d — panier et commande de médicaments : ✅ VALIDÉ (G5, 2026-09-05)

**B3-c est clos** (G4 propriétaire fait, G5 écrite, commit `47d5b04` **poussé** le 2026-09-04).
**B3-d a été REPRIS le 2026-09-05** : B4 (canal GeniusPay réel) étant VALIDÉ G5 (a et b), F6 a été
**réécrit** — plus de second mécanisme simulé, plus de drapeau : le règlement en ligne d'une
commande emprunte le MÊME canal réel que le rendez-vous. **F1→F5 et F7→F12 restaient valables tels
quels.** Le propriétaire a validé ce G1 réécrit (« je valide »), et l'incrément a été **exécuté en
totalité** dans la foulée : modèles, services, contrôleurs, routes, vues, écrans mobiles, puis G3
(régression + mutation), puis G2 live réel. **Le lot B3 (Pharmacie) est désormais COMPLET
(a, b, c, d).**

**Ce qui a été construit** : `Commande`/`CommandeLigne` (4 déclencheurs dual-dialecte),
`StatutCommande`/`ModeRetraitCommande`/`ModeReglementCommande` (miroir `@masante/shared`, garde
anti-divergence), `ServiceCommande` (patient — F3 double garde renforcée : un produit sur
ordonnance est refusé, **en le nommant**, si l'ordonnance désignée ne le prescrit pas réellement,
pas seulement si aucune n'est désignée), `ServiceTraitementCommande` (pharmacien, permission neuve
`commande.traiter`), contrôleurs Sanctum + Blade, `PortailRolesSeeder`, vues, écrans mobiles
(panier Zustand, liste et détail des commandes).

**Deux bugs `$fillable`, un seul trouvé par le G2 live** (famille déjà rencontrée en P6.7b/B2-b/
B3-b) : `Commande::$fillable` (trouvé par des tests rouges) et **`CommandeLigne::$fillable`
omettant `medicament_id`** — invisible aux 20 tests automatisés (SQLite et MySQL tolèrent tous deux
plusieurs `NULL` sous `UNIQUE(commande_id, medicament_id)`, donc l'index restait inerte sans
qu'aucun test ne s'en aperçoive), **trouvé uniquement par inspection SQL directe en G2 live**, qui
aurait cassé en silence la sortie de stock d'une vente libre en production. Corrigé, un vecteur de
régression ajouté, la garantie reprouvée en direct par une commande créée via l'API réelle.

**G3** : 37 vecteurs dédiés ; suite complète **1764/1764** (après correctif d'un échec transitoire,
`commande.traiter` manquant côté `@masante/shared`) ; **mutation manuelle, 11 mutations, 11/11
conformes** (10 tueuses sur les gardes F3/F7/F6/F9/F10/cycle/anti-IDOR, dont une dédiée au
correctif du couplage ci-dessous ; 1 témoin resté vert) ; Pint propre, baseline `HEAD` respectée.

**G2 live réel** (officine 18/`CI-ETS900010`, Java/MySQL/`artisan serve` hérités de B4-b, base de
dev conservée pour le G4) : stock réel entré ; cycle réel `accepter → preparer → remettre` sur une
commande liée à une ordonnance → vraie `Delivrance` + vraie `traces_dispensation` + stock réellement
décrémenté (chemins B3-a/B3-c inchangés) ; même cycle sur une vente libre → stock décrémenté **sans**
délivrance ni trace, dans les deux sens ; refus réel avec motif. **Défaut réel de contrat trouvé en
direct, absent de tout vecteur** : le microservice Java refuse tout paiement GeniusPay sous
5000 FCFA (`422`), plancher jamais documenté côté B3-d ni B4 — découvert en tentant le tout premier
vrai checkout (commande à 1500 FCFA). Contourné pour la suite (commande à 6000 FCFA) : **règlement
en ligne réel de bout en bout** — vraie Facture Java créée, vrai checkout GeniusPay sandbox ouvert,
notification interne signée réelle (hors PHPUnit) → règlement réel posé, **commission réelle créée
par le mécanisme générique de B4-a sans aucun appel neuf depuis le domaine commande** (montant_brut
6000, taux 250 bps, commission 150, `facture_patient_id NULL` — la preuve centrale de F6) ;
idempotence réelle prouvée à deux niveaux (rejeu exact de la notification → aucune seconde
commission ; nouvelle tentative de paiement sur commande déjà réglée → refus réel). **Limite ajoutée
par le G2, non prévue au plan** : aucune garde de plancher de montant côté Laravel avant d'appeler
GeniusPay — le refus Java protège déjà les données, seul le message reste brut pour le patient.

Documentation écrite : `plan.md` PLAN 2 §13, `docs/adr/ADR-055-delivrance-ordonnance.md` §11, guide
`GUIDE_TEST_APPLICATIONS_METIER.md` partie 14, `GUIDE_TEST_INDEX.md` mis à jour, `CLAUDE.md` mis à
jour. **Scripts scratch supprimés** (`g2_setup_b3d.php`, `g2_notif_b3d.php`). **G4 propriétaire OK
(« G4 validé »), G5 « c'est bon pour le G5 »** — commité et poussé sur demande explicite.

Ce qu'il faut retenir du G0 initial, si quelqu'un reprend ici :

- **Le constat central est `medicaments.ordonnance_requise`** : la colonne existe, elle est saisie au
  portail, elle entre dans la projection gouvernée, elle s'affiche au mobile — et **aucune garde
  métier ne la lit**. Or CDC_11 §10.5 en fait une **règle de sécurité** (« l'application ne doit pas
  permettre l'achat sur la base du triage »). C'est ce que B3-d referme.
- **Trois mécanismes existent déjà et ne doivent pas être refaits** : l'itinéraire OSRM (entièrement
  côté mobile, avec les coordonnées déjà renvoyées par le comparateur), le stock réel de B3-b qui
  alimente `prix_pharmacie.disponible`, et le **jeton d'ordonnance de B3-a** qui fait lire une
  ordonnance au pharmacien **sans ouvrir le dossier**.
- **Le renouvellement SORT du périmètre**, contrairement à ce que le titre du sous-lot annonçait :
  CDC_04 le range dans le domaine **prescription**, CDC_11 ne le mentionne **nulle part**, et
  CDC_01 §17 module 7 dit « recherche, panier, ordonnance, commande » **sans lui**. Le livrer ici
  ouvrirait un sujet médical (qui renouvelle ? sur quelle durée ? avec quelle validation ?) au
  milieu d'un sujet commercial.
- **Les trois arbitrages sont rendus** (propriétaire, 2026-09-04 — `plan.md` PLAN 2 §10) :
  **l'ordonnance est désignée dans le carnet**, **un seul incrément**, et surtout **le paiement
  existe, en deux modes** — *décision prise contre ma recommandation, et elle était fondée*. Le
  patient règle **en ligne** ou **à la pharmacie**, et **la commission ne s'applique qu'au règlement
  en ligne**.
- **Ce que cet arbitrage avait fait découvrir, et ce que B4 a depuis rendu inutile à contourner** :
  **tout le domaine commission existait déjà** (`CommissionService`, barèmes, plan tarifaire,
  `CommissionTransaction`, factures partenaires) et portait **textuellement** la règle rappelée par
  le propriétaire — *une pharmacie réglée hors ligne est exonérée*. Le blocage de l'époque
  (`calculerEtEnregistrer()` sans appelant en production, le payload Java ne portant aucun
  identifiant de structure) **a été refermé par B4-a**, pas par B3-d : `PaiementNotificationController`
  appelle désormais `CommissionService` sur **tout** succès `canal=geniuspay`. **B3-d n'a donc plus
  besoin d'appeler ce service lui-même** — il lui suffit de router le règlement en ligne par le VRAI
  checkout ; la commission arrive avec le webhook, exactement comme pour le rendez-vous.
- **Défaut réel trouvé au G0 de la reprise (2026-09-05), en relisant `PaiementNotificationController`
  avant d'y brancher un troisième dispatch** : ses deux dispatches existants (commission, règlement
  facture/RDV) ne sont pas réellement indépendants malgré leur docblock — une exception du calcul de
  commission (bareme manquant) empêche le règlement de s'exécuter dans le MÊME appel, faute de
  `try/catch`. Corrigé en même temps (correction chirurgicale sur un fichier validé G5) : la
  commission ne pourra plus jamais bloquer un règlement, RDV ou commande.
- **Leçon de méthode, notée parce qu'elle resservira** : le G0 initial avait audité le domaine de la
  **commande** et pas celui de la **commission**, parce que le corpus applicatif n'y renvoyait pas.
  *Un G0 borné par ce que le corpus nomme peut manquer un domaine entier déjà construit dans le
  dépôt.*
- **Prérequis de déploiement** : `BaremesCommissionSeeder` a déjà été rejoué sur la base de
  développement pendant le G2 de B4 (`baremes_commission` n'est plus vide) — resterait un prérequis
  réel sur une base neuve.

**La méthode a tenu et se poursuit** : *ne pas partir du plan G1 du lot sans le confronter au code
réel* — sur B3-c le G0 avait corrigé **trois** de ses affirmations, et un vecteur de **G3** avait
ensuite corrigé une décision du **G1** que le G0 n'avait pas vue (la clé étrangère `nullOnDelete` sur
`medicament_id`, §5 ci-dessus). Le G0 de B3-d vient d'en corriger **deux** de plus (Q6 et Q7).

**Rappel du processus à trois fichiers (règle propriétaire du 2026-09-03)** : décision →
`CLAUDE.md` (avant d'écrire une ligne de code) → `plan.md` (un bloc `# PLAN n : …`) → exécution →
`handoff.md`. B3-c est le premier incrément mené sous cette règle, B3-d le second.

### Après le lot B3 — les dettes transverses, sans porteur numéroté

*Aucune n'est engagée ; elles attendent une décision du propriétaire.* Dettes nommées :

- **Migration du portail Blade vers Next.js** — ADR-011 la tranche, ADR-029 en a fait « un module
  identifié ». **29 zones, 77 vues**, chacune ayant besoin de son API en plus de son écran. C'est
  là que le design moderne se fera **une fois**, sur le design system partagé.
- **Référentiel d'allergènes** — verrou de la vérification des contre-indications (§7.2, §5.4).
- **Élévation de la gouvernance du socle P6.3** — porteur des asymétries « donnée gouvernée, lecture
  non gouvernée » (`poids_severite` de P10b-3-ii, `code_barres` de B3-c).
- **Trois CDN subsistent** au portail (`html5-qrcode`, Chart.js ×2) — même défaut que Bootstrap,
  corrigé en P6.4d pour lui seul.
- **Contenus de référentiels** — la plupart sont des **jeux de démonstration** honnêtement
  étiquetés (médicaments, maladies, vaccins, analyses, assurances, numéros d'urgence). Les charger
  pour de vrai est **de la donnée, zéro code** — mais tant que ce n'est pas fait, **ce ne sont pas
  des référentiels nationaux**, et les écrans le disent.

---

## 7. Le lot clos — B5, le circuit du laboratoire (COMPLET, a → c)

**État : les trois sous-incréments sont VALIDÉS (G5)** — B5-a et B5-b le 2026-09-05, B5-c le
2026-09-06 (G3/G2 menés le 2026-09-05). G1 validé par le propriétaire
(« je valide le G1 de B5 »), périmètre intégral tenu (« on ne va rien abandonner ») : les quatre
tables absentes du CDC_04 §109, les sept éléments du corpus, la traçabilité du laborantin et la
notification patient ajoutées par le propriétaire — **rien de ce qui a été demandé n'a été
reporté**. Plan complet : `plan.md` **PLAN 4** (constats K1→K11, décisions L1→L16, §11 exécution
B5-a, §12 exécution B5-b, §13 compléments M1→M12, §14 exécution B5-c). Documentation : ADR-057
(B5-a), ADR-058 (B5-b), ADR-059 (B5-c), guide `GUIDE_TEST_APPLICATIONS_METIER.md` parties 17-19.

### Pourquoi ce lot, et pourquoi maintenant

C'est l'**étape 9 de l'ordre que CDC_11 §12 fixe lui-même** (« Laboratoire, puis Radiologie ») ; les
huit précédentes sont closes. Elle a un porteur nommé par trois modules déjà validés : P6.5b
(« une prescription biologique est une DEMANDE, pas un document »), P6.7 (« §7.4 non livré ») et
B2-c, qui a explicitement écarté les demandes d'examens en disant que « les livrer ici serait ouvrir
un module dans un autre ».

### Le périmètre est INTÉGRAL (arbitrage propriétaire, 2026-09-05)

> « on ne va rien abandonner, on va ajouter tout ce qui manque »

Les **quatre tables absentes** (`demandes_analyses`, `prelevements`, `validations_biologiques`,
`automates`) et les sept éléments du corpus : demandes d'examens · prélèvements avec scan
code-barres/QR · **saisie et import** des résultats · validation biologiste · publication vers le
dossier patient · catalogue des analyses · **connexion aux automates**. Plus deux exigences ajoutées
par le propriétaire : **la traçabilité du laborantin** (« tout comme le journal d'audit du médecin au
carnet de santé ») et **une notification au patient après publication**.

*Ma première proposition écartait les automates ; elle a été corrigée par le propriétaire, et le
résultat est meilleur — la frontière est désormais technique et nommée au lieu d'être un renoncement.*

### Ce que le G0 a trouvé, et qu'il faut connaître avant de reprendre

- **Le circuit n'existe à aucun degré** (K1) : quatre des six tables du CDC_04 §109 sont absentes en
  base réelle. Ce qui existe est un **résultat sans émetteur ni circuit** — rien ne relie un résultat
  à une demande, et le prescripteur y est une simple *déclaration* facultative (K4).
- **Le blocage que le code lui-même avait posé est levé** (K2) : `RegistreDocumentsSignables` dit
  depuis P6.5b que la prescription biologique « prescrirait des examens en texte libre » **sans le
  catalogue national (étape 7)**. L'étape 7 est faite. B5 lèverait la troisième des six entités non
  branchées de la PKI.
- **UN DÉFAUT RÉEL ET ACTIF (K5 + K11), à corriger dans B5-a** : `source = 'structure'` est une clé
  dormante sans émetteur depuis le Module 2, **et elle est déclarable par le client** sur trois
  sections du carnet. Le G0 l'a d'abord crue latente ; K11 l'a trouvée active —
  `ServiceFicheParcours::autresEntrees()` filtre sur `whereIn('source', ['medecin','structure'])` en
  affirmant « ce sont des faits ». **La saisie personnelle d'un patient peut donc apparaître dans sa
  fiche de parcours présentée comme un fait de professionnel**, document lu par ses délégués et son
  second responsable de famille. *Vérifié aussi que la fiche vitale n'est PAS touchée* : elle ne lit
  `source` que sur les vaccinations, dont le contrôleur ne l'accepte pas — le commentaire de P6.8b
  dit vrai là où il est écrit.
- **Deux prérequis de déploiement** (K6, K7) : `analyses` est à **0 ligne** sur la base de dev
  (`CatalogueAnalysesSeeder` puis `masante:analyses:backfill`), et l'unique laboratoire en base a son
  `type_laboratoire` à NULL.
- **Aucune bibliothèque de code-barres ni de QR dans le dépôt** (L16) — vérifié dans `composer.json`
  et `vendor/`.

### Les décisions structurantes à ne pas défaire

- **Le laboratoire n'ouvre JAMAIS de session de dossier** (L3). Il lit la demande par son **jeton** et
  écrit un résultat borné à cette demande. *Vecteur central du lot, comme en B3-a : une **absence** —
  servir une demande ne crée aucune ligne dans `acces_dossier`.*
- **Un automate ne valide JAMAIS** (L10). Un résultat importé entre en `en_analyse` et attend la
  validation humaine — sans quoi une machine publierait dans le dossier d'un patient sans qu'aucun
  biologiste ne l'ait vu (CDC_00 §4). Le rattachement se fait **par l'étiquette du tube**, jamais par
  rapprochement de nom : *ce serait l'erreur d'identification que le §7.4 existe pour supprimer*.
- **Le jeton et l'étiquette sont deux choses différentes** (L5) : l'un est un secret d'accès, l'autre
  est imprimée sur un tube qui circule. *Les confondre reviendrait à distribuer la clé du dossier avec
  l'échantillon.*
- **La traçabilité vit dans `journal_laboratoire`, pas dans `acces_dossier`** (L13) — vérifié plutôt
  que supposé : `ServiceFicheParcours` apparie ouvertures et clôtures sur `duree_minutes`, donc une
  ligne de dépôt isolée s'y afficherait « **consultation non clôturée** », *une phrase fausse dans le
  document même qui existe pour dire au patient ce qui s'est passé*. **Et le patient voit quand même
  le dépôt** : une fois `source = 'structure'` réellement écrite, le résultat apparaît de lui-même
  dans la fiche de parcours qui filtre déjà sur cette valeur — *le mécanisme existant devient exact au
  lieu d'être doublé*, et P7-D2 comme les six voies d'accès restent **intacts**.
- **La notification dit l'événement, jamais le résultat** (L14) : « un résultat a été déposé par le
  Laboratoire X », **ni nom d'analyse ni valeur**. *Un push s'affiche sur un écran verrouillé, et
  « sérologie VIH » sur un téléphone posé sur une table est une divulgation.*
- **Saisie et import passent par UN SEUL service** (L15) — *ça diverge toujours du côté qu'on regarde
  le moins*, ici l'import, qu'aucun humain n'ouvre jamais.
- **`analyse.valider` n'est portée par aucun rôle** (L7) : *un résultat biologique validé engage la
  responsabilité d'un biologiste nommé*. Aucun douzième rôle n'est créé pour un écran.

### Découpage

| | Contenu | État |
|---|---|---|
| **B5-a** | Fermeture de la porte `source` (K5/K11) · demandes + lignes · lien catalogue · jeton · signature PKI · écran de prescription | ✅ **VALIDÉ (G5, 2026-09-05)** |
| **B5-b** | Prélèvements · `ReglesCode128` et étiquette imprimable · scan · cycle à six états et gardes du moteur · `journal_laboratoire` · écran laboratoire | ✅ **VALIDÉ (G5, 2026-09-05)** |
| **B5-c** | Résultats **saisie et import** par un seul service · `automates` · validation biologiste et son verrou · publication en `source='structure'` · **notification** | ✅ **VALIDÉ (G5, 2026-09-06)** |

**Aucun des sept éléments demandés n'est reporté.** B5-c est le seul qui touche le carnet du patient,
donc le seul qui exige le vecteur anti-fuite et la campagne de mutation sur la garde de validation.

### Ce que B5-a a livré, et ce qu'il ne faut pas défaire

- **`demandes-analyses` est devenue une sixième section du registre du carnet**
  (`RegistreSectionsCarnet::SECTIONS`/`SECTIONS_AUTEUR_EST_PRESCRIPTEUR`/`SECTIONS_SOIGNANT`) :
  tout le mécanisme générique existant s'applique sans code neuf, **aucune permission nouvelle**.
  Si B5-b/c doivent ajouter des permissions (`analyse.executer`, `analyse.valider` — L7), ce sera
  la première fois que ce lot en crée.
- **`source` est fermée sur les trois sections existantes** (`AntecedentController`,
  `OrdonnanceController`, `ResultatAnalyseController`) — ne jamais la rouvrir dans leurs règles de
  validation : c'était le défaut actif K5/K11.
- **`ServiceLienAnalyse` (P6.7a) a été généralisée** (paramètre `$champ`) pour servir aussi
  `demandes_analyses`, plutôt que dupliquée — à réutiliser telle quelle si un futur lien au
  catalogue est nécessaire ailleurs.
- **Deux défauts réels trouvés par les tests, aucun par la relecture**, à garder en tête pour
  B5-b/c qui toucheront les mêmes zones : (1) un nom de colonne (`structure_nom` au lieu de
  `structure_sanitaire`) qui ne correspondait pas à ce que le mécanisme générique de réécriture
  attend — **vérifier systématiquement les noms de colonnes contre les mécanismes génériques
  existants avant de les inventer** ; (2) une comparaison enum-à-chaîne dans une garde de
  projection, toujours vraie — **comparer un enum casté à un autre enum, jamais à sa valeur
  brute**.
- **Le jeton (`demandes_analyses.jeton_partage`) est posé, mais rien ne le consomme encore** —
  c'est le premier travail de B5-b : lire une demande par jeton, sans ouvrir de dossier (patron
  `ServiceDelivrance::ordonnancePourJeton()`).

### Ce que B5-b a livré, et ce qu'il ne faut pas défaire

- **Le jeton de B5-a trouve enfin son lecteur** : `ServiceCircuitPrelevement::demandePourJeton()`
  (temps constant, `hash_equals`) et `journaliserConsultation()` (appelée séparément — un jeton FAUX
  ne journalise rien). **Vecteur central vérifié en direct** : `acces_dossier` reste à **1** du
  début à la fin du cycle complet (consultation, enregistrement, réception, mise en analyse), sur
  une base MySQL réelle et non seulement en test.
- **`analyse.executer` est la PREMIÈRE permission créée par ce lot** (annoncé comme tel en B5-a) —
  donnée au seul `laborantin`, qui **garde** `qr.scan`/`dossier.ecrire`/`triage.view` (précédent
  B3-a : une permission neuve n'en retire jamais une existante). **`analyse.valider` (L7) n'est PAS
  créée ici** — elle appartient au contrôleur de validation biologique de B5-c ; la créer maintenant
  sans porte aurait été une clé sans serrure (refus P11.1-D5).
- **Le cycle a SIX états déclarés, mais seulement QUATRE sont atteignables** : `preleve`,
  `expedie` (facultatif), `recu`, `en_analyse`. `valide`/`publie` existent dans l'ENUM (ADR-024,
  jamais réécrit) mais aucune transition n'y mène — elles attendent B5-c.
- **`journal_laboratoire` est un journal SÉPARÉ d'`acces_dossier`, jamais un doublon** : append-only
  à deux niveaux (modèle + déclencheurs), identifiants sans contrainte (ADR-042 D1), vérifié refuser
  `UPDATE` et `DELETE` **en SQL direct contre la base réelle**.
- **Un DÉFAUT RÉEL DE B5-a, trouvé EN CONSTRUISANT B5-b** (pas au G0, pas à la relecture) :
  `ProjecteurLignesDemande::projeter()` comparait `statut` (un ENUM casté) à `EMISE->value` (une
  CHAÎNE) — toujours vrai — et n'avait **aucune garde relationnelle** : rien n'empêchait de
  reprojeter les lignes d'une demande déjà prélevée, ce qui aurait désynchronisé le tube physique de
  la liste affichée au carnet. Corrigé (`if ($demande->prelevements()->exists()) { return -1; }`),
  vérifié en direct sur la vraie demande créée pour le G2 live.
- **`ReglesCode128` (SVG pur, zéro dépendance) reste PROUVÉE PAR AUTO-COHÉRENCE, PAS PAR UN
  SCANNER PHYSIQUE** — limite dite, pas cachée : le tableau des 107 motifs est reproduit de mémoire
  d'après la norme ISO/IEC 15417, vérifié par invariant (chaque motif somme à 11 ou 13 modules) et
  par unicité, jamais testé contre une douchette réelle.

### Ce que B5-c a livré, et ce qu'il ne faut pas défaire

- **Le brouillon d'un résultat vit hors du carnet**, sur `prelevements.resultats_bruts_json`
  (chiffré) — jamais sur une ligne `resultats_analyses`, qui serait visible du patient avant la
  validation. Il **survit** à la publication (pièce médico-légale distincte de la copie du carnet,
  que le patient peut ensuite modifier).
- **La publication réutilise `ServiceLienResultat` DIRECTEMENT, jamais le composite
  `preparerDonnees()` du contrôleur** : celui-ci re-résoudrait aussi `resultats_json` contre le
  catalogue au moment de la publication, sur des valeurs déjà figées à la saisie. `resultats_json`
  publié est **copié verbatim** depuis le brouillon — ne jamais réintroduire une ré-résolution ici.
- **`source='structure'` n'est posée QU'à cet endroit**, jamais par un client — c'est ce qui
  referme K5/K11 pour de bon.
- **Un automate n'écrit jamais qu'un brouillon** (`ServiceValidationBiologique::importer()`) — ne
  jamais lui laisser atteindre `valider()`/`publier()`, même indirectement.
- **`DemandeAnalyse::estOuverte()` est désormais câblée** dans
  `ServiceCircuitPrelevement::enregistrer()` (`assertOuverte()`) — une demande `servie`/`annulee`
  refuse un nouveau prélèvement.
- **Piège de migration à retenir pour tout futur `->change()` sur une table portant des
  déclencheurs** : sous SQLite, Laravel reconstruit la table entière et supprime ses déclencheurs
  au passage — vérifier après coup (`SHOW TRIGGERS` en MySQL, requête sur `sqlite_master` en test)
  que rien n'a disparu, et recréer explicitement si besoin (voir
  `reconstituerGardesJournalLaboratoire()` dans la migration de B5-c).

### Prochaine étape

**Aucun incrément n'est engagé.** Le lot B5 étant complet, CDC_11 §12 nomme la **radiologie**
comme seconde moitié de l'étape 9 (hors périmètre de B5, DICOM/PACS) — mais aucune décision n'a été
prise de l'entreprendre. Avant de commencer quoi que ce soit :

1. Commiter et pousser le travail de B5-c (voir §4 pour la liste des fichiers).
2. Rejouer sur la base de développement, la migration de B5-c étant revenue à `Pending` après son
   G2 live (le commit ne rejoue jamais les migrations, seul le code est versionné) :

   ```bash
   cd services/api
   XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan migrate --force
   XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan db:seed --class=PortailRolesSeeder --force
   ```

3. Discuter avec le propriétaire de la suite : radiologie, ou l'un des chantiers transverses listés
   en §6 (migration du portail vers Next, référentiel d'allergènes, etc.).
