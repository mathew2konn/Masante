# GUIDE_TEST_TRIAGE.md — Triage et orientation (P10)

Guide de test du domaine **Triage**. Écrit avant le G4, conservé après le G5 comme procédure de
non-régression (règle propriétaire, CDC_01 §2.4).

| Partie | Incrément | Objet |
|--------|-----------|-------|
| **1** | **P10a** | Orientation après triage + gouvernance du triage + fiche §5.4 |
| **2** | **P10b-1** | Registre des protocoles médicaux + moteur de règles + le niveau de triage |
| **3** | **P10b-2** | Sélecteur, ordre de priorité §3, conflits §8, journal d'exécution §10 |
| **4** | **P10b-3-i** | Questionnaire adaptatif + bornes opposables + `triage_reponses` |
| **5** | **P10b-3-ii** | Borne des antécédents sous protocole + écran §7 de lecture et signature |
| **6** | **P10c-1** | Constantes cliniques du §5.2 |
| **7** | **P10c-2-i** | Le retour du soignant + le socle IA (§5.5.4) |
| **8** | **P10c-3-i** | Export anonymisant + entraînement réel + registre de gouvernance |
| **9** | **P10c-3-ii** (lot A) | Déploiement en observation + captation du diagnostic, de la spécialité et du niveau réel |

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

---

# Partie 5 — P10b-3-ii : la part des antécédents sous protocole + écran §7

> Ajoutée le 2026-08-21. Écrite avant le G4, conservée après le G5 comme procédure de
> non-régression. ADR-041 §B4 ; plan `docs/PLAN_G1_P10b3ii_Antecedents_Ecran7.md`.

## 1. Ce que cet incrément change, en une phrase

Le **dernier seuil** de `TriageService` (`PLAFOND_ANTECEDENTS = 20`) quitte le code pour un
protocole relu et signé, et les quatre validations du §7 se signent enfin **depuis un écran** au
lieu de `curl`.

### Ce qui se voit tout de suite

| Avant | Après |
|---|---|
| la borne des antécédents est une constante PHP | une règle publiée, relue par quatre validateurs |
| corriger la borne = livrer une version de l'application | une **publication**, sans une ligne de code |
| un médecin spécialiste signe par `curl` | il relit les règles **en français** et signe à l'écran |
| une validation périmée se déduit de deux empreintes | elle est **marquée caduque**, en toutes lettres |
| l'assemblage des faits vivait en **trois** exemplaires | une seule source (`FaitsTriage`) |

> **Ce que cet incrément ne fait PAS** : déplacer le poids des symptômes. Le plan G1 a conclu que ce
> serait une erreur (ADR-041 §B4.1), et l'asymétrie qui subsiste est nommée, pas tue.

---

## 2. Préparation — CINQ étapes de déploiement, désormais

`seuils_mesure` · `symptomes_triage` · `TRIAGE-NIVEAU` · `TRIAGE-QUESTIONNAIRE` ·
**`TRIAGE-ANTECEDENTS`**.

Tant que la cinquième manque, `POST /triage/analyser` répond **503** — **même pour un patient sans
aucun antécédent**. Sinon un oubli de publication passerait inaperçu sur la majorité des triages et
ne se signalerait que sur les autres.

Comptes nécessaires : un **relecteur** portant les quatre permissions `protocole.valider.*`, un
**publieur** portant `protocole.publier`, et — pour éprouver le quatre-yeux — un **rédacteur**
portant `protocole.rediger` **et** `protocole.publier`.

> **Piège** : ne bâtissez aucun de ces comptes sur le rôle `admin_ivoirsante`, qui reçoit **toutes**
> les permissions. Un vecteur monté sur lui est vert quoi qu'il arrive (leçon P6.6a) — cela s'est
> produit pendant ce G2 et a dû être repris. Utilisez un rôle de portail réel (`agent_garde`) et
> accordez les permissions **nominativement**.
>
> **Second piège** : la connexion au portail se fait par **e-mail**, pas par téléphone.

---

## 3. Les vecteurs (curl + SQL)

### W1 — Le refus bruyant, avec son motif

```bash
curl -s -X POST $API/triage/analyser -H 'Accept: application/json' \
  -H 'Content-Type: application/json' -d '{"symptomes":[14],"patient_age":30}'
```

