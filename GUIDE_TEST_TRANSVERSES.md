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
