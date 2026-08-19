# GUIDE_TEST_TRIAGE.md — Triage et orientation (P10)

Guide de test du domaine **Triage**. Écrit avant le G4, conservé après le G5 comme procédure de
non-régression (règle propriétaire, CDC_01 §2.4).

| Partie | Incrément | Objet |
|--------|-----------|-------|
| **1** | **P10a** | Orientation après triage + gouvernance du triage + fiche §5.4 |
| **2** | **P10b-1** | Registre des protocoles médicaux + moteur de règles + le niveau de triage |
| *(à venir)* | P10b-2 | Sélecteur, ordre de priorité §3, conflits §8 |
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