Attendu : **503**, message contenant « **la mise en vigueur est une étape de déploiement, jamais un
repli du code** ». Le motif compte : un second refus existe (« aucune de ses règles ne s'applique »)
et il ne dit pas la même chose.

### W2 — La garde de l'écran

Sans aucune permission de protocole → **403**. Avec l'une des quatre → **200**, la liste montre
`TRIAGE-ANTECEDENTS`.

### W3 — Les règles sont lisibles

Sur la fiche de version : « **Borner la part des antécédents à 20** » et « **(toujours)** » —
une règle sans condition s'applique toujours, et l'écran le dit plutôt que d'afficher un vide qu'un
relecteur lirait comme une omission. Les quatre validations sont marquées « non signée ».

### W4 — Signer depuis l'écran

Quatre `POST …/valider` (type, avis, rôle). Attendu en base :

```sql
SELECT type, avis, validateur_nom, validateur_role FROM protocole_validations WHERE version_id = ?;
```

Chaque ligne nomme **le compte** et **le rôle déclaré** — c'est ce que le §7 appelle opposable.

### W5 — Publier depuis l'écran

Le relecteur (sans `protocole.publier`) est refusé en **nommant la permission** ; le publieur
réussit, et `publie_par` porte son identifiant.

### W6 — La borne s'applique réellement

Un membre déclarant **37** points d'antécédents :

- anonyme → `details_score.antecedents = 0`, score 8 ;
- avec carnet → `details_score.antecedents = **20**`, score **28**.

La somme brute (37) n'est **jamais** celle qui entre dans le score.

### W7 — Un `UPDATE` direct reste sans effet

```sql
UPDATE protocole_actions a JOIN protocole_regles r ON r.id = a.regle_id
   SET a.valeur_json = '[3]' WHERE r.version_id = ?;
```

La table dit `3`, le serveur applique toujours **20** : c'est l'instantané publié qui fait foi.

### W8 — Une validation caduque a l'air caduque

Signer les quatre validations d'un brouillon, puis modifier son contenu en SQL. La fiche passe de
**0** à **4** mentions de « caduque », avec la phrase « **ne vaut plus pour le texte** ».

### W9 — Publier par-dessus une relecture périmée est refusé

Le message **nomme les quatre validations et leur signataire**, et dit pourquoi : « Publier
maintenant mettrait en vigueur des règles cliniques que personne n'a relues. »

### W10 — Le quatre-yeux, prouvé PAR SON MOTIF

Donnez au rédacteur `protocole.publier` **en plus** de `protocole.rediger`, puis faites-le publier
sa propre version. Attendu : « **Le rédacteur d'une version ne peut pas la publier lui-même** ».

> Un 403 rendu à quelqu'un qui n'a pas la permission prouverait **l'habilitation**, pas le
> quatre-yeux. C'est le piège relevé en P6.8e et en P10b-1 ; le vecteur n'a de valeur que sur un
> compte qui *a* le droit de publier.

### W11 — La borne change avec la version, sans une ligne de code

Publier une v2 bornant à 5, puis rejouer W6 avec **le même patient et les mêmes antécédents** :

- part **20 → 5**, score **28 → 13**.

C'est ce que « le seuil quitte le code » veut dire.

---

## 4. Ce qu'il faut vérifier même si tout semble marcher

1. **Le brut n'entre jamais dans le score** : `details_score.antecedents` ne doit jamais valoir la
   somme déclarée quand celle-ci dépasse la borne.
2. **Aucun champ d'édition de règle** dans le HTML de la fiche (`name="libelle"` absent).
3. **La base restaurée** : cet incrément n'a aucune migration ; après le G2, `protocoles`,
   `protocole_versions`, `users` et `antecedents` doivent revenir à leur compte initial.

---

## 5. Limites de cet incrément, à ne pas prendre pour des défauts

1. **`poids_severite` et `drapeau_rouge` restent sous deux signatures** (§10) et non quatre.
   Porteur : un incrément de gouvernance du socle P6.3.
2. **`impact_triage` reste déclaré par le patient.** La borne est la réponse à cette absence de
   vérification, pas une incohérence avec elle (ADR-041 §B4.5).
3. **Aucun écran d'authoring** : un brouillon se construit par seeder ou par API.
4. **Deux bornes divergentes sont refusées, pas départagées** — plus strict que le §8, délibérément
   (§B4.7).
5. Le contexte `triage_questionnaire` porte désormais autre chose qu'un questionnaire.
6. Contenu de démonstration : `niveau_preuve = 'D'`, aucun validateur forgé, aucune autorité nommée.
7. **§11 (< 100 ms)** toujours non déclaré atteint.

---

## 6. Checklist de clôture

> **Clôturée le 2026-08-21 — G5.** Les cases **W** ont été prouvées au G2 live ; base de
> développement sauvegardée avant, **restaurée compte pour compte** après (protocoles 4, versions 5,
> validations 12, users 8, membres 1, antécédents 0, triages 2, `protocole_journal` 34).
> **G4 déclaré validé par le propriétaire.**

- [x] W1 — 503 avec le motif « mise en vigueur »
- [x] W2 — 403 sans permission / 200 avec
- [x] W3 — règle en français, « (toujours) », 4 validations « non signée »
- [x] W4 — quatre signatures depuis l'écran, nominatives
- [x] W5 — publication refusée au relecteur **en nommant la permission**, réussie au publieur
- [x] W6 — brut 37 → part **20**, score 8 → 28
- [x] W7 — `UPDATE` direct sans effet (base `3`, serveur `20`)
- [x] W8 — 0 → **4** mentions de « caduque » après modification
- [x] W9 — publication refusée, les quatre validations **nommées avec leur signataire**
- [x] W10 — quatre-yeux **par son motif**, sur un compte portant `protocole.publier`
- [x] W11 — **part 20 → 5, score 28 → 13** après publication d'une v2
- [x] G3 — **1179 tests / 16 430 assertions, 0 échec** ; 23 vecteurs dédiés ; Pint ;
      **mutation 6 tueuses + 1 volontairement verte**, arbre restauré et vérifié
- [x] G4 propriétaire *(déclaré validé le 2026-08-21)*

---

# Partie 6 — P10c-1 : les constantes cliniques du §5.2

> **Ajoutée le 2026-08-21.** Écrite avant le G4, conservée après le G5 comme procédure de
> non-régression. Elle **ne remplace aucune partie précédente** : les scénarios 1 à 5 restent en
> vigueur — avec **une étape de déploiement de plus**, et elle vient **en premier** (§2.2).

## 1. Périmètre — et ce que ce module ne fait PAS

### Ce qu'il livre

- **Le triage collecte des constantes cliniques** (température, pouls, saturation, tension, poids,
  glycémie), là où il ne collectait que des symptômes, un âge et un sexe.
- **Le §1.2 est retourné à l'endroit.** CDC_08 §1.2 donne son interdit littéral — « Interdit :
  `if temperature > 39: urgence = True` ». Cette phrase existe désormais dans le projet, **en
  donnée**, dans une version relue et signée par les quatre validateurs du §7, corrigible sans
  déploiement et estampillée sur chaque triage qu'elle a jugé.
- **Le carnet propose, le patient confirme** : une mesure récente pré-remplit le champ avec sa
  date ; une mesure ancienne est montrée pour information et **n'entre dans aucune règle**.
- **Les bornes du référentiel deviennent opposables au triage** : une valeur hors plage est
  **refusée**, jamais ramenée dans la plage.

### Ce qu'il ne fait pas — à lire avant de tester

| Attendu du corpus | État | Où |
|---|---|---|
| Microservice `triage-service`, XGBoost, SHAP (CDC_05 §5.1) | **non livré** | P10c-2 |
| Questionnaire personnalisé par l'IA (§5.5.2) | **non livré**, nommé comme limite | après P10c-2 |
| Compréhension du langage naturel (§5.5.1) | **non livré** | CDC_07 |
| `allergies` structurées (§5.2) | **aucune table dans le projet** | — |
| `duree_symptomes_heures`, `evolution`, `douleur` (§5.2) | **de la donnée, zéro code** — ce sont des questions de protocole (`reponse.duree_jours`, `reponse.intensite`) | version du questionnaire |
| Un 8ᵉ type de constante | **exige une migration** — l'ENUM de `referentiels_mesure` plafonne à 7 | — |

**Ce qui est délibérément absent, et c'est le point de conception** : il n'existe **aucun fait
`constante.temperature_statut`**. Le référentiel sait pourtant classer 39,5 °C en « critique », et
s'y adosser aurait été plus court. Mais `critique_haut` est gouverné par les **deux signatures
administratives** du §10, alors qu'un seuil décidant de l'urgence relève des **quatre validations**
du §7 — c'est l'asymétrie que P10b-3-i a passé un incrément entier à refermer. Le protocole compare
donc la **valeur brute**, et c'est là que le seuil est relu et signé.

**Les durées de fraîcheur sont un jeu de démonstration** : elles n'ont été confrontées à aucune
recommandation publiée et ne sont attribuées à aucune autorité. Les corriger est de la donnée,
zéro code — mais tant que ce n'est pas fait, ce ne sont pas des fenêtres nationales.

---

## 2. Prérequis

### 2.1 Migration et jeu de démonstration

```bash
cd services/api
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan migrate
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan db:seed --class=ReferentielMesureSeeder
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan serve --host=0.0.0.0 --port=8000
```

### 2.2 CINQUIÈME ÉTAPE DE DÉPLOIEMENT — ET ELLE PASSE EN PREMIER

Le triage exigeait déjà quatre mises en vigueur (`symptomes_triage`, `TRIAGE-NIVEAU`,
`TRIAGE-QUESTIONNAIRE`, `TRIAGE-ANTECEDENTS`). Il en faut une cinquième : **`seuils_mesure`**.

**Et son ordre n'est pas indifférent.** `TRIAGE-NIVEAU` porte désormais une règle sur
`constante.temperature` ; le contrôle qualité du §7.4 refuse une constante absente de la version
publiée des seuils. **Publier le protocole avant les seuils échoue.** L'ordre est :

```
1. seuils_mesure          (référentiel, deux agents habilités)
2. symptomes_triage       (référentiel, deux agents habilités)
3. TRIAGE-NIVEAU          (protocole : quatre validations §7 + publication)
4. TRIAGE-QUESTIONNAIRE
5. TRIAGE-ANTECEDENTS
```

Si vous obtenez « Le protocole ne satisfait pas les contrôles techniques du §7.4 », lisez le détail :
il **nomme** la constante manquante et liste celles de la version en vigueur.

---

## 3. Scénarios backend (curl reproductibles)

```bash
BASE=http://localhost:8000/api/v1
SYMPT=1   # un symptôme anodin quelconque de la version publiée
```

### W1 — Les constantes collectables sont servies, sans compte

```bash
curl -s "$BASE/triage/constantes" | python -m json.tool | head -30
```

**Attendu** : 7 lignes portant `type_mesure`, `libelle`, `unite`, `decimales`, `valeur_min`,
`valeur_max`, et **`proposition: null` / `contexte: null`** — sans carnet, il n'y a rien à proposer.
`referentiel_version` est renseignée.

### W2 — Une valeur hors bornes est REFUSÉE, et la borne est nommée

```bash
curl -s -X POST "$BASE/triage/analyser" -H 'Content-Type: application/json' \
  -d "{\"symptomes\":[$SYMPT],\"constantes\":[{\"type_mesure\":\"temperature\",\"valeur\":60}]}" \
  | python -m json.tool
```

**Attendu** : **422**, message citant `45` (la borne publiée) et la phrase
« La valeur n'est pas ramenée dans la plage ». Vérifier qu'aucune constante n'a été écrite :

```sql
SELECT COUNT(*) FROM triage_constantes;   -- inchangé
```

### W3 — Une précision de trop est refusée, pas arrondie

```bash
# le référentiel publie `decimales = 1` pour la température
curl -s -X POST "$BASE/triage/analyser" -H 'Content-Type: application/json' \
  -d "{\"symptomes\":[$SYMPT],\"constantes\":[{\"type_mesure\":\"temperature\",\"valeur\":39.5}]}"   # 201
curl -s -X POST "$BASE/triage/analyser" -H 'Content-Type: application/json' \
  -d "{\"symptomes\":[$SYMPT],\"constantes\":[{\"type_mesure\":\"temperature\",\"valeur\":39.55}]}"  # 422
```

**Attendu** : `39.5` accepté, `39.55` **refusé** en citant « au plus 1 décimale » — la borne
**publiée**. Sans ce refus, la valeur serait arrondie en silence et le dossier porterait une valeur
que le patient n'a pas saisie.

**Le miroir importe autant que le refus** : si `39.5` était refusé aussi, la garde ne prouverait
rien d'autre qu'un serveur qui dit non.

**Deux bornes de nature différente, la plus stricte l'emporte** : `decimales` est une donnée
**gouvernée** du référentiel publié ; `decimal(8,2)` est ce que la **colonne** sait porter. Si un
référentiel publiait trois décimales, c'est le stockage qui aurait le dernier mot et le message
nommerait « au plus 2 décimales » — la borne réellement appliquée, jamais la promesse.

> **Ce vecteur a été corrigé après le G2 live.** Le serveur acceptait `39.55` : seule la capacité de
> la colonne mordait, et la borne publiée était **décorative** — le défaut exact que cet incrément
> referme pour `valeur_min`/`valeur_max` (constat X4 de P10b-3-i), laissé ouvert un cran plus loin.

### W4 — Le nom du §5.2 n'est pas celui du projet

```bash
curl -s -X POST "$BASE/triage/analyser" -H 'Content-Type: application/json' \
  -d "{\"symptomes\":[$SYMPT],\"constantes\":[{\"type_mesure\":\"spo2\",\"valeur\":91}]}"
```

**Attendu** : **422** nommant `saturation_o2`. Le vocabulaire est **adopté**, pas réinventé
(principe P6.8a) : deux noms pour le même fait clinique feraient deux vérités.

### W5 — LE VECTEUR CENTRAL : le §1.2 en donnée

```bash
# Enfant de 4 ans, fièvre à 39,6
curl -s -X POST "$BASE/triage/analyser" -H 'Content-Type: application/json' \
  -d "{\"symptomes\":[$SYMPT],\"patient_age\":4,\"constantes\":[{\"type_mesure\":\"temperature\",\"valeur\":39.6}]}" \
  | python -m json.tool | grep -E 'niveau|score_severite|libelle'
```

**Attendu** : `niveau: "urgence"`, `score_severite >= 90`, et la règle **« Fièvre élevée chez le
jeune enfant »** dans `regles_declenchees`.

**Les trois miroirs, sans lesquels le vecteur ci-dessus ne prouverait qu'un score qui monte** :

```bash
# même fièvre, adulte  -> PAS urgence (la condition d'âge)
curl -s -X POST "$BASE/triage/analyser" -H 'Content-Type: application/json' \
  -d "{\"symptomes\":[$SYMPT],\"patient_age\":30,\"constantes\":[{\"type_mesure\":\"temperature\",\"valeur\":39.6}]}" | grep -o '"niveau":"[a-z]*"'

# enfant, fièvre sous le seuil -> PAS urgence
curl -s -X POST "$BASE/triage/analyser" -H 'Content-Type: application/json' \
  -d "{\"symptomes\":[$SYMPT],\"patient_age\":4,\"constantes\":[{\"type_mesure\":\"temperature\",\"valeur\":38.2}]}" | grep -o '"niveau":"[a-z]*"'

# enfant, AUCUNE constante -> PAS urgence, et le triage aboutit quand même
curl -s -X POST "$BASE/triage/analyser" -H 'Content-Type: application/json' \
  -d "{\"symptomes\":[$SYMPT],\"patient_age\":4}" | grep -o '"niveau":"[a-z]*"'
```

### W6 — Le seuil change avec la version publiée, sans une ligne de code

Corriger la règle en base **ne suffit pas** : il faut republier le protocole par le cycle §7
complet. Vérifier qu'un `UPDATE` direct sur `protocole_conditions.valeur_json` **ne change rien** au
triage rendu, puis republier et constater que le même patient change de niveau. C'est la garantie
de P10b-1, ici exercée sur une constante.

### W7 — Le client ne peut pas déclarer d'où vient sa valeur

```bash
curl -s -X POST "$BASE/triage/analyser" -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d "{\"symptomes\":[$SYMPT],\"membre_id\":$MEMBRE,\"constantes\":[{\"type_mesure\":\"temperature\",\"valeur\":38.4,\"origine\":\"reprise_du_carnet\",\"mesure_id\":4242}]}" \
  | python -m json.tool | grep origine
```

**Attendu** : `"origine": "saisie"`. En base :

```sql
SELECT origine, mesure_id FROM triage_constantes ORDER BY id DESC LIMIT 1;  -- saisie, NULL
```

### W8 — Le carnet propose, et seulement dans sa fenêtre

Créer pour un membre une température datée d'il y a **30 minutes**, puis :

```bash
curl -s "$BASE/triage/constantes?membre_id=$MEMBRE" -H "Authorization: Bearer $TOKEN" \
  | python -m json.tool
```

**Attendu** : la température en **`proposition`**, avec `date_mesure`. Recommencer avec une
température de **3 jours** : elle bascule en **`contexte`**, `proposition` reste `null` (fenêtre
publiée = 120 min).

**Le corollaire qui compte** : envoyer exactement la valeur du `contexte` à l'analyse donne
`origine: "saisie"` — elle n'a pas été proposée, donc la saisir reste une saisie.

### W9 — Anti-IDOR sur le carnet d'autrui

```bash
curl -s -o /dev/null -w '%{http_code}\n' "$BASE/triage/constantes?membre_id=$MEMBRE_AUTRUI" -H "Authorization: Bearer $TOKEN"   # 403
curl -s -o /dev/null -w '%{http_code}\n' "$BASE/triage/constantes?membre_id=$MEMBRE"                                            # 401
curl -s -o /dev/null -w '%{http_code}\n' "$BASE/triage/constantes"                                                              # 200
```

### W10 — Le triage n'écrit RIEN dans le carnet

```sql
SELECT COUNT(*) FROM mesures_sante;      -- avant
-- lancer un triage avec constantes, rattaché au membre
SELECT COUNT(*) FROM mesures_sante;      -- IDENTIQUE
SELECT COUNT(*) FROM triage_constantes;  -- +1
```

### W11 — La fiche §5.4 montre les constantes avec leur origine

```bash
curl -s "$BASE/triage/$TRIAGE_ID/fiche?jeton=$JETON" | python -m json.tool | grep -A6 constantes
```

**Attendu** : `type_mesure`, `valeur`, `unite`, `origine`, `referentiel_version`.

### W12 — La garde du moteur

```sql
-- Une constante qui se dit reprise du carnet doit dire LAQUELLE.
INSERT INTO triage_constantes (triage_id, type_mesure, valeur, unite, origine, mesure_id,
                               referentiel_version, created_at, updated_at)
VALUES (1, 'temperature', 38.0, '°C', 'reprise_du_carnet', NULL, 1, NOW(), NOW());
-- Attendu : ERROR 1644 (45000) : ck_triage_constante_origine

-- Un triage ne porte qu'une valeur par constante :
-- insérer deux fois le même couple (triage_id, type_mesure) -> ERROR 1062
```

---

## 4. Scénarios mobile (Expo Go SDK 54)

1. Onglet **Triage** → cocher un symptôme → **Continuer**.
2. L'écran **« Vos mesures »** apparaît entre les symptômes et les précisions. Vérifier :
   - les 7 champs, chacun avec son unité et sa plage en filigrane ;
   - **aucune couleur, aucun statut, aucun verdict** — l'écran ne juge rien ;
   - laisser tout vide et continuer : **le triage aboutit** (tout est facultatif).
3. Saisir `60` en température puis lancer l'analyse : le message d'erreur **du serveur** s'affiche,
   et la valeur **n'est pas corrigée** dans le champ.
4. Avec un compte et un membre ayant une mesure récente : le champ est **pré-rempli** et porte
   « Repris de votre carnet (il y a N min) ». Avec une mesure de 3 jours : le champ est **vide** et
   la ligne dit « Dernière valeur connue … Trop ancienne pour être reprise automatiquement ».
5. **Mode avion** : l'écran affiche son message d'erreur et laisse **continuer** — une liste de
   mesures indisponible n'est pas une panne du triage.

---

## 5. Invariants base de données

```sql
-- 1. La colonne de fraîcheur existe et n'est pas imposée
SHOW COLUMNS FROM referentiels_mesure LIKE 'fraicheur_max_minutes';   -- YES (nullable)

-- 2. Les deux déclencheurs
SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
 WHERE EVENT_OBJECT_TABLE = 'triage_constantes';   -- 2 lignes

-- 3. L'unicité
SHOW INDEX FROM triage_constantes WHERE Key_name = 'uq_triage_constante';

-- 4. `mesure_id` n'est PAS une clé étrangère
--    (ADR-042 D1 : un identifiant de trace est un identifiant, pas une relation vivante —
--     supprimer la mesure du carnet ne doit pas effacer d'où venait la valeur du triage)
SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_NAME = 'triage_constantes' AND REFERENCED_TABLE_NAME = 'mesures_sante';   -- 0

-- 5. Aucune constante n'est orpheline de version
SELECT COUNT(*) FROM triage_constantes WHERE referentiel_version IS NULL;   -- 0
```

---

## 6. Limites annoncées (à ne pas signaler comme des défauts)

1. **Aucune IA** — c'est P10c-2. Cet incrément livre la collecte et le gain de protocole.
2. **Aucune allergie structurée** dans le projet : le §5.2 reste partiellement irreprésentable.
3. **Durées de fraîcheur = démonstration**, non confrontées à une recommandation publiée.
4. **La règle de fièvre est en `niveau_preuve = 'D'`**, sans validateur forgé (décision N3).
5. **Une constante saisie n'est pas vérifiée** : le patient déclare ce qu'affiche son thermomètre.
   Même régime que `impact_triage` des antécédents — et si le poids d'une déclaration non vérifiée
   doit être borné, ce sera une **règle de protocole**, pas une constante de code.
6. **Le moteur ne garantit pas les bornes.** Elles vivent dans un instantané publié, qu'un
   déclencheur SQL ne peut pas lire : la garde est **applicative**, annoncée comme telle et jamais
   déguisée en garantie du moteur (précédent du quota d'images, P6.4c).
7. **Risque résiduel nommé** : si un écran cessait de collecter une constante, une règle qui s'y
   réfère ne se déclencherait plus **sans bruit** — un fait connu mais non renseigné pour ce patient
   ne lève pas, par construction, et c'est ce qui garde le triage anonyme possible.

---

## 7. Ce que la campagne de mutation a trouvé

Onze mutations, **dix tueuses et une volontairement verte** (celle-ci teste le harnais lui-même :
sans elle, un lanceur cassé ferait paraître tout le monde tueur).

Deux enseignements, tous deux au crédit du harnais et non de la relecture :

- **`g5` a d'abord SURVÉCU.** Le vecteur « une fraîcheur absente ne propose jamais » passait pour
  une raison qui n'était pas la garde : sans elle, `(int) null` vaut 0, la fenêtre devient « zéro
  minute » et une mesure passée est écartée de toute façon. Il prouvait l'arithmétique, pas
  l'intention. Le cas qui les sépare est une mesure **datée du futur** (horloge d'appareil en
  avance) — vecteur ajouté, mutation tuée. **Septième instance** de la famille « le vecteur prouve
  autre chose » dans ce projet.
- **`g10` a été refusée par le harnais lui-même**, au titre de sa règle 6 (l'ancre ne doit pas être
  un préfixe du remplacement, sinon le contrôle « mutation appliquée » la retrouve dans le texte
  muté et abandonne à tort). La définition était fautive, pas le code ; elle a été réécrite sur une
  ancre d'une seule ligne.

---

## 8. Ce que le G2 live MySQL a établi (2026-08-21)

Base `ivoirsante` **sauvegardée** (`mysqldump --routines --triggers`, 111 tables recensées),
éprouvée, puis **restaurée compte pour compte**. Ce que SQLite ne pouvait pas prouver :

| # | Vecteur | Résultat |
|---|---|---|
| I1-I5 | Schéma : colonne de fraîcheur nullable, **2 déclencheurs**, `uq_triage_constante`, **0 clé étrangère vers `mesures_sante`** | conforme |
| W1 | **Publier `TRIAGE-NIVEAU` AVANT `seuils_mesure`** | **refusé**, le message **nomme** « temperature » et dit « aucune version publiée » |
| W2 | Les cinq mises en vigueur dans l'ordre annoncé | la publication refusée en W1 **passe** — l'ordre a changé, pas le code |
| W3 | `GET /triage/constantes` sans compte | 7 lignes, **aucune clé de statut** : le référentiel sait classer 39,5 °C en « critique » et **cela ne sort pas du serveur** |
| W4-W6 | Hors bornes · précision de trop · `spo2` | **422**, chacun **par son motif**, `triage_constantes` et `triages` inchangés |
| W7 | **Le §1.2 en donnée** : enfant de 4 ans, 39,6 °C | `urgence`, score **90**, les **deux règles enchaînées** visibles |
| — | Les trois miroirs (adulte · fièvre sous le seuil · aucune constante) | `faible`, score 8, règle **non déclenchée** — sans eux W7 ne prouverait qu'un score qui monte |
| W8 | Client envoyant `origine: reprise_du_carnet` et `mesure_id: 4242` | base : **`saisie`, `NULL`** |
| W9 | Carnet : température de 30 min · pouls de 3 jours | **proposition** / **contexte** — et la valeur du contexte, saisie à l'identique, revient **`saisie`** |
| W10 | Anti-IDOR | carnet d'autrui **403** · sans jeton **401** · sans membre **200** |
| W11 | `mesures_sante` avant / après un triage avec constantes | **identique** — le triage n'ouvre pas de 4ᵉ chemin d'écriture |
| W12 | Fiche §5.4 par jeton | constantes avec unité, origine et version ; sans jeton et jeton faux → **404, jamais 403** |
| W13 | `UPDATE` direct sur `referentiels_mesure` | **aucun effet** : la table dit 40, le serveur sert 45, et 44 °C passe encore |
| W14 | Quatre-yeux, sur un compte portant **les deux** permissions | **409 « L'auteur d'une proposition ne peut pas la valider lui-même »** — prouvé par son motif |
| W15 | Après publication par un second agent | le serveur sert 40 et refuse 44 °C, **sans une ligne de code** |
| W16 | Journal de gouvernance et `laravel.log` | **0 valeur clinique** |
| W12-bis | `origine = 'reprise_du_carnet'` sans `mesure_id`, en SQL direct | **`ERROR 1644`** — et le déclencheur **UPDATE** mord aussi ; doublon → **`ERROR 1062`** |

### Le défaut trouvé par le G2, et pourquoi les 46 vecteurs ne le voyaient pas

Le référentiel publie `decimales = 1` pour la température, **et le serveur acceptait 39,55**. La
borne gouvernée était décorative : seule la capacité de `decimal(8,2)` mordait. Aucun vecteur ne
l'attrapait parce que tous éprouvaient `39.555` — une valeur que **les deux** bornes refusent. Il a
fallu la valeur qui les sépare.

C'est la forme exacte du constat X4 de P10b-3-i (« le référentiel publiait `min:1 max:10` et le
serveur ne les regardait pas »), que cet incrément referme pour `valeur_min`/`valeur_max` — et
laissait ouverte un cran plus loin. Corrigé, trois vecteurs ajoutés, un vecteur hérité **réécrit
pour dire la garantie qui tient** plutôt que corrigé pour passer (précédent P6.4d).

