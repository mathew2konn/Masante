# GUIDE_TEST_TRIAGE.md — Triage et orientation (P10)

Guide de test du domaine **Triage**. Écrit avant le G4, conservé après le G5 comme procédure de
non-régression (règle propriétaire, CDC_01 §2.4).

| Partie | Incrément | Objet |
|--------|-----------|-------|
| **1** | **P10a** | Orientation après triage + gouvernance du triage + fiche §5.4 |
| **2** | **P10b-1** | Registre des protocoles médicaux + moteur de règles + le niveau de triage |
| **3** | **P10b-2** | Sélecteur, ordre de priorité §3, conflits §8, journal d'exécution §10 |
| *(à venir)* | P10b-3 | Questionnaire adaptatif §4.3b + écran d'authoring |
| *(à venir)* | P10c | Microservice `triage-service` (CDC_05 §5) |

---

# Partie 1 — P10a : orientation, gouvernance, fiche §5.4

## 1. Périmètre — et ce que ce module ne fait PAS

### Ce qu'il livre

1. **L'orientation devient une donnée gouvernée.** Chaque symptôme porte, en base, les spécialités
   vers lesquelles il oriente, **rangées**. Le triage agrège ; il ne déduit rien.
2. **Le triage lit la version publiée** du référentiel `symptomes_triage`, plus la table de travail.
   Chaque triage est **estampillé** de la version qui l'a gouverné.
3. **La fiche du §5.4** est complète : réponses au questionnaire, hôpitaux proches proposant le
   service, **QR** permettant au médecin d'accéder au triage, et la **mention obligatoire**.
4. **Le logo de MaSanté au centre de tous les QR** de l'application (décision D7).

### Ce qu'il ne fait pas — à lire avant de tester

| # | Limite |
|---|--------|
| **L1** | **Aucun protocole clinique.** Le score reste l'arbre pondéré du Module 1. Les protocoles de CDC_08 sont P10b, l'IA de CDC_05 §5 est P10c. |
| **L2** | **Le triage n'est jamais un diagnostic** (CDC_05 §1). Il ordonne des codes de spécialité, il ne nomme aucune maladie. |
| **L3** | **Pas de PDF.** Le §5.4 le demande ; il exigerait une dépendance Composer, donc l'accord écrit du propriétaire (§2.6). Le partage se fait par le **texte** et le **QR**. |
| **L4** | **L'orientation ne couvre que 11 des 20 symptômes** du jeu de démonstration. Les neuf autres n'orientent vers rien et retombent sur « médecine générale par défaut » — c'était déjà le cas avant P10a. |
| **L5** | **Le vocabulaire des spécialités reste une adoption de 13 + 1 termes**, pas la nomenclature officielle (limite héritée de P6.8a). |
| **L6** | **Le temps de trajet vient d'un routeur public gratuit** (`routing.openstreetmap.de`, déjà utilisé depuis P3). Sans garantie de service : son absence est normale et n'enlève jamais un hôpital de la liste. |
| **L7** | **Le libellé du repli pédiatrique n'est pas figé** dans l'instantané — c'est le seul. Renommer « Pédiatrie » au vocabulaire change le texte lu sans publication, alors que renommer « Cardiologie » ne le change pas. Inconfortable, et dit plutôt que déguisé. |
| **L8** | **`symptomes.specialite_hint` et `maladies_probables_json` sont conservées mais plus personne ne les écrit ni ne les publie.** Ce sont des colonnes mortes assumées (ADR-024). |

---

## 2. Prérequis

```bash
# Backend
cd services/api
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan migrate
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan db:seed --class=SpecialiteMedicaleSeeder
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan masante:orientation:backfill --dry-run
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan masante:orientation:backfill
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan serve --host=0.0.0.0 --port=8000
```

### ⚠ ÉTAPE DE DÉPLOIEMENT OBLIGATOIRE — sans elle, le triage répond 503

C'est **voulu**, pas une panne : tant qu'aucune version n'est publiée, le triage refuse
bruyamment plutôt que de lire la table (voir §3, W1). La mise en vigueur passe par le
**quatre-yeux du §10** : deux agents habilités, jamais un seeder.

```bash
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan tinker --execute="
\$g = app(App\Services\Referentiel\ServiceGouvernanceReferentiel::class);
\$g->enregistrer('symptomes_triage');
\$a = App\Models\User::find(4); \$a->givePermissionTo('referentiel.proposer');
\$b = App\Models\User::find(6); \$b->givePermissionTo('referentiel.publier');
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
\$g->proposer('symptomes_triage','CI',\$a->fresh(),'Mise en vigueur initiale du referentiel national de triage.');
echo 'v'.\$g->publier('symptomes_triage','CI',\$b->fresh(),'Controles qualite conformes, mise en vigueur.')->numero;
"
```

**Mobile** : `pnpm --filter @masante/mobile start` puis Expo Go SDK 54 via tunnel Ngrok
(`EXPO_PUBLIC_API_URL` dans `apps/mobile/.env`).

---

## 3. Scénarios backend (curl reproductibles)

> Les identifiants de symptôme dépendent du jeu seedé. Les récupérer d'abord :
> `curl -s http://127.0.0.1:8000/api/v1/symptomes | python -m json.tool | head -40`

### W1 — Le refus bruyant avant la v1

**Avant** l'étape de déploiement ci-dessus :

```bash
curl -s http://127.0.0.1:8000/api/v1/symptomes
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://127.0.0.1:8000/api/v1/triage/analyser \
  -H "Content-Type: application/json" -H "Accept: application/json" -d '{"symptomes":[1]}'
```

**Attendu** : `503` sur les deux, avec le message
*« Le référentiel national des symptômes de triage n'a aucune version en vigueur : aucun triage ne
peut être rendu tant qu'une version n'a pas été publiée (CDC_09 §10). »*

### W2 — L'orientation est ordonnée par le rang

```bash
# Douleur thoracique (annotée « Cardiologie / Urgences »)
curl -s -X POST http://127.0.0.1:8000/api/v1/triage/analyser \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"symptomes":[<id douleur thoracique>]}'
```

**Attendu** : `specialites` = `[cardiologie, urgences]` **dans cet ordre** (l'ordre écrit devient le
rang ; la commande de transposition ne réordonne jamais), `specialite_requise` = `"Cardiologie"`,
`referentiel_version` = le numéro publié.

### W3 — La restriction de sexe, dans les trois cas

```bash
for S in '"patient_sexe":"M",' '"patient_sexe":"F",' ''; do
  curl -s -X POST http://127.0.0.1:8000/api/v1/triage/analyser \
    -H "Content-Type: application/json" -H "Accept: application/json" \
    -d "{$S\"symptomes\":[<id douleurs pelviennes>]}"
done
```

| Sexe déclaré | Attendu | Pourquoi |
|---|---|---|
| `M` | `[]` | on **sait** que la restriction n'est pas remplie |
| `F` | `[gynecologie, maternite]` | |
| **absent** | `[gynecologie, maternite]` | **un sexe inconnu n'écarte rien** — retirer une orientation faute d'information reviendrait à décider à la place du patient |

### W4 — Le repli pédiatrique (§5.1.3)

```bash
curl ... -d '{"symptomes":[<id fièvre>],"patient_age":6}'          # → ["pediatrie"]
curl ... -d '{"symptomes":[<id douleur dentaire>],"patient_age":6}' # → ["dentisterie"]
curl ... -d '{"symptomes":[<id fièvre>],"patient_age":40}'          # → []
```

**Le second cas est le vecteur qui compte** : un enfant qui a mal aux dents va chez le dentiste, pas
en pédiatrie parce qu'il est enfant.

### W5 — Un `UPDATE` direct reste sans effet

> ⚠ **Choisir un symptôme SANS drapeau rouge.** Sur un symptôme à drapeau rouge, le score est forcé
> à 90 et le vecteur **passe sans rien prouver** — c'est arrivé au premier essai du G2.

```bash
mysql ... -e "UPDATE symptomes SET poids_severite=88 WHERE nom_fr='Douleur dentaire';"
curl ... -d '{"symptomes":[<id douleur dentaire>]}'
```

**Attendu** : le score **ne bouge pas** (8, pas 88). Puis republier (proposition par A, publication
par B) → le score devient 88 et `referentiel_version` s'incrémente.

### W6 — Le quatre-yeux, refusé **par son motif**

> ⚠ Utiliser un agent portant **les deux** permissions. Avec un agent qui n'a que `proposer`, le
> refus vient de l'habilitation et **ne prouve pas le quatre-yeux** — piège déjà rencontré en P6.8e.

**Attendu** : *« L'auteur d'une proposition ne peut pas la valider lui-même (CDC_09 §10, double
validation). »*

