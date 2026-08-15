# Guide de test — Référentiels transverses (CDC_09 §8)

> Étape **8** de l'ordre de construction du §14. Domaine à incréments successifs : chaque incrément
> ajoute une **partie** à ce fichier (règle propriétaire du 2026-08-11).
>
> | Partie | Incrément | Statut |
> |---|---|---|
> | **1** | **P6.8a — Spécialités médicales** | ✅ validé G5 (2026-08-14) |
> | **2** | **P6.8b — Vaccins et calendrier vaccinal** | ✅ validé G5 (2026-08-15) |
> | **3** | **P6.8c — Maladies (CIM)** | ✅ validé G5 (2026-08-15) |
> | **4** | **P6.8d — Assurances et organismes agréés** | ✅ validé G5 (2026-08-15) |
> | **5** | **P6.8e — Numéros d'urgence nationaux** | ✅ validé G5 (2026-08-15) |
>
> **P6.8 est COMPLET — l'étape 8 du §14 est terminée.** Ce guide devient la procédure de
> non-régression des cinq incréments.
>
> *Les « compléments du découpage » annoncés au plan de P6.8 sont **hors périmètre** (décision
> propriétaire C5) : ils sont de la donnée, pas du code, et restent à ADR-026.*

---

# Partie 1 — P6.8a, vocabulaire national des spécialités

## 1.1 Périmètre, et ce que cet incrément ne fait PAS

**Ce qu'il fait.** Il remplace cinq vocabulaires incompatibles par un seul, gouverné : une table
`specialites_medicales`, un code adopté de l'existant, et un formulaire de portail qui ne laisse
plus taper un code libre. Il rattache les deux consommateurs réels — les services d'établissement
et les fiches de praticiens.

**Ce qu'il ne fait PAS, et il faut le lire avant de tester :**

1. **L'orientation après triage (F1.5) n'est PAS branchée.** Le triage continue de produire un
   libellé (`ORL (Oto-Rhino-Laryngologie)`) que rien ne rapproche du code `orl`. C'est la limite
   principale, elle est **outillée et non refermée**, et son foyer est **P10** — qui refond déjà le
   triage. Trois commentaires de migration promettaient ce rapprochement depuis le Module 3 ; ils
   ont été corrigés en même temps que le code.
2. **`symptomes.specialite_hint` reste un libellé libre.** Même raison.
3. **Le contenu est un jeu d'ADOPTION, pas la nomenclature officielle.** Les treize termes seedés
   sont ceux qui existaient déjà dans ce projet. La nomenclature ivoirienne des spécialités
   reconnues relève d'un arrêté qui n'a pas été vu : la charger sera de la **donnée, zéro code**.
4. **Aucun écran mobile de gouvernance** — comme tous les référentiels depuis P6.3.
5. **Les limites L1/L2 d'ADR-025 s'appliquent** : P3 et P4 lisent les tables en direct, pas la
   version publiée. Après un `UPDATE` direct, `/v1/specialites` change mais
   `/v1/referentiels/specialites` non — **ce n'est pas un bug**, c'est le §1.2.4.

---

## 1.2 Prérequis

```bash
cd services/api
XDEBUG_MODE=off C:/wamp64/bin/php/php8.3.28/php.exe artisan migrate --force
XDEBUG_MODE=off C:/wamp64/bin/php/php8.3.28/php.exe artisan db:seed --class=SpecialiteMedicaleSeeder --force
XDEBUG_MODE=off C:/wamp64/bin/php/php8.3.28/php.exe artisan db:seed --class=PortailRolesSeeder --force
XDEBUG_MODE=off C:/wamp64/bin/php/php8.3.28/php.exe artisan masante:specialites:backfill
XDEBUG_MODE=off C:/wamp64/bin/php/php8.3.28/php.exe artisan serve --host=0.0.0.0 --port=8000
```

**Comptes.** Un **gestionnaire d'établissement** (rôle `gestionnaire_etablissement`, rattaché à une
structure) et un compte portant **`specialite.referentiel`**, accordée **nominativement** —
elle n'est portée par aucun rôle métier. `admin_ivoirsante` la reçoit par le `syncPermissions(all)`
du seeder ; c'est dit honnêtement dans son commentaire.

> **Piège** : la permission n'existe en base qu'après `PortailRolesSeeder`, et spatie met les
> permissions en cache — un `forgetCachedPermissions()` (ou un redémarrage) est nécessaire après
> une attribution nominative.

---

## 1.3 Scénarios portail (libellés exacts)

### §1.3.1 — Le formulaire de service ne laisse plus taper un code

1. Se connecter au portail avec le **gestionnaire**, aller dans **Services** → **Nouveau service**.
2. Le champ **« Spécialité »** est une **liste déroulante**, plus une zone de saisie.
   Sous le champ : *« Vocabulaire national des spécialités (CDC_09 §8). »*
3. Chaque option affiche **le libellé et le code** : `Médecine générale (medecine_generale)`.

**Attendu** : aucune saisie libre possible, aucune `datalist`.

### §1.3.2 — Le libellé d'un praticien vient du référentiel

1. **Praticiens** → **Nouveau praticien**. Le champ **« Spécialité »** est une liste déroulante qui
   affiche les **libellés seuls** (pas les codes — c'est ce que le citoyen lira).
2. Sous le champ : *« Vocabulaire national (CDC_09 §8). Le libellé affiché aux patients vient du
   référentiel. »*
3. Choisir **Oto-rhino-laryngologie (ORL)**, enregistrer, rouvrir la fiche.

**Attendu** : la fiche porte le libellé **officiel**, pas celui qu'un agent aurait tapé.

### §1.3.3 — Une fiche héritée annonce son écart

Sur une fiche antérieure à P6.8a et non rattachée, le texte d'aide ajoute :
*« Fiche actuellement non rattachée — libellé enregistré : « … ». »*

### §1.3.4 — L'écran du vocabulaire

Tableau de bord → carte **« Spécialités »** (visible seulement avec `specialite.referentiel`).

- Titre : **« Vocabulaire national des spécialités »**.
- Bandeau jaune : *« Ce que vous modifiez ici est le **contenu de travail**… »*
- Bandeau bleu s'il y a des écarts : *« N fiche(s) de praticien affichent un libellé différent de
  celui de leur terme… Ce libellé **n'a délibérément pas été réécrit** … »*
- Bandeau rouge s'il reste des services orphelins.
- Colonnes : Code · Libellé · Nature · Profession · Services · Praticiens.

**À l'ajout d'un terme**, un bandeau d'avertissement précède le formulaire :
*« **Le code ne pourra plus être modifié.** … Un terme qui ne convient plus se *désactive*. »*

---

## 1.4 Scénarios backend (curl reproductibles)

| # | Vecteur | Commande | Attendu |
|---|---|---|---|
| **W9** | Code hors vocabulaire | `POST /portail/services` avec `specialite=cardio` | 302 + message **« Ce code ne fait pas partie du vocabulaire national des spécialités. Termes admis : … »**, **rien créé** |
| **W10** | Rattachement envoyé par le client | `specialite=orl` **et** `specialite_id=<id de cardiologie>` | en base `specialite_id` = celui d'**orl** |
| **W11** | Diffusion publique | `GET /api/v1/specialites` **sans jeton** | 200, 13 termes, `don_sang` présent |
| **W11b** | Filtre | `GET /api/v1/specialites?nature=activite` | `biologie`, `pharmacie`, `don_sang` — pas `cardiologie` |
| **W12** | **Contrat P3 (G5) intact** | `GET /api/v1/structures?specialite=orl` | 200 avec des structures |
| **W13** | Le code du don de sang vient du serveur | `GET /api/v1/don-sang` (Sanctum) | `regles.specialite_centre = "don_sang"` |
| **W8** | Habilitation | `GET /portail/specialites` gestionnaire / habilité | **403** / **200** |

### Gouvernance (§10) — le cycle complet

```bash
# A propose, B publie — deux comptes distincts, motif ≥ 10 caractères
curl -X POST -H "Authorization: Bearer $TA" -H "Content-Type: application/json" \
  $API/v1/referentiels/specialites/propositions -d '{"motif":"Vocabulaire initial"}'   # 201
curl -X POST -H "Authorization: Bearer $TA" ... /publication                            # 403 (A n'a pas `publier`)
curl -X POST -H "Authorization: Bearer $TB" ... /publication -d '{"motif":"Mise en vigueur"}'  # 200
```

**Anti-substitution** — le contrôle central d'ADR-025, rejoué sur ce référentiel :

```bash
# après la proposition, modifier le contenu en base, puis tenter de publier
```
→ **409** : *« Le contenu de « specialites » a changé depuis la proposition n°N : ce qui serait
publié n'est plus ce qui a été relu. »*

**`UPDATE` direct sans effet sur le diffusé** : modifier `specialites_medicales.libelle` en SQL puis
`GET /api/v1/referentiels/specialites` → **le libellé diffusé ne bouge pas**. C'est le but (§1.2.4).

---

## 1.5 Invariants base de données

```sql
-- a. unicité par pays (le pays qualifie, il ne s'écrit pas dans le code)
SHOW INDEX FROM specialites_medicales;            -- uq_specialite_pays_code (pays_code, code)
INSERT INTO specialites_medicales (pays_code,code,libelle,nature,ordre,actif)
  VALUES ('CI','cardiologie','Cardio bis','specialite_medicale',99,1);   -- ERROR 1062 « CI-cardiologie »

-- b. deux pays partagent un code
INSERT ... VALUES ('SN','cardiologie', ...);      -- accepté

-- c. les FK ne détruisent rien
--    medecins.specialite_id et services_etablissement.specialite_id : ON DELETE SET NULL

-- d. aucun service orphelin après backfill
SELECT COUNT(*) FROM services_etablissement WHERE specialite_id IS NULL;   -- 0

-- e. écarts de libellé : signalés, jamais réécrits
SELECT COUNT(*) FROM medecins m JOIN specialites_medicales s ON s.id = m.specialite_id
 WHERE s.libelle <> m.specialite;                  -- > 0 attendu sur une base héritée
```

### Les deux vecteurs en miroir — aucun ne suffit seul

| Action | Empreinte du vocabulaire | Pourquoi |
|---|---|---|
| Modifier le **tarif** d'un praticien | **inchangée** | le vocabulaire ne porte aucune valeur dérivée de l'usage |
| Renommer le **libellé** d'un terme | **change** | c'est une donnée d'autorité |

Et la conséquence **assumée et annoncée avant de coder** : rattacher `medecins.specialite` fait
**changer l'empreinte du référentiel des PROFESSIONNELS** (`specialite` y figure depuis P6.5a).
Ce n'est pas une dérive — même cas que `forme_juridique` en P6.4d.

---

## 1.6 Le backfill

```bash
artisan masante:specialites:backfill --dry-run   # « 26 service(s) et 28 praticien(s) seraient rattachés. »
artisan masante:specialites:backfill             # mêmes chiffres, écrits
artisan masante:specialites:backfill             # « 0 service(s) et 0 praticien(s) » — idempotent
```

Un praticien est rattaché **par son service**, jamais par ressemblance de libellé : « Maternité » ne
mène par aucun rapprochement textuel à `gynecologie`. Et **aucun libellé n'est réécrit** — c'est ce
que l'établissement affiche (leçon de P6.7b : un serveur qui réécrit une déclaration humaine se
trompe avec autorité).

