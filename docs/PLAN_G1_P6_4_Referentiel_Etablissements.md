# PLAN G1 — P6.4 « Référentiel national des établissements » (CDC_09 §4)

**Statut : P6.4a ✅ VALIDÉ (G5, 2026-08-13) — G1 tranché, G2 live, G3 prouvés, G4 propriétaire OK.**
Décisions tranchées (2026-08-13) : **D1 (a)** projection d'identité administrative · **D2 (a)** `ETS` + 6 chiffres littéral · **D3 (a)** `type` = catégorie + `statut_juridique` · **D4 (a)** Méthode 2 hors périmètre · **D5 (a)** jeu partiel annoncé comme tel.
Décision finale : [ADR-026](adr/ADR-026-referentiel-etablissements.md) · Guide : **partie 2** de [GUIDE_TEST_REFERENTIELS.md](../GUIDE_TEST_REFERENTIELS.md).
Date : 2026-08-13 · Module précédent : P6.3 Socle référentiel (VALIDÉ G5) · Corpus : **CDC_09 §4**, §1.2, §8, §14.4 · **CDC_11 §3** (onboarding) · Décisions en vigueur : [ADR-024](adr/ADR-024-referentiels-nationaux.md) (enrichissement additif), [ADR-025](adr/ADR-025-socle-referentiel.md) (socle).

---

## 1. G0 — audit réel

### F1 — La table est la plus référencée du projet après `membres_famille`
`structures_sanitaires` est touchée par **40 fichiers** : `StructureService` (P3), `RendezVous` (P4), `Medecin`, `Avis`, `Signalement`, `PharmacieGarde`, `PrixPharmacie`, `BesoinSang`, `ServiceEtablissement`, `User.structure_id` (rattachement du staff portail), `acces_dossier.etablissement` (P7-D2), plus 15 suites de tests. **P3 et P4 sont validés G5.**

**Bonne nouvelle vérifiée** : le contrat de lecture sérialise le **modèle Eloquent entier** (`StructureService::rechercher()` / `fiche()`). Ajouter des colonnes est donc **automatiquement additif** — le mobile reçoit davantage de champs, rien ne casse. Et `@masante/shared` **ne contient aucun type de structure** : il n'y a pas de contrat partagé à faire évoluer. Côté front, une seule valeur d'énumération est écrite en dur (`'centre_sante'`, en repli dans `structures/itineraire.tsx`).

### F2 — L'écart au CDC est massif : 11 champs collectés, ~25 exigés
Le formulaire admin (`Portail\EtablissementController`) valide **11 champs** : nom, type, adresse, commune, latitude, longitude, téléphone, whatsapp, spécialités, tarif min/max, partenaire.

CDC_09 §4.2 **et** CDC_11 §3.1 en exigent bien davantage, **aucun présent** : identifiant national, statut juridique, catégorie, **niveau de soins**, région, **district sanitaire**, email, site web, pays, quartier, directeur, capacité d'accueil, nombre de lits, agréments, certifications, n° d'autorisation, n° fiscal, registre du commerce, date de création, licence d'exploitation, autorité de tutelle, logo, photos, description.

### F3 — La colonne `type` mélange deux axes que le CDC sépare
ENUM actuel : `chu, chr, clinique_privee, cabinet, pharmacie, laboratoire, centre_sante`.

CDC_11 §3.1 distingue explicitement **deux** notions :
- **Type** : public / privé / universitaire / militaire ;
- **Catégorie** : Hôpital, Clinique, Centre médical, Centre de santé, Laboratoire, Cabinet.

L'ENUM existant est une **catégorie**, et la valeur `clinique_privee` fusionne à elle seule les deux axes. Par ailleurs le **périmètre §4.1 est plus large** : manquent hôpitaux généraux, centres de santé urbains/ruraux, **centres d'imagerie**, **centres de dialyse**, **centres de vaccination**.

### F4 — Aucun identifiant national ; l'exemple imposé n'a pas de clé de contrôle
`ETS000152` (§4.3). À noter : **§3.2 impose explicitement un checksum pour le NIS et ne le fait pas ici.** Inventer une clé de contrôle rendrait l'exemple imposé du CDC **invalide**.