### Une précision d'environnement, dite plutôt que laissée croire

`origine` est un **ENUM**. Sur ce poste WAMP, le `sql_mode` **global est vide** : une valeur inventée
y devient `''` au lieu d'être refusée. Ce n'est pas le chemin de l'application — Laravel pose
`STRICT_TRANS_TABLES` sur **sa** session (`config/database.php`, `'strict' => true`), et l'insertion
d'une `origine` inventée par le code applicatif est bien **refusée** (vérifié). La garantie tient
donc sur tous les chemins que l'application emprunte, et dépend du mode de session en dehors —
comme le quota d'images de P6.4c, elle est **annoncée**, jamais déguisée en garantie du moteur.

---

# Partie 7 — P10c-2-i : le retour du soignant + le socle IA (§5.5.4)

> **Ajoutée le 2026-08-28.** Deux commits, un seul G1 (`docs/PLAN_G1_P10c2i_Boucle_Apprentissage_
> Service.md`, F1→F11) — **partie A** (F1-F3, `417b231`) puis **partie B** (F4-F10 + le microservice,
> `888b64c`). Ce G1 avait été écrit et partiellement codé le 2026-08-22 mais jamais validé par
> écrit ; trouvé et clos en reprenant P10 après un lot GeniusPay sans rapport. Écrite avant le G4,
> conservée après le G5.

