# Plan G1 — P6.8d : Assurances et organismes agréés (CDC_09 §8)

> Quatrième incrément de **P6.8** (étape 8 de l'ordre du §14), après **a** spécialités, **b** vaccins
> et calendrier, **c** maladies. Referme **T5** du G0 de P6.8.
>
> Statut : **G1 validé par écrit le 2026-08-15** ; incrément **validé G5 le 2026-08-15** (G4 propriétaire OK).
> Décisions propriétaire déjà prises (2026-08-15) : **F1** table de couvertures ·
> **F2** provenance déclarée dite comme telle · **F3** conventionnement hors périmètre.

---

## 1. G0 — ce que le code dit réellement

Audit conduit en lisant les migrations, `CarteCmuService`, les requêtes de validation, le mobile, le
portail, `SourceEtablissements` et le service de paiement Java. Cinq constats, dont **trois que le
plan G1 global de P6.8 n'annonçait pas**.

### U1 — La CMU est codée dans les **noms de colonnes** (T5 confirmé)

`membres_famille` porte `cmu_numero` (chiffré), `cmu_statut`
(`ENUM actif|expire|non_inscrit`, défaut `non_inscrit`) et `cmu_validite`. Conséquences vérifiées :

- **Un seul tiers payant est représentable**, et son nom est dans le schéma. Le §8.2 du CDC_06 en
  nomme six familles (assurances privées, mutuelles, entreprises, ONG et organismes internationaux,
  programmes gouvernementaux) ; aucune n'a de place.
- **La séquence du §8 est irréalisable** : « CNAM, **puis** assurances privées » suppose deux
  couvertures sur la même facture. Trois colonnes n'en portent qu'une.
- **`non_inscrit` est un statut qui dit qu'il n'y a pas de couverture** — une absence stockée comme
  une valeur, sur une ligne qui existe toujours.

### U2 — Le vocabulaire à adopter existe déjà, et il est côté Java

`TypePriseEnCharge` (service de paiement) porte exactement les six familles du §8.2 :
`CNAM, ASSURANCE, MUTUELLE, ENTREPRISE, ONG, PROGRAMME_GOUVERNEMENTAL`. **Il y a donc un vocabulaire
à adopter, pas à inventer** — précédent P6.8a, où les codes `orl` / `cardiologie` ont été promus
plutôt que réinventés. En inventer ici ferait diverger les deux moitiés de la plateforme sur la
nature d'un tiers payant.

### U3 — Le rôle `assurance` existe depuis P1 et n'a **rien à quoi se rattacher**

Il figure dans les 11 rôles de `RoleSeeder`, dans les rôles soumis à MFA (`config/mfa.php`) et dans
l'enum `Role` de `@masante/shared` (traduit « Assurance » / « Insurer »). Mais **aucun assureur
n'existe comme objet**, aucune permission ne lui est attachée, et `Portail\AuthController` ne
l'accepte pas à la connexion. *Un acteur est déclaré depuis quatre modules et n'a aucun objet, aucun
écran et aucune porte.*

### U4 — Le statut est **déclaré par le client**, et l'écran dit qu'il est **confirmé**

`StoreMembreRequest` / `UpdateMembreRequest` : `cmu_statut => nullable|in:actif,expire,non_inscrit`.
C'est la famille de **T2** (P6.8b, où `obligatoire` et `statut` étaient déclarés par l'intéressé).
**Mais la différence commande la solution** : en P6.8b le calcul était possible (âge + calendrier
publié), donc le statut est devenu un calcul. Ici il ne l'est pas — **l'étape 2 du §8.1
(« le système vérifie son éligibilité, API CNAM ») n'existe pas**, et rien dans ce projet ne peut
l'inventer.

Le défaut n'est donc pas dans la donnée, il est dans **ce que le système en dit** :

- `CarteCmuService` **signe** la déclaration (HMAC-SHA256) et produit un « code de présentation » ;
- l'écran mobile affiche « Présentez ce code à l'agent d'accueil. **Il confirme votre statut CMU** »
  et « la carte devient présentable comme **justificatif** une fois votre identité confirmée ».