### F5 — L'onboarding « Méthode 2 » n'existe pas, alors que CDC_11 §3 dit que les deux sont implémentées
Seule la **Méthode 1** existe (l'admin crée l'établissement + un lien d'activation part au gestionnaire — `EtablissementController` + `ActivationPortail`). La **Méthode 2** (« Clinique Saint Joseph demande son inscription » → formulaire → vérification → publication) n'a **aucune trace** dans le code.

### F6 — Ni région, ni district sanitaire, ni quartier nulle part
Aucune colonne, aucune table, aucune donnée — y compris dans le seeder (**12 structures**, toutes d'Abidjan). Or §4.2 exige le **district sanitaire**, et §1.2.4 interdit qu'une donnée de référence soit « saisie librement dans un module métier ».

---

## 2. Le constat qui change la nature du module

**Un annuaire d'établissements n'est pas un référentiel de règles.** P6.3 gouverne des tables de dizaines de lignes qui changent rarement et sur décision. `structures_sanitaires` mélange deux natures de données :

| Nature | Exemples | Rythme de changement |
|---|---|---|
| **Identité administrative** | identifiant national, nom officiel, statut juridique, catégorie, niveau de soins, district sanitaire, agréments, n° d'autorisation | rare, sur décision d'une autorité |
| **État opérationnel** | `note_moyenne`, `nb_avis`, horaires, disponibilité du jour, tarifs, spécialités | continu, souvent **automatique** |

`note_moyenne` et `nb_avis` sont **recalculés à chaque avis déposé** (`NoteStructureService`). Placer l'établissement entier sous versionnage rendrait l'instantané **divergent en permanence** : le contrôle d'anti-substitution de P6.3 refuserait toute publication dès qu'un citoyen dépose un avis, et la commande de contrôle signalerait « DIVERGENTE » en continu. Le mécanisme deviendrait inexploitable — et surtout **mensonger**, puisqu'il prétendrait qu'une note d'avis est une donnée de référence nationale.

C'est exactement la limite **L6** annoncée en P6.3 (« l'instantané JSON convient à des référentiels de règles ; sa pertinence pour un référentiel volumineux sera réexaminée »). Elle se présente **dès P6.4**, et non en P6.6.

---

## 3. Périmètre proposé

**P6.4a (backend)** — le référentiel proprement dit :
1. **Enrichissement additif** de `structures_sanitaires` (ADR-024) : identité administrative, coordonnées complètes, informations légales, capacité, description.
2. **Identifiant national** `ETS` + compteur, attribué et journalisé, avec commande de backfill idempotente (motif P6.1).
3. **Découpage sanitaire** : tables `regions` et `districts_sanitaires`, FK depuis l'établissement (§1.2.4 : pas de texte libre).
4. **Mise sous gouvernance P6.3** — d'une **projection** et non de la table entière (voir décision D1).
5. Contrôles qualité §10 : unicité de l'identifiant, cohérence GPS/région, agrément expiré, coordonnées hors Côte d'Ivoire, doublons de nom dans un même district.

**P6.4b (écrans)** — formulaires du portail alignés sur le nouveau schéma.

**Hors périmètre, dit franchement** : onboarding Méthode 2 (voir décision D4), images/logo (stockage de fichiers), niveau de soins par service, PKI (P6.5).

---

## 4. Décisions à trancher

### D1 — Que met-on sous gouvernance versionnée ? *(la décision centrale)*
| Option | Description |
|---|---|
| **(a) Une projection « identité administrative » seule** *(recommandée)* | Une `SourceReferentiel` qui n'extrait **que** les champs de référence (identifiant national, nom officiel, catégorie, statut juridique, niveau de soins, région, district, agréments, actif). Note, avis, horaires, tarifs et disponibilités en sont **exclus**. L'instantané ne bouge alors que sur décision, et l'anti-substitution redevient exploitable. |
| (b) La table entière | L'instantané divergerait à chaque avis déposé : publication impossible en pratique, et le référentiel prétendrait qu'une note d'étoiles est une donnée nationale. |
| (c) Aucune gouvernance, enrichissement seul | Le socle P6.3 ne servirait à rien pour le premier référentiel d'annuaire — on saurait à peine pourquoi on l'a construit. Les contrôles qualité et l'audit resteraient pourtant utiles. |

### D2 — Format de l'identifiant national
| Option | Description |
|---|---|
| **(a) `ETS` + 6 chiffres, littéral, sans clé** *(recommandée)* | Respecte l'exemple imposé `ETS000152`. §3.2 exige un checksum pour le NIS et **ne le fait pas ici** — ajouter une clé rendrait l'exemple du CDC invalide. Multi-pays par `UNIQUE(pays_code, identifiant_national)` : le pays qualifie, il ne s'écrit pas dans l'identifiant. |
| (b) Avec clé mod-97, comme le NIS | Cohérent avec P6.1, mais **contredit l'exemple imposé**. Un établissement n'est de plus jamais saisi de tête par un citoyen — le risque d'erreur de frappe que la clé traite n'existe pas ici. |

### D3 — Le couple `type` / `catégorie` / `statut juridique`
| Option | Description |
|---|---|
| **(a) `type` reste la catégorie, on ajoute `statut_juridique`** *(recommandée)* | `type` est **de facto** la catégorie (c'est ce que filtrent P3/P4) : on l'assume, on **étend son ENUM** aux valeurs manquantes du §4.1 (hôpital général, centre d'imagerie, dialyse, vaccination, centre de santé urbain/rural), et on ajoute une colonne `statut_juridique` (public/privé/universitaire/militaire). `clinique_privee` est conservée telle quelle — la renommer casserait des données et des filtres validés G5. |
| (b) Nouvelle colonne `categorie`, `type` déprécié | Plus propre sur le papier, mais impose de migrer les données et de toucher `StructureService`, le portail Blade, les filtres mobiles et 15 suites de tests — pour le même contenu sous un autre nom. |

### D4 — L'onboarding « Méthode 2 » (CDC_11 §3)
| Option | Description |
|---|---|
| **(a) Hors P6.4, dette nommée avec foyer désigné** *(recommandée)* | P6.4 livre le **référentiel** (données, identifiant, gouvernance). La Méthode 2 est un **parcours applicatif** (formulaire public, vérification, publication, notifications) qui relève de CDC_11 et du portail **Next** — or ADR-011 programme déjà la migration du portail Blade vers Next. La faire deux fois serait du gaspillage. |
| (b) L'inclure en P6.4b | Le module double de taille : formulaire public non authentifié, workflow de validation, notifications, et un écran de vérification côté plateforme. |

### D5 — Données du découpage sanitaire
La Côte d'Ivoire compte **33 régions** et **113 districts sanitaires**. Le seeder actuel n'a que 12 structures, toutes d'Abidjan.
| Option | Description |
|---|---|
| **(a) Jeu partiel annoncé comme tel** *(recommandée)* | Les régions et districts **réellement nécessaires** aux 12 structures seedées (Abidjan et alentours), avec une mention explicite « jeu partiel de démonstration » dans le seeder et le guide — jamais présenté comme le découpage national complet. |
| (b) Découpage national complet | Exige une source officielle fiable ; sans elle, on produirait une liste **inventée** présentée comme nationale. C'est précisément ce que le corpus interdit (« ne jamais inventer »). |

---

## 5. Preuves attendues

- **G2** — MySQL live : migration additive sur base peuplée sans perte ; attribution + backfill idempotent de l'identifiant ; unicité par pays ; FK région/district ; cycle de gouvernance sur la projection ; **le dépôt d'un avis ne fait PAS diverger la projection** (le vecteur qui prouve D1) ; contrôles qualité sur données saines et sur anomalies injectées.
- **G3** — suite complète verte (référence actuelle : **456 tests / 14 517 assertions**), tests dédiés dans les deux sens, typecheck ×3.
- **G4** — **partie 2** de `GUIDE_TEST_REFERENTIELS.md` (convention : un domaine, un guide, des parties).
- **G5** — écrit, limites reportées telles quelles.

## 6. Dépendances

**Aucune prévue.**