### W7 — Un symptôme absent de la version publiée est **refusé**, pas ignoré

```bash
mysql ... -e "INSERT INTO symptomes (nom_fr,categorie,poids_severite,drapeau_rouge,actif,created_at,updated_at)
              VALUES ('Ajout apres publication','general',30,0,1,NOW(),NOW());"
curl ... -d '{"symptomes":[<connu>,<nouveau>]}'
```

**Attendu** : `422`, erreur sur `symptomes.1` — *« ne fait pas partie de la version en vigueur »*.
Accepter puis ignorer en silence serait le pire des deux.

### W8 — L'estampille, et le client qui ment

```bash
curl ... -d '{"symptomes":[<id>],"referentiel_version":999,
              "specialites_json":[{"code":"cardiologie","libelle":"INVENTE"}]}'
mysql ... -e "SELECT referentiel_version, specialites_json, LENGTH(jeton_partage) FROM triages ORDER BY id DESC LIMIT 1;"
```

**Attendu** : la vraie version (pas 999), le vrai libellé (pas `INVENTE`), un jeton de **48**
caractères.

### W9 — La fiche §5.4 n'est pas lisible par son seul identifiant

```bash
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:8000/api/v1/triage/<id>/fiche"
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:8000/api/v1/triage/<id>/fiche?jeton=faux"
curl -s "http://127.0.0.1:8000/api/v1/triage/<id>/fiche?jeton=<vrai>&lat=5.35&lng=-3.99"
```

**Attendu** : `404`, `404`, puis `200`. Le `404` est délibéré — un `403` confirmerait qu'un triage
existe à cet identifiant et rendrait l'énumération à nouveau possible.

La réponse `200` doit porter :
- `fiche.mention_obligatoire` = **« Ce document constitue une aide à l'orientation et ne remplace pas un diagnostic médical. »** (texte imposé, au mot près) ;
- `fiche.reponses` (tableau, même vide) ;
- `fiche.etablissements` groupés **par spécialité**, avec `distance_km` ;
- `qr_payload` contenant le jeton.

### W10 — L'historique exige un compte

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/api/v1/triage/historique
```

**Attendu** : `401`. Avant P10a il renvoyait **les 50 derniers triages de tout le monde**, nom du
patient et symptômes compris.

---

## 4. Scénarios mobile (Expo Go)

### M1 — Le résultat du triage

1. Onglet **Triage** → « Démarrer le triage » → cocher **Douleur thoracique** → questions →
   **Voir le résultat**.
2. Attendu à l'écran :
   - le score et le badge de niveau (inchangés) ;
   - un bloc **« Services conseillés »** listant **Cardiologie** puis **Médecine d'urgence** — dans
     cet ordre, celui du serveur ;
   - un bloc par spécialité avec les établissements proches, `commune · X km · ~Y min en voiture` ;
   - un bloc **« À montrer au soignant »** avec un QR portant **le logo MaSanté au centre** ;
   - en bas, la **mention obligatoire** au mot près.

### M2 — Le temps de trajet est un confort, pas une condition

Couper les données mobiles juste après l'affichage, refaire un triage : les établissements
**restent affichés** (ils viennent de la fiche), seule la mention `~ min en voiture` disparaît.
**Aucun hôpital ne doit disparaître de la liste.**

### M3 — Le refus de localisation

Refuser la localisation : l'écran affiche les mêmes établissements, **sans** `km` ni durée.
Un refus prive du tri par proximité, jamais de l'information.

### M4 — Les QR de toute l'application portent le logo

Vérifier les **cinq** : carte CMU · QR d'un membre · enrôlement MFA · reçu de paiement ·
fiche de triage.

> ⚠ **Le vrai test est la lisibilité**, pas l'apparence. Scanner **chacun** avec un lecteur réel —
> en particulier l'enrôlement MFA avec **Google Authenticator ou Aegis**, qui n'ont aucune raison
> d'être indulgents. Un logo trop grand rend un QR *joli et illisible*.
> Si un lecteur échoue : passer `avecLogo={false}` **sur ce site-là seulement**.

---

## 5. Invariants base de données

```sql
-- a) Les gardes du moteur existent
SELECT trigger_name FROM information_schema.triggers
 WHERE trigger_schema='ivoirsante' AND event_object_table='symptome_specialites';
-- attendu : ck_orientation_insert, ck_orientation_update

-- b) Un rang nul est refusé par le moteur
INSERT INTO symptome_specialites (symptome_id,specialite_id,rang,created_at,updated_at)
VALUES (<s>,<sp>,0,NOW(),NOW());              -- attendu : ERROR 1644 ck_orientation_rang

-- c) Une orientation vers un terme désactivé est refusée par le moteur
UPDATE specialites_medicales SET actif=0 WHERE code='orl';
INSERT INTO symptome_specialites ... (orl)     -- attendu : ERROR 1644 ck_orientation_specialite_inactive
UPDATE specialites_medicales SET actif=1 WHERE code='orl';   -- NE PAS OUBLIER

-- d) Tout triage porte un jeton, et ils sont tous distincts
SELECT COUNT(*) , COUNT(DISTINCT jeton_partage) FROM triages;   -- les deux égaux

-- e) Aucune orientation orpheline
SELECT COUNT(*) FROM symptome_specialites ss
  LEFT JOIN specialites_medicales sm ON sm.id = ss.specialite_id WHERE sm.id IS NULL;  -- 0
```

**Contrôle qualité bloquant à la publication** (et non à la proposition) :

```bash
curl -s http://127.0.0.1:8000/api/v1/referentiels/symptomes_triage/controle \
  -H "Authorization: Bearer <jeton agent>" -H "Accept: application/json"
```

Après `UPDATE specialites_medicales SET actif=0 WHERE code='dentisterie';`, la publication doit être
**refusée** avec *« orienté vers « dentisterie », terme DÉSACTIVÉ du vocabulaire national »*.

---

## 6. Commandes de qualité (G3)

```bash
cd services/api && XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan test
cd ../.. && pnpm typecheck
cd apps/mobile && npx expo-doctor
```

**Attendu** : suite **1010 tests / 15 922 assertions, 0 échec** · typecheck ×3 verts ·
expo-doctor **18/18**.

> ### Alignement des versions Expo du 2026-08-19 (accord propriétaire, §2.6)
>
> Le G3 initial sortait à **16/18** : `expo` était resté un cran en dessous de ce qu'attend le SDK
> (`54.0.36` contre `~54.0.37`), avec `expo-constants`, `expo-file-system` et
> `expo-local-authentication`. **Le projet n'avait pas bougé** — `apps/mobile/package.json` datait
> de P7-D1 : c'est le référentiel de versions d'Expo qui a avancé. Aligné sur décision du
> propriétaire ; seules ces **quatre** plages changent, le reste du lockfile ne bouge que parce que
> les empreintes de pairs `expo@54.0.36` deviennent `54.0.37`.
>
> **Deux pièges rencontrés, à connaître avant de refaire l'opération :**
>
> 1. **`npx expo install --fix` échoue sous Windows + pnpm `hoisted`** :
>    `ERR_PNPM_ENOENT … babel-preset-expo_tmp_XXXXX` — le `pnpm add` sous-jacent bute sur un
>    répertoire temporaire. Il s'arrête **à mi-chemin** : deux paquets sur quatre étaient déjà
>    installés et le lockfile modifié, alors que `package.json` ne l'était pas. Chemin qui marche :
>    **fixer les quatre plages dans `package.json`, puis `pnpm install` à la racine** — c'est ce
>    que `--fix` aurait écrit, sans le `pnpm add` qui plante.
> 2. **L'échec laisse des copies imbriquées, et `expo-doctor` passe alors à 15/18** : un
>    `apps/mobile/node_modules/expo-file-system@19.0.23` **masquait** la 19.0.24 hoistée, et
>    `expo-constants` existait en trois exemplaires. *Un arbre à moitié installé ne se voit pas au
>    typecheck* — il était vert pendant que le paquet chargé au runtime était le périmé. Nettoyage :
>    supprimer les copies imbriquées puis `pnpm install`.
>
> Le check *Expo config schema* **appelle l'API Expo** : sans réseau il tombe en `fetch failed` et
> le total affiche 17/18. Ce n'est pas un défaut du projet (déjà rencontré en P6.8b) — mais il ne
> se déclare pas vert pour autant, il se rejoue connecté.

**Mutation** (`scratchpad/mutation_p10a.sh`) : 9 gardes, chacune tuant ses vecteurs.

---

## 7. Checklist de clôture

> **Clôturée le 2026-08-19 — G5.** Les cases W et « Invariants » ont été prouvées au **G2 live** ;
> les cases **M1→M4** relèvent du **G4, déclaré validé par le propriétaire**. La distinction est
> maintenue plutôt que fondue en une seule liste de coches : *ce guide sert de procédure de
> non-régression, et celui qui le rejouera doit savoir lesquelles se rejouent en curl et lesquelles
> exigent un téléphone.*

- [x] W1 — 503 avant la v1, sur `/symptomes` **et** `POST /analyser`
- [x] W2 — `[cardiologie, urgences]` dans cet ordre
- [x] W3 — M → `[]` · F → 2 codes · **sexe inconnu → 2 codes**
- [x] W4 — enfant sans orientation → pédiatrie · **enfant avec mal de dent → dentisterie**
- [x] W5 — `UPDATE` direct sans effet, puis effet après republication (**symptôme sans drapeau rouge**)
- [x] W6 — quatre-yeux refusé **par son motif**, agent portant les deux permissions
- [x] W7 — symptôme hors version → 422
- [x] W8 — estampille réelle malgré `referentiel_version:999`
- [x] W9 — fiche : 404 / 404 / 200 ; mention au mot près ; hôpitaux groupés ; QR portant le jeton
- [x] W10 — historique sans compte → 401
- [x] M1 — écran de résultat complet *(G4 propriétaire)*
- [x] M2 — hors ligne : les hôpitaux restent, la durée disparaît *(G4 propriétaire)*
- [x] M3 — refus de localisation : liste conservée *(G4 propriétaire)*
- [x] M4 — **les 5 QR se scannent réellement**, MFA compris *(G4 propriétaire)*
- [x] Invariants a→e
- [x] G3 vert — **1010 tests / 15 922 assertions, 0 échec** · typecheck ×3 · expo-doctor **18/18** ·
      mutation 9/9
- [x] Base de dev restaurée compte par compte

---

## 8. Pièges rencontrés

1. **Un vecteur qui passe sans rien prouver.** Le premier essai de W5 utilisait *Douleur thoracique*,
   qui porte un **drapeau rouge** : le score est forcé à 90 avant comme après l'`UPDATE`. Le vecteur
   était vert et ne discriminait rien. → toujours choisir un symptôme **sans** drapeau rouge.

2. **Un refus pour la mauvaise raison.** Le premier W6 utilisait un agent n'ayant que `proposer` : le
   refus venait de **l'habilitation**, pas du quatre-yeux. Même piège qu'en P6.8e — *vérifier un
   refus par son motif, jamais par son code*.

3. **Le garde-fou de mutation peut mentir sur lui-même.** Le script s'est arrêté sur « mutation non
   appliquée » alors que le marqueur asserté n'était simplement pas celui inséré. C'est le
   comportement voulu (P6.8e), mais il faut lire le message plutôt que conclure trop vite.

4. **`iconv('ASCII//TRANSLIT')` dépend du locale.** Il rend `é` par `'e` sur ce poste, d'où
   `gyn_ecologie` : le backfill signalait « Gynécologie » comme introuvable. Ailleurs il aurait rendu
   `e` et tout aurait marché — *une normalisation dont le résultat change avec la machine se comporte
   différemment en production et en test*. Remplacé par une table explicite.

