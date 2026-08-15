# Plan G1 — P6.8c Référentiel national des maladies (CDC_09 §8)

> Troisième incrément de P6.8 (étape **8** de l'ordre du §14). Referme **T3** du G0 de P6.8.
>
> Statut : **G1 en attente de validation écrite du propriétaire.**
> Quatre décisions propriétaire prises le 2026-08-15 : **E1** périmètre des consommateurs ·
> **E2** une maladie n'appartient à aucun pays · **E3** libellés multilingues livrés ·
> **E4** l'alerte épidémique garde une porte ouverte.

---

## 1. G0 — ce que le code dit réellement

Audit conduit en lisant les migrations, les contrôleurs, les seeders et les clients. Le plan de P6.8
annonçait **trois** vocabulaires ; il y en a **cinq**, et deux des constats sont d'une autre nature
que ce que T3 décrivait.

### V1 — La liste en dur du contrôleur est **décorative**

`Portail\AlerteEpidemiqueController::MALADIES` (ligne 28) fige sept libellés qui alimentent un
`<select>`. À dix lignes de là, `valider()` accepte `maladie => ['required', 'string', 'max:100']`.
Le commentaire du code l'avoue lui-même : « *champ libre malgré tout* ».

Le `<select>` **ressemble** donc à une contrainte sans en être une, et ce libellé n'est pas
administratif : il part au mobile et s'affiche **brut** dans la bannière d'alerte
(`BanniereAlerte.tsx:36` — « Alerte sanitaire · {maladie} ») et sur l'écran de détail
(`AlertesEcran.tsx:74`). Deux conséquences vérifiées :

- une faute de frappe s'affiche telle quelle à tous les utilisateurs d'une commune ;
- « combien d'alertes de choléra cette année ? » est **insoluble** — `Choléra`, `cholera` et
  `Choléra (suspicion)` sont trois chaînes, et le §4.4 assigne pourtant les statistiques au
  référentiel.

C'est la situation exacte que P6.8a a fermée pour les spécialités (« le vocabulaire est défini par ce
qui a été tapé en premier ») et que P6.4d a fermée pour le couple région/district.

### V2 — Le troisième vocabulaire n'a **aucun lecteur**… sauf celui qui lui donne le plus d'autorité

`symptomes.maladies_probables_json` est **absent** du `select` de `TriageController::symptomes()`
(`TriageController.php:31`, colonnes explicites) et n'apparaît dans **aucune** réponse de triage —
vérifié en cherchant le terme dans `TriageService` et dans tout le client mobile : zéro occurrence.

Son **seul** lecteur est `SourceSymptomesTriage::extraire()` (ligne 64) : cette colonne sort donc du
serveur **uniquement** par l'instantané publié du référentiel gouverné `symptomes_triage` et par
l'endpoint public `GET /referentiels/symptomes_triage`. *Le seul endroit où ces libellés circulent est
celui qui les présente comme une donnée de référence nationale, versionnée et scellée par une chaîne
d'audit.*

Et le contenu (`SymptomeSeeder`) mêle des maladies (`Paludisme`, `Méningite`, `Choléra`), des
syndromes (`Détresse respiratoire`, `Urgence neurologique`, `Problème cardiaque`) et un état
physiologique (`Grossesse`). C'est la **quatrième colonne dormante** du projet après
`acces_dossier.donnees_ajoutees` (P7-D0), `structures_sanitaires.specialites_json` (P6.4d-J4) et
`tokens_qr.used_by_etablissement` (P7-D2-F2) — et la seule qui soit **publiée**.

### V3 — Une promesse écrite dans le code désigne cet incrément

`2026_08_15_000002_referentiel_vaccins.php:82`, à propos de `vaccins.maladies_evitees` :

> « TEXTE et non table de maladies : **la CIM arrive en P6.8c**, et un lien vers une table qui
> n'existe pas encore serait une promesse, pas une donnée. »

C'est l'équivalent de **T8** pour les spécialités (`ProfessionsSante` : « `specialite` reste libre
jusqu'à l'étape 8 »). Une promesse écrite dans le code qui nomme son porteur — et le porteur, c'est
cet incrément.

### V4 — Une cinquième place, et c'est la **clinique**

`antecedents` (type `maladie_chronique`) porte la maladie dans `description` : **texte libre chiffré**.
Et c'est cette chaîne que `FicheVitaleService::maladiesChroniques()` (ligne 98) montre à un secouriste
**sans authentification** — carte vitale §5.1, corps du SMS du bouton SOS §5.2, bris de glace §5.3.