---

## 1.7 Commandes de qualité (G3)

```bash
cd services/api && XDEBUG_MODE=off C:/wamp64/bin/php/php8.3.28/php.exe artisan test
cd ../.. && pnpm typecheck
cd apps/mobile && npx expo-doctor
```

**Référence de la validation** : 789 tests / 15 421 assertions, 0 échec · typecheck ×3 vert ·
expo-doctor 18/18 · **mutation : 4 gardes neutralisées → exactement 4 vecteurs morts**.

---

## 1.8 Checklist de clôture

- [ ] Le champ « Spécialité » du formulaire de service est une **liste**, sans saisie libre
- [ ] Un code hors vocabulaire est **refusé** et **rien n'est créé**
- [ ] Un `specialite_id` envoyé par le client est **ignoré**
- [ ] Le libellé d'un praticien est celui du **référentiel**, pas celui envoyé
- [ ] Un terme **désactivé** ne peut plus être rattaché
- [ ] Le **code n'est pas modifiable** après création
- [ ] `GET /v1/specialites` répond **sans jeton** et contient `don_sang`
- [ ] `GET /v1/structures?specialite=orl` répond toujours (**contrat P3 intact**)
- [ ] `GET /v1/don-sang` expose `regles.specialite_centre`
- [ ] Gestionnaire **403** / habilité **200** sur `/portail/specialites`
- [ ] Cycle §10 : proposition (A) → publication (B), **A seul ne peut pas publier**
- [ ] **Anti-substitution → 409**
- [ ] `UPDATE` direct **sans effet** sur le diffusé
- [ ] Les deux vecteurs en miroir tiennent (tarif → inchangée, libellé → change)
- [ ] Backfill : dry-run = passage réel, rejeu = 0
- [ ] **Base restaurée** après le test

---

## 1.9 Pièges rencontrés

1. **Le dry-run mentait.** Il annonçait « 0 praticien » puis le passage réel en rattachait 28 : en
   simulation le service n'est pas écrit, donc son `specialite_id` est encore NULL. *Trouvé au G2
   live, pas par les tests* — corrigé, avec un vecteur qui compare les deux annonces.
2. **Restaurer deux mutations visant le même fichier.** La seconde sauvegarde `.bak` écrasait
   l'originale avec la version **déjà mutée** : la restauration réintroduisait la première mutation.
   Toujours vérifier l'état du fichier après restauration, pas seulement la présence du `.bak`.
3. **Un test de quatre-yeux peut échouer pour la mauvaise raison.** Le premier essai a répondu 422
   (motif trop court) et non 403 : il ne prouvait rien. Un refus doit être vérifié **par son motif**,
   pas seulement par son échec (même famille que le contrôle de révocation de P6.5b).
4. **Les routes du portail sont en français** : `/portail/services/creer`, mais
   `/portail/specialites/nouveau`. Une URL fausse tombe sur la page SPA (200, 869 Ko de JS) et non
   sur un 404 — un `grep` sur le formulaire renvoie alors 0 sans que rien n'ait échoué.
5. **Le client MySQL en ligne de commande refuse la connexion** dans cet environnement
   (`'root'@'@localhost'`) : passer par `artisan tinker --execute` pour tout le SQL du G2.
6. **Les tests hérités doivent recevoir la nouvelle précondition.** Trois suites créaient un service
   ou une fiche via le portail : elles seedent désormais `SpecialiteMedicaleSeeder`. Le test de
   création de praticien a été **renforcé** (il vérifie que le libellé vient du référentiel), pas
   assoupli.

---

# Partie 2 — P6.8b : Vaccins et calendrier vaccinal national (CDC_09 §8)

> Deuxième incrément de P6.8. **Referme T2** du G0 : « le statut d'une vaccination est déclaré par
> le client ». Le G0 de cet incrément a montré que le défaut était plus grave que T2 ne le disait.
>
> Guide écrit **avant le G4**, conservé après le G5 comme procédure de non-régression.

## 2.1 Ce qu'il faut comprendre avant de tester

Deux colonnes de `vaccinations` étaient remplies par le client — `obligatoire` et `statut` — et ce
sont **exactement, et uniquement, les deux que lisait la fiche vitale d'urgence**, l'écran montré à
un secouriste **sans authentification** (carte vitale, SMS du bouton SOS, bris de glace), sous un
bloc « Vaccinations essentielles » et une icône de bouclier coché.

Conséquence en deux sens :

- n'importe qui cochant « obligatoire » + « fait » faisait apparaître au secouriste une vaccination
  **présentée comme attestée** ;
- un BCG **réellement administré**, saisi sans cocher la case, en était **absent**.

S'y ajoutait que `statut` était écrit **une seule fois**, à la saisie, et que rien ne repassait
jamais dessus : un « à faire » dépassé depuis six mois restait « à faire », avec une pastille de
couleur qui lui donnait l'autorité d'un calcul.

**Ce que l'incrément change** : le statut est **calculé**, le caractère obligatoire est **lu au
calendrier national publié**, et la fiche vitale s'appuie désormais sur `source` — le signal que le
serveur écrit lui-même depuis P7-D0 et que le client ne peut pas falsifier.

**Une chose à savoir sur le contenu** : le calendrier livré est un **jeu de démonstration** qui
reprend la structure du calendrier élargi de vaccination de l'OMS. Ni l'arrêté du PEV ivoirien ni le
calendrier officiel du Ministère n'ont été vus. Chaque échéance porte `source = 'demonstration'`, et
les écrans en affichent le **compte exact**. Tant que ce nombre n'est pas à zéro, **ce n'est pas un
calendrier national** — et c'est écrit à l'écran, pas seulement ici.

## 2.2 Préparation