5. **La proposition passe, la publication refuse.** Les contrôles qualité du §10 sont bloquants **à
   la publication**. Un `201` sur une proposition ne veut pas dire que le contenu est sain.

6. **`ServiceSymptomesTriage` est lié en `scoped`.** Dans les tests, il faut oublier les instances de
   portée entre deux requêtes (`simulerNouvelleRequete()`), sinon un vecteur qui publie puis rejoue
   lit encore la version d'avant — et prouve la mémoïsation, pas la bascule (piège de L1+L2).

---

# Partie 2 — P10b-1 : registre des protocoles, moteur de règles, niveau de triage

> **Ajoutée le 2026-08-19.** Écrite avant le G4, conservée après le G5 comme procédure de
> non-régression. Elle **ne remplace pas la partie 1** : P10a reste en vigueur, et ses scénarios
> continuent de s'appliquer — avec une étape de déploiement de plus (§2.2 ci-dessous).

## 1. Périmètre — et ce que ce module ne fait PAS

### Ce qu'il livre

- Le **registre des protocoles médicaux** (CDC_08 §4.4) : 8 tables, versionnage par protocole,
  cycle de vie `brouillon → actif → archive` (§6.1), dossier de validation à quatre couches (§7),
  chaîne d'audit à hachage (§10).
- Le **moteur d'inférence** (§4.3a) : règles « SI … ALORS … » interprétées, avec trois listes
  blanches fermées (faits, opérateurs, actions) et un chaînage avant.
- **Le niveau de priorité du triage quitte le code.** `TriageService::niveauDepuisScore()` — un
  `match` sur trois seuils — et le relèvement du score sur drapeau rouge n'existent plus en PHP.
  Les seuils vivent dans le protocole `TRIAGE-NIVEAU`, relu et signé par quatre validateurs.
- **Les quatre niveaux patient de CDC_05 §5.3** entrent en vigueur (`faible`, `recommandee`,
  `rapide`, `urgence`), là où le projet en rendait trois.

### Ce qu'il ne fait pas — à lire avant de tester

| Attendu du corpus | État | Où |
|---|---|---|
| Sélecteur, ordre de priorité §3, conflits §8 | **non livré** | P10b-2 |
| Questionnaire adaptatif §4.3b | **non livré** | P10b-3 |
| Écran d'authoring §2.1 | **non livré** — la gouvernance passe par l'API | P10b-3 / migration du portail |
| Protocoles thérapeutiques applicables (§5.1) | **délibérément aucun** | voir §4 |
| Journal d'exécution `protocole_applications` (§10) | **non livré** | P10b-2 |
| Évaluation sous 100 ms P95 (§11) | **non déclaré atteint** — cache `database`, pas Redis | — |
| IA (§9) | **non livré** | P10c |

**Un seuil clinique subsiste dans le code** : `TriageService::PLAFOND_ANTECEDENTS = 20`. Il ne
décide d'aucun niveau — il borne la contribution d'une des trois parts du score. Son porteur est
**P10b-3**, où l'assemblage du score devient lui-même protocolaire. C'est dit plutôt que déguisé.

---

## 2. Prérequis

### 2.1 Migration et jeu de démonstration

```bash
cd services/api
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan migrate
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan db:seed --class=ProtocoleSeeder
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan serve --host=0.0.0.0 --port=8000
```

Le seeder ouvre **trois brouillons** et n'en publie **aucun** : publier depuis un seeder
contournerait le quatre-yeux du §10 dès le premier jour.

### 2.2 SECONDE ÉTAPE DE DÉPLOIEMENT — sans elle, le triage répond 503

La partie 1 en imposait déjà une (le référentiel des symptômes). **Il y en a maintenant deux**, et
la seconde exige les quatre validations du §7. Voir le script complet en fin de guide (§7).

**Le 503 n'est pas une panne, c'est la garantie.** Un repli sur des seuils par défaut laisserait un
oubli de publication passer inaperçu : le triage rendrait des niveaux que personne n'a validés, en
croyant appliquer un protocole.

---

## 3. Scénarios backend (curl reproductibles)

```bash
BASE=http://localhost:8000/api/v1
curl -s "$BASE/symptomes" | grep -o '"id":[0-9]*,"nom_fr":"[^"]*"'
```

### V1 — Le refus bruyant, sur les trois surfaces

**À jouer AVANT l'étape 2.2.**

```bash
curl -i "$BASE/protocoles/TRIAGE-NIVEAU"
curl -i -X POST "$BASE/triage/analyser" -H "Content-Type: application/json" -d '{"symptomes":[1]}'
curl -s "$BASE/protocoles"
```

Attendu : **404** avec son motif · **503** sur l'analyse · le registre reste lisible et annonce
`"version_en_vigueur": null`.