**À dire honnêtement : ce n'est pas le défaut de P6.8b.** Rien ici n'est présenté comme attesté, rien
n'est coché par l'intéressé pour se donner l'apparence d'un fait vérifié. Le défaut est
d'**exploitabilité**, pas de confiance : « diabète », « Diabète type 2 » et « DT2 » sont trois
chaînes, aucune n'est rapprochable d'une autre, et le §8 attend un vocabulaire.

### V5 — Aucun code CIM nulle part, et `alertes_epidemiques` n'est pas gouverné

Recherche de `cim10`, `cim-10`, `cim11`, `cim-11` dans `services/api`, `apps`, `packages` :
**zéro occurrence**. `RegistreReferentiels` (ligne 26) dit explicitement que `alertes_epidemiques`
n'est pas sous gouvernance — « ouverture ultérieure additive ». Ce plan **ne l'y ajoute pas** : ce
qui entre sous gouvernance ici, c'est le **vocabulaire des maladies**, pas le journal des alertes.

---

## 2. Décisions propriétaire (2026-08-15)

### E1 — Trois consommateurs branchés : alertes, antécédents, vaccins

Le référentiel est créé **et branché** sur les trois endroits où un humain nomme une maladie :
la santé publique (V1), le carnet (V4) et le référentiel des vaccins (V3). Les créer sans
consommateur aurait été le « socle à vide » que **P6.3-D3** avait explicitement refusé.

`symptomes.maladies_probables_json` (V2) reste **hors périmètre** — voir §7.

### E2 — Une maladie n'appartient à aucun pays

**Première rupture assumée** avec `ETS` / `PRO` / `MED` / `ANA` / `VAC`, qui numérotent tous des
objets **nationaux** (cet hôpital-ci, ce praticien-là) et portent `UNIQUE(pays_code, code)`.

Le paludisme est le paludisme partout — c'est la raison d'être même d'une classification
internationale. Écrire `pays_code` sur une maladie affirmerait dans le schéma que le paludisme
ivoirien diffère du paludisme sénégalais. La symétrie décorative a déjà été refusée en P6.4a
(pas de journal de non-réutilisation pour un établissement) et en P6.8a (pas de `SPE000001`).

**Ce qui est national, c'est la LISTE sous surveillance**, pas la maladie : les maladies à
déclaration obligatoire diffèrent d'un pays à l'autre. D'où une seconde table,
`maladie_surveillance`, portée **par pays** — et publiée dans le **même instantané** que les
maladies, motif des interactions de P6.6a et des strates de P6.7a (les publier séparément
laisserait une surveillance désigner une maladie absente de la version en vigueur).

### E3 — Les libellés multilingues sont livrés (§8 les exige explicitement)

Le §8 dit « Maladies : CIM-11 (et CIM-10 pour compatibilité), **libellés multilingues** ».
Différer aurait laissé une exigence du corpus sans porteur — et *une dette sans porteur ne se fait
jamais* (leçon L1/L2).

### E4 — Une alerte épidémique peut nommer une maladie absente du référentiel