## 1. Périmètre

### Ce que ça livre

- **Un soignant peut désigner le triage auquel répond sa consultation** et dire si l'orientation
  rendue était `adaptee` / `sur_triage` / `sous_triage` (F1-F3, permission `triage.retour`).
- **Chaque retour alimente une ligne pseudonymisée** du jeu d'apprentissage (F4), qu'un second
  soignant (permission `apprentissage.valider`, orpheline) valide ou rejette avant qu'elle puisse
  un jour entrer dans un export.
- **`services/triage-service` existe** (FastAPI/Pydantic, port 8095) et un client Laravel
  (`ClientTriageIa` + `DisjoncteurTriageIa`) sait l'appeler, se dégrader honnêtement et rouvrir un
  disjoncteur — **gaté OFF par défaut**.

### Ce que ça ne fait PAS — à lire avant de tester

| Attendu | État |
|---|---|
| Un modèle réel, une prédiction réelle | **aucun** — `/api/v1/triage/score` répond 503 à CHAQUE appel (F5/F6), c'est le régime nominal |
| Anonymisation du jeu d'apprentissage | **pseudonymisation seulement** — `triage_id` reste, l'export qui le retire est en P10c-3 |
| Écran citoyen montrant une assistance IA | **P10c-2-ii**, différé |
| `predictions_ia` durcie en chaîne d'audit | **différé à P10c-3** — la table ne porte aujourd'hui aucun contenu clinique |

## 2. Prérequis

Les cinq mises en vigueur de la Partie 6 (§2.2 ci-dessus) restent nécessaires pour que
`POST /triage/analyser` aboutisse — ce module ne change rien à cet ordre.

```bash
cd services/api
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan migrate
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan db:seed --class=PortailRolesSeeder
```

Pour éprouver le socle IA en direct (§4 ci-dessous), le microservice doit tourner :

```bash
cd services/triage-service
python -m venv .venv && .venv/Scripts/python.exe -m pip install -r requirements.txt
.venv/Scripts/python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8095
# ou : docker compose up --build
```

## 3. Le retour du soignant et la revue (F1-F4, portail)

Ces deux écrans exigent une session portail (`auth`) ; les scénarios ci-dessous passent par
`artisan tinker` pour appeler les services directement — c'est le chemin que `RetourTriageTest.php`
et `JeuApprentissageTriageTest.php` couvrent déjà à l'HTTP, dans les deux sens.

```php
// Un médecin donne un retour sur un triage de son patient.
$medecin = \App\Models\User::role('medecin')->first();   // ou : ->givePermissionTo('triage.retour')
$membre  = \App\Models\MembreFamille::first();
$triage  = \App\Models\Triage::where('membre_id', $membre->id)->first();

app(\App\Services\Triage\ServiceRetourTriage::class)->enregistrer(
    $medecin, $membre, $triage, \App\Support\RegistreRetourTriage::ADAPTEE
);
// -> nouvelle entrée `protocole_applications` (decision_finale='adaptee')
// -> nouvelle ligne `jeux_donnees_entrainement`, SANS AUCUNE colonne d'identité

// Un second retour sur LE MÊME triage : une SECONDE ligne, jamais une réécriture.
app(\App\Services\Triage\ServiceRetourTriage::class)->enregistrer(
    $medecin, $membre, $triage, \App\Support\RegistreRetourTriage::SOUS_TRIAGE, 'Douleur sous-évaluée.'
);

// Un réviseur (habilité differemment) valide la première ligne.
$reviseur = \App\Models\User::factory()->create();
$reviseur->givePermissionTo('apprentissage.valider');
$ligne = \App\Models\JeuDonneesEntrainement::where('triage_id', $triage->id)->first();
app(\App\Services\Triage\ServiceValidationApprentissage::class)->valider($reviseur, $ligne);

// La ligne validée entre dans le contrôle d'export ; la seconde (non décidée) n'y entre pas.
app(\App\Services\Triage\ServiceValidationApprentissage::class)
    ->pretsPourExport()->pluck('triage_id');   // -> [le triage ci-dessus], une seule fois
```

Écran portail : `/portail/dossier/{membre}?section=triage` (F1-F3) et `/portail/apprentissage`
(F4) — ce dernier gardé par `permission:apprentissage.valider`, sans investissement de design
(précédent K1 de P6.4d).

## 4. Le socle IA : les trois états du disjoncteur (F7/F8)