Le 404 **nomme sa raison** (« aucune version n'a franchi les quatre validations du §7 »). Un 404
muet ne prouverait rien : il pourrait venir d'un protocole introuvable.

### V2 — Les quatre niveaux patient sortent du protocole

Après l'étape 2.2, avec le jeu de symptômes seedé :

| Symptômes | Score | Niveau attendu |
|---|---|---|
| Courbatures | 8 | `faible` |
| Fièvre élevée + Douleur abdominale | 40 | `recommandee` |
| Fièvre + abdo + courbatures + dent | 56 | `rapide` |

Chaque réponse porte `niveau`, `niveau_libelle`, `protocole` (code + version) et
`regles_declenchees`.

### V3 — LE DRAPEAU ROUGE PRIME, ET SA PRIORITÉ EST UNE DONNÉE

Douleur dentaire (poids 8) **+** Convulsions (drapeau rouge) :

Attendu : `score_severite: 90`, `niveau: "urgence"`, et surtout `regles_declenchees` montre
**deux** règles enchaînées :

```
ordre 1 — Un signe critique relève le score au niveau d'urgence
ordre 5 — Score de 76 à 100 : Urgence
```

C'est le chaînage avant : la règle d'ordre 1 relève le score, la bande suivante le lit relevé.
**Aucune priorité n'est codée** — c'est l'ordre d'une règle, en base.

### V4 — LE VECTEUR CENTRAL : modifier le protocole change la conclusion

Ouvrir une v2, recopier les règles, changer la bande haute de `urgence` à `faible`, faire signer
les quatre relecteurs, publier — puis rejouer V3.

La **même entrée** (score 90) rend `urgence` sous la v1 et `faible` sous la v2, **sans qu'une
ligne de code ait bougé**. Si un repli subsistait dans `TriageService`, ce vecteur ne bougerait pas.

### V5 — Les quatre validations du §7, et le refus qui NOMME la manquante

Publier avec trois validations sur quatre → **409** : « il manque la validation **technique** ».
Un refus « validation incomplète » obligerait le rédacteur à deviner laquelle des quatre.

### V6 — Le quatre-yeux (§10), refusé par son motif

Faire publier la version par **son propre rédacteur** → **409** : « Le rédacteur d'une version ne
peut pas la publier lui-même ». Vérifier le **motif**, pas seulement le code : un 403
d'habilitation passerait pour un quatre-yeux qui fonctionne.

### V7 — L'ANTI-SUBSTITUTION, la garde la plus importante

Après les quatre signatures, modifier le contenu **sans le rendre invalide** (changer le niveau
d'une bande, pas ses bornes), puis tenter de publier.

Attendu : **409** — « Le contenu du protocole a été modifié depuis sa relecture : les validations …
ne portent plus sur ce texte. Publier maintenant mettrait en vigueur des règles cliniques que
personne n'a relues. »

`GET /protocoles/TRIAGE-NIVEAU/versions/2/validations` affiche `porte_sur_le_contenu_actuel: false`
sur les quatre. Re-signer les quatre puis publier → **200** : la garde exige la **fraîcheur**, pas
l'immobilité.

**La modification de test doit rester VALIDE.** Élargir une bande créerait un recouvrement, et le
contrôle qualité refuserait *avant* l'anti-substitution : le vecteur passerait pour la mauvaise
raison. (Défaut réel de la première rédaction de ce test.)

### V8 — Les contrôles techniques du §7.4

| Manipulation sur le brouillon | Attendu |
|---|---|
| Un trou entre deux bandes (0-20 puis 26-50) | **422** « Trou dans les bandes de score » |
| Un recouvrement (20-50 sur 0-25) | **422** « Recouvrement des bandes » |
| Retirer le `MESSAGE` d'une règle de niveau | **422** « fixe un niveau sans dire au patient quoi faire » |
| Un fait inconnu (`temperature`) | **422**, avec la liste des faits connus |
| Supprimer toutes les références | **422** « une recommandation clinique sans source » |

Le **trou** est le contrôle central : c'est le seul défaut de cette famille qui **ne fait aucun
bruit**. Il se publierait sans erreur et n'apparaîtrait qu'au premier patient tombant dedans.

### V9 — L'estampille médico-légale (§6.1)

```sql
SELECT id, niveau, protocole_code, protocole_version, referentiel_version FROM triages ORDER BY id;
```

Les triages neufs portent `TRIAGE-NIVEAU` et son numéro de version. Les triages **antérieurs à
P10b restent à `NULL`** — leur attribuer une version serait un mensonge d'archive. Envoyer
`"protocole_version": 999` dans la requête : la base porte la **vraie** version.

### V10 — Le message vient du protocole, le numéro du référentiel

Le texte de recommandation d'un cas urgent contient **185** et **aucun `{urgence:…}`**. La consigne
est dans le protocole, le numéro dans le référentiel national (P6.8e) : chacun se corrige par sa
propre porte — changer un numéro d'urgence n'a pas à repasser par quatre validations cliniques.

### V11 — La chaîne d'audit (§10)

`GET /protocoles/journal/integrite` (authentifié) → `intacte: true`.

Réécrire un `acteur_nom` en base → `intacte: false`, rupture **CONTENU**. Rétablir →
`intacte: true`. Le journal **nomme** les acteurs (« Awa Relectrice », pas « Système ») et ne
contient **aucun contenu clinique** : ni « SAMU », ni « urgences », ni posologie.

### V12 — §6.1 : une version archivée reste consultable indéfiniment

`GET /protocoles/TRIAGE-NIVEAU/versions/1` → `etat: "archive"`, contenu complet, empreinte
inchangée. C'est ce qui rend une décision passée explicable.

---

## 4. LE POINT LE PLUS IMPORTANT À VÉRIFIER — aucun traitement n'est applicable

**Décision propriétaire N3 du 2026-08-19.**

```bash
curl -i "$BASE/protocoles/PROT-CI-PALU-SIMPLE"
curl -i "$BASE/protocoles/PROT-CI-HTA-SUIVI"
```

```sql
SELECT p.code, v.etat,
       (SELECT COUNT(*) FROM protocole_validations x WHERE x.version_id = v.id) AS validations
FROM protocole_versions v JOIN protocoles p ON p.id = v.protocole_id;
```

Attendu : **404** sur les deux · état `brouillon` · **zéro validation** · `organisme` vaut
« Source non fournie — aucun document d'autorité consulté » · `auteur` est `NULL`.

Le moteur ne sait pas lire un brouillon — ce n'est pas une politique, c'est une incapacité.

**Pourquoi c'est la vérification la plus importante.** Publier un protocole thérapeutique
exigerait de seeder ses quatre validations, donc d'inscrire dans une chaîne d'audit **immuable**
qu'un médecin spécialiste et le Ministère de la Santé ont validé une posologie. Le §7 dit
« opposable » : c'est la pièce qu'on produirait devant un tribunal. Partout ailleurs dans ce
projet, un jeu de démonstration fabrique une donnée fausse ; **ici il fabriquerait une validation
clinique fausse.**

---

## 5. Mobile (Expo Go SDK 54)

| # | Écran | Vérifier |
|---|---|---|
| M1 | Triage → résultat | Le badge affiche l'un des **quatre** niveaux, avec sa couleur du design system |
| M2 | Triage → résultat | Le libellé vient du serveur (« Consultation recommandée », pas « MODÉRÉ ») |
| M3 | Résultat d'un cas à drapeau rouge | Badge **Urgence** en rouge, bouton SOS proéminent |
| M4 | Historique | Un triage **antérieur à P10b** s'affiche encore, avec son ancien libellé |
| M5 | Protocole non publié | Message d'erreur, **jamais** un niveau inventé |

**M4 est le vecteur de non-régression du vocabulaire** : les trois valeurs héritées
(`leger`/`modere`/`urgent`) doivent rester lisibles. Les convertir changerait ce qu'un patient a
réellement lu sur son écran.

---

## 6. Limites annoncées (à ne pas signaler comme des défauts)

1. **Aucun protocole thérapeutique applicable** — décision N3, vérifiée au §4.
2. **Le contenu du protocole de triage est un jeu de démonstration**, et il l'était déjà : les
   bandes reprennent les seuils du Module 1, redécoupés en quatre. `niveau_preuve = 'D'`, le plus
   bas, et c'est la vérité. Le gain n'est pas qu'ils soient justes — c'est qu'ils soient
   **relisibles, signés et corrigibles sans déploiement**.
3. **Aucun écran d'authoring** : la gouvernance passe par l'API, comme les dix référentiels de P6.
4. **MFA non exigé** sur ces routes (§10 le demande) : `MFA_ENFORCE` est fermé depuis P1 —
   « prêt à activer », pas actif.
5. **Évaluation sous 100 ms P95 non déclarée atteinte** : le cache est `database` et non Redis.
6. `PLAFOND_ANTECEDENTS = 20` reste dans le code (voir §1).
7. **Deux étapes de déploiement** sont désormais nécessaires avant qu'un triage fonctionne.

---

## 7. Script de mise en vigueur (étape 2.2)

À exécuter une fois, après la migration et le seeder. Il exige **deux comptes** : le quatre-yeux
du §10 ne se contourne pas.

```
artisan tinker --execute="
$g = app(App\Services\Protocole\ServiceGouvernanceProtocole::class);
$v = App\Models\Protocole::where('code','TRIAGE-NIVEAU')->firstOrFail()
        ->versions()->where('etat','brouillon')->firstOrFail();
$r = App\Models\User::find(4);
$r->syncPermissions(array_values(App\Services\Protocole\ServiceGouvernanceProtocole::PERMISSIONS_VALIDATION));
$d = App\Models\User::find(6); $d->givePermissionTo('protocole.publier');
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
foreach (array_keys(App\Services\Protocole\ServiceGouvernanceProtocole::PERMISSIONS_VALIDATION) as $t) {
    $g->valider($v, $r->fresh(), $t, 'favorable', 'Relecteur '.$t);
}
echo 'v'.$g->publier($v->fresh(), $d->fresh())->numero;
"
```

Adapter les identifiants `find(4)` / `find(6)` aux comptes réels : ils doivent être **distincts**,
sans quoi la publication est refusée par le quatre-yeux — ce qui est le comportement attendu.

---

# Partie 3 — P10b-2 : sélecteur, ordre de priorité §3, conflits §8, journal d'exécution §10

> **Ajoutée le 2026-08-20.** Écrite avant le G4, conservée après le G5 comme procédure de
> non-régression. Elle **ne remplace ni la partie 1 ni la partie 2** : P10a et P10b-1 restent en
> vigueur, et leurs scénarios continuent de s'appliquer tels quels.

## 1. Ce que cet incrément change, en une phrase

Le triage n'applique plus **un** protocole désigné par son code : il applique **tous** ceux qui sont
en vigueur pour le contexte `triage`, et quand deux d'entre eux divergent, c'est l'ordre du §3 qui
tranche — pas le code.

### Ce qu'il livre

- un **sélecteur** : quels protocoles s'appliquent (pays, contexte déclaré, version en vigueur, non
  périmée) ;
- la **cascade §3/§8** : rang national > régional > OMS > société savante > hospitalier, puis le
  plus récent, puis le meilleur niveau de preuve ;
- le **journal d'exécution §10**, chaîné et append-only, avec les divergences consignées ;
- `POST /protocoles/evaluer`, le contrat du §9.1 ;
- un **second protocole de démonstration** (`TRIAGE-NIVEAU-REGIONAL`), sans lequel rien de tout cela
  ne serait exercé par du contenu réel.

### Ce qu'il ne livre PAS, et qu'il ne faut pas chercher

- **aucun écran** : ni pour l'évaluation, ni pour le journal, ni pour les divergences ;
- **aucun protocole thérapeutique applicable** — ils restent des brouillons non validés (décision
  N3, inchangée) ;
- les critères **4 et 5** du §8 (avis de la spécialité, validation du médecin) : ce sont des actes
  humains, ils ne sont pas automatisés et ne le seront pas ;
- le **questionnaire adaptatif** (P10b-3).

---

## 2. Préparation — TROIS étapes de déploiement, désormais

La partie 2 en annonçait deux. Il y en a trois si l'on veut voir la cascade fonctionner ; les deux
premières restent obligatoires pour que le triage réponde tout court.

| # | Ce qu'il faut publier | Sans quoi |
|---|---|---|
| 1 | le référentiel `seuils_mesure` (L1+L2) et `symptomes_triage` (P10a) | `GET /symptomes` et `POST /triage/analyser` répondent **503** |
| 2 | le protocole `TRIAGE-NIVEAU` (P10b-1) | `POST /triage/analyser` répond **503** |
| 3 | *(facultatif)* le protocole `TRIAGE-NIVEAU-REGIONAL` | la cascade §3 n'a rien à départager |

### 2.0 — ATTENTION : les protocoles publiés AVANT P10b-2 doivent être republiés

Une version publiée avant cet incrément ne déclare **aucun contexte** dans son instantané. Le
sélecteur lit l'instantané, jamais la table : elle cesse donc d'être sélectionnée, et
`POST /triage/analyser` répond **503** au lendemain du déploiement.

Ce n'est pas un défaut : c'est la même bascule que L1+L2 pour `seuils_mesure`. Renseigner
`protocoles.contextes_json` **ne suffit pas** — il faut ouvrir une nouvelle version et la publier
par le cycle §7 complet. *Un champ d'application qu'un `UPDATE` suffirait à élargir serait un
champ d'application que personne n'a relu.*


Les étapes 1 et 2 sont décrites dans les parties 1 et 2 — ne les refaites pas si elles sont déjà
faites sur cette base.

### 2.1 Publier le protocole régional (étape 3)

Comme toujours : **deux comptes distincts**, l'un qui valide, l'autre qui publie. Le quatre-yeux du
§10 ne se contourne pas, et un raccourci ici prouverait le contraire de ce que la gouvernance
garantit.

```
# 1. les quatre validations du §7 (compte A)
POST /api/v1/protocoles/TRIAGE-NIVEAU-REGIONAL/versions/1/valider
     { "type": "clinique",      "avis": "favorable", "validateur_nom": "Dr …" }
     { "type": "reglementaire", "avis": "favorable", "validateur_nom": "…" }
     { "type": "scientifique",  "avis": "favorable", "validateur_nom": "…" }
     { "type": "technique",     "avis": "favorable", "validateur_nom": "…" }

# 2. la publication (compte B, différent du rédacteur)
POST /api/v1/protocoles/TRIAGE-NIVEAU-REGIONAL/versions/1/publier
```

---

## 3. Les vecteurs

### V1 — Avec un seul protocole, rien ne change

Refaites **n'importe quel scénario de la partie 2**. Le résultat doit être identique : mêmes
niveaux, même estampille, même message.

*C'est le vecteur le plus important de cette partie.* Le sélecteur s'intercale entre le triage et le
moteur ; s'il changeait quoi que ce soit quand il n'y a rien à sélectionner, il changerait des
décisions de santé sans qu'aucune décision humaine ne l'ait voulu.

### V2 — Le national l'emporte sur le régional

Une fois `TRIAGE-NIVEAU-REGIONAL` publié, faites un triage pour **un enfant de moins de 5 ans** dont
le score tombe entre 26 et 50.

Attendu :
- le niveau rendu est celui du **national** (`recommandee`), pas celui du régional (`rapide`) ;
- `protocole.code` dans la réponse vaut **`TRIAGE-NIVEAU`** ;
- le régional apparaît quand même dans le journal comme **évalué**.

### V3 — Le protocole écarté garde ses autres recommandations

Sur le même triage, l'orientation vers la **pédiatrie** doit être présente.

*Le §3 est un ordre de départage, pas d'exclusion.* Un protocole qui perd sur le niveau n'est pas
mis à la poubelle : ce qu'il dit d'autre reste. Si l'orientation pédiatrique disparaissait, la
cascade se comporterait comme un filtre, et la moitié du contenu régional deviendrait inutile.

### V4 — La divergence est consignée, avec les DEUX valeurs

```
GET /api/v1/protocoles/applications
GET /api/v1/protocoles/applications/{trace_id}
```

Attendu dans le détail :
- un conflit sur `DEFINIR_NIVEAU` ;
- `retenu` : valeur `recommandee`, protocole `TRIAGE-NIVEAU`, source `national` ;
- `ecarte` : valeur `rapide`, protocole `TRIAGE-NIVEAU-REGIONAL`, source `regional` ;
- `critere` : **`rang`**.

Les deux côtés sont conservés : le §8 exige de pouvoir présenter *les deux* recommandations et leurs
sources. Ne garder que la gagnante rendrait le départage incontestable — au mauvais sens du mot.

### V5 — Un enfant hors de la bande : aucun conflit

Refaites le même triage avec un **adulte**. La règle régionale ne se déclenche pas :

- aucun conflit consigné ;
- le régional apparaît dans le journal avec `a_contribue: false`.

Ce n'est **pas** une anomalie : un protocole sélectionné qui ne dit rien sur ce cas-là ne dit rien,
voilà tout.

### V6 — On ne peut pas publier une version que seule la date départagerait

Tentez de publier un second protocole **national**, de même niveau de preuve, qui fixe lui aussi le
niveau. Attendu : **refus 422**, avec un détail qui **nomme** le protocole concurrent.

*C'est le contrôle central de cet incrément.* Les quatre validateurs du §7 ont relu ce protocole
**isolément** ; personne ne leur a montré celui qui était déjà en vigueur. Laisser passer ferait
basculer des décisions au moment de la publication, pour des cas que personne n'a examinés — et le
départage se ferait sur le **calendrier**.

Vérifiez ensuite que le **même** protocole est publiable :
- déclaré `regional` → **accepté** (le rang départage) ;
- déclaré `national` avec un niveau de preuve **A** → **accepté** (la preuve départage).

### V7 — Un protocole sans contexte déclaré est refusé à la publication

Attendu : **422**, avec la liste des contextes admis.

Un protocole publié qui ne déclare aucun contexte serait en vigueur et pourtant **muet** : le
sélecteur ne le retiendrait jamais. C'est le genre de panne qui ne fait aucun bruit.

### V8 — La couverture des niveaux est vérifiée sur l'ENSEMBLE, plus protocole par protocole

Tentez de publier un protocole de triage **complet** dont les bandes laissent un trou (par exemple
0-25, 51-100). Attendu : **refus 422** nommant l'intervalle non couvert.

Puis vérifiez l'inverse : le **régional**, qui ne couvre qu'un cas particulier et laisse donc
« des trous » partout, se publie sans difficulté.

*C'est un défaut de b-1 corrigé ici.* La couverture y était vérifiée protocole par protocole — exact
tant qu'un seul protocole existait, et **interdisant toute surcouche** dès qu'il y en a deux.

### V8-bis — Un `UPDATE` sur `contextes_json` reste sans effet

```sql
UPDATE protocoles SET contextes_json = '["consultation"]' WHERE code = 'TRIAGE-NIVEAU';
```

Refaites un triage : il fonctionne toujours. La colonne est une table de **travail** ; c'est
l'instantané publié qui décide. Elle ne prendra effet qu'à la publication suivante.

N'oubliez pas de remettre la valeur d'origine.

### V9 — Le journal d'exécution est immuable, et ça se vérifie

```
GET /api/v1/protocoles/applications/integrite   → { "intacte": true, "entrees": N }
```

Puis, en SQL direct sur la base :

```sql
UPDATE protocole_applications SET contexte = 'consultation' WHERE id = 1;
-- attendu : ERROR 1644 (45000) : protocole_applications_append_only
DELETE FROM protocole_applications WHERE id = 1;
-- attendu : ERROR 1644 (45000)
```

Le déclencheur rend l'altération **impossible** par les voies ordinaires ; la chaîne la rend
**détectable** si quelqu'un retire le déclencheur. Aucune des deux ne rattrape l'autre.

### V10 — Le journal ne porte ni nom ni symptôme en clair

```sql
SELECT * FROM protocole_applications\G
```

Le patient est désigné par ses **identifiants** (`membre_id`, `user_id`, `triage_id`), jamais par
son nom ; aucun libellé de symptôme n'apparaît.

Ce journal contient en revanche les **recommandations** — c'est le §10 qui l'exige, et c'est sa
raison d'être : un journal d'exécution qui tairait ce qui a été recommandé ne servirait à rien le
jour d'un litige.

### V11 — La décision finale reste vide sur un triage citoyen

Dans le détail d'une évaluation issue d'un triage :

```
"professionnel_id": null,
"decision_finale": null,
"ecart_justification": null
```

**C'est voulu, et c'est une limite écrite.** Le §10 nomme ces trois champs ; le triage citoyen n'a
personne pour décider. Les rendre explicitement nuls, plutôt que de les omettre, évite de faire
passer une absence structurelle pour un défaut d'affichage.

### V12 — `POST /protocoles/evaluer` est gardé

Sans la permission `protocole.evaluer` : **403**. Avec : **201**, et la réponse porte les clés du
§9.1 — `recommandations`, `conflits`, `trace_id`, `questions_suivantes` (vide jusqu'à P10b-3).

Un professionnel peut consigner sa décision **dans le même appel** (`decision_finale`,
`ecart_justification`). Elle ne se rattrape pas ensuite : le journal est append-only, et compléter
après coup serait réécrire le passé.

---

## 4. Ce qu'il faut vérifier même si tout semble marcher

1. **`GET /api/v1/protocoles/applications/integrite` répond, et n'est pas prise pour un `trace_id`.**
   Sans le bon ordre de déclaration des routes, elle répondrait 404 — un défaut qui ne casse rien et
   ne se voit pas.
2. **Un triage écrit exactement UNE entrée au journal.** Deux entrées signifieraient qu'un chemin
   d'écriture a été dupliqué ; zéro, qu'une décision de santé a été rendue sans trace.
3. **`triages.protocole_code` désigne le protocole qui a EMPORTÉ le niveau**, pas le premier évalué.

---

## 5. Limites de cet incrément, à ne pas prendre pour des défauts

1. Le rang **`hospitalier`** du §3 n'a **aucune portée réelle** : aucun protocole ne peut être
   rattaché à un établissement, faute d'écran où un hôpital rédigerait le sien.
2. Les critères **4 et 5** du §8 ne sont pas implémentés — actes humains.
3. La **présentation d'un conflit à un médecin** est conçue, pas activée : le champ `conflits` est
   rendu par l'API, aucun écran ne l'affiche.
4. **Contenu de démonstration** : `TRIAGE-NIVEAU-REGIONAL` est **inventé**, `niveau_preuve = 'D'`,
   organisme « source non fournie ». Il n'a été fourni par aucune direction régionale. Il existe
   pour que la sélection et le départage soient exercés par du contenu réel plutôt que par des
   tests seuls.
5. Le **§11 (< 100 ms)** n'est toujours pas déclaré atteint, et la chaîne du journal ajoute une
   écriture sérialisée par évaluation.
6. **La chaîne de GOUVERNANCE (`/protocoles/journal/integrite`) répond aujourd'hui
   `intacte: false`**, et ce n'est pas P10b-2 qui l'a rompue : `protocole_journal.acteur_id` est
   une clé étrangère `nullOnDelete` **prise dans l'empreinte**, et la restauration du G2 de
   P10b-1 a supprimé ses comptes temporaires. Seize entrées portent `acteur_id = NULL`.

   *On ne répare pas une chaîne de hachage* : recalculer les empreintes reviendrait à réécrire
   l'histoire, ce que la chaîne existe pour rendre impossible. Le journal d'**exécution** de
   P10b-2 ne reproduit pas le défaut — ses identifiants ne sont pas des clés étrangères.
   La décision (vivre avec une rupture datée, ou repartir d'une chaîne neuve en archivant
   l'ancienne) appartient au propriétaire.

---

# Partie 4 — P10b-3-i : questionnaire adaptatif, bornes opposables, `triage_reponses`

> Incrément livré le 2026-08-20. Il **referme le constat X3 du G0** : l'impact d'une réponse sur le
> score était une règle médicale gouvernée par deux signatures administratives (§10) alors que
> P10b-1 venait de soumettre les seuils de niveau, de même nature, aux **quatre validations du §7**.

## 1. Ce que cet incrément change, en une phrase

Les questions du triage ne sont plus une propriété des symptômes : elles vivent dans le protocole
`TRIAGE-QUESTIONNAIRE`, elles sont posées **selon les réponses précédentes**, et les bornes qu'elles
publient sont désormais **opposables**.

### Ce qui se voit tout de suite

| Avant | Après |
|---|---|
| toutes les questions de tous les symptômes cochés, d'un bloc | seulement celles qu'une règle déclenche, tour par tour |
| `intensite = 100` sur une échelle 1-10 → acceptée, score saturé à 100 | **422** nommant la question, aucun triage enregistré |
| une clé inconnue → 0 point **en silence** | **422** |
| réponses dans `triages.reponses_json` | table `triage_reponses` (CDC_04 §115), énoncé **figé** |
| `GET /symptomes` servait `questions_complementaires_json` | la clé a disparu de la réponse |

---

## 2. Préparation — QUATRE étapes de déploiement, désormais

La partie 3 en annonçait trois.

| # | Ce qu'il faut publier | Sans quoi |
|---|---|---|
| 1 | référentiels `seuils_mesure` et `symptomes_triage` | `GET /symptomes` et `POST /triage/analyser` → **503** |
| 2 | protocole `TRIAGE-NIVEAU` | `POST /triage/analyser` → **503** |
| 3 | **protocole `TRIAGE-QUESTIONNAIRE`** *(nouveau)* | `POST /triage/questions` **et** `/analyser` → **503** |
| 4 | *(facultatif)* `TRIAGE-NIVEAU-REGIONAL` | la cascade §3 n'a rien à départager |

### 2.1 Publier le questionnaire (étape 3)

Comme toujours : **deux comptes distincts**. Le quatre-yeux du §10 ne se contourne pas.

```
# 1. les quatre validations du §7 (compte A)
POST /api/v1/protocoles/TRIAGE-QUESTIONNAIRE/versions/1/valider
     { "type": "clinique",      "avis": "favorable", "role": "Medecin specialiste" }
     { "type": "reglementaire", "avis": "favorable", "role": "..." }
     { "type": "scientifique",  "avis": "favorable", "role": "..." }
     { "type": "technique",     "avis": "favorable", "role": "..." }

# 2. la publication (compte B, différent du RÉDACTEUR)
POST /api/v1/protocoles/TRIAGE-QUESTIONNAIRE/versions/1/publier
```

### 2.0 — ATTENTION : `symptomes_triage` doit être REPUBLIÉ

Une version publiée **avant** cet incrément porte encore `questions_complementaires_json` dans son
instantané. Rien ne la lit plus, mais le référentiel diffusé ne correspond alors plus à ce qu'une
extraction fraîche produirait. Le G2 l'a mesuré : empreinte publiée `23558142…` contre extraction
`d5209f33…`.

Republier (proposition par un compte, publication par un **autre**) ; l'ancienne version est
**archivée avec ses questions**, et c'est voulu — une archive ne se réécrit pas.

### 2.2 — Quatre pièges de l'API de gouvernance, relevés au G2

1. Le champ de rôle du validateur s'appelle **`role`**, pas `validateur_role` — sinon **422**.
2. `POST /protocoles/{code}/versions` exige **`version`** (le libellé, ex. `"2026.2"`).
3. Ce même appel crée un brouillon **VIDE** : il ne recopie ni les questions, ni les règles, ni
   `niveau_preuve` / `population`. Sans ces deux dernières, la publication est refusée **422** par
   les contrôles §4.1 — un refus juste, mais dont le motif surprend si on l'attribue au contenu.
4. Le quatre-yeux du §10 côté **protocole** signifie **publieur ≠ RÉDACTEUR**, pas
   publieur ≠ validateur : un relecteur qui porte `protocole.publier` **peut** publier ce qu'il a
   signé. C'est le choix délibéré de P10b-1 (le §7 n'interdit pas le cumul). Côté **référentiel**,
   la règle est l'inverse — l'auteur d'une proposition ne peut pas la valider lui-même.

   *Conséquence pour ce guide : un 403 rendu à un relecteur qui tente de publier prouve
   l'habilitation, PAS le quatre-yeux.*

**Le refus vaut même quand le patient ne répond à aucune question.** Sans lui, un oubli de
publication ferait trier des patients **sans jamais les interroger**, avec un score systématiquement
plus bas et rien pour le signaler.

---

## 3. Les vecteurs

### W1 — Le refus bruyant, avant publication

Avec `symptomes_triage` et `TRIAGE-NIVEAU` publiés mais **pas** le questionnaire :

```
POST /api/v1/triage/questions   { "symptomes": [5] }      → 503
POST /api/v1/triage/analyser    { "symptomes": [5] }      → 503
```

Le message doit parler du **questionnaire** et citer le §1.6. Un 503 rendu pour une autre raison ne
prouverait rien.

### W2 — L'arborescence du §4.3b

Symptôme **Difficulté respiratoire (essoufflement)** :

```
POST /api/v1/triage/questions  { "symptomes": [6] }
→ questions: [au_repos]        termine: false
```

Répondez **oui** :

```
POST /api/v1/triage/questions  { "symptomes": [6], "reponses": [{"cle":"au_repos","valeur":true}] }
→ questions: [intensite]       termine: false
```

**C'est le cœur de l'incrément** : `intensite` n'est posée par aucun symptôme ici — elle est
débloquée par la *réponse*. Répondez-y, et le tour suivant rend `termine: true`.

### W3 — Une question déjà répondue n'est jamais reposée

Rejouez le premier appel de W2 avec `au_repos` **et** `intensite` renseignées → `questions: []`,
`termine: true`. Sans cette garde, la boucle ne convergerait jamais.

### W4 — Le score ne dépend pas du nombre de tours *(vecteur obligatoire)*

Prenez **Toux** (id 5) et les réponses `duree_jours = 5`, `type_toux = grasse`.

1. Envoyez-les **d'un coup** à `/analyser` → notez `score_severite` et `niveau`.
2. Recommencez en passant réellement par `/questions`, tour après tour, puis `/analyser`.

**Les deux scores doivent être identiques.** S'ils diffèrent, une règle a été comptée deux fois :
la décision R5 (« une seule évaluation finale fait autorité ») n'est plus tenue.

### W4bis — Le drapeau rouge d'une RÉPONSE prime *(vecteur qui a trouvé un défaut réel)*

Deux triages à comparer :

```
POST /api/v1/triage/analyser  { "symptomes": [2] }                       # Frissons seuls
→ drapeau_rouge: false, niveau faible

POST /api/v1/triage/analyser
  { "symptomes": [2, 1], "reponses": [{"cle":"fievre_sup_40","valeur":true}] }
→ drapeau_rouge: true, score >= 90, niveau: urgence
```

« Fièvre élevée » n'est **pas** un drapeau rouge de symptôme : le niveau `urgence` ne peut venir que
du plancher posé par la **réponse**, via `DEFINIR_SCORE_MINIMUM`. C'est ce qui remplace
`drapeau_rouge_si_vrai`.

**Ce vecteur a trouvé un vrai défaut** : le service lisait le plancher dans les faits rendus par le
sélecteur, qui ne restitue le chaînage avant que du protocole ayant emporté une action **exclusive**
— un questionnaire n'en produit aucune, donc le plancher valait **toujours 0** et le drapeau rouge
d'une réponse était perdu **en silence**. Si ce vecteur retombe, c'est que la régression est revenue.

### W5 — Les bornes publiées sont opposables

```
POST /api/v1/triage/analyser
  { "symptomes": [7], "reponses": [{"cle":"intensite","valeur":100}] }
→ 422, message citant « Intensité de 1 à 10 ? »
```

Vérifiez ensuite `SELECT COUNT(*) FROM triages` : **aucun triage ne doit avoir été enregistré**.

La valeur **n'est pas écrêtée à 10**, et c'est délibéré : le patient croirait avoir répondu 100 et
son dossier porterait 10.

### W6 — Une clé inconnue est refusée, pas ignorée

```
{ "reponses": [{"cle":"question_inventee","valeur":3}] }  → 422 citant la clé
```

Avant cet incrément, elle valait **0 point en silence**.

### W7 — Une option hors catalogue est refusée

```
{ "reponses": [{"cle":"type_toux","valeur":"sifflante"}] }
→ 422 listant les réponses proposées
```

### W8 — Un `UPDATE` direct sur les symptômes n'a plus aucun effet

```sql
UPDATE symptomes
SET questions_complementaires_json = '[{"cle":"injectee","libelle":"Posée par UPDATE","type":"booleen"}]'
WHERE nom_fr = 'Toux';
```

Rejouez `POST /triage/questions { "symptomes": [5] }` → `injectee` **n'apparaît pas**, `duree_jours`
si. La règle ne se corrige plus qu'en republiant.

**Restaurez ensuite la colonne** (elle reste en base, plus personne ne l'écrit).

### W9 — Les questions ont quitté l'instantané des symptômes

```
GET /api/v1/referentiels/symptomes_triage
```

Aucune ligne ne doit porter `questions_complementaires_json`. Et l'**empreinte du référentiel a
changé** par rapport à la partie 1 : ce n'est pas une dérive, c'est le déménagement.

```
GET /api/v1/symptomes
```

La clé a également disparu de la liste servie au mobile.

### W10 — `triage_reponses` (CDC_04 §115)

Après un triage avec réponses :

```sql
SELECT question_cle, question_libelle, valeur, protocole_code, protocole_version
FROM triage_reponses WHERE triage_id = <id>;

SELECT reponses_json FROM triages WHERE id = <id>;   -- doit valoir []
```

`protocole_code` doit dire **`TRIAGE-QUESTIONNAIRE`** — pas `TRIAGE-NIVEAU`. Deux protocoles, deux
cycles de validation, deux estampilles.

### W11 — L'énoncé est figé

```sql
UPDATE protocole_questions SET libelle = 'Énoncé réécrit' WHERE cle = 'duree_jours';
SELECT question_libelle FROM triage_reponses WHERE question_cle = 'duree_jours';
```

Doit toujours dire **« Depuis combien de jours ? »**. Republier le questionnaire ne réécrit pas ce
qu'un patient a lu. *(Restaurez le libellé.)*

### W12 — Un triage antérieur reste lisible

Ouvrez la fiche d'un triage créé **avant** cet incrément : ses réponses viennent encore de
`reponses_json`, et `triage_reponses` est vide pour lui. Lui fabriquer des lignes serait un
**mensonge d'archive**.

### W13 — Le moteur refuse une échelle incohérente

```sql
INSERT INTO protocole_questions (version_id, cle, libelle, type, valeur_min, valeur_max, ordre,
                                 created_at, updated_at)
VALUES (<id>, 'absurde', 'Test', 'echelle', 10, 1, 99, NOW(), NOW());
→ ERROR 1644 : ck_protocole_question_bornes
```

`CHECK` impossible (`version_id` est `cascadeOnDelete` → **erreur 3823**, le mur de P6.3) : c'est un
déclencheur, dans les deux dialectes.

### W14 — Les gardes de publication (§7.4)

Sur un **brouillon**, vérifiez que chacune de ces modifications **bloque la publication** et que le
message la nomme :

| Modification | Message attendu |
|---|---|
| condition `reponse.duree` (au lieu de `duree_jours`) | cite `duree` et liste les questions de la version |
| action `POSER_QUESTION('fantome')` | cite `fantome` |
| `>=` sur `reponse.au_repos` (booléenne) | cite `au_repos` et le type |
| supprimer les réponses possibles de `type_toux` | cite `type_toux` |
| condition sur `score` | **nomme `score_symptomes`** comme alternative |

### W15 — Quatre-yeux et anti-substitution

- Le rédacteur ne peut pas publier lui-même → **409**, et **vérifiez le motif** : un refus rendu
  pour l'habilitation au lieu du quatre-yeux ne prouve rien (piège rencontré en P6.8e et P10a).
- Modifiez un **énoncé de question** après les quatre signatures, puis publiez → **409**, les
  validations sont **caduques**. Sans cela, il suffirait de faire signer un questionnaire anodin
  puis d'en changer les bornes.

---

## 4. Mobile (Expo Go SDK 54)

1. Onglet **Triage** → **Commencer** → cochez **Toux** → **Continuer**.
2. L'écran « Quelques précisions » affiche d'abord *Préparation du questionnaire…*, puis les
   questions **débloquées**, dans une carte unique — elles ne sont plus groupées par symptôme,
   parce qu'elles n'appartiennent plus à un symptôme.
3. Le bouton dit **« Continuer »** tant qu'il reste des questions, puis **« Analyser mes
   symptômes »**. C'est le **serveur** qui dit que l'interrogatoire est terminé.
4. Cochez **Difficulté respiratoire**, répondez **Oui** à la gêne au repos, **Continuer** → une
   question d'intensité apparaît, que rien n'avait annoncée. C'est l'arbre du §4.3b.
5. Les libellés des boutons de choix viennent du protocole (« Sèche », « Grasse ») ; leur **valeur**
   (`seche`, `grasse`) ne s'affiche jamais.

---

## 5. Limites de cet incrément, à ne pas prendre pour des défauts

1. **Le poids des symptômes et `PLAFOND_ANTECEDENTS = 20` restent dans le code** → P10b-3-ii. X3
   n'est refermé **que pour les réponses**.
2. **Aucun écran §7** : les quatre validations s'obtiennent toujours en curl. Un médecin
   spécialiste ne devrait pas signer par curl un document que le §7 qualifie d'*opposable* →
   P10b-3-ii.
3. **Un aller-retour réseau par tour.** Compiler l'arbre côté client l'éviterait et mettrait une
   règle médicale dans le front (CDC_01 §0.1). Atténué : le serveur rend **toutes** les questions
   déblocables à chaque tour, pas une seule.
4. **Le coefficient linéaire de l'échelle est devenu trois bandes.** L'ancien impact était
   `round(valeur × 1,2)` ; un moteur à liste blanche fermée n'exprime pas de formule, et ajouter une
   action « multiplier » ouvrirait dans la donnée une arithmétique que personne ne relirait.
   **Certains scores diffèrent d'un ou deux points de ceux du Module 1.** C'est un écart réel, dit
   ici plutôt qu'ignoré.
5. **Les conditions de déclenchement sont neuves** : il n'en existait aucune. Contenu de
   démonstration comme le reste — `niveau_preuve = 'D'`, source non fournie, aucun validateur forgé.
6. **Le protocole désigne les symptômes par leur `symptome_id`.** C'est la transcription exacte
   (la question appartenait à un symptôme précis, pas à sa famille), mais un identifiant technique
   ne veut rien dire hors de cette base — le reproche que P10a faisait à `specialite_id`. Les
   symptômes n'ont pas de code national ; tant qu'ils n'en auront pas, ce protocole est lié à cette
   installation.
7. **`triage_reponses` ne porte pas de colonne `points`.** Le plan G1 en prévoyait une ; cette
   valeur **n'existe plus** depuis que l'impact est une règle, car une règle peut porter sur
   plusieurs réponses et ses points ne se répartissent entre elles par aucun partage défendable.
   L'explication du score vit dans le journal d'exécution du §10 (P10b-2), qui nomme les règles
   déclenchées.
8. **§11 (< 100 ms)** toujours non déclaré atteint : cache `database`, et la boucle multiplie les
   appels.
9. **Aucune compréhension du langage naturel** (CDC_05 §5.5.1) → P10c. Le questionnaire adaptatif
   est précisément ce qui « permet le triage **sans IA** » (§13 étape 4).

---

## 6. Checklist de clôture

> **Clôturée le 2026-08-20 — G5.** Les cases **W** ont été prouvées au **G2 live** (base MySQL de
> développement sauvegardée avant, restaurée compte pour compte après : triages 2, protocoles 4,
> journal 34, users 8) ; les cases **M** relèvent du **G4, déclaré validé par le propriétaire**.
> La distinction est maintenue plutôt que fondue en une seule liste : *celui qui rejouera ce guide
> doit savoir lesquelles se rejouent en curl et lesquelles exigent un téléphone.*

- [x] W1 — schéma : 3 tables, 3 index uniques, 2 déclencheurs, **aucune colonne `points`**
- [x] W2 — **503 sur les deux endpoints** avant publication, message nommant le questionnaire
- [x] W3 — publication du questionnaire par **deux comptes distincts** (§10)
- [x] W4 — arborescence réelle : `au_repos=true` débloque `intensite`, `au_repos=false` ne débloque
      rien — **c'est le contraste qui est la preuve**
- [x] W5 — plancher live : 37 + 15 = 52 **relevé à 90** → `urgence` + drapeau ; `false` → 37
- [x] W6 — **score identique** en 1 tour, en 2 tours et à ordre de réponses inversé (23)
- [x] W7 — 5 refus **422 nommant chacun sa question** ; compteur de triages 9 → 10 (seul le valide
      est enregistré : on refuse, on n'écrête pas)
- [x] W8 — `UPDATE` direct sans effet ; le serveur pose toujours `duree_jours` / `type_toux`
- [x] W9 — republication de `symptomes_triage` : empreinte `23558142…` → `d5209f33…`, **v1 archivée
      gardant ses questions**
- [x] W10 — `triage_reponses` peuplée : libellé **figé**, `TRIAGE-QUESTIONNAIRE` (et non
      `TRIAGE-NIVEAU`), `reponses_json = []`
- [x] W11 — renommage au protocole → **l'archive ne suit pas** ; les deux formes d'archive sont lues
      (ancienne `valeur_impact`, neuve `libelle`)
- [x] W12 — `ERROR 1644` sur bornes **inversées** *et* **égales**
- [x] W13 — les **5 gardes §7.4** refusent chacune **par son motif** ; restauration → 0 erreur
- [x] W14 — **anti-substitution déclenchée par le seul renommage d'une QUESTION** (409 nommant les
      4 validations caduques) : la preuve que les questions sont dans l'empreinte
- [x] W15 — estampille **par version** (réponses anciennes `v1`, neuve `v2`) · **0 contenu clinique**
      au journal de gouvernance
- [x] M1 — questions débloquées tour par tour, carte unique *(G4 propriétaire)*
- [x] M2 — « Continuer » → « Analyser mes symptômes », décidé par le **serveur** *(G4 propriétaire)*
- [x] M3 — gêne au repos → question d'intensité **que rien n'avait annoncée** *(G4 propriétaire)*
- [x] M4 — libellés du protocole affichés, **valeurs jamais** *(G4 propriétaire)*
- [x] G3 vert — **1138 tests / 16 313 assertions, 0 échec** · typecheck ×3 · expo-doctor **18/18** ·
      Pint · **mutation 9/9**, arbre restauré et vérifié sans résidu

### Quatre écarts guide ↔ API relevés au G2 et corrigés dans ce guide

Ils sont consignés ici parce qu'ils feront trébucher quiconque rejouera la procédure :

1. le champ de validation s'appelle **`role`**, pas `validateur_role` ;
2. `POST /protocoles/{code}/versions` exige **`version`** ;
3. **le brouillon naît vide** (ni questions, ni règles, ni `niveau_preuve`/`population`) — d'où un
   422 §4.1 au motif déroutant si l'on publie trop tôt ;
4. le quatre-yeux **côté protocole** est **publieur ≠ RÉDACTEUR**, pas ≠ validateur (choix délibéré
   de P10b-1). Un 403 rendu à un relecteur prouve **l'habilitation**, pas le quatre-yeux — celui-ci
   se prouve **par son motif côté référentiel** (409 « l'auteur d'une proposition ne peut pas la
   valider lui-même », sur un compte qui *a* la permission).

> **Constat, non causé par cet incrément.** `GET /protocoles/journal/integrite` répond
> `intacte: false` sur l'entrée #1 : rupture déjà constatée en P10b-2 (`acteur_id` en `nullOnDelete`
> pris dans l'empreinte, comptes supprimés lors de la restauration du G2 de P10b-1). *On ne répare
> pas une chaîne de hachage* — la décision appartient au propriétaire.