Lien **facultatif**, libellé libre conservé, **écart compté et affiché**. Raison : une maladie
émergente n'est dans aucune nomenclature au moment où elle émerge, et le contenu livré ici est un jeu
de démonstration dont les lacunes sont certaines. Imposer le référentiel ferait payer ces lacunes à
une alerte **urgente** — argument de P6.6b (« l'imposer ferait de ses LACUNES un blocage clinique »).

La différence avec les spécialités n'est pas le principe mais la **conséquence d'un refus** : un
service refusé est ressaisi dix secondes plus tard ; une alerte sanitaire refusée pendant qu'on
convoque deux agents habilités n'est envoyée à personne.

---

## 3. Conception

### 3.1 `MAL` + 6, littéral et sans clé — et **le code CIM n'est pas ce code-là**

Sixième application du raisonnement `ETS`/`PRO`/`MED`/`ANA`/`VAC`, mais l'argument est **refait, pas
recopié** — parce que le critère posé en P6.8b (« instance → numéro ; terme de nomenclature → code
littéral ») plaiderait ici pour un code littéral, comme `orl` en P6.8a.

Il ne s'applique pas, et pour une raison propre à ce référentiel : **la CIM occupera la place du code
littéral** le jour où elle sera chargée. Fabriquer aujourd'hui `fievre_typhoide` créerait un
pseudo-code qui **ressemble** à un code de nomenclature et qui devrait ensuite cohabiter avec `A01.0`
— deux codes littéraux concurrents pour la même chose. Et contrairement à P6.8a, il n'y a **rien à
adopter** : les valeurs en base sont des phrases accentuées (« Fièvre typhoïde »), pas des codes.

Donc : `code` = **identifiant de ligne** (`MAL000001`, unique **globalement**, hors `$fillable`) ;
`code_cim10` / `code_cim11` = **la nomenclature**, et ils resteront **vides**. On ne mélange pas les
deux.

### 3.2 Ce que la table ne portera **pas**

Pas de `categorie` (infectieuse / chronique / …) : **aucun consommateur n'en a besoin**, et classer
une maladie n'est pas gratuit. Le seul besoin de regroupement réel — « quelles maladies surveille-t-on
ici ? » — est porté par `maladie_surveillance`. Ajouter une classification que personne ne lit serait
le socle à vide refusé en P6.3-D3, avec en prime une affirmation clinique non sourcée.

Pas de compteur d'alertes ni de cas : la projection prend la **ligne entière** (§3.4), et elle ne
peut le rester que si rien n'écrit automatiquement dans la table (précaution de P6.8a).

### 3.3 Schéma (migration additive, ADR-024)

**`maladie_compteurs`** — `cle` (PK, valeur unique `'global'`), `dernier`. *Une seule ligne, et le
commentaire dira pourquoi : le compteur des autres référentiels est indexé par pays parce que leurs
objets le sont ; celui-ci ne peut pas l'être sans contredire E2.*

**`maladies`** — `code` (`UNIQUE` **global**), `libelle` (le libellé officiel **français**, source
unique lue par tous les consommateurs), `code_cim10`, `code_cim11` (nullables, **vides**),
`description`, `source` (ENUM **NOT NULL**), `source_detail`, `actif`, timestamps.

**`maladie_libelles`** — `maladie_id` (cascade), `langue` (5), `libelle`, `principal` (bool),
`source`, `source_detail`. `UNIQUE(maladie_id, langue, libelle)`.

> **Le schéma rend la seconde vérité inexprimable.** Le libellé officiel français vit sur la ligne
> `maladies` et **nulle part ailleurs** : cette table ne porte que des libellés **alternatifs**
> (autres langues, synonymes de recherche comme « palu »). Aucune colonne `type` ne peut donc
> désigner deux libellés officiels concurrents pour la même langue pivot.
>
> `principal` désigne, **par langue**, celui qu'on affiche ; les autres ne servent qu'à retrouver.
> MySQL 8 n'ayant pas d'index unique partiel, « exactement un principal par langue » est tenu par le
> **contrôle qualité**, pas par le moteur — **annoncé comme tel et non déguisé en garantie du
> moteur**, précédent du quota de P6.4c.

**`maladie_surveillance`** — `maladie_id` (cascade), `pays_code` (2), `declaration_obligatoire`
(bool), `surveillance_prioritaire` (bool), `source` (**NOT NULL**), `source_detail`.
`UNIQUE(maladie_id, pays_code)`.

**Liens des consommateurs** (tous nullables, tous `nullOnDelete`) :

| Table | Colonnes ajoutées | Ce qui est figé |
|---|---|---|
| `alertes_epidemiques` | `maladie_id`, `maladie_code` | `maladie` (libellé) **repris du référentiel** quand un lien est fourni |
| `antecedents` | `maladie_id`, `maladie_code`, `maladie_libelle` | le code et le libellé du référentiel — **`description` n'est jamais touchée** |
| `vaccin_maladies` (table de liaison) | `vaccin_id`, `maladie_id`, `UNIQUE` du couple | — ; `vaccins.maladies_evitees` **conservée** (ADR-024) |

### 3.4 Ce qui entre dans la projection gouvernée

**Question reposée, pas recopiée** (méthode P6.6a / P6.8a / P6.8b) : *rien n'écrit automatiquement
dans `maladies`, `maladie_libelles` ni `maladie_surveillance`* — les alertes vivent dans
`alertes_epidemiques`, les antécédents dans le carnet. Donc **la ligne entière**, les libellés et la
surveillance, dans **un seul instantané**, la surveillance et les libellés portés **par code
national** jamais par identifiant technique.

**Conséquence assumée, écrite avant de coder** : rattacher un vaccin à des maladies fait **changer
l'empreinte du référentiel des vaccins** (`SourceVaccins` gagnera les codes des maladies évitées).
Ce n'est pas une dérive — même cas que `forme_juridique` en P6.4d et que `specialite` en P6.8a — et
**deux vecteurs en miroir le prouveront** (§5).

### 3.5 Les gardes

- **Permission `maladie.referentiel`, portée par aucun rôle métier — 10ᵉ occurrence.** Raison propre
  à ce référentiel : `sante_publique.manage` publie les alertes ; l'étendre au vocabulaire ferait de
  **l'auteur d'une alerte celui qui décide de ce qu'est une maladie**. Vérifiée `can()` **en
  service**, pas par le middleware spatie (routes Sanctum, permissions guard `web` — piège P4).
- **`source` obligatoire en base**, et le contrôle qualité **refuse de publier** une maladie, un
  libellé ou une surveillance sans provenance. 4ᵉ application du motif `loinc` (P6.7a) /
  `calendrier_vaccinal.source` (P6.8b).
- **Le contrôle qualité n'exige PAS de code CIM** — l'exiger rendrait le référentiel impubliable dès
  le premier jour. L'absence est **comptée et affichée**, pas transformée en blocage.
- **Le serveur ne devine jamais une maladie.** Aucun rapprochement automatique d'un texte libre vers
  un code : ce serait un **diagnostic posé par une machine** (CDC_00 §4). Le lien est **déclaré** par
  l'humain qui saisit, comme le prescripteur et le laboratoire en P6.7b. Vecteur dédié.
- **`antecedents.description` n'est jamais réécrite.** C'est la leçon de P6.7b, où la réécriture du
  prescripteur inscrivait le nom du **mauvais** médecin : le lien s'ajoute **à côté** des mots du
  patient, il ne les remplace pas.
- Résolution serveur via `preparerDonnees()` sur les **trois chemins d'écriture** et sur le `PUT`
  (précédent P6.8b, où le défaut préexistant de `update()` a été trouvé en passant).

### 3.6 Les consommateurs, un par un

**a) Alertes épidémiques.** Le `<select>` devient réel : il liste les maladies de la **version
publiée** (jamais la table — leçon L1/L2), triées surveillance prioritaire d'abord. Choisir une
maladie fige `maladie` depuis le référentiel ; le champ libre reste disponible sous une case
explicite. L'écran affiche en permanence le **compte des alertes hors référentiel**. Avant la
première publication, le `<select>` est vide **et l'écran le dit** — la saisie libre reste le
chemin, donc aucune alerte n'est bloquée (c'est E4 ; pas de 503 ici, à la différence de `GET /v1/maladies`).