```bash
cd services/api
PHP="C:/wamp64/bin/php/php8.3.28/php.exe"

XDEBUG_MODE=off $PHP artisan migrate
XDEBUG_MODE=off $PHP artisan db:seed --class=VaccinSeeder
XDEBUG_MODE=off $PHP artisan masante:vaccins:backfill --dry-run   # aperçu
XDEBUG_MODE=off $PHP artisan masante:vaccins:backfill             # VAC000001…
```

**La mise en vigueur est une étape à part**, et c'est voulu : le seeder **ne publie rien**. Publier
depuis un seeder contournerait le quatre-yeux du §10 dès le premier jour. Il faut donc **deux
comptes habilités** (`referentiel.proposer` et `referentiel.publier`), comme pour les seuils de
mesure en L1+L2.

## 2.3 Ce qui doit se produire AVANT toute publication

| # | Geste | Attendu |
|---|-------|---------|
| V1 | `GET /api/v1/vaccins` | **503** explicite, jamais une liste vide |
| V2 | `GET /api/v1/membres/{id}/calendrier-vaccinal` | **503**, message nommant l'absence de version |
| V3 | Créer une vaccination **avec** `vaccin_id` | **422** sur `vaccin_id` |
| V4 | Créer une vaccination **sans** lien | **201** — le carnet fonctionne |

*Le refus est bruyant, jamais un repli sur la table : un repli laisserait un oubli de publication
passer inaperçu, tout fonctionnerait, et personne ne saurait la garantie inactive.*

## 2.4 Le statut ne se déclare plus, et ne se périme plus

| # | Geste | Attendu |
|---|-------|---------|
| V5 | `POST /vaccinations` avec `statut: fait`, **aucune** date d'administration, `date_rappel` d'il y a un an | Réponse `statut` = **`en_retard`** |
| V6 | La même ligne, relue le lendemain | Toujours cohérente — **aucune écriture n'a eu lieu** |
| V7 | Une dose **administrée** dont l'échéance est dépassée | **`fait`** — l'administration l'emporte |
| V8 | `PUT` avec `statut: en_retard` sur une dose administrée | Reste **`fait`** |
| V9 | En SQL : `SELECT statut FROM vaccinations WHERE id = …` | **NULL** sur les lignes neuves — la colonne n'est plus écrite |

*V9 est le vecteur qui prouve qu'il n'y a plus de seconde vérité à maintenir. Les lignes
**antérieures** conservent leur valeur : la réécrire serait un mensonge d'archive.*

## 2.5 `obligatoire` se lit, il ne se coche plus

| # | Geste | Attendu |
|---|-------|---------|
| V10 | Rattacher au **Rotavirus dose 1** en envoyant `obligatoire: true` | Enregistré **`false`** (recommandé au calendrier) |
| V11 | Rattacher au **Pentavalent dose 1** en envoyant `obligatoire: false` | Enregistré **`true`** (obligatoire au calendrier) |
| V12 | Envoyer un `vaccin_code` inventé avec un `vaccin_id` valide | Le code enregistré est celui du **référentiel** |
| V13 | Envoyer `numero_dose: 9` sur un vaccin à 3 doses | **422** nommant le schéma réel |

## 2.6 Le calendrier dépend de la personne

| # | Geste | Attendu |
|---|-------|---------|
| V14 | Membre de **5 semaines** → Penta dose 1 | **`a_venir`** — ce n'est **pas** un retard |
| V15 | Membre de **7 semaines** → Penta dose 1 | **`a_faire`** (délai de grâce en cours) |
| V16 | Membre de **400 jours** → Rotavirus dose 1 | **`hors_delai`** — la fenêtre de rattrapage est passée |
| V17 | Dose administrée et **rattachée** | **`fait`**, avec l'identifiant de la ligne de carnet |
| V18 | Dose administrée mais **non rattachée** | L'échéance reste due — *assumé : le rattachement est ce qui distingue un texte d'un fait vérifiable* |

## 2.7 La gouvernance mord

| # | Geste | Attendu |
|---|-------|---------|
| V19 | `UPDATE calendrier_vaccinal SET age_jours_du = 200` puis relire | **Aucun effet** sur le calendrier diffusé |
| V20 | Publier une version corrigée (deux agents) | La réponse **change**, et cite le **nouveau numéro de version** |
| V21 | Le proposant tente de publier | **Refusé** — vérifier **par le motif**, pas seulement par l'échec |
| V22 | Portail : compte non habilité sur `/portail/vaccins` | **403** |
| V23 | Publier un vaccin dont le calendrier est **incomplet** | **Refusé**, message nommant l'écart doses/échéances |
| V24 | Publier une échéance **sans provenance** | **Refusé** |
| V25 | Insérer une échéance dont le rattrapage se ferme avant l'âge dû (SQL direct) | **Refusée par le moteur** (trigger) |

*V19 sans V20 ne prouverait que « plus rien ne fonctionne » : les deux vont ensemble.*

## 2.8 La fiche vitale — décision propriétaire W2-bis

| # | Geste | Attendu |
|---|-------|---------|
| V26 | Vaccination **faite**, `obligatoire` non coché, `source = medecin` | **Présente** à la fiche vitale, `atteste = true` |
| V27 | Vaccination **faite**, saisie par la famille | **Présente**, `atteste = false` |
| V28 | Vaccination **prévue mais pas faite** | **Absente** de la fiche vitale |
| V29 | Ordre d'affichage | Ce qui est **attesté** remonte en premier |

*V26 est le vecteur central : sous l'ancien critère, cette ligne était **invisible** du secouriste.*

## 2.9 La notification d'échéance (décision propriétaire W3)

| # | Geste | Attendu |
|---|-------|---------|
| V30 | Membre de **42 jours** exactement, `masante:vaccins:echeances` | **1 seule** notification, alors que 4 vaccins sont dus |
| V31 | Contenu de la notification | **Aucun nom de vaccin** — elle dit combien, jamais quoi |
| V32 | Rejouer la commande le même jour | **0** nouvelle notification |
| V33 | Membre de 43 jours | **0** notification (ni due ce jour, ni fin de grâce) |
| V34 | Membre de **57 jours** (42 + 14 + 1) | 1 notification, `en_retard = true` |
| V35 | Un **délégué en lecture** existe | **2** notifications |
| V36 | Après la commande | `vaccinations` et `rappels` **inchangées** — le calendrier n'écrit pas dans le carnet |

## 2.10 Mobile (Expo Go)

- [ ] Le formulaire d'une vaccination **n'a plus** de menu « Statut »
- [ ] Il **n'a plus** d'interrupteur « Vaccin obligatoire »
- [ ] Saisir 3 caractères propose les vaccins du calendrier national
- [ ] Choisir un vaccin **fige le nom** et propose **les doses que le calendrier prévoit**
- [ ] « Détacher » rend la saisie libre
- [ ] **En mode avion**, le champ se tait : la saisie libre reste entière, **aucune erreur affichée**
- [ ] La fiche d'un membre porte « Calendrier vaccinal » → « Voir le calendrier »
- [ ] L'écran groupe : En retard · À faire · À venir · Déjà fait · Fenêtre passée
- [ ] L'**avertissement de démonstration est en tête**, jamais en bas de page
- [ ] Le pied de page cite la **version** du calendrier

## 2.11 Checklist G4