La signature prouve que le message vient de MaSanté ; elle ne prouve rien sur le statut, qui est une
case remplie par l'intéressé. *C'est la famille du bouclier coché de P6.8b — ici sur le mot, pas sur
la donnée.* À la décharge du code existant, le document F2.3 le disait (« restitue le **statut
déclaré** ») : **la conception était honnête, l'écran ne l'est pas.**

### U5 — Le paiement ne peut rien confronter, et Laravel ne l'appelle jamais

`CreerFactureRequete.PriseEnChargeRequete` fait **déclarer par l'appelant** le type, le taux et le
plafond ; `MoteurPriseEnCharge` les valide (0–100, plafond ≥ 0) mais ne les rapproche d'aucun
contrat. Et **aucune ligne de Laravel n'appelle le service de paiement** (vérifié : zéro référence).
Brancher le référentiel sur la facturation serait donc une **intégration inter-services** — le
symétrique inverse d'ADR-019, où le paiement expose et la fraude consomme — pas la suite de P6.8.

### U6 — Le conventionnement existe déjà, en texte libre, et il est **déjà publié**

`structures_sanitaires.agrements_json` (placeholder du portail : « Agrément CNAM, Convention CMU »)
est **dans la projection gouvernée** des établissements (`SourceEtablissements:110`). Un fait de
conventionnement assureur ↔ établissement est donc **déjà diffusé comme donnée nationale**, sous
forme de chaîne libre. Hors périmètre (décision F3), mais écrit ici pour que le porteur existe.

---

## 2. Décisions propriétaire (2026-08-15)

### F1 — Le lien assuré est une **table de couvertures**