**b) Antécédents.** `maladie_id` facultatif sur les trois chemins ; `FicheVitaleService` joint le
couple `{code, libelle}` du référentiel **à côté** de la description du patient, jamais à sa place.
*Un champ de plus sur un écran lu sans authentification se justifie ici parce qu'il ne révèle rien de
neuf : c'est la normalisation de ce qui s'y affiche déjà.* Écrans : sélecteur facultatif au mobile
(recherche ≥ 3 caractères, **silence hors ligne** — motif P6.6b) et au formulaire soignant Blade.

**c) Vaccins.** Table de liaison, multi-sélection au formulaire du portail, `maladies_evitees`
conservée. Tient la promesse de `referentiel_vaccins.php:82`.

### 3.7 Diffusion et refus bruyant

`GET /v1/maladies` (public, comme `/vaccins`, `/analyses`, `/specialites`) lit la **version
publiée** et répond **503 explicite** avant la v1 — motif P6.8b : *un repli sur la table laisserait
un oubli de publication invisible.* La réponse cite la version, comme `/medicaments/interactions`.

---

## 4. Ce qui sera livré

| Bloc | Contenu |
|---|---|
| Migration | 4 tables + 1 table de liaison + colonnes de lien ; aucune suppression |
| Gouvernance | `SourceMaladies` + ligne au `RegistreReferentiels` + permission `maladie.referentiel` |
| Commande | `masante:maladies:backfill` (dry-run → réel → rejeu, idempotente) |
| Services | `ServiceLienMaladie` (résolution + figeage, classe unique appelée par les 3 chemins) |
| API | `GET /v1/maladies` (public, version publiée, 503 avant v1) |
| Portail | écrans référentiel (liste / création / édition / libellés / surveillance) + `<select>` d'alerte + multi-sélection vaccins + sélecteur antécédent |
| Mobile | sélecteur facultatif de maladie au formulaire d'antécédent |
| Seeder | jeu de **démonstration** étiqueté `source='demonstration'`, incluant l'adoption des 7 libellés du contrôleur |