- [ ] 503 explicite avant la première publication (API **et** calendrier d'un membre)
- [ ] Statut **calculé**, jamais déclaré, jamais périmé (V5→V9)
- [ ] `obligatoire` lu au calendrier (V10, V11)
- [ ] Deux âges → deux réponses (V14, V15)
- [ ] `UPDATE` direct sans effet **et** publication corrigée effective (V19, V20)
- [ ] Quatre-yeux vérifié **par son motif** (V21)
- [ ] Fiche vitale : rien ne disparaît, l'attestation est distinguée (V26→V29)
- [ ] Une notification, sans nom de vaccin, idempotente (V30→V32)
- [ ] Le calendrier **n'écrit rien** dans le carnet (V36)
- [ ] Écrans mobiles conformes au §2.10
- [ ] **Base restaurée** après le test

## 2.12 Pièges rencontrés

1. **`statut` était `NOT NULL` sans défaut.** Cesser de l'écrire faisait échouer toute insertion.
   La colonne est devenue **nullable** — ce qui est la déclaration juste : plus personne ne l'écrit.
2. **Un accesseur ne s'applique pas à une colonne non chargée.** Une ligne fraîchement créée était
   renvoyée **sans statut**. Corrigé par `$appends`, pour que le contrat de lecture reste
   exactement celui d'avant la bascule (cache hors ligne de P2, écrans validés G5).
3. **`obligatoire` doit rester `$fillable`.** Il est posé par le service **dans le tableau validé**,
   qui traverse `fill()` : l'en retirer aurait fait écarter silencieusement la valeur lue au
   calendrier. Même piège qu'en P6.7b — et la garantie ne vient pas de `$fillable`, elle vient du
   service, avec un vecteur par couche.
4. **Un vecteur qui ne teste que le validateur ne teste pas le service.** « Le client ne peut pas
   déclarer le statut » reste vert garde retirée, car `validate()` écarte déjà les clés non
   déclarées. **Dédoublé** : un vecteur HTTP, un vecteur appelant le service directement, comme le
   ferait un import (leçon de la mutation de P6.6b).
5. **`membres_famille.date_naissance` est `NOT NULL`.** La branche « âge inconnu » du service n'est
   donc **pas atteignable** par ce chemin. Écrire un vecteur HTTP qui « prouve » ce cas aurait
   prouvé le contraire de ce qu'il annonce : il est testé **sur la règle pure**, et le vecteur HTTP
   affirme la contrainte du schéma.
6. **Un test hérité affirmait le comportement retiré** (un `PUT` du statut le changeait). Il a été
   **réécrit pour dire la garantie neuve**, pas corrigé pour passer (précédent P6.4d).
7. **Les routes typées d'Expo ignorent un écran neuf** tant qu'`expo start` n'a pas régénéré
   `.expo/types/router.d.ts` — piège déjà rencontré en P7-D1.
8. **Un trou antérieur trouvé en passant** : `update()` n'appelait pas `preparerDonnees()`. Un `PUT`
   pouvait donc changer `laboratoire_id` **sans repasser** par le contrôle « ce n'est pas un
   laboratoire » de P6.7b. Corrigé pour toutes les sections du carnet.

---

# Partie 3 — P6.8c : Référentiel national des maladies (CDC_09 §8)

> Troisième incrément de P6.8. Referme **T3** du G0 — la liste de maladies en dur dans un contrôleur
> et les vocabulaires libres qui l'entouraient. ADR-037.

## 3.1 Ce qu'il faut comprendre avant de tester

**Le menu déroulant des alertes ressemblait à une contrainte et n'en était pas une.** Sept libellés
étaient figés dans `AlerteEpidemiqueController::MALADIES` pour alimenter un `<datalist>`, pendant que
la validation acceptait n'importe quelle chaîne de 100 caractères — le commentaire du code l'avouait
lui-même. Ce libellé part **brut** dans la bannière du mobile : une faute de frappe s'affichait telle
quelle à toute une commune, et « combien d'alertes de choléra cette année ? » restait insoluble.

**Trois choses à ne pas confondre en testant :**

| Ce qu'on teste | Ce que ça veut dire |
|---|---|
| le **code national** (`MAL000001`) | un identifiant de **ligne**, attribué par la plateforme |
| le **code CIM** (`code_cim10`) | la **nomenclature de l'OMS** — elle n'a **pas** été chargée, et **rien n'a été inventé** |
| le **libellé officiel** | il vit sur `maladies.libelle` et **nulle part ailleurs** |

**Une maladie n'appartient à aucun pays** (décision E2) : `MAL000001` est unique **globalement**,
à la différence de `ETS`, `PRO`, `MED`, `ANA` et `VAC`. Ce qui est national, c'est la **surveillance**
— ce que le pays suit et ce qu'il faut déclarer —, dans sa propre table.

**Le lien d'une alerte est facultatif** (décision E4) : une maladie émergente n'est dans aucune
nomenclature au moment où elle émerge. L'écart n'est donc pas supprimé, il est **compté et affiché**.

## 3.2 Préparation

```bash
cd services/api
XDEBUG_MODE=off php artisan migrate
XDEBUG_MODE=off php artisan db:seed --class=VaccinSeeder       # les vaccins d'abord
XDEBUG_MODE=off php artisan masante:vaccins:backfill
XDEBUG_MODE=off php artisan db:seed --class=MaladieSeeder      # puis les maladies (elles s'y relient)
XDEBUG_MODE=off php artisan db:seed --class=PortailRolesSeeder # cree la permission maladie.referentiel
```

> **Piège** : la permission `maladie.referentiel` n'existe qu'**après** `PortailRolesSeeder`. Sans
> lui, tout appel à `can('maladie.referentiel')` lève `PermissionDoesNotExist` (déjà rencontré en
> P6.5a).

## 3.3 Le backfill

```bash
XDEBUG_MODE=off php artisan masante:maladies:backfill --dry-run   # annonce, n'ecrit rien
XDEBUG_MODE=off php artisan masante:maladies:backfill             # MAL000001 ... MAL000021
XDEBUG_MODE=off php artisan masante:maladies:backfill             # rejeu : rien a faire
```

**Ce que l'aperçu annonce doit être exactement ce que fera le passage réel** — le G2 de P6.8a avait
trouvé l'inverse.

Le backfill rattache aussi les alertes **existantes**, mais **par égalité exacte seulement** (casse et
accents normalisés). Vecteur à rejouer : une alerte « Cholécystite » à côté d'une alerte « choléra »
→ la seconde est rattachée, la première **jamais**. *Mesurer une distance entre deux noms de maladies
pour décider laquelle l'agent voulait dire serait deviner une maladie* (CDC_00 §4).

## 3.4 Les gardes du moteur

```sql
-- Doublon de code national
INSERT INTO maladies (code, libelle, source, actif, created_at, updated_at)
VALUES ('MAL000001','Test','demonstration',1,NOW(),NOW());
-- attendu : ERROR 1062 ... 'maladies.uq_maladie_code'

-- Doublon de libelle (deux maladies indiscernables dans une liste d'alerte)
INSERT INTO maladies (code, libelle, source, actif, created_at, updated_at)
VALUES ('MAL999999','Paludisme','demonstration',1,NOW(),NOW());
-- attendu : ERROR 1062 ... 'maladies.uq_maladie_libelle'

-- Un libelle alternatif qui RECOPIE le libelle officiel
INSERT INTO maladie_libelles (maladie_id, langue, libelle, principal, source, created_at, updated_at)
SELECT id,'fr',libelle,0,'demonstration',NOW(),NOW() FROM maladies WHERE libelle='Paludisme';
-- attendu : ERROR 1644 (45000) : ck_libelle_alternatif_distinct
```

**Et le vecteur en sens inverse, qui doit PASSER :**

```sql
SELECT m.libelle AS officiel, l.langue, l.libelle AS alternatif
FROM maladies m JOIN maladie_libelles l ON l.maladie_id = m.id
WHERE m.libelle = 'Choléra';
-- attendu : Choléra | en | Cholera
```

> **C'est le défaut que le G2 a trouvé et que les tests ne pouvaient pas voir.** Écrit en `=` simple,
> le déclencheur comparait avec la **collation** de la colonne — insensible à la casse **et aux
> accents** sous MySQL 8. « Cholera » et « Choléra » y étaient **égaux**, et le seeder s'arrêtait sur
> `ERROR 1644` en enregistrant un libellé anglais légitime. SQLite compare octet à octet : la suite
> de tests était verte. Correctif : `CAST(... AS BINARY)`.
>
> **Divergence connue** : `uq_maladie_libelle` hérite de la même collation. MySQL refuserait donc
> deux maladies dont les libellés ne diffèrent que par un accent, là où SQLite les accepterait.
> C'est plus strict en production qu'en test, et c'est écrit ici plutôt que découvert.

## 3.5 La gouvernance mord — et le refus se vérifie PAR SON MOTIF

```bash
XDEBUG_MODE=off php artisan tinker
```

1. Enregistrer le référentiel, créer **deux** comptes habilités.
2. A propose. **A tente de publier alors qu'il porte l'habilitation `referentiel.publier`.**
3. Attendu : *« L'auteur d'une proposition ne peut pas la valider lui-même (CDC_09 §10, double
   validation) »*.
4. B publie → version 1.

> **Piège de méthode, déjà rencontré en P6.8a.** Si A n'a **pas** la permission de publier, le refus
> vient de l'habilitation et **ne prouve rien du quatre-yeux**. C'est arrivé au premier essai de ce
> G2 : le message reçu parlait d'habilitation, pas de double validation. Un refus se vérifie
> **par son motif**.

## 3.6 Un `UPDATE` direct reste sans effet

```sql
UPDATE maladies SET libelle = 'MENSONGE DIRECT' WHERE code = 'MAL000001';
```

```bash
curl -s "http://127.0.0.1:8000/api/v1/maladies?q=palu"
# attendu : "libelle":"Paludisme"  <- la version publiee, pas la table
```

```sql
SELECT libelle FROM maladies WHERE code='MAL000001';  -- dit bien MENSONGE DIRECT
```

C'est le but du §1.2.4, et la leçon de L1+L2. **Avant la première publication**, `GET /v1/maladies`
répond **503**, jamais une liste vide : *un repli laisserait un oubli de publication invisible.*

## 3.7 Les trois vecteurs en miroir — aucun ne suffit seul

| # | Geste | Empreinte attendue |
|---|---|---|
| 1 | publier une **alerte épidémique** | **inchangée** (rien n'écrit automatiquement dans `maladies`) |
| 2 | corriger le **libellé officiel** | **changée** |
| 3 | rattacher un **vaccin** à une maladie | l'empreinte du **référentiel des vaccins** change |

Le 3 est une **conséquence assumée et annoncée avant d'avoir codé** : les codes des maladies entrent
dans la projection des vaccins. Même cas que `forme_juridique` en P6.4d.

## 3.8 Le consommateur clinique : l'antécédent

Avec un jeton Sanctum et un membre à soi :

```bash
# 1. Sans lien -- le serveur NE DEVINE PAS, meme quand la description est exacte
curl -X POST .../antecedents -d '{"type":"maladie_chronique","description":"Diabete sucre"}'
# attendu : maladie_code = null

# 2. Avec lien, en envoyant un code fantaisiste
curl -X POST .../antecedents -d '{"type":"maladie_chronique",
  "description":"DT2 decouvert en 2019, suivi au CHU",
  "maladie_id":18,"maladie_code":"MAL999999","maladie_libelle":"Ce que je veux"}'
# attendu : code = MAL000018, libelle fige = Diabete sucre,
#           et la DESCRIPTION EST INTACTE, mot pour mot

# 3. Maladie inconnue
curl -X POST .../antecedents -d '{"type":"maladie_chronique","description":"x","maladie_id":99999}'
# attendu : 422 « La maladie n°99999 n'existe pas au referentiel national. »
```

**Le vecteur 1 est le plus important du module.** La description est *exactement* le libellé officiel,
et pourtant rien n'est rattaché : rapprocher serait un **diagnostic posé par une machine**.

**Le vecteur 2 tient la leçon de P6.7b** : le lien s'ajoute **à côté** des mots du patient, il ne les
remplace pas — là-bas, la réécriture du prescripteur inscrivait le nom du **mauvais** médecin.

**Fiche vitale** : le bloc « Maladies chroniques » montre désormais `code_national` et
`libelle_reference` **à côté** de la description, jamais à sa place.

## 3.9 L'alerte épidémique (décision E4)

- Avec un lien : le libellé enregistré est celui **du référentiel**, quel que soit le texte envoyé.
- Sans lien : « Pneumonie atypique d'origine inconnue » est **acceptée**, `maladie_id` reste `NULL`.
- L'écran de liste affiche en permanence *« N alerte(s) sur M ne désignent aucune entrée du
  référentiel »*, et chaque ligne concernée porte un badge « hors référentiel ».

## 3.10 Le portail

- `/portail/maladies` → **403** pour un compte qui porte `sante_publique.manage` (l'auteur des
  alertes ne décide pas de ce qu'est une maladie) ; **200** pour `maladie.referentiel`.
- Le formulaire **ne propose ni le code national ni les codes CIM** : envoyer `code=MAL999999` le
  laisse `NULL`.
- Deux bandeaux comptent l'honnêteté du contenu : **entrées de démonstration** et **entrées sans code
  CIM** (21/21 au moment du G5).
- La fiche d'édition refuse un libellé alternatif identique à l'officiel **avec un message d'écran**,
  pas une erreur 500 : le moteur le rend impossible, l'écran le nomme.

## 3.11 Mobile (Expo Go)

Carnet → un membre → **Antécédents** → *Ajouter*. Sous « Description » apparaît « Maladie du
référentiel national » (facultatif) : trois caractères suffisent à proposer, « palu » retrouve
« Paludisme ». Une fois rattachée, la ligne affiche « Référentiel national · … » **sous** la
description. **Hors ligne, le champ se tait** — une recherche impossible n'est pas une panne.

## 3.12 Checklist G4

- [ ] `MAL000001…MAL000021` attribués, rejeu sans effet
- [ ] `1062` sur code et sur libellé ; `1644` sur la recopie du libellé officiel
- [ ] « Cholera » (en) coexiste avec « Choléra » (fr)
- [ ] contrôle qualité : **0 anomalie**, 21/21 en démonstration, 21/21 sans code CIM, 0 prétendant
      venir d'une autorité
- [ ] quatre-yeux refusé **par son motif**, puis publication par un second agent
- [ ] `UPDATE` direct sans effet sur `/v1/maladies` ; **503** avant la v1
- [ ] les **trois** vecteurs en miroir
- [ ] antécédent : le serveur ne devine pas · description intacte · code et libellé figés · 422 nommant
- [ ] alerte : libellé repris du référentiel · maladie émergente acceptée · écart compté
- [ ] portail 403/200 · code national ignoré · deux bandeaux d'honnêteté
- [ ] mobile : rattachement, détachement, silence hors ligne
- [ ] **base restaurée compte par compte**

## 3.13 Pièges rencontrés

1. **La collation MySQL rendait le déclencheur plus strict que sa règle** (§3.4) — trouvé au G2,
   invisible en test.
2. **Une mutation a montré qu'un vecteur ne prouvait rien.** « Le client ne peut pas déclarer les
   valeurs figées » envoyait *aussi* `maladie_id`, donc le service réécrivait les deux valeurs de
   toute façon : retirer l'effacement le laissait vert. Le cas que la garde couvre réellement est
   **un code déclaré sans lien** — vecteur ajouté. Troisième instance de cette famille après les
   `expectExceptionCode` de P6.4c et le contrôle de révocation de P6.5b.
3. **Un refus de quatre-yeux qui parle d'habilitation ne prouve pas le quatre-yeux** (§3.5).
4. **`MembreFamille::create` ne passe pas `user_id`** (hors `$fillable`) : utiliser la factory.
5. **La permission n'existe qu'après `PortailRolesSeeder`** (§3.2).
6. **`admin_ivoirsante` reçoit `maladie.referentiel`** comme toutes les autres, par
   `syncPermissions(Permission::all())`. « Portée par aucun rôle » veut dire **aucun rôle métier** —
   le filtrage réel se joue à l'attribution nominative. C'est dit depuis P6.3, et ça reste vrai ici.

---

# Partie 4 — P6.8d : Assurances et organismes agréés (CDC_09 §8)

> Quatrième incrément de P6.8. Referme **T5** du G0 : la CMU était codée dans les **noms de
> colonnes** de `membres_famille`, et aucun des six autres tiers payants du §8.2 du CDC_06 n'était
> représentable.

## 4.1 Ce qu'il faut comprendre avant de tester

**Une couverture n'est pas un attribut de la personne : c'est un contrat entre une personne et un
organisme.** Les trois colonnes `cmu_numero` / `cmu_statut` / `cmu_validite` disaient l'inverse —
elles en faisaient une propriété du corps, comme le groupe sanguin, et nommaient la CMU dans le
schéma. C'est ce qui rendait inexprimable la situation la plus banale qui soit : un fonctionnaire à
la CMU **et** à la mutuelle de son ministère. Or le §8 du CDC_06 enchaîne « CNAM, **puis** assurances
privées » sur la même facture.

**Quatre choses ont changé de nature, et il faut les tester comme telles :**

| Avant | Après |
|---|---|
| une couverture par personne, dans trois colonnes | **plusieurs**, dans `couvertures_membre` |
| `statut` **déclaré** par le client (`actif`/`expire`/`non_inscrit`) | **calculé** à partir des dates de la ligne |
| `non_inscrit` = un statut qui dit qu'il n'y a pas de couverture | **l'absence de ligne** |
| la carte annonçait « il **confirme** votre statut CMU » | « statut **déclaré par l'assuré**, non vérifié » |

**Le dernier point est le cœur de l'incrément, et il faut savoir ce qu'il n'est PAS.** Contrairement
à P6.8b — où le statut vaccinal *pouvait* devenir un calcul (âge + calendrier publié) — le statut
d'une couverture ne peut pas être vérifié : **l'étape 2 du §8.1 du CDC_06 (« le système vérifie son
éligibilité, API CNAM ») n'existe pas dans ce projet**, et rien ici ne peut l'inventer. À la décharge
du code existant, la conception de F2.3 était honnête (« restitue le statut **déclaré** ») : c'est
l'écran qui promettait plus que le code ne savait. **Le seul correctif honnête porte donc sur le
mot**, et il est servi comme une **donnée** (`mention_provenance`) pour qu'aucun écran ne l'oublie.

## 4.2 Ce que cet incrément ne fait PAS

1. **Aucune vérification auprès d'un organisme.** `provenance = verifie` est **réservé et
   inatteignable** : aucun chemin d'écriture ne peut le poser, et un vecteur le prouve.
2. **Le paiement continue de faire déclarer taux et plafond** par l'appelant : le registre dit *qui*
   couvre, jamais *ce que* couvre un contrat. Aucune garantie, aucun plafond, aucune exclusion.
3. **Le conventionnement établissement ↔ assureur reste en texte libre** (`agrements_json`, déjà
   publié dans la projection des établissements — constat U6).
4. **Le rôle `assurance` reste sans porte** : il existe depuis P1, il est soumis à MFA, et aucun
   portail ne l'accepte. Lui en ouvrir un suppose l'authentification d'une **troisième population**,
   ce qu'ADR-030 refuse d'étirer.
5. **Le contenu est un jeu de démonstration** : la CNAM (que le corpus nomme) et **cinq organismes
   explicitement fictifs**. Aucun assureur privé réel n'est nommé, aucun numéro d'agrément n'a été
   chargé — écrire un nom de compagnie dans un registre intitulé « organismes agréés » affirmerait un
   agrément que personne n'a vu.

## 4.3 Préparation

```bash
cd services/api
XDEBUG_MODE=off php artisan migrate
XDEBUG_MODE=off php artisan db:seed --class=OrganismeAssuranceSeeder
XDEBUG_MODE=off php artisan masante:assurances:backfill        # ASS000001 ... ASS000006
XDEBUG_MODE=off php artisan db:seed --class=PortailRolesSeeder # cree assurance.referentiel
```

> **Piège** : la permission `assurance.referentiel` n'existe qu'**après** `PortailRolesSeeder`.

**Puis la bascule des données existantes — c'est une ÉTAPE DE DÉPLOIEMENT, pas un détail :**

```bash
XDEBUG_MODE=off php artisan masante:couvertures:backfill --dry-run   # annonce, n'ecrit rien
XDEBUG_MODE=off php artisan masante:couvertures:backfill
XDEBUG_MODE=off php artisan masante:couvertures:backfill             # rejeu : rien a faire
```

**Tant qu'elle n'a pas tourné, un membre dont la colonne dit « actif » répond `non_inscrit`.** Ce
n'est pas un bug : les accesseurs lisent la couverture et **ne se replient jamais sur la colonne** —
un repli ressusciterait une valeur périmée le jour où un citoyen supprime sa couverture, et
rétablirait les deux vérités que ce module supprime. Même nature que la publication de la v1 en
L1+L2 : *une bascule se fait, elle ne se devine pas*.

## 4.4 Les gardes du moteur

```sql
-- Un agrément qui finit avant de commencer
UPDATE organismes_assurance SET agrement_debut='2026-12-31', agrement_fin='2026-01-01' WHERE id=1;
-- attendu : ERROR 1644 (45000): ck_agrement_dates

-- Une couverture qui ne nomme aucun organisme : « je suis assuré » sans dire chez qui
INSERT INTO couvertures_membre (membre_id, provenance, created_at, updated_at)
VALUES (1, 'declare', NOW(), NOW());
-- attendu : ERROR 1644 (45000): ck_couverture_organisme

-- Doublon de code national dans un pays
UPDATE organismes_assurance SET code='ASS000001' WHERE id=2;
-- attendu : ERROR 1062 ... 'uq_organisme_code_pays'

-- Deux organismes indiscernables à l'écran (le nom, c'est ce que l'assuré lit et choisit)
UPDATE organismes_assurance SET nom='Mutuelle de Démonstration' WHERE id=2;
-- attendu : ERROR 1062 ... 'uq_organisme_nom_pays'

-- CI et SN partagent ASS000001 (un agrément est NATIONAL — question reposée depuis P6.8c)
INSERT INTO organismes_assurance (code, pays_code, nom, type, source, actif, created_at, updated_at)
VALUES ('ASS000001','SN','Institution de Prevoyance Maladie','cnam','demonstration',1,NOW(),NOW());
-- attendu : accepté

-- Supprimer un organisme qui couvre des assurés
DELETE FROM organismes_assurance WHERE id=1;
-- attendu : ERROR 1451 (contrainte de clé étrangère) — on DÉSACTIVE, on ne supprime pas
```

> **Pourquoi des déclencheurs et non des `CHECK`** : la garde « une couverture nomme son organisme »
> vise `organisme_assurance_id`, qui porte une action référentielle — le mur de P6.3 (MySQL 8.4,
> **erreur 3823**), cousin du 1215 de P6.1. Et SQLite refuse `ALTER TABLE … ADD CONSTRAINT`, donc les
> gardes de dates n'existeraient pas dans la suite de tests : *une garantie que les tests ne peuvent
> pas éprouver n'en est pas une*.

## 4.5 La gouvernance mord

```bash
# 503 tant qu'aucune version n'est en vigueur — jamais une liste vide
curl -s http://127.0.0.1:8000/api/v1/assurances | head -3

# A propose, B publie (quatre-yeux §10, motif >= 10 caracteres)
curl -s -X POST .../referentiels/assurances/proposer -H "Authorization: Bearer $A" ...
curl -s -X POST .../referentiels/assurances/publier   -H "Authorization: Bearer $A" ...   # 403
curl -s -X POST .../referentiels/assurances/publier   -H "Authorization: Bearer $B" ...   # 200
```

**Vérifier le refus PAR SON MOTIF** (leçon P6.8a) : un 422 « motif trop court » ne prouve pas le
quatre-yeux.

```bash
# UPDATE direct : sans effet sur ce qui est diffusé
mysql> UPDATE organismes_assurance SET nom='Nom change en douce' WHERE id=1;
curl -s .../v1/assurances | grep -o '"nom":"[^"]*"' | head -1
# attendu : le nom PUBLIE, pas celui de la table
```

## 4.6 Les deux vecteurs en miroir — aucun ne suffit seul

| # | Action | Empreinte du registre |
|---|---|---|
| 1 | un citoyen déclare une couverture | **inchangée** |
| 2 | l'agrément d'un organisme passe à `suspendu` | **change** |

Le premier prouve que la projection peut prendre la **ligne entière** : rien n'écrit automatiquement
dans `organismes_assurance`. **Aucun compteur d'assurés n'y a été ajouté** — il aurait été utile à
l'écran, il aurait rendu cette phrase fausse (précaution née de `note_moyenne` en P6.4a). Le second
prouve que retirer un agrément est un **acte d'autorité**, soumis au quatre-yeux.

## 4.7 Le contrat de P2 survit — par dérivation

```bash
curl -s .../v1/membres/1 -H "Authorization: Bearer $T" | python -m json.tool | grep cmu
# attendu, a l'identique : cmu_statut, cmu_validite, cmu_numero_masque
# et JAMAIS cmu_numero
```

- Couverture **CNAM active** → `cmu_statut: "actif"`.
- Couverture **échue ou résiliée** → `"expire"` (la distinction existe sur la couverture ; l'inventer
  dans un contrat qui ne l'a jamais portée casserait un client validé G5).
- **Aucune couverture** → `"non_inscrit"` : c'est le seul endroit où cette valeur subsiste.
- **Une mutuelle n'est pas une carte CMU** : le TYPE fait foi, jamais le nom.

## 4.8 Ce que le client ne déclare pas

```bash
# provenance : reservee et INATTEIGNABLE
curl -s -X POST .../v1/membres/1/couvertures -H "Authorization: Bearer $T" \
  -d '{"organisme_assurance_id":1,"provenance":"verifie"}'
# attendu : "provenance":"declare"

# les champs cmu_* envoyes au membre sont IGNORES en silence
curl -s -X POST .../v1/membres -H "Authorization: Bearer $T" \
  -d '{"nom":"X","prenom":"Y","date_naissance":"1990-01-01","sexe":"M","cmu_statut":"actif"}'
# attendu : "cmu_statut":"non_inscrit" et rien dans les colonnes heritees
```

## 4.9 L'écart hors référentiel (motif E4)

- Un organisme **absent du registre** : la saisie libre est **acceptée**, la ligne porte
  `hors_referentiel: true`, et un avertissement dit que MaSanté ne confirme rien.
- Un organisme **en table mais non publié** : **422** qui le **nomme**.
- L'écran portail affiche en permanence *« N couverture(s) nomment un organisme absent de ce
  registre »*. **Ce nombre doit tendre vers zéro** — c'est ce qui distingue cet écart de celui des
  alertes épidémiques, qui est structurel (une maladie émergente n'est dans aucune nomenclature ;
  ici, c'est **notre** registre qui est incomplet).

## 4.10 Le portail

- `/portail/assurances` → **403** pour `gestionnaire_etablissement`, **200** pour
  `assurance.referentiel`.
- Le formulaire **ne propose ni le code national ni le numéro d'agrément** : envoyer
  `code=ASS999999` et `numero_agrement=AGR-INVENTE-001` les laisse **NULL**.
- **Trois bandeaux d'honnêteté** : entrées de démonstration · organismes sans numéro d'agrément ·
  couvertures hors référentiel.
- L'état d'agrément peut rester « **non renseigné** », et c'est une réponse légitime : *un organisme
  sans agrément renseigné n'est pas « probablement agréé »*.

## 4.11 Mobile (Expo Go)

Carnet → un membre → bloc « CMU (assurance santé) » → **Couvertures santé**.

- La liste montre une carte par couverture : organisme, famille, statut **calculé**, numéro masqué.
- *Ajouter une couverture* → la recherche propose le registre national à partir de **2 caractères** ;
  « Mon organisme n'est pas dans la liste » ouvre la saisie libre.
- **Hors ligne, la recherche se tait** — une recherche impossible n'est pas une panne (motif P6.6b).
- Le **formulaire de membre n'a plus de bloc CMU** : ni numéro, ni sélecteur de statut, ni date.
- La **carte CMU** affiche l'organisme et la mention de provenance ; le texte du code de présentation
  ne dit plus « il confirme votre statut CMU ».
- Un **délégué en lecture** voit les couvertures et **n'a aucun bouton** (correction F6 de P7-D2).

## 4.12 Checklist G4

- [ ] `ASS000001…ASS000006` attribués, dry-run = réel, rejeu muet
- [ ] `1644` sur les dates d'agrément **et** sur une couverture sans organisme
- [ ] `1062` sur code et sur nom ; CI et SN partagent `ASS000001` ; `1451` sur suppression
- [ ] **503** avant la v1 ; `UPDATE` direct sans effet ; quatre-yeux refusé **par son motif**
- [ ] les **deux** vecteurs en miroir
- [ ] `GET /membres` : `actif` / `expire` / `non_inscrit` dérivés ; mutuelle ≠ carte CMU
- [ ] `provenance: verifie` envoyé → `declare` ; champs `cmu_*` envoyés → ignorés, colonnes vides
- [ ] hors référentiel accepté et compté ; organisme non publié → 422 **nommant**
- [ ] portail 403/200 · code et numéro d'agrément ignorés · trois bandeaux
- [ ] mobile : liste, ajout, recherche, saisie libre, silence hors ligne, carte, délégué sans bouton
- [ ] backfill des couvertures : `expire` sans date → approximation **annoncée** ; déclaration
      contradictoire → **rien créé**
- [ ] **base restaurée compte par compte**

## 4.13 Pièges rencontrés

1. **`$appends` est obligatoire pour une valeur dérivée d'une colonne que plus rien n'écrit.** Un
   accesseur ne s'applique qu'aux clés **présentes** dans les attributs : une ligne fraîchement créée
   n'en portait aucune, et la réponse d'une **création** de membre omettait `cmu_statut` alors que la
   même fiche **relue** le portait. Trouvé par un vecteur, pas par relecture. Deuxième instance après
   le statut vaccinal de P6.8b.
2. **Une mutation peut s'appliquer AU MAUVAIS ENDROIT — raffinement du piège de P6.7b.** La mutation
   « le lien est relu à la version publiée » remplaçait `if ($publie === null) {`… sauf que cette
   ligne apparaît **deux fois** dans `ServiceCouvertures`, et `perl s///` remplace la **première** :
   elle neutralisait le retour anticipé d'`avertissements()`, pas la garde visée. Le vecteur survivait
   donc **pour une raison qui n'avait rien à voir avec lui**. P6.7b avait appris qu'une mutation doit
   être *assertée appliquée* ; celle-ci apprend qu'il faut aussi **asserter le site** — ancre unique,
   et contrôle que l'autre occurrence est restée intacte.
3. **Publier un référentiel vide est refusé** — et c'est le contrôle qualité qui parle, pas le
   harnais. Dans un vecteur, créer le contenu **avant** `publierReferentiel()`.
4. **`Delegation` porte `titulaire_user_id` / `delegue_user_id` / `acceptee_at`**, pas
   `proprietaire_id` / `statut`.
5. **`EmpreinteReferentiel::duContenu()`**, jamais `calculer()`.
6. **`assertSee` ne convient pas sur une réponse JSON accentuée** : les accents y sont échappés
   (`é`). Vérifier le message par `json('errors.<champ>.0')`.
7. **`admin_ivoirsante` reçoit `assurance.referentiel`** comme toutes les autres, par
   `syncPermissions(Permission::all())`. « Portée par aucun rôle » veut dire **aucun rôle métier**.

---

# Partie 5 — P6.8e : Numéros d'urgence nationaux (CDC_09 §8)

> Dernier incrément de **P6.8** → l'étape **8** du §14 est complète.
> Referme **T4** du G0 de P6.8. Décisions propriétaire **C1** à **C5** (2026-08-15).

## 5.1 Ce qu'il faut comprendre avant de tester

**Ce référentiel n'est pas comme les neuf autres.** Tous les précédents répondent à « qu'est-ce qui
fait autorité ? ». Celui-ci répond d'abord à « **que compose-t-on quand plus rien ne fonctionne ?** »

Son consommateur central n'a **ni réseau, ni session, ni compte**, et c'est délibéré : la carte
vitale d'urgence s'ouvre **depuis l'écran de connexion**, pour un secouriste qui ramasse le téléphone
d'un inconscient (FN2). Les référentiels précédents pouvaient poser un **refus bruyant** avant leur
v1 ; ici, un refus signifierait *pas de numéro d'urgence, dans une urgence*.

**Le motif n'est pas abandonné, il est DÉPLACÉ.** Deux moitiés, et il faut tester les deux :

| Côté | Comportement attendu | Ce que cela garantit |
|---|---|---|
| **Serveur** | 503 tant que rien n'est publié ; jamais la table de travail | l'honnêteté envers l'exploitant |
| **Client** | référentiel → cache `SecureStore` → valeur livrée avec l'app | la disponibilité envers le secouriste |

**Ce que l'écran ne fait PAS** : il n'affiche **aucun avertissement** sur la provenance du numéro.
Un « numéro par défaut, non vérifié » lu par quelqu'un qui compose des secours est du bruit au pire
moment. L'honnêteté est due à l'exploitant — journaux du serveur et écran du portail.

### Ce que l'incrément ne fait PAS

1. **Les six conseils cliniques seedés gardent le « 185 » en dur** (décision C4). Ce sont des
   **données déjà publiées** sous gouvernance (`seuils_mesure`, depuis L1+L2) : réécrire le seeder
   serait sans effet, et republier des conseils médicaux est un acte de gouvernance clinique.
   Porteur : **P10**.
2. **Les « compléments du découpage » sont hors périmètre** (décision C5) — ils restent à ADR-026.
3. **Aucun des trois numéros livrés n'a été confronté à un arrêté.** Le SAMU 185 vient du corpus ;
   le 100 et le 180 ont été déclarés par le propriétaire le 2026-08-15. C'est écrit **dans la
   donnée** (`source = declaration_projet`) et compté à l'écran.

## 5.2 Préparation

```bash
cd services/api
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan migrate
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan db:seed --class=NumeroUrgenceSeeder
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan db:seed --class=PortailRolesSeeder
```

Un agent habilité : il lui faut **`urgence.referentiel`** (portée par aucun rôle métier) **et** un
rôle de portail (`admin_ivoirsante` / `gestionnaire_etablissement` / `agent_garde` — piège de P6.4d).

## 5.3 Le serveur refuse honnêtement avant la v1

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8000/api/v1/numeros-urgence
# attendu : 503  — et NON 200 avec le contenu de la table
```

Puis vérifier que la trace existe :

```bash
tail -n 20 storage/logs/laravel.log | grep -i repli
# attendu : warning "Numéros d'urgence : repli sur la valeur livrée avec l'application."
```

**C'est ce warning qui rend le repli acceptable.** Sans lui, la disponibilité gagnerait contre la
traçabilité en silence.

## 5.4 Le triage reste utilisable, et il porte le numéro publié

Avant publication, un triage URGENT doit quand même donner un numéro :

```bash
# POST /api/v1/triage/analyser avec un symptôme drapeau rouge
# attendu AVANT publication : le texte contient « 185 » (repli), et le log porte le warning
```

Après publication d'une version où `samu` vaut `186` :

```bash
# attendu : le texte contient « 186 » et PLUS « 185 »
```

*C'est le vecteur qui prouve que CDC_02 §37 est tenu : « rien en dur, y compris les numéros
d'urgence ».*

## 5.5 La gouvernance mord — et le refus se vérifie PAR SON MOTIF

```bash
# A propose, A ne peut pas publier (quatre-yeux §10)
# attendu : 409, et le motif doit dire « l'auteur ne peut pas valider lui-même »
#           — un 422 « motif trop court » ne prouverait RIEN (leçon P6.8a)
```

Puis, une fois publié :

```bash
mysql -u root ivoirsante -e "UPDATE numeros_urgence SET numero='999' WHERE code='samu';"
curl -s http://localhost:8000/api/v1/numeros-urgence | grep -o '"numero":"[^"]*"' | head -1
# attendu : "numero":"185"  <- la version publiée, PAS la table
```

## 5.6 La garde du moteur

```sql
-- un numéro vide est un bouton qui ne compose rien
UPDATE numeros_urgence SET numero='' WHERE code='samu';
-- attendu : ERROR 1644 (45000) : ck_numero_urgence_vide

-- doublon de code dans un pays
INSERT INTO numeros_urgence (pays_code, code, numero, libelle, ordre, actif, source, created_at, updated_at)
VALUES ('CI','samu','999','Doublon',10,1,'declaration_projet',NOW(),NOW());
-- attendu : ERROR 1062 sur uq_numero_urgence_pays_code

-- deux pays partagent un code : ACCEPTÉ (un numéro n'existe que dans un plan national)
INSERT INTO numeros_urgence (pays_code, code, numero, libelle, ordre, actif, source, created_at, updated_at)
VALUES ('SN','samu','1515','SAMU',10,1,'declaration_projet',NOW(),NOW());
-- attendu : 1 row affected
```

**Ce que le moteur ne garde PAS, et c'est dit** : la *composabilité* (pas de lettres). MySQL 8 sait
le faire en `REGEXP`, SQLite non — la garde serait **plus stricte en production qu'en test**, la
divergence exacte relevée en P6.8c avec la collation. Ce contrôle vit dans
`SourceNumerosUrgence::controlerQualite()`, où il est éprouvable dans les deux dialectes.

## 5.7 Le contrôle central : une version sans secours joignable est refusée

```bash
mysql -u root ivoirsante -e "UPDATE numeros_urgence SET actif=0;"
# attendu à la publication : refus, motif « Aucun numéro actif »
```

Pourquoi ce contrôle plutôt qu'un autre : publier une liste tout inactif **ne casserait rien de
visible**. Les téléphones retomberaient sur la valeur compilée, en silence, sans que personne ne
l'ait décidé.

## 5.8 Les deux vecteurs en miroir — aucun ne suffit seul

| Action | Empreinte de `numeros_urgence` |
|---|---|
| Déclencher un **SOS** (ligne dans `alertes_sos`) | **INCHANGÉE** |
| Modifier le **numéro** du SAMU | **CHANGE** |

Le premier n'est pas gratuit : la table est **construite** pour qu'il soit vrai — elle ne porte
**aucun compteur d'appels**, alors qu'il serait facile d'en tenir un. *Le référentiel dirait qu'il a
changé au moment précis où il compte le plus qu'il n'ait pas bougé.*

## 5.9 Le portail

- **403** pour un gestionnaire sans `urgence.referentiel` · **200** pour l'agent habilité.
- Écran : bandeau **vert** « Version N en vigueur » **ou** bandeau **rouge** « Aucune version en
  vigueur » disant explicitement ce que composent les téléphones et que c'est **voulu**.
- Bandeau d'honnêteté : « **3 numéros sur 3** n'ont été confrontés à aucune publication officielle ».
- `code` et `pays_code` envoyés au `PUT` → **ignorés** (hors `$fillable`).
- Numéro non composable (`SAMU`) → **message d'écran**, rien enregistré.
- Le **code est immuable** à l'édition (champ `disabled`, jamais soumis).

## 5.10 Mobile (Expo Go)

| Vecteur | Attendu |
|---|---|
| Écran d'accueil, en ligne | bouton « Urgence — SAMU 185 » (valeur du référentiel) |
| Écran SOS | bouton SAMU **en tête**, puis « Autres secours » (pompiers, police) |
| Publier `samu = 186`, rouvrir l'app | le bouton affiche **186** |
| **Mode avion** après un passage en ligne | **186** toujours (cache `SecureStore`) |
| **Se déconnecter** puis rouvrir la carte vitale | le numéro **survit** — c'est le piège évité |
| **Installation neuve, jamais connectée** | **185**, écran pleinement utilisable |

Le dernier vecteur est **le cœur du module** : c'est celui où un refus bruyant aurait laissé un
secouriste sans numéro.

## 5.11 Checklist G4

- [ ] 503 avant la v1 sur `/api/v1/numeros-urgence` · warning « repli » dans `laravel.log`
- [ ] triage utilisable **avant** publication (185) et **portant 186 après**
- [ ] `UPDATE` direct sans effet · publication effective
- [ ] `ERROR 1644` numéro vide · `ERROR 1062` doublon · deux pays acceptés
- [ ] publication refusée si plus aucun numéro actif
- [ ] quatre-yeux refusé **par son motif**
- [ ] les **deux** vecteurs en miroir
- [ ] API publique **sans jeton** · ordre `samu, pompiers, police` · inactif absent
- [ ] portail 403/200 · `code` ignoré · numéro non composable refusé · bandeaux d'honnêteté
- [ ] mobile : les six vecteurs du §5.10, dont **déconnexion** et **installation neuve**
- [ ] **base restaurée compte par compte**

## 5.12 Pièges rencontrés

1. **`EmpreinteReferentiel::duContenu()`, jamais `calculer()`** — et ce piège était **déjà écrit à
   la partie 4**. Je l'ai refait. Un piège consigné ne protège que si on relit la consigne ; la
   suite de tests l'a rattrapé, mais au prix d'un cycle complet.
2. **`Symptome` n'a pas de factory** et sa colonne est `drapeau_rouge`, pas `est_drapeau_rouge`.
   Créer par `Symptome::create([...])` comme le fait `TriageAntecedentsTest`.
3. **Le repli doit être journalisé UNE FOIS par requête.** Trois appels donneraient trois lignes
   identiques, et le journal cesserait de dire « une version manque » pour dire « il s'est passé
   beaucoup de choses ». C'est ainsi qu'un avertissement devient invisible.
4. **Ne pas ranger les numéros d'urgence dans le cache chiffré P2.** `SessionContext` appelle
   `viderDossierCache()` à la déconnexion : ils disparaîtraient **précisément** dans l'état du
   téléphone que consulte un secouriste. `SecureStore` (celui de la carte vitale) survit.
5. **`estEnVigueur()` ne doit ni replier ni journaliser** : c'est la méthode que lit le portail pour
   annoncer l'absence de version. Si elle repliait, elle mentirait à l'exploitant exactement là où
   il attend la vérité brute.
6. **QUATRIÈME instance du piège « le vecteur prouve le validateur, pas la garde ».** La mutation
   « `code` redevient `$fillable` » a **survécu** à toute la suite : `validate()` écarte déjà les
   clés non déclarées, si bien que le vecteur HTTP ne touchait jamais le modèle. Parade identique à
   P6.6b : **dédoubler — une couche, un vecteur**, le second appelant le modèle **directement**,
   comme le ferait un import. Le vecteur ajouté meurt bien sous la mutation.
7. **Le garde-fou de mutation lui-même peut mentir** — raffinement des pièges de P6.7b (asserter que
   la mutation est appliquée) et de P6.8d (asserter le **site**). Ici, `grep -qF` avec un motif
   **multi-lignes** ne matche jamais : le script a annoncé « mutation non appliquée » alors qu'elle
   l'était parfaitement, s'est arrêté, et a laissé un fichier muté sur le disque. **L'ancre
   d'assertion doit tenir sur UNE SEULE LIGNE**, et la restauration doit être vérifiée par `diff`,
   pas supposée.