`couvertures_membre` : plusieurs couvertures cumulables par membre, chacune rattachée à un organisme
du référentiel. Les trois colonnes `cmu_*` sont **conservées** (ADR-024 ; une migration destructive
perdrait de l'information réelle pour un gain nul — précédent P6.4d-K2) et **backfillées**, mais
**plus personne ne les écrit** : motif exact de `vaccinations.statut` en P6.8b.

### F2 — La provenance est **déclarée**, et l'écran le dit

La couverture porte `provenance` (`declare` / `verifie`). `verifie` est **réservé et prêt à
activer** : sans API CNAM, **aucun chemin d'écriture ne peut le poser**, et un vecteur le prouve
(client envoyant `provenance: verifie` → `declare` enregistré). La carte, le portail et l'écran
mobile disent « statut **déclaré par l'assuré**, non vérifié auprès de l'organisme ». *Rien ne
disparaît de l'écran ; ce qui s'y affiche cesse de prétendre plus qu'il ne sait.*

### F3 — Le conventionnement reste hors périmètre

Le §8 du CDC_09 demande un registre d'**organismes**, pas la relation. Le rattacher ferait changer
l'empreinte du référentiel des établissements (module G5) et ouvrirait une question qu'aucun
document ne tranche : *qui déclare la convention, l'hôpital ou l'assureur ?* Constat U6 écrit,
porteur nommé, texte libre conservé.

---

## 3. Le point de conception

> **Une couverture n'est pas un attribut de la personne : c'est un contrat entre une personne et un
> organisme.**

Trois colonnes `cmu_*` disent l'inverse — elles font de la couverture une propriété du corps, comme
le groupe sanguin, et elles nomment l'organisme **dans le schéma**. C'est ce qui rend inexprimable la
situation la plus banale qui soit : un fonctionnaire à la CMU **et** à la mutuelle de son ministère.

Bascule strictement parallèle à celle de P6.8b (« un calendrier ne répond pas *ce vaccin est-il
fait ?* mais *qu'est-ce qui est dû pour cette personne-là, aujourd'hui ?* ») et à celle d'ADR-034
(« une plage biologique dépend de la personne ») : la variable est ici **l'organisme**, et il y en a
plusieurs.

---

## 4. Conception

### 4.1 Deux tables, et une seule sous gouvernance

| Table | Nature | Gouvernée ? |
|---|---|---|
| `organismes_assurance` | le **registre national** des organismes agréés (§8) | **oui** — `SourceAssurances` |
| `couvertures_membre` | le **contrat déclaré** par un citoyen | non — c'est du carnet, pas du référentiel |

La seconde n'entre pas sous gouvernance pour la même raison qu'`alertes_epidemiques` en P6.8c :
c'est un **fait individuel**, pas une donnée de référence. Un quatre-yeux sur la déclaration de sa
propre mutuelle n'aurait aucun sens.

### 4.2 `ASS` + 6 littéral, et l'agrément est **national**

Sixième application du motif (`ETS`, `PRO`, `MED`, `ANA`, `VAC`) : un organisme est une **instance**
— cette caisse-ci, cette mutuelle-là —, pas un terme de nomenclature ; il se numérote. Hors
`$fillable` : un client ne choisit pas un code national.

**Question reposée, pas recopiée** — P6.8c vient de rompre avec `pays_code` parce qu'une maladie est
un fait de nature. Un organisme d'assurance est **une personne morale agréée par un État** : son
agrément est délivré, suspendu et retiré par une autorité nationale. Donc `UNIQUE(pays_code, code)`,
et CI comme SN peuvent porter `ASS000001` — vecteur exigé, comme depuis P6.4a.

### 4.3 Le type est **adopté** du vocabulaire existant (U2)

`type` ENUM = `cnam`, `assurance`, `mutuelle`, `entreprise`, `ong`, `programme_gouvernemental` —
transcription exacte de `TypePriseEnCharge`. Les libellés citoyens sont **servis par l'API**, jamais
recopiés dans le mobile : le constat G-a de P6.4b a déjà récidivé trois fois (communes d'Abidjan,
libellés de statut vaccinal, catégories d'établissement) et il ne récidivera pas ici.

### 4.4 Ce qui entre dans la projection gouvernée : **la ligne entière**

Question reposée une quatrième fois. Vérification : **rien n'écrit automatiquement dans
`organismes_assurance`** — les couvertures citoyennes vivent dans `couvertures_membre`, comme les
prix relevés vivent dans `prix_pharmacie` (P6.6a). Aucun compteur d'assurés n'est ajouté, et c'est
**délibéré** : il serait utile à l'écran, il rendrait la réponse fausse.

**Deux vecteurs en miroir, aucun ne suffit seul** :

1. un citoyen déclare une couverture → l'empreinte du référentiel **ne change pas** ;
2. le statut d'agrément d'un organisme passe à `suspendu` → elle **change**.

### 4.5 Ce que la gouvernance protège ici

Retirer l'agrément d'un organisme, ou le renommer, change le sens de **toutes les couvertures déjà
rattachées** et de ce qu'un agent d'accueil lit à un guichet. C'est un acte d'autorité, pas une
correction de saisie — d'où le quatre-yeux du §10.

### 4.6 Le nom de l'organisme n'est **pas figé** — et c'est une rupture assumée

P6.6b, P6.7b et P6.8c figent le code et le libellé au moment de l'écriture. **La question est
reposée et la réponse est inverse**, pour une raison de nature :

> ceux-là inscrivaient un fait **historique** dans un carnet — une ordonnance signée, un résultat
> rendu, un antécédent daté. Une couverture est un **état courant** : « je suis assuré chez X
> aujourd'hui ». Si X est renommé, la phrase reste vraie sous le nouveau nom, et afficher l'ancien
> ferait porter au citoyen un nom que le guichet ne reconnaît plus.

Le nom vient donc du référentiel **à la lecture**. Corollaire tenu par le schéma : on **désactive**
un organisme, on ne le supprime pas (`actif`, comme `maladies`), et la clé étrangère est
`restrictOnDelete` — supprimer un organisme qui couvre des assurés doit échouer bruyamment.

### 4.7 Le lien est **obligatoire quand il est possible**, et l'écart est compté

Le registre livré est un jeu de démonstration : ses lacunes sont certaines. Imposer le référentiel
rendrait **indéclarable** la couverture d'un citoyen réellement assuré — le « mur » refusé en P6.8c
(*un contrôle qu'on ne peut pas satisfaire n'est pas une exigence*). Donc, **troisième application du
motif E4** : `organisme_assurance_id` nullable + `organisme_libelle` libre en repli, **écart compté
et affiché** (portail et API), badge « hors référentiel » à l'écran.

*Différence avec l'alerte épidémique, qui est la raison pour laquelle la question méritait d'être
reposée* : là-bas la porte reste ouverte parce qu'une **maladie émergente n'est dans aucune
nomenclature** ; ici, parce que **notre registre est incomplet**. Le premier est structurel, le
second est temporaire — et c'est justement pour cela que l'écart est **compté** : il doit tendre
vers zéro à mesure que le registre réel est chargé.

### 4.8 Le statut de la couverture devient un **calcul**, et `non_inscrit` disparaît

`statut` n'est plus une colonne déclarée mais un accesseur **pur, lisant sa seule ligne** (leçon
P6.8b : *une valeur qui change selon la façon dont on la demande n'est pas un calcul, c'est un
hasard*) :

- `resiliee_le` renseignée → `resiliee` ;
- `date_fin` révolue → `expiree` ;
- sinon → `active`.

**`non_inscrit` n'existe plus** : l'absence de couverture se dit par **l'absence de ligne**, pas par
une ligne qui affirme qu'il n'y en a pas. La valeur reste dans l'ENUM hérité de `membres_famille`,
qui n'est plus écrit.

### 4.9 Le contrat P2 reste intact — par **dérivation**, pas par recopie

`GET /membres` (module G5, avec son cache hors-ligne chiffré) expose `cmu_statut`, `cmu_validite`,
`cmu_numero_masque`. Après la bascule, ces trois valeurs sont **dérivées de la couverture CNAM/CMU**
par accesseurs (les colonnes restent en base, personne ne les lit plus).

**Pourquoi pas simplement laisser les colonnes** : elles seraient figées au jour du backfill pendant
que le citoyen modifie sa couverture → **deux vérités visibles dans la même réponse**. La dérivation
en laisse une seule. Le coût — un chargement de relation — est payé par un `with()` explicite dans
les contrôleurs concernés, et il sera vérifié qu'aucune liste ne déclenche de N+1.

### 4.10 Les gardes

| # | Garde | Tenue par |
|---|---|---|
| G1 | Permission **`assurance.referentiel`, portée par aucun rôle métier** (11ᵉ occurrence) | `can()` **en service** (piège P4 : routes Sanctum, permissions guard `web`) |
| G2 | Le client ne déclare **ni** le code national, **ni** `provenance: verifie`, **ni** le statut | service, sur **tous** les chemins d'écriture |
| G3 | Provenance de l'organisme **obligatoire** (5ᵉ application) | `NOT NULL` + contrôle qualité bloquant |
| G4 | Dates d'agrément incohérentes (`debut > fin`) refusées **par le moteur** | `CHECK` si MySQL 8.4 l'accepte ici (aucune colonne visée ne porte d'action référentielle), **sinon déclencheurs dans les deux dialectes** — *vérifié au G2, jamais supposé* |
| G5 | Doublon `(pays_code, code)` et nom d'organisme indiscernable dans un pays | index unique + contrôle qualité aussi strict que le moteur, **ni plus ni moins** (leçon P6.5a) |
| G6 | Une seule couverture vivante par (membre, organisme) | **applicatif, et annoncé comme tel** — MySQL 8 n'a pas d'index unique partiel (précédent du quota d'images P6.4c) |

*Pourquoi G1 n'est portée par aucun rôle, la raison propre à ce référentiel* : `gestionnaire_
etablissement` gère les conventions de **son** établissement, et le rôle `assurance` désigne
précisément **les organismes que ce registre recense**. Laisser l'un ou l'autre l'éditer ferait
décider de la liste des organismes agréés par **un assureur ou par un client d'assureur** — juge et
partie sur son propre agrément.

### 4.11 Honnêteté du contenu (3ᵉ application du motif `loinc` / CIM)

- `numero_agrement` **existe et reste vide** : les numéros d'agrément sont délivrés par une autorité
  (ministère, CIMA) et **je n'en invente pas**. Le contrôle qualité ne l'exige pas — l'exiger rendrait
  le référentiel impubliable dès le premier jour ; l'absence est **comptée et affichée**.
- **Le jeu livré ne nomme aucun assureur privé réel.** La CNAM est nommée parce que le corpus la
  nomme (CDC_06 §8.1) ; pour les cinq autres familles, les organismes portent des noms
  **explicitement fictifs**. Nommer NSIA ou une mutuelle réelle comme « agréée » affirmerait un
  agrément que personne n'a vu — et *une liste inventée qui a l'air juste ne se fait jamais
  corriger* (P6.4a, découpage sanitaire).
- `source` NOT NULL, nomenclature `demonstration | autorite_nationale | publication`. Tout le jeu
  livré est `demonstration`, et l'écran en affiche le **compte exact**.

---

## 5. Périmètre livré

1. **Migration** : `organismes_assurance`, `organisme_assurance_compteurs`, `couvertures_membre` ;
   garde G4 ; aucune colonne supprimée.
2. **Gouvernance** : `SourceAssurances` + une ligne dans `RegistreReferentiels` (le moteur ne bouge
   pas) ; permission `assurance.referentiel` ; écrans portail (liste, création, édition, publication)
   avec le bandeau de démonstration et les deux compteurs (sans agrément / hors référentiel).
3. **Seeder** de démonstration + `masante:assurances:backfill` (codes) et
   `masante:couvertures:backfill` (`cmu_*` → `couvertures_membre`), tous deux **idempotents**, avec
   un `--dry-run` qui **annonce exactement ce que le passage réel fera** (défaut trouvé au G2 de
   P6.8a).
4. **API** : `GET /v1/assurances` (public, filtre `?type=`, libellés de type servis ici),
   `GET/POST/PUT/DELETE /membres/{membre}/couvertures` (Policy existante : le propriétaire écrit, le
   délégué en lecture ne modifie pas), carte F2.3 **inchangée dans son contrat** mais alimentée par
   la couverture et **portant la mention de provenance**.
5. **Mobile** : le bloc « CMU (assurance santé) » du formulaire devient un écran **Couvertures
   santé** (liste, ajout, recherche d'organisme dans le référentiel, repli hors référentiel, silence
   hors ligne comme en P6.6b) ; la carte affiche l'organisme et la mention « déclaré par l'assuré ».

**Hors périmètre, avec porteur nommé** : le conventionnement (F3/U6) · le branchement du paiement
Java (U5) · un portail pour le rôle `assurance` (U3) — il suppose l'authentification d'une
**troisième population**, exactement la question qu'ADR-030 refuse d'étirer.

---

## 6. Preuves attendues

- **G3** — vecteurs dédiés écrits dans les deux sens ; suite complète verte ; typecheck ×3 ;
  `expo-doctor` ; **mutation obligatoire**, chaque garde du §4.10 neutralisée devant tuer exactement
  son vecteur — et **chaque mutation assertée appliquée avant d'être interprétée** (piège P6.7b).
- **G2 live MySQL** — schéma et gardes en base ; `1062` sur doublon ; CI et SN partagent `ASS000001` ;
  garde G4 prouvée par le moteur ; backfill dry-run = réel = rejeu muet, sur les **deux** commandes ;
  gouvernance à deux agents habilités, quatre-yeux refusé **par son motif** (leçon P6.8a) ; `UPDATE`
  direct sans effet sur le diffusé ; anti-substitution → 409 ; portail 403/200 ; les **deux vecteurs
  en miroir** du §4.4 ; client envoyant `provenance: verifie` et un code national → **ignorés** ;
  `GET /membres` répondant la **même chose** avant et après la bascule pour un membre inchangé ;
  **base restaurée compte par compte**.
- **G4** — `GUIDE_TEST_TRANSVERSES.md` **partie 4**, écrite avant le G4.

---

## 7. Limites qui seront annoncées

1. **Aucune vérification auprès d'un organisme** : l'étape 2 du §8.1 (API CNAM) n'existe pas.
   `provenance = verifie` est prêt à activer et **aucun chemin ne l'écrit**.
2. **Le paiement continue de faire déclarer taux et plafond** (U5) : le référentiel ne dit pas encore
   *ce que couvre* un contrat, seulement *qui* couvre. Porteur = incrément de paiement nommé, le même
   que celui des actes et tarifs (D2).
3. **Aucune garantie, aucun plafond, aucune exclusion** dans le référentiel : ce sont des clauses de
   contrat, pas des données d'agrément.
4. **Contenu = jeu de démonstration**, sans aucun numéro d'agrément et sans aucun assureur privé
   réel (§4.11). Charger le registre officiel est **de la donnée, zéro code** — et **tant que ce
   n'est pas fait, ce n'est pas un référentiel national**.
5. **Le rôle `assurance` reste sans porte** (U3), et le conventionnement reste en texte libre (U6).
6. **L1/L2 d'ADR-025 s'appliquent** : les écrans lisent la table, pas la version publiée.