**`TRIAGE_IA_ENABLED` reste à `false` en local/prod par défaut** — ne le passer à `true` que pour
ce scénario, jamais laissé dans `.env` après (voir §6, `phpunit.xml` l'isole désormais en test).

```bash
# .env, temporairement :
TRIAGE_IA_ENABLED=true
TRIAGE_IA_BASE_URL=http://127.0.0.1:8095
TRIAGE_IA_DISJONCTEUR_SEUIL=2
TRIAGE_IA_DISJONCTEUR_DUREE=15
```

```bash
BASE=http://localhost:8000/api/v1

# État 1 — service DEBOUT : 503 honnête, triage complet quand même.
curl -s -X POST $BASE/triage/analyser -d '{"symptomes":[1]}' -H "Content-Type: application/json"
# -> 201, niveau + recommandation présents ; predictions_ia: mode=degrade, motif=modele_indisponible

# État 2 — arrêter triage-service (Ctrl+C), puis :
curl -s -X POST $BASE/triage/analyser -d '{"symptomes":[1]}' -H "Content-Type: application/json"  # échec 1 (~1-2s)
curl -s -X POST $BASE/triage/analyser -d '{"symptomes":[1]}' -H "Content-Type: application/json"  # échec 2 -> disjoncteur OUVERT
curl -s -X POST $BASE/triage/analyser -d '{"symptomes":[1]}' -H "Content-Type: application/json"  # AUCUN appel réseau : quasi instantané
# -> le 3e a predictions_ia.latence_ms = 0, motif=disjoncteur_ouvert

# État 3 — redémarrer triage-service, attendre > 15s, puis :
curl -s -X POST $BASE/triage/analyser -d '{"symptomes":[1]}' -H "Content-Type: application/json"
# -> succès réel (latence_ms > 0), motif=modele_indisponible de nouveau : le circuit s'est refermé
```

```php
// Vérifier l'état du disjoncteur sans passer par un appel :
app(\App\Services\Triage\DisjoncteurTriageIa::class)->estOuvert();
```

## 5. Invariants base de données

- `predictions_ia` : une ligne à CHAQUE appel de `analyser()` (gaté OFF compris), jamais absente.
- `triages.modele_version` : **NULL sur toutes les lignes**, tant qu'aucun modèle n'existe (F5).
- `jeux_donnees_entrainement` : **aucune colonne** `patient_nom`/`membre_id`/`user_id`/`nis` —
  vérifiable au schéma, pas seulement au comportement du service.
- `validations_medecins` : au plus une ligne par `jeu_id` (`UNIQUE`), jamais de statut « en attente ».

## 6. Limites annoncées (à ne pas signaler comme des défauts)

1. **Aucun modèle, donc aucune prédiction réelle** — régime nominal, pas une panne.
2. **Pseudonymisation seulement** ; l'export anonymisant est en P10c-3.
3. **`predictions_ia` non durcie** (pas de chaîne append-only) — différé à P10c-3, motif dans la
   migration `2026_08_28_000002_predictions_ia_triage`.
4. **Aucune campagne de mutation formelle** sur ce périmètre — proportionné à ce que 40 tests dédiés
   + un G2 live réel (§7) couvrent déjà ; dit plutôt que déguisé.
5. **Aucune entité consultation/diagnostic d'épisode** (Y7 du G1) : le label reste une appréciation
   sur l'orientation, jamais une issue clinique observée à 48 h.

## 7. Ce que le G2 live a établi (2026-08-28)

Base MySQL sans aucun protocole triage publié (constat en reprenant le module) : chaîne complète
republiée par de vrais comptes à quatre-yeux (`seuils_mesure` → `TRIAGE-NIVEAU` →
`TRIAGE-QUESTIONNAIRE` → `TRIAGE-ANTECEDENTS` → `symptomes_triage`). Puis, contre un
`triage-service` réellement démarré (venv+uvicorn) et un `php artisan serve` réel :

| # | Vecteur | Résultat |
|---|---|---|
| 1 | Retour `adaptee` sur un triage réel lié au membre | accepté, `jeux_donnees_entrainement` peuplée sans identité |
| 2 | `sous_triage` sans motif | **refusé par son message** |
| 3 | Triage sans `membre_id` (anti-IDOR) | **refusé par son message** |
| 4 | Rôle `agent_garde` | **refusé par son message** |
| 5 | Second retour sur le même triage | accepté comme **2ᵉ entrée** du journal, chaîne vérifiée octet à octet (`empreinte` de la 1ʳᵉ = `empreinte_precedente` de la 2ᵉ) |
| 6 | Service IA debout | **503 réel**, triage complet, `predictions_ia` correct, disjoncteur resté **fermé** |
| 7 | Service IA arrêté, 2 échecs réels | disjoncteur **ouvert**, 3ᵉ appel en **0 ms réseau** |
| 8 | Service IA redémarré, fenêtre passée | succès réel (19 ms), circuit **refermé** |

### Le défaut trouvé par le G2, invisible aux 36 tests dédiés qui précédaient ce run

`DisjoncteurTriageIa` stockait un objet `Carbon` comme valeur de cache. `phpunit.xml` force
`CACHE_STORE=array` : écriture et lecture dans le **même** processus PHP, jamais sérialisé, donc
jamais cassé. En conditions réelles (`CACHE_STORE=database`, `php artisan serve` traitant chaque
requête dans un **processus séparé**), le `Carbon` désérialisé devenait un `__PHP_Incomplete_Class`
et `Carbon::lt()` levait une `TypeError` en plein triage — **le disjoncteur cassait ce qu'il devait
protéger**. Corrigé en stockant un horodatage Unix (entier) plutôt qu'un objet.

Un second oubli, de méthode : `TRIAGE_IA_ENABLED=true` laissé dans `.env` après ce G2 a fait échouer
le vecteur « gaté OFF par défaut » au run PHPUnit suivant — `phpunit.xml` ne l'isolait pas comme il
isole déjà `PULSE_ENABLED`/`TELESCOPE_ENABLED`. Ajouté à la même liste (§4 ci-dessus le rappelle).

## 8. Checklist de clôture

- [x] Migrations appliquées (`2026_08_28_000001`, `2026_08_28_000002`)
- [x] G3 Python (8 tests, ruff+mypy propres, build Docker indépendant vert)
- [x] G3 Laravel (40 tests dédiés du module, 1343/1343 sur la suite complète)
- [x] G2 live réel (tableau ci-dessus, 3 états du disjoncteur prouvés dans l'ordre)
- [x] G4 propriétaire *(déclaré validé le 2026-08-28)*

---

# Partie 8 — P10c-3-i : export anonymisant + entraînement réel + registre de gouvernance

> **Ajoutée le 2026-08-29.** Plan `docs/PLAN_G1_P10c3i_Export_Anonymisant_Modele_Reel.md`
> (F12→F21), G1 validé le 2026-08-29 après deux arbitrages du propriétaire (découpage i/ii sur la
> charnière du §7.2 ; antécédents ajoutés au vecteur, allergies/médicaments différés). Ferme la
> première moitié de la chaîne §7.2 — l'anonymisation devient EFFECTIVE (F4 de la Partie 7 l'avait
> annoncé) et le modèle est réel dans son mécanisme. **VALIDÉ G5 le 2026-08-29.**
>
> *Mise à jour du 2026-08-30* : au moment où cette partie a été écrite, rien n'était branché sur
> `/api/v1/triage/score` — **P10c-3-ii l'a fait depuis** (Partie 9). Les vecteurs ci-dessous
> restent valables tels quels ; seule cette phrase a vieilli.

## 1. Périmètre

### Ce que ça livre

- **Un export anonymisé versionné** (`ServiceExportJeuEntrainement`) : `triage_id` retiré, âge
  généralisé en bande (config `masante.triage_ia.bandes_age`), date réduite au mois, `k_estime`
  calculé (jamais bloquant). Habilité par `ia_triage.valider`.
- **Un entraînement réel** (`triage-service` gagne XGBoost+SHAP+MLflow, `POST /api/v1/triage/
  entrainement`) : multiclasse (`adaptee`/`sur_triage`/`sous_triage`), `rappel_sous_triage` loggé à
  part, refus sous 30 lignes (double garde Laravel + Python).
- **Un registre de gouvernance** (`versions_modeles`/`metriques_modeles`) : `candidat` posé
  automatiquement, `valide` exige un **second** compte habilité (quatre-yeux, motif
  `ServiceGouvernanceProtocole`).
- **`score_antecedents`** (P10b-3-ii) entre dans le vecteur de features — persistée sur `triages`
  à l'écriture, reprise (jamais recalculée) au moment du retour.
- **Un écran portail** (`/portail/modeles-ia`) : exporter, lancer un entraînement, valider — sans
  design (K1).
- **Une notification** `MODELE_IA_CANDIDAT` (canal existant, `ServiceNotification`) vers les
  détenteurs de `ia_triage.valider`, sauf l'auteur de l'entraînement — corps sans métrique.

### Ce que ça ne fait PAS — à lire avant de tester

| Attendu | État |
|---|---|
| Un modèle qui répond réellement à un triage | **aucun** — `/api/v1/triage/score` répond toujours 503, même avec un modèle `valide` en base (prouvé au G2 live, §6) |
| Anonymisation garantissant un vrai k-anonymat | **estimé sur les seuls quasi-identifiants généralisés** (bande d'âge, sexe, mois-année) — constantes/symptômes restent à précision clinique, et c'est dit (F20) |
| Un modèle validé statistiquement | **non** — le pipeline est réel, le volume de retours réels ne l'est pas encore (§6) |
| Allergies, médicaments en cours dans le vecteur | **hors périmètre**, dette groupée (décision propriétaire) |
| Drift, canary, équité (§8) | **non traités** — le corpus les place lui-même en dernier de son propre ordre de construction |

## 2. Prérequis

```bash
cd services/api
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan migrate
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan db:seed --class=PortailRolesSeeder
```

`services/triage-service` gagne la stack ML — **recréer le venv** si un venv Python ≥ 3.12 existe
déjà (xgboost/shap/scikit-learn n'ont pas tous de roue précompilée au-delà de 3.12 sur Windows au
moment d'écrire ceci ; `uv python install 3.11` + `uv venv --python 3.11 .venv` reproduit exactement
la cible du Dockerfile) :

```bash
cd services/triage-service
uv python install 3.11 && uv venv --python 3.11 .venv
uv pip install --python .venv -r requirements.txt
.venv/Scripts/python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8095
```

**Un compte habilité `ia_triage.valider` doit AUSSI porter un rôle du portail** (`admin_ivoirsante`
/ `gestionnaire_etablissement` / `agent_garde` / `medecin`) — voir §6, ce n'est pas propre à cet
incrément, c'est la politique de `AuthController::ROLES_PORTAIL` qui existe depuis le Module 4.

## 3. Scénarios (curl + artisan, reproductibles)

Refus sous le seuil (aucune ligne validée) :

```bash
curl -s -X POST http://127.0.0.1:8095/api/v1/triage/entrainement \
  -H "Content-Type: application/json" -d '{"pays_code":"CI","numero_export":1,"lignes":[]}'
# → 422 {"motif":"volume_insuffisant","message":"0 ligne(s) reçue(s), 30 requises au minimum..."}
```

Export puis entraînement, en tant que compte habilité (`--utilisateur=<id>`) :

```bash
XDEBUG_MODE=off php artisan masante:triage:jeu-entrainement:exporter --utilisateur=<id>
# → Export #N (CI, vN) : <n> ligne(s), k estimé = <k>.

TRIAGE_IA_BASE_URL=http://127.0.0.1:8095 php artisan masante:triage:modele:entrainer <export_id> --utilisateur=<id>
# → Version N (CI) créée en statut « candidat » — run MLflow <run_id>.
```

Quatre-yeux (même compte refusé, un autre compte accepté) — via le service ou l'écran
`POST /portail/modeles-ia/{version}/valider` (session + CSRF réels).

## 4. Invariants base de données

- `exports_jeu_entrainement.instantane_json` : **aucune clé** `triage_id`/`membre_id`/`user_id`/
  `patient_nom`/`nis`/`id` dans aucune ligne.
- `versions_modeles.statut` ne vaut jamais `actif`/`archive` dans cet incrément.
- `metriques_modeles` porte toujours `rappel_sous_triage`, jamais absent d'un entraînement réussi.
- `versions_modeles.valide_par` ≠ `versions_modeles.entraine_par` sur toute ligne `valide`.
- `predictions_ia` reste inchangée par cet incrément (toujours `mode=degrade` en pratique).

## 5. Limites annoncées (à ne pas signaler comme des défauts)

Voir le tableau du §1. En plus : `/api/v1/triage/entrainement` sans principal signé (même posture
que `/score` aujourd'hui) ; aucune règle de « meilleur modèle » entre plusieurs candidats (choix
humain, à l'écran) ; `alertes_drift`/`explications_ia` nommées par le corpus, non créées faute de
consommateur ; §5.5.2 (questionnaire personnalisé IA) reste sans porteur numéroté.

## 6. Ce que le G2 live a établi (2026-08-29)

Base MySQL réelle (`ivoirsante`), rien réinitialisé. Migrations appliquées sans incident (aucun
équivalent MySQL 1215/3823 cette fois). 35 lignes réelles semées via `ServiceRetourTriage`/
`ServiceValidationApprentissage` (mécanisme déjà prouvé par HTTP en Partie 7 — semé ici par appel de
service direct, pas re-rejoué par HTTP, et c'est dit plutôt que déguisé) : `export #1` → 35 lignes,
**`k_estime=5`**, `instantane_json` vérifié colonne par colonne sans `triage_id` ni `membre_id`.
Entraînement réel contre le VRAI `triage-service` (stack ML réelle, aucun mock) → **run MLflow
`fc4b731ea19a46a882d9d9885fa5bc3d`**, métriques réelles (`exactitude=0.222`, `rappel_sous_
triage=0.333`, `auc=0.407` — proches du hasard, **honnêtement**, la fixture n'avait aucun signal
réel à apprendre, exactement la limite annoncée par le plan). Quatre-yeux prouvé deux fois : par
service direct (v1) et par le VRAI écran, deux comptes, deux sessions authentifiées réelles avec
CSRF (v2, `versions_modeles.valide_par=105` en base). Notification reçue par les 2 détenteurs
réels de la permission (dont l'admin, via `syncPermissions(Permission::all())`) et PAS par
l'auteur — corps vérifié sans métrique. **Le boundary Y10/F18** : avec `TRIAGE_IA_ENABLED=true` et
un modèle `valide` existant, un vrai `POST /api/v1/triage/analyser` a bien fait partir un vrai
appel à `/score` (vu dans le log `uvicorn`) — réponse encore et toujours 503,
`predictions_ia.mode=degrade`. Base restaurée : les 35 triages de test, les 2 exports, les 2
versions et leurs métriques, les 5 comptes de test et leurs notifications ont été supprimés ;
**`protocole_applications` n'a délibérément PAS été touchée** (append-only, motif constant de ce
projet) — les 35 entrées de retour y restent, pour de vrai.

**DEUX DÉFAUTS RÉELS TROUVÉS, TOUS DEUX PAR LE HARNAIS DE TEST, AVANT MySQL** : `score_antecedents`
manquait du `$fillable` de **`Triage`** ET de **`JeuDonneesEntrainement`** — Eloquent l'écartait
silencieusement à chaque assignation de masse, et la valeur restait `NULL` en base sans qu'aucune
erreur ne le signale. Trouvé par `ExportJeuEntrainementTest::test_score_antecedents_traverse_du_
triage_a_lexport`, qui a échoué deux fois de suite (une fois par modèle) avant que les deux soient
corrigés. Un troisième défaut, de PLUS FAIBLE gravité, corrigé avant même d'atteindre MySQL : la
bande d'âge `1-4` du plan portait en réalité `min:2` — l'étiquette mentait sur sa propre borne.

**PIÈGE — LE PREMIER APPEL RÉEL À `triage-service` PEUT S'ÉTERNISER, LE SUIVANT NON** : la toute
première commande `masante:triage:modele:entrainer` a expiré après 60 s **sans qu'aucune ligne
n'apparaisse dans le log `uvicorn`** — la requête n'avait donc jamais atteint le service (un `curl`
direct, lui, a répondu en moins d'une seconde). Rejouée sans rien changer, la commande a réussi.
Même famille que le « cold-start `host.docker.internal` → 502 transitoires » déjà documenté pour le
paiement : le premier aller-retour d'un processus PHP vers un port local fraîchement ouvert peut
être anormalement lent sous Windows, indépendamment du code. **Piège de méthode, distinct** : le
serveur `php artisan serve` intégré est **mono-requête** — un test scripté qui enchaîne des appels
réels vers `triage-service` (entraînement compris) peut le rendre transitoirement injoignable
(`curl` → `000`) ; il redevient disponible seul, sans redémarrage, quelques dizaines de secondes
plus tard.

**DÉCOUVERTE, PAS UN DÉFAUT** : un compte ne portant QUE `ia_triage.valider` (sans rôle) est refusé
au **login** du portail lui-même (`AuthController::ROLES_PORTAIL`, Module 4) — avant même d'atteindre
la garde de la route. C'est une politique **transversale**, pas propre à cet incrément : elle vaut
déjà pour `apprentissage.valider`, `referentiel.publier` et toutes les permissions orphelines du
projet. Un compte réel doit porter l'un des quatre rôles du portail **et**, en plus, la permission
nominative — jamais la permission seule.

## 7. Checklist de clôture

- [x] Migrations appliquées (`2026_08_29_000001`, `2026_08_29_000002`)
- [x] G3 Python (19 tests — 8 hérités + 11 dédiés —, ruff+mypy propres)
- [x] G3 Laravel (27 tests dédiés du module ; **1370/1370** sur la suite complète, 16948 assertions)
- [x] **G3 image Docker — construite (972 Mo), et l'étape de qualité a tourné DANS l'image cible** :
      `ruff check app tests` → « All checks passed! », puis `python -m pytest` → **19 passed**, sous
      `python:3.11-slim` avec la résolution figée du build (`mlflow-2.16.2`, `xgboost-2.1.4`,
      `shap-0.46.0`, `scikit-learn-1.5.2`, `numpy-2.0.2`). **Preuve plus forte qu'un run local** :
      les 19 vecteurs, dont les deux neufs sur la classe à un seul exemplaire, passent dans
      l'environnement réellement livré et non dans un venv de poste. Il a fallu **quatre
      tentatives**, les trois premières tombant sur le réseau (voir le piège ci-dessous) : ~700 s
      d'installation et ~610 s d'export de couches, cohérent avec le « export image ML lourd
      (~600 s) » déjà noté pour `fraud-detection`.
- [x] G2 live réel (export anonymisé, entraînement réel contre MySQL + triage-service réels,
      quatre-yeux par service ET par écran authentifié, notification, boundary Y10/F18, base
      restaurée)
- [x] **G4 propriétaire — validé le 2026-08-29**

## 8. Reprise de vérification après le G4 (2026-08-29)

Contrôle de non-régression joué après la validation du propriétaire : suites rejouées
(**19/19** Python, **46/46** sur le périmètre triage, **1370/1370** sur la suite Laravel complète,
16948 assertions), `ruff`+`mypy` propres, `pint` propre sur **tous** les fichiers touchés
(`ServiceNiveauTriage.php` échoue déjà avant cet incrément — écart de style **hérité**, dernière
modification en P10b-2, délibérément pas reformaté), `pnpm typecheck` vert sur les trois espaces de
travail, routes et commandes réellement enregistrées (`web` + `Authenticate` +
`PermissionMiddleware:ia_triage.valider`, **plus** la garde de service en second rideau), base
vérifiée sans résidu (`exports_jeu_entrainement`, `versions_modeles`, `metriques_modeles`,
`jeux_donnees_entrainement`, `validations_medecins` à 0 ; `predictions_ia` ne porte que les 7 lignes
du 2026-08-28, aucune du 29 ; `TRIAGE_IA_ENABLED` absent de `.env`, donc gaté OFF).

**UN DÉFAUT RÉEL TROUVÉ À CETTE REPRISE, PAR UN CAS LIMITE QU'AUCUN DES 17 VECTEURS N'EXERÇAIT.**
`train_test_split(stratify=…)` exige **au moins deux exemplaires de chaque classe présente** ; en
dessous il lève un `ValueError` nu, que FastAPI rendait en **500 opaque**. Le cas n'est pas
théorique : sur les premiers retours réels la classe rare sera `sous_triage` — **précisément la
seule dangereuse**, celle que le §F16 fait suivre à part parce qu'un agrégat ne la rattrape jamais.
Un jeu de 30 lignes dont **une seule** en `sous_triage` est exactement ce qu'on attend des premiers
mois. Reproduit en direct avant correction (`ValueError: The least populated class in y has only 1
member`). Corrigé par un **refus motivé qui NOMME la classe** (motif des quatre validations de
P10b-1, « le refus nomme celle qui manque »), réutilisant le contrat 422 `volume_insuffisant`
existant plutôt que d'en inventer un second. **Deux vecteurs, une couche chacun** (le service en
direct, puis HTTP en vérifiant que c'est bien un 422 et pas un 500) — parade P6.6b.

**PIÈGE — UN BUILD DOCKER QUI ÉCHOUE PEUT MENTIR SUR SA CAUSE.** La première construction s'est
arrêtée sur `ResolutionImpossible` en accusant `mlflow 2.14.0 depends on sqlalchemy<3`. C'était une
**fausse piste**, et la quatrième tentative l'a prouvé en passant **sans qu'une ligne de
`requirements.txt` ne bouge** : un aléa réseau pendant la récupération des métadonnées fait
rétrograder pip jusqu'à la plus vieille version candidate, puis il impute l'échec à **celle-là**.
Les deux tentatives intermédiaires ont d'ailleurs donné la vraie cause en clair —
`ReadTimeoutError` sur `files.pythonhosted.org` en récupérant un wheel de **342 Mo**
(`nvidia_nccl_cu12`, tiré transitivement par `xgboost`), puis un blocage sur les **223 Mo** de
`xgboost` lui-même. **Avant de desserrer une borne de version sur la foi d'un message de
résolution, rejouer le build et confronter à un `pip install --dry-run`** : le dry-run résolvait
proprement dès la première fois, et c'est lui qui disait la vérité.

*Conséquence pratique* : cette image tire près de **600 Mo de wheels** dont un `nvidia_nccl_cu12`
que le service n'utilisera jamais (dépendance transitive GPU d'`xgboost`). Ce n'est pas un défaut
de cet incrément — `fraud-detection` a exactement la même — mais c'est ce qui rend la construction
fragile sur une connexion moyenne, et ça mérite d'être su avant de la lancer.

---

# Partie 9 — P10c-3-ii : déploiement en observation, captation des faits manquants, comparaison et dérive

> **Ajoutée le 2026-08-30.** Plan `docs/PLAN_G1_P10c3ii_Deploiement_Shadow.md` (F22→F39), G1 validé
> le 2026-08-29 puis élargi le même jour par le propriétaire (captation de maladie/spécialité/
> priorité, et construction d'`alertes_drift`). Livré en **deux lots**, tous deux couverts ici :
> **A** (shadow + captation) et **B** (comparaison + dérive). **VALIDÉ G5 le 2026-08-30** — cette
> partie est conservée comme procédure de non-régression (règle propriétaire, CDC_01 §2.4).

## 1. Périmètre

### Ce que ça livre

- **Le modèle prédit pour de vrai, à chaque triage** — et sa prédiction **n'influence rien**. Mode
  `observation`, jamais `hybride` : CDC_08 §3 place le raisonnement IA au sixième et dernier rang.
- **Le registre décide quel modèle répond** (`versions_modeles.statut = actif`). Le service charge
  ce run-là, et refuse s'il ne l'a pas — jamais un autre.
- **`valide → actif`**, au plus un actif par pays, rollback possible.
- **`predictions_ia` devient une chaîne d'audit** (append-only, empreinte chaînée, origine
  déclarée), parce qu'elle porte désormais une explication SHAP.
- **Les trois faits du §5.5.4 sont captés** : diagnostic final, spécialité ayant pris en charge,
  niveau que le soignant aurait retenu — dans `retours_cliniques_triage`, chaînée elle aussi.
- **`k_estime` compte le diagnostic** parmi les quasi-identifiants.

### Ce que ça ne fait PAS — à lire avant de tester

| Attendu | État |
|---|---|
| L'IA propose une maladie, une spécialité ou un niveau | **non, et ce n'est pas de la prudence** : le modèle apprend `adaptee`/`sur_triage`/`sous_triage`, il n'a jamais vu de maladie. Même avec du volume, un diagnostic ne remontera pas dans le triage (CDC_00 §4) |
| Quelque chose de l'IA apparaît sur la fiche du patient | **rien** — vérifié en direct (§6) |
| Un écran pour lire les prédictions | **livré (lot B)** — mais sur la surface administrateur seulement, jamais dans le parcours de soin |
| Une alerte de dérive | **livrée (lot B)** — détection seule, elle ne retire jamais un modèle du service |
| Un modèle validé statistiquement | **non** : réel dans son mécanisme, entraîné sur un volume faible |

## 2. Préparation — DEUX étapes de déploiement s'ajoutent

| # | Étape | Sans elle |
|---|---|---|
| 1 | `php artisan db:seed --class=MaladieSeeder` **puis** `php artisan masante:maladies:backfill` | le référentiel des maladies est vide ou sans code : **le diagnostic ne peut être rattaché à rien**. Le seeder seul ne suffit pas — les codes viennent du backfill (vérifié au G2) |
| 2 | Entraîner → faire valider par un **second** agent → **activer** | `/score` répond 503 `modele_indisponible`, et le triage reste rendu complet par les protocoles seuls |

Les cinq mises en vigueur des parties 6 à 8 restent nécessaires (référentiels + protocoles).

```bash
# Le service, avec sa stack ML
cd services/triage-service && .venv/Scripts/python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8095

# Laravel, IA allumée (gatée OFF par défaut)
TRIAGE_IA_ENABLED=true TRIAGE_IA_BASE_URL=http://127.0.0.1:8095 php artisan serve --port=8010
```

## 3. Les vecteurs

**Mise en service, quatre-yeux compris :**

```bash
php artisan masante:triage:jeu-entrainement:exporter --utilisateur=<A>
TRIAGE_IA_BASE_URL=http://127.0.0.1:8095 php artisan masante:triage:modele:entrainer <export> --utilisateur=<A>
# validation par un SECOND compte habilité, puis activation
```

L'entraîneur qui tente de valider son propre candidat doit être refusé **par son motif**
(« le §9 exige une double décision »), et non par l'habilitation.

**Un triage réel, IA allumée** — noter la forme de `constantes`, qui est une **liste d'objets** :

```bash
curl -s -X POST http://127.0.0.1:8010/api/v1/triage/analyser \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"symptomes":[1,2],"patient_age":34,"patient_sexe":"F","reponses":{},
       "constantes":[{"type_mesure":"temperature","valeur":38.9},{"type_mesure":"pouls","valeur":98}]}'
```

Attendu : la réponse **ne contient rien de l'IA**. La prédiction se lit en base, pas à l'écran.

**Captation, depuis le portail** (section « Fiche de triage » du dossier ouvert) : les trois champs
sont facultatifs ; le diagnostic et la spécialité se **choisissent dans une liste**. Un niveau qui
contredit le verdict doit être **refusé en nommant la contradiction**, jamais arbitré.

## 4. Invariants base de données

- `predictions_ia.mode` ne vaut jamais `hybride`.
- Les entrées antérieures au mécanisme restent à `chaine = NULL` et `empreinte = NULL` ; la
  déclaration d'origine porte leur **nombre**.
- `versions_modeles` : au plus **une** ligne `actif` par `pays_code`.
- `UPDATE`/`DELETE` direct sur `predictions_ia` ou `retours_cliniques_triage` → refusé par le
  moteur (`1644`).
- `retours_cliniques_triage.maladie_libelle` ne change pas quand le référentiel est corrigé.
- Les deux chaînes se vérifient `intacte` après un usage réel.

## 5. Limites annoncées (à ne pas signaler comme des défauts)

Voir le tableau du §1. En plus : aucune surface de lecture (lot B) ; artefacts sur le disque du
service et non dans MinIO (§10) — c'est ce que `modele_absent_du_service` rend visible ; la chaîne
ne témoigne pas du passé ; `retours_cliniques_triage` est un **fragment** d'épisode clinique, pas un
dossier de consultation.

## 6. Ce que le G2 live a établi (2026-08-30)

Base MySQL réelle, sauvegardée (`mysqldump --routines --triggers`) puis restaurée. Migration
appliquée : ENUM à trois valeurs, **quatre déclencheurs**, **7 entrées antérieures à `chaine =
NULL`**, déclaration d'origine portant ce chiffre. Les quatre `UPDATE`/`DELETE` directs refusés
(`1644`) ; altération volontaire → chaîne rompue, puis rétablie.

**36 retours réels** via les services réels → export de 36 lignes, **sans `triage_id`**.
Entraînement réel contre un `triage-service` démarré → run MLflow `6c1b3471…`. Quatre-yeux refusé
**par son motif**, second agent valide, mise en service avec `activee_par` et **un seul actif**.

**Triage citoyen bout-en-bout** → `observation`, SHAP réel, confiance `elevee`, 748 ms,
`triages.modele_version` renseignée (§115). **Frontière vérifiée** : ni `sous_triage`, ni
`observation`, ni `shap`, ni l'identifiant du modèle dans la réponse rendue au patient.

Captation : diagnostic et spécialité **figés** ; contradiction refusée en nommant les deux moitiés,
**rien écrit** ; diagnostic inconnu refusé. **`k_estime` tombe de 2 à 1** dès qu'une ligne porte un
diagnostic.

Base restaurée (triages 52→11, jeu 37→0, exports et versions à 0, comptes de test supprimés). Les
journaux append-only sont **délibérément conservés** — les effacer serait le geste que la chaîne
existe pour rendre détectable. Les 21 codes de maladies sont gardés : étape de déploiement, pas
donnée de test.

## 7. Checklist de clôture

- [x] Migration appliquée (`2026_08_30_000001`)
- [x] G3 Python (**31 tests**, `ruff` + `mypy` propres)
- [x] G3 Laravel (**22 vecteurs dédiés** ; suite complète verte ; `pint` propre sur les fichiers
      touchés — `DossierController` échouait **déjà avant**, écart hérité non reformaté)
- [x] `pnpm typecheck` vert ×3
- [x] Campagne de mutation lot A : **9/9 conformes** (8 tueuses + 1 témoin volontairement vert)
- [x] Lot B : `ReglesDerive`, `TraitsDepuisTriage`, comparaison, `alertes_drift`, écran, tâche
      quotidienne (**17 vecteurs**, **10/10 mutations conformes**)
- [x] G2 live réel des deux lots (voir §6 et §9)
- [x] **G4 propriétaire — validé le 2026-08-30**

## 9. Le lot B — comparaison et dérive

### Ce qu'il ajoute

- **Un écran de comparaison** (`/portail/modeles-ia/{version}/comparaison`) : matrice de confusion
  en production, concordance, latence, et **seul sur sa ligne** le rappel sur `sous_triage`
  confronté à celui du jeu de test.
- **`alertes_drift`** : PSI par feature (la population a-t-elle changé ?) et chute du rappel (le
  modèle rate-t-il davantage le cas dangereux ?). **Jamais fondus en un score global.**
- **Une tâche quotidienne** `masante:triage:modele:derive`, plus un bouton « Analyser maintenant ».
- **La mise en service depuis l'écran**, qui sert aussi de **rollback** (§8) : réactiver une version
  archivée est le même geste.

### Les vecteurs

```bash
# Le rapport de dérive, à la demande
php artisan masante:triage:modele:derive --pays=CI
```

Attendu selon l'état : « aucun modèle en service » (rien à surveiller), « échantillon insuffisant »
(aucun indice calculé plutôt qu'un chiffre qui ne voudrait rien dire), ou le détail des dérives —
**et le modèle reste `actif` dans tous les cas**.

À vérifier à l'écran : le bandeau dit ce que la comparaison mesure **et ce qu'elle ne mesure pas**
avant d'afficher le moindre chiffre ; le rappel en production affiche « aucun sous-triage constaté »
et non « 0 % » quand aucun cas n'est survenu ; aucune action n'est proposée depuis la section des
dérives.

### Invariants

- Une journée sans dérive **n'écrit aucune ligne**.
- Rejouer le rapport d'un jour ne crée pas de seconde ligne **et ne reprévient personne**.
- `versions_modeles.statut` n'est jamais modifié par une analyse de dérive.
- Un triage jugé deux fois compte **deux couples** dans la comparaison.

### Ce que le G2 live du lot B a établi (2026-08-30)

Trente triages réels d'une population **âgée** confrontés à un export de référence d'adultes :
**9 alertes** — huit dérives d'entrée dont `bande_age` à 17,8 (populations disjointes), plus la
chute de rappel (**0,80 au test contre 0,00 en production**). Comparaison réelle : 30 prédictions,
30 couples, concordance 50 %, matrice complète, latence 30 ms. **Le modèle reste `actif`.** Lignes
idempotentes au rejeu. Base restaurée, journaux append-only conservés, chaînes vérifiées intactes.

*Honnêteté sur la lecture* : la fenêtre mêlait ces trente triages aux onze triages de développement
préexistants, qui n'ont ni constantes ni réponses. Les indices sont exacts, leur interprétation ne
vaut que pour ce jeu — **ce n'est pas une cohorte propre**.

---

## 8. Pièges rencontrés

**UNE GARANTIE QUI NE VAUT QUE D'UN CÔTÉ — TROIS FOIS DANS LA MÊME SÉANCE.** SQLite laisse passer ce
que MySQL refuse ou modifie, et aucun des tests verts ne pouvait le voir :

1. **`VARCHAR` : SQLite n'impose pas la longueur.** `audit_chaines.motif` est un `string(300)` ; la
   déclaration d'origine dépassait. La migration a échoué au premier contact avec la base réelle
   (`1406 Data too long`) **après avoir posé une partie du schéma** — le DDL MySQL n'est pas
   transactionnel, il a donc fallu restaurer et rejouer.
2. **`decimal(5,4)` : MySQL arrondit.** Le service rend `0.752762`, la base garde `0.7528`. La
   valeur hachée n'était pas la valeur stockée.
3. **JSON : MySQL ne distingue pas `0.0` de `0`.** SHAP rend `0.0` pour une feature sans influence ;
   relu, c'est un entier. Les features qui ne pesaient **rien** suffisaient à casser l'empreinte.

Les deux derniers produisaient le pire défaut possible pour un journal médico-légal : **une fausse
accusation** — la chaîne se déclarait rompue sur des entrées que personne n'avait touchées. Ce n'est
plus la leçon d'`entierOuNull` (P10b-2), où *le pilote retypait* : ici **la base modifie la valeur**.
Les parades normalisent **en PHP**, donc identiquement sur les deux moteurs.

**UNE MIGRATION NE DOIT PAS LIRE UN REGISTRE VIVANT.** La migration d'ADR-042 itérait sur
`ChaineAudit::JOURNAUX`. Y inscrire deux journaux neufs a fait qu'une migration du 21 août s'est
mise à vouloir altérer des tables **qui n'existaient pas à sa date** — `migrate:fresh` cassait net.
Même famille que « ajouter une clé à `charge()` recalcule les vieilles empreintes ».

**UN REFUS DE CONTRAT N'EST PAS UNE PANNE.** Le service nommait la cause (`bande_age_inconnue`) et
Laravel l'écrasait en `reponse_inattendue_422` **en comptant l'appel comme un échec** : une
divergence de configuration se serait déguisée en service en panne, puis aurait ouvert le
disjoncteur.

**UN VECTEUR DE G2 PEUT NE RIEN PROUVER, LUI AUSSI.** « Altérer » `confiance` en y remettant sa
valeur courante ne prouve rien — et c'est la restauration qui a réellement cassé la chaîne. Vérifier
la valeur AVANT de la modifier.

**UN TABLEAU PHP VIDE SE SÉRIALISE `[]`, PAS `{}`.** `'constantes' => []` fait échouer la validation
Pydantic, qui attend un objet. Sans effet en production (le contrôleur envoie toujours les clés),
mais deux vecteurs de G2 ont d'abord échoué **pour cette raison et non pour celle qu'ils visaient**.

**LE SEEDER DES MALADIES NE POSE PAS LES CODES.** `MaladieSeeder` crée 21 lignes ; les codes
nationaux viennent de `masante:maladies:backfill`. Sans lui, la captation d'un diagnostic n'a rien à
quoi se rattacher — et l'effet du diagnostic sur `k_estime` reste **invisible**, ce qui m'a fait
annoncer un chiffre pour une raison fausse avant de le corriger.

**TROIS PIÈGES DE PLUS, VENUS DU LOT B.**

**Une clé d'idempotence qui compare une date à un datetime n'est pas une clé.** `date_rapport` est
castée en `date` : Eloquent range `2026-08-29 00:00:00`, un `where('date_rapport', '2026-08-29')`
compare la chaîne brute, et les deux ne se rencontrent jamais. `updateOrCreate` créait une seconde
ligne que la contrainte refusait ensuite. **Troisième occurrence** de la famille « la valeur n'est
pas stockée sous la forme où on l'interroge », après l'arrondi du `decimal` et le `0.0` devenu `0`.

**Un rapport peut être idempotent dans ses lignes et pas dans ses notifications.** Le G2 a produit
trois messages identiques pour la même journée. Un contrôleur qui reçoit le même avertissement à
chaque passage **cesse de les lire**.

**La production n'est pas homogène.** Un triage dont `symptomes_json` était **doublement encodé**
faisait tomber le rapport quotidien entier. Rangé en catégorie `illisible` — jamais en « zéro
symptôme », qui aurait fabriqué une donnée.

**ET UN PIÈGE DE TEST, ATTRAPÉ PAR LA MUTATION.** `Notification::sentNotifications()` rend un
tableau imbriqué à **quatre niveaux** ; un `count()` dessus compte les *classes de destinataires* —
toujours 1. Le vecteur passait **en ne mesurant rien**, et seule la mutation l'a révélé en
survivant. Compter des notifications exige d'aplatir les quatre niveaux.
