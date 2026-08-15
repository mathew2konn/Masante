# Guide de test — Référentiels transverses (CDC_09 §8)

> Étape **8** de l'ordre de construction du §14. Domaine à incréments successifs : chaque incrément
> ajoute une **partie** à ce fichier (règle propriétaire du 2026-08-11).
>
> | Partie | Incrément | Statut |
> |---|---|---|
> | **1** | **P6.8a — Spécialités médicales** | à valider |
> | 2 | P6.8b — Vaccins et calendrier vaccinal | non commencé |
> | 3 | P6.8c — Maladies (CIM) | non commencé |
> | 4 | P6.8d — Assurances et organismes agréés | non commencé |
> | 5 | P6.8e — Numéros d'urgence et compléments du découpage | non commencé |

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