---

## 5. Vecteurs en miroir exigés (aucun ne suffit seul)

1. Publier une **alerte épidémique** → l'empreinte du référentiel des maladies **ne change pas**
   (rien n'écrit automatiquement dans la table : c'est ce qui autorise la projection entière).
2. Corriger le **libellé officiel** d'une maladie → l'empreinte **change**.
3. Rattacher un **vaccin** à des maladies → l'empreinte du **référentiel des vaccins** change
   (conséquence de §3.4, prouvée et non supposée).
4. Envoyer `maladie_id` **et** un libellé fantaisiste sur une alerte → la base porte le libellé
   **du référentiel**.
5. Écrire un antécédent « diabète » **sans** lien → aucun code n'apparaît (**le serveur ne devine
   pas**) ; avec lien → `description` **inchangée**, code et libellé figés à côté.
6. Corriger le libellé d'une maladie **après** un antécédent lié → l'antécédent **ne bouge pas**.
7. `UPDATE` direct en base → **aucun effet** sur `GET /v1/maladies` avant publication.

---

## 6. Preuves attendues

- **G3** — vecteurs dédiés écrits dans les deux sens ; suite complète verte ; typecheck ×3 ;
  `expo-doctor` ; **mutation obligatoire** : neutraliser chaque garde doit tuer **exactement** son
  vecteur, et *chaque mutation sera assertée appliquée avant d'être interprétée* (piège de P6.7b :
  une mutation qui ne s'applique pas ressemble exactement à un vecteur qui survit ; piège de P6.8a :
  deux mutations sur un même fichier, la seconde sauvegarde `.bak` écrasant l'originale).
- **G2 live MySQL** — schéma et contraintes en base ; `1062` sur doublon de code ; backfill
  dry-run = réel = rejeu ; gouvernance à **deux agents habilités** et refus du quatre-yeux
  **vérifié par son motif** (piège P6.8a) ; portail 403 / 200 ; **503 avant la v1** ; les sept
  vecteurs du §5 ; **base restaurée compte par compte**.
- **G4** — `GUIDE_TEST_TRANSVERSES.md` **partie 3**, écrite avant le G4.
- **ADR-037** — la décision E2 (une maladie n'appartient à aucun pays) et le §3.1 (le code national
  n'est pas le code CIM) y sont consignés.

---

## 7. Limites qui seront annoncées

1. **Aucun code CIM.** `code_cim10` / `code_cim11` existent et **restent vides**. CIM-10 et CIM-11
   sont des publications de l'OMS ; **je n'invente pas de codes**. Les charger sera de la **donnée,
   zéro code** — et tant que ce n'est pas fait, **ce n'est pas un référentiel national**, ce que
   l'écran affichera par un compte exact (3ᵉ application du motif `loinc`).
2. **Contenu = jeu de démonstration**, `source='demonstration'` sur chaque ligne, jamais attribué à
   une autorité.
3. **`symptomes.maladies_probables_json` n'est pas rattaché** (V2). Deux raisons, et la seconde est
   la vraie : le triage est refondu en **P10** (y toucher maintenant modifierait deux fois un module
   G5 — argument déjà tenu pour `specialite_hint` en P6.8a) ; et **personne ne lit cette colonne**,
   donc la rattacher aujourd'hui serait un socle à vide. Le porteur est **nommé (P10)** et un
   commentaire de `SourceSymptomesTriage` le dira — *nommer un manque ne le comble pas, mais un
   manque nommé ne s'oublie pas.*
4. **Aucun code SNOMED CT** (§8 les demande pour les symptômes) : licence de membre national requise.
5. **`alertes_epidemiques` n'entre pas sous gouvernance** — c'est un journal d'événements, pas un
   référentiel. Seul son **vocabulaire** est gouverné.
6. **L1/L2 d'ADR-025 s'appliquent** aux consommateurs antérieurs : le formulaire d'alerte et le
   carnet lisent la version publiée, mais rien ne réécrit rétroactivement les alertes et antécédents
   existants — *leur inventer un code serait un mensonge d'archive* (précédent L2).
7. **Multilingue livré comme structure**, avec un contenu français et quelques synonymes : aucune
   traduction ne sera attribuée à une autorité.
8. « Exactement un libellé principal par langue » est un **contrôle applicatif**, pas une garantie du
   moteur (MySQL 8 n'a pas d'index unique partiel) — dit, pas déguisé.
