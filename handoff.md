# Handoff — MaSanté (IVOIRSANTÉ)

> **Point de reprise.** Écrit pour quelqu'un qui reprendrait le projet demain sans rien en savoir.
> Dernière mise à jour : **2026-09-04**. Branche : **`feat/masante-p0-socle`**, à jour avec
> `origin`. Dernier commit poussé : **`47d5b04`** — **B3-c VALIDÉ (G5, 2026-09-04), G4 propriétaire
> OK** ; **le lot B3 est COMPLET sur a, b, c**.
>
> **Dernier incrément clos** : **B4-a** (canal de paiement GeniusPay) — **✅ VALIDÉ (G5,
> 2026-09-04)** — G4 propriétaire OK, G5 « c'est bon pour le G5 ». Checkout GeniusPay réellement
> ouvert en bac à sable, webhook réellement signé et vérifié, notification réellement relayée
> Java→Laravel, **commission réellement calculée et enregistrée en base MySQL réelle**.
> `docs/adr/ADR-056-paiement-en-ligne-geniuspay.md` et le guide partie 15 écrits. Java (Docker) et
> Laravel (`artisan serve`) restent démarrés, données de test conservées. Détail complet : `plan.md`
> PLAN 3 §8.
>
> **En cours** : **B4-b** (le rendez-vous) reste à faire — c'est le prochain incrément. **B3-d**
> (panier et commande, `PLAN 2`) reste en attente : il dépend du canal que B4-a vient de livrer. Voir
> §6.
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
| **B3** — **Pharmacie** | 🔵 a ✅, b ✅, **c ✅ (G5, 2026-09-04)** ; **d (panier/commande, dernier sous-lot annoncé) reste à faire** |

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

- Branche **`feat/masante-p0-socle`**. Avant ce passage, dernier commit poussé : **`1451ce1`** —
  *feat(pharmacie) : l'officine tient enfin un vrai stock (B3-b)*.
- **B3-c est VALIDÉ (G5, 2026-09-04) — G4 propriétaire OK, et committé dans ce passage.** 16 fichiers
  touchés (7 nouveaux, 9 modifiés — détail dans le commit lui-même).
- Suite Laravel au dernier passage complet (avec B3-c) : **1676 tests / 17 821 assertions / 0
  échec**.
- **Aucun secret suivi par git** (vérifié : 0 correspondance sur les `.env`).

### Base de données de développement — à jour, 0 migration `Pending`

Toutes les migrations sont **`Ran`**, y compris les cinq qu'un précédent G2 avait laissées
`Pending` (consultations, diagnostics, ordonnance_prescripteur, delivrance_ordonnance,
stock_officine) et la nouvelle de B3-c (`tracabilite_medicaments`) — le G2 live de B3-c les a
toutes rejouées avant de sauvegarder la base, et la restauration a donc capturé cet état à jour.
**L'avertissement d'une précédente version de ce fichier ne s'applique plus.**

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
| **B3-d** | **Panier + commande** (§9.5, §10.5) — *le renouvellement en SORT, voir ci-dessous* | ⏸️ **G1 rédigé (`plan.md` PLAN 2), MIS EN ATTENTE** — dépend du lot **B4**. Aucun code écrit |

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

---

## 6. Prochaines étapes

### B4-a — le canal GeniusPay : ✅ VALIDÉ (G5, 2026-09-04)

> « on va brancher les paiements sur GeniusPay et aussi le brancher au rendez-vous »
> « je valide le G1 de B4-a » · « G4 validé » · « c'est bon pour le G5 »

**B3-d reste en attente** : son règlement en ligne se branchera sur ce canal, maintenant réel.

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

### Ensuite — B4-b (le rendez-vous)

Non commencé. S5/S6/S8/S9 du plan (`plan.md` PLAN 3 §3) restent à implémenter : la facture RDV
naîtra `A_REGLER` au lieu de `PAYEE` immédiate quand le règlement passe par ce canal, le reçu
n'étant créé qu'à la notification — jamais un retour d'application. Le canal lui-même (B4-a) n'a
plus à être réinventé, seulement appelé.

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

### Ensuite — B3-d, dont le G0 est fait et le G1 rédigé

**B3-c est clos** (G4 propriétaire fait, G5 écrite, commit `47d5b04` **poussé** le 2026-09-04) — il
n'y a plus rien à y faire. **B3-d est écrit et attend B4** (voir ci-dessus) : son règlement en ligne
se branchera sur le canal réel plutôt que sur un second mécanisme simulé. **F1→F5 et F7→F12 restent
valables tels quels ; seul F6 sera remplacé.**

Le **G0 de B3-d a été mené le 2026-09-04** (douze constats, vérifiés en base réelle et dans le code)
et le **G1 est rédigé dans `plan.md`, bloc `PLAN 2`** : douze décisions de conception (F1→F12), le
schéma exact des deux tables, les vecteurs obligatoires, les limites à annoncer.
**Aucune ligne de code n'est écrite, et aucune ne le sera avant validation du propriétaire.**

Ce qu'il faut retenir du G0, si quelqu'un reprend ici :

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
- **Ce que cet arbitrage a fait découvrir, et qui compte pour la suite du projet** : **tout le
  domaine commission existe déjà** (`CommissionService`, barèmes par palier de volume, plan
  tarifaire, `CommissionTransaction`, factures partenaires, écran de facturation) et il porte
  **textuellement** la règle rappelée par le propriétaire — *une pharmacie réglée hors ligne est
  exonérée*. Mais `calculerEtEnregistrer()` **n'a aucun appelant en production** (0 ligne en base),
  parce que le payload du microservice **Java ne porte aucun identifiant de structure MaSanté** —
  blocage réel, documenté dans `PaiementNotificationController` et **vérifié côté Java**. Laravel,
  lui, connaît l'officine : **B3-d en devient le premier appelant**, sans modifier le service.
  L'encaissement, lui, reste **simulé** (comme celui du RDV), donc le règlement en ligne est
  **gaté OFF par défaut** — sinon on facturerait un partenaire réel sur un encaissement fictif.
- **Leçon de méthode, notée parce qu'elle resservira** : le G0 initial avait audité le domaine de la
  **commande** et pas celui de la **commission**, parce que le corpus applicatif n'y renvoyait pas.
  *Un G0 borné par ce que le corpus nomme peut manquer un domaine entier déjà construit dans le
  dépôt.*
- **Prérequis de déploiement repéré** : `BaremesCommissionSeeder` n'a jamais été joué sur la base de
  développement (0 palier) — un règlement en ligne y échouerait bruyamment, ce qui est le bon
  comportement mais doit être su.

**La méthode a tenu et se poursuit** : *ne pas partir du plan G1 du lot sans le confronter au code
réel* — sur B3-c le G0 avait corrigé **trois** de ses affirmations, et un vecteur de **G3** avait
ensuite corrigé une décision du **G1** que le G0 n'avait pas vue (la clé étrangère `nullOnDelete` sur
`medicament_id`, §5 ci-dessus). Le G0 de B3-d vient d'en corriger **deux** de plus (Q6 et Q7).

**Rappel du processus à trois fichiers (règle propriétaire du 2026-09-03)** : décision →
`CLAUDE.md` (avant d'écrire une ligne de code) → `plan.md` (un bloc `# PLAN n : …`) → exécution →
`handoff.md`. B3-c est le premier incrément mené sous cette règle, B3-d le second.

### Après le lot B3

Étapes restantes de CDC_11 §12 et dettes nommées avec leur porteur :

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
