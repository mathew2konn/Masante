# Guide de test G4 — P6.1 · Identifiant National de Santé (NIS)

> Module **P6.1** du corpus **CDC_09 §3** — décisions consignées dans **ADR-021**.
> Statut : **VALIDÉ G5 le 2026-08-11**. Ce guide reste la procédure de re-test (non-régression).

Périmètre : algorithme de calcul du NIS, attribution transactionnelle, dossier de santé du
titulaire du compte, écran mobile de complétion, backfill, endpoints de vérification et de lecture.

> Convention : **« libellé »** = texte exact affiché à l'écran. Les écrans en **lecture seule**
> sont signalés. Chaque scénario porte un **attendu** ; note tout écart, même cosmétique.

---

## Ce que ce module fait — et ce qu'il ne fait pas

**Il fait** : attribuer à chaque *dossier patient* un identifiant national vérifiable hors ligne
(clé de contrôle ISO 7064), garanti unique et **jamais réattribué**, même après suppression du
dossier.

**Il ne fait pas** (ce n'est pas un défaut, c'est le découpage) :
- pas de **détection de doublons** ni de fusion de dossiers → c'est **P6.2 (MPI)**, ADR-022 ;
- pas de **carte physique** ni d'impression du NIS → module ultérieur ;
- pas d'**échange inter-établissements** du NIS → CDC_08 (protocoles), non ouvert ;
- le NIS **ne remplace pas** `matricule_ivs` : les deux coexistent, aucun n'est renommé. Le
  `matricule_ivs` reste **caché** de l'API, le NIS est **exposé** (CDC_09 §3.5 : il est fait pour
  être communiqué au soignant).

---

## 0. Pré-requis

### 0.1 Backend

Dans `services/api` — PHP = `C:\wamp64\bin\php\php8.3.28\php.exe`, **toujours** préfixé
`XDEBUG_MODE=off`. Base MySQL WAMP 8.4.7 : `ivoirsante`.

```bash
PHP="C:/wamp64/bin/php/php8.3.28/php.exe"

# 1. Migrations (la migration P6.1 est ADDITIVE : elle ne touche aucune donnée existante)
XDEBUG_MODE=off "$PHP" artisan migrate

# 2. Rôles + comptes de démonstration
XDEBUG_MODE=off "$PHP" artisan db:seed

# 3. API joignable sur le réseau local (nécessaire pour Ngrok mobile)
XDEBUG_MODE=off "$PHP" artisan serve --host=0.0.0.0 --port=8000
```

> ⚠️ **Vérifier que la migration est bien passée avant tout autre test.** MySQL refuse une colonne
> générée dérivée d'une colonne portant `ON DELETE CASCADE` (**erreur 1215**, au message
> trompeur : « Cannot add foreign key constraint »). La migration contourne le problème par une
> colonne maintenue + `UNIQUE` + `CHECK` — mais si tu repars d'une base ayant subi un échec
> partiel, la table peut avoir 4 colonnes ajoutées **sans** les tables `nis_*`. Contrôle en 0.3.

### 0.2 Compte de test

| Compte | Téléphone (saisi en local) | Mot de passe | Rôle |
|--------|---------------------------|--------------|------|
| Patient | `0700000000` | `password` | `patient` |

Le backend stocke `+2250700000000` ; la conversion est faite par l'app.

**Pour tester le scénario A.2 (complétion du profil), le compte doit être SANS dossier titulaire.**
Un compte fraîchement inscrit l'est par construction. Pour remettre un compte existant dans cet
état :

```bash
XDEBUG_MODE=off "$PHP" artisan tinker
>>> \App\Models\MembreFamille::where('user_id', 1)->where('est_titulaire', true)->delete();
>>> exit
```

> Le NIS libéré n'est **pas** récupérable : sa ligne de `nis_journal` survit avec
> `membre_id = NULL`. C'est voulu (§C.3).

### 0.3 Contrôle du schéma (30 secondes, à faire une fois)

```sql
-- Les 4 colonnes ajoutées à membres_famille
SHOW COLUMNS FROM membres_famille LIKE 'nis%';
SHOW COLUMNS FROM membres_famille LIKE '%titulaire%';
SHOW COLUMNS FROM membres_famille LIKE 'pays_code';

-- Les 2 tables du module
SHOW TABLES LIKE 'nis_%';          -- attendu : nis_compteurs, nis_journal

-- Les garde-fous déclaratifs
SHOW INDEX FROM membres_famille WHERE Key_name IN ('membres_famille_nis_unique','uq_membres_un_seul_titulaire');
SHOW CREATE TABLE membres_famille\G   -- doit contenir ck_membres_titulaire_coherent
```

✅ **Attendu** : `nis` (VARCHAR 15, NULL, UNIQUE), `nis_attribue_le`, `pays_code` (CHAR 2, défaut
`CI`), `est_titulaire`, `titulaire_du_compte` ; les tables `nis_compteurs` et `nis_journal` ; la
contrainte `ck_membres_titulaire_coherent`.

---

## A. Mobile — Expo Go SDK 54 + Ngrok · **le cœur du G4**

### A.0 Lancement
1. `ngrok http 8000` → copier l'URL `https://xxxx.ngrok-free.app`.
2. La renseigner dans `apps/mobile/app.config.ts` (`extra.apiUrl`) **ou** via `EXPO_PUBLIC_API_URL`.
3. `pnpm mobile` → scanner le QR avec **Expo Go**.
4. Écran **« Connexion »** → `0700000000` / `password`.

### A.1 Porte d'entrée du carnet (compte sans dossier titulaire)

1. Barre d'onglets du bas → onglet **« Carnet »**.

✅ **Attendu** : sous la carte de compte, **une seule carte centrée** :
- une icône bouclier bleue ;
- titre **« Créez votre dossier de santé »** ;
- texte **« Il vous manque deux informations pour ouvrir votre carnet et recevoir votre numéro national de santé. »** ;
- bouton **« Compléter mon profil »**.

❌ **Ne doivent PAS être visibles** : la section « Membres de la famille », le compteur `x/15`,
le bouton « Ajouter un membre ». Tant qu'il n'y a pas de titulaire, il n'y a personne à qui
rattacher une famille.

> **Point de frontière à vérifier** : cette carte apparaît parce que le **backend** a répondu
> `{ "existe": false }` sur `GET /membres/titulaire`. Elle n'est **jamais** déduite du fait que la
> liste des membres est vide. Preuve : ajoute un membre en base sans `est_titulaire`, la carte
> reste affichée.

### A.2 Complétion du profil titulaire

1. Appuyer sur **« Compléter mon profil »**.
2. Écran **« Votre profil santé »**, sous-titre **« Une dernière étape avant d'ouvrir votre carnet »**.
   - Texte d'introduction mentionnant **« votre numéro national de santé »**.
   - Bloc bleu **« Dossier au nom de »** + prénom/nom, avec l'aide
     **« Repris de votre compte. Pour le corriger, modifiez votre compte. »**
   - **« Date de naissance »** (sélecteur de date, obligatoire).
   - **« Sexe »** : segments **« Masculin »** / **« Féminin »**.
   - **« Groupe sanguin (facultatif) »** : puces A+, A−, B+, …
   - Bouton **« Créer mon dossier de santé »**.

3. **Validation locale d'abord** : appuyer sur le bouton **sans rien saisir**.

✅ **Attendu** : **« Sélectionnez le sexe. »** sous les segments, et un message sous la date.
Aucun appel réseau (le bouton ne charge pas).

4. Saisir une date de naissance, choisir un sexe, laisser le groupe vide → **« Créer mon dossier de santé »**.

✅ **Attendu** : retour automatique au **« Carnet »**, qui affiche maintenant :
- section **« Mon dossier de santé »** avec **une** carte à ton nom ;
- section **« Membres de la famille »** avec le compteur **« 0/15 »** ;
- carte vide **« Aucun membre pour l'instant. »** ;
- bouton **« Ajouter un membre »** actif.

> 🔒 **Ce qui n'est pas demandé est significatif** : ni le nom, ni le prénom ne sont saisis ici, et
> l'app **ne les envoie pas**. Le serveur les reprend du compte. Un client qui tenterait de les
> imposer serait ignoré (`StoreDossierTitulaireRequest` n'accepte que trois champs).

### A.3 Le titulaire est hors quota

1. Depuis le **« Carnet »**, ajouter des membres jusqu'au plafond.

✅ **Attendu** : le compteur **« Membres de la famille »** monte `1/15`, `2/15`… **sans jamais
compter ton propre dossier**. Le bouton **« Ajouter un membre »** se désactive à `15/15`, avec
**« Limite de 15 membres atteinte. »**. Ton dossier reste affiché au-dessus, dans sa propre section.

### A.4 Le NIS est visible sur le dossier

1. **« Carnet »** → section **« Mon dossier de santé »** → appuyer sur ta carte.

✅ **Attendu** : le détail du membre s'ouvre (écran P2, inchangé). Le NIS est disponible par
`GET /membres/{id}/nis` — vérifiable en B.4 si l'écran ne l'affiche pas encore.

### A.5 Hors-ligne — dégradation honnête

1. Se remettre dans l'état « sans dossier titulaire » (§0.2), retourner au **« Carnet »**.
2. Activer le **mode avion**, puis appuyer sur **« Compléter mon profil »** et **« Créer mon dossier de santé »**.

✅ **Attendu** : message d'erreur réseau **clair**, pas de crash, pas de gel, **pas de faux
succès**. Le dossier n'est **pas** créé localement.

> **Pourquoi cet écran n'est délibérément PAS mis en cache hors ligne** : créer un dossier hors
> ligne exigerait d'attribuer un NIS côté client. Le NIS est une **séquence nationale sous verrou**
> — un identifiant fabriqué par le téléphone serait un doublon en puissance. La règle de frontière
> (CDC_01 §0.1) l'interdit ; l'écran assume donc d'exiger le réseau.

3. Couper le mode avion → l'action refonctionne.

### A.6 Non-régression des modules validés G5

Parcourir rapidement : **Accueil**, **Triage**, **Carte**, un **détail de membre**, **Carte vitale
d'urgence**, **Partages reçus**.

✅ **Attendu** : aucun changement de comportement. P6.1 est **additif** — aucun écran validé
n'a été réécrit.

---

## B. Backend — HTTP live sur MySQL

> Exemples en **Git Bash**. Récupérer d'abord un jeton :
>
> ```bash
> API="http://localhost:8000/api/v1"
> TOKEN=$(curl -s -X POST "$API/auth/login" \
>   -H 'Content-Type: application/json' \
>   -d '{"telephone":"+2250700000000","password":"password"}' \
>   | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
> echo "$TOKEN"
> ```
>
> ⚠️ Les jetons Sanctum contiennent un `|`. **Toujours guillemeter** `"$TOKEN"` — sinon le shell
> l'interprète comme un tube.

### B.1 Vérification publique du NIS (sans authentification)

```bash
for N in CIS241200012535 SNS241200012504 CIS241200012547 CIS2412000125 C1S241200012535 CIS24120001253A; do
  printf '%-18s ' "$N"; curl -s "$API/nis/$N/verifier"; echo
done
```

✅ **Attendu**, dans l'ordre :

| NIS | Réponse |
|-----|---------|
| `CIS241200012535` | `{"data":{"valide":true,"motif":null}}` |
| `SNS241200012504` | `{"data":{"valide":true,"motif":null}}` — mêmes chiffres que le CI, **clé différente** |
| `CIS241200012547` | `{"data":{"valide":false,"motif":"CLE_INVALIDE"}}` — l'exemple **erroné** d'ADR-001 |
| `CIS2412000125` | `LONGUEUR_INVALIDE` |
| `C1S241200012535` | `FORMAT_INVALIDE` |
| `CIS24120001253A` | `FORMAT_INVALIDE` |

> La ligne 2 est la démonstration du **multi-pays sans code conditionnel** : le préfixe pays entre
> dans le calcul de la clé (A=10…Z=35), donc `CIS…125` et `SNS…125` ne peuvent pas se confondre.

### B.2 Anti-énumération — **le test le plus important du module**

```bash
# Un NIS parfaitement valide mais JAMAIS attribué à personne
curl -s "$API/nis/CIS269999999948/verifier"; echo
# Un NIS réellement attribué (relevé en base)
curl -s "$API/nis/<NIS_REEL>/verifier"; echo
```

✅ **Attendu** : **les deux réponses sont rigoureusement identiques** —
`{"data":{"valide":true,"motif":null}}`.

❌ **Échec du test** si les réponses diffèrent d'une quelconque façon (contenu, code HTTP, ou même
**temps de réponse** perceptible). Un endpoint public qui confirmerait l'*existence* d'un NIS
serait un oracle permettant de balayer la population nationale par force brute (CDC_10 §5).
L'endpoint **ne consulte jamais la base** ; il valide le format et la clé, rien d'autre.

### B.3 Limiteur de débit

```bash
for i in $(seq 1 36); do
  curl -s -o /dev/null -w '%{http_code} ' "$API/nis/CIS241200012535/verifier"
done; echo
```

✅ **Attendu** : environ **30 × `200`** puis des **`429`**. (Limiteur `throttle:30,1` en plus du
limiteur `api` global.)

### B.4 Lecture du NIS d'un dossier — anti-IDOR

```bash
# 1. Sans jeton
curl -s -o /dev/null -w '%{http_code}\n' "$API/membres/1/nis"

# 2. Avec jeton, sur SON dossier
curl -s -H "Authorization: Bearer $TOKEN" "$API/membres/<MON_ID>/nis"; echo

# 3. Avec jeton, sur le dossier d'un AUTRE compte
curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $TOKEN" "$API/membres/<ID_AUTRUI>/nis"
```

✅ **Attendu** : `401` · puis `{"data":{"nis":"CIS26...","nis_attribue_le":"...","pays_code":"CI"}}`
· puis `403`.

> L'isolation est portée par `MembreFamillePolicy::view`, **existante et inchangée** (P2 validé G5).
> P6.1 ne réécrit pas une règle de sécurité déjà prouvée : il s'y branche.

### B.5 Flux du dossier titulaire

```bash
# État initial
curl -s -H "Authorization: Bearer $TOKEN" "$API/membres/titulaire"; echo
# → {"existe":false,"membre":null}

# Création
curl -s -X POST "$API/membres/titulaire" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"date_naissance":"1990-05-14","sexe":"M","groupe_sanguin":"O+"}'; echo
# → 201, {"membre":{ ... "nis":"CIS26XXXXXXXXKK", "est_titulaire":true ... }}

# Double création
curl -s -X POST "$API/membres/titulaire" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"date_naissance":"1990-05-14","sexe":"M"}'; echo
# → 409, {"error":{"code":"DOSSIER_TITULAIRE_EXISTANT", ...}}

# Le client tente d'imposer une identité
curl -s -X POST "$API/membres/titulaire" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"date_naissance":"1990-05-14","sexe":"M","nom":"USURPATEUR","prenom":"X"}'; echo
```

✅ **Attendu** : `409` (le dossier existe), et **en aucun cas** un dossier au nom « USURPATEUR ».
Sur un compte vierge, le même appel crée le dossier **au nom du compte** — `nom`/`prenom` sont
ignorés, jamais lus.

✅ **Attendu aussi** : `GET /auth/me` expose désormais `"a_dossier_titulaire": true`.

---

## C. Base de données — invariants MySQL

### C.1 Un seul titulaire par compte

```sql
-- Tentative de second titulaire pour le même compte
INSERT INTO membres_famille (user_id, nom, prenom, date_naissance, sexe, matricule_ivs, est_titulaire, pays_code, created_at, updated_at)
VALUES (1, 'DOUBLON', 'Test', '1990-01-01', 'M', 'IVS-TEST-DUP', 1, 'CI', NOW(), NOW());
```

✅ **Attendu** : rejet **`Duplicate entry … for key 'uq_membres_un_seul_titulaire'`**.

```sql
-- Tentative de valeur menteuse : titulaire déclaré, colonne de cohérence à NULL
UPDATE membres_famille SET est_titulaire = 1, titulaire_du_compte = NULL WHERE id = <UN_MEMBRE_NON_TITULAIRE>;
```

✅ **Attendu** : rejet par **`ck_membres_titulaire_coherent`**. La cohérence ne repose pas sur la
seule discipline du code applicatif.

### C.2 Non-réutilisabilité du NIS — l'invariant central

```sql
SELECT COUNT(*) AS journal, SUM(membre_id IS NULL) AS orphelins FROM nis_journal;
```

Supprimer un membre porteur d'un NIS, puis rejouer la requête.

✅ **Attendu** : `journal` **inchangé**, `orphelins` **+1**. La ligne survit à la suppression du
dossier (`ON DELETE SET NULL`), et le compteur `nis_compteurs` **ne recule jamais**.

❌ **Ne jamais « nettoyer » `nis_journal`.** Ses lignes orphelines *sont* la garantie de
non-réutilisabilité. Les purger rendrait des NIS réattribuables à d'autres personnes — un NIS
réattribué, c'est un dossier médical rattaché au mauvais patient.

> Après le G2 du 2026-08-11, la base de dev conserve volontairement **22 lignes de journal dont
> 21 orphelines**, alors que 34 comptes de test ont été supprimés. C'est le comportement correct.

### C.3 Concurrence — deux attributions simultanées

Deux connexions MySQL, en parallèle, créant chacune un dossier titulaire.

✅ **Attendu** : **deux NIS distincts**, compteurs **consécutifs**, **zéro deadlock**. La seconde
connexion bloque le temps que la première commite, puis passe en quelques millisecondes.

> **Ce vecteur a trouvé un vrai défaut** que la suite de tests ne pouvait pas voir (elle tourne sur
> SQLite, sans concurrence réelle). Le motif `insertOrIgnore` → `SELECT … FOR UPDATE`, hérité du
> service paiement et **correct sur PostgreSQL**, **deadlocke sur MySQL** (erreur 1213) : le
> contrôle de doublon de l'INSERT prend un verrou partagé, le `FOR UPDATE` demande ensuite un
> verrou exclusif — montée de verrou croisée entre deux transactions. Correctif : prendre le verrou
> **exclusif dès le premier accès** (`UPDATE … dernier + 1`, INSERT seulement si 0 ligne affectée),
> plus `DB::transaction(…, 3)` en filet.
>
> **À retenir pour les modules suivants** : un motif de verrouillage validé sur le service paiement
> (PostgreSQL) **n'est pas transposable tel quel** au cœur Laravel (MySQL).

### C.4 Backfill des dossiers antérieurs

```bash
XDEBUG_MODE=off "$PHP" artisan masante:nis:backfill --dry-run   # compte, n'écrit rien
XDEBUG_MODE=off "$PHP" artisan masante:nis:backfill             # attribue
XDEBUG_MODE=off "$PHP" artisan masante:nis:backfill             # rejeu
```

✅ **Attendu** : la simulation annonce N dossiers et écrit **« Mode --dry-run : aucune écriture
effectuée. »** · l'exécution attribue N NIS · le **rejeu** affiche **« Aucun dossier sans NIS —
rien à faire. »** et **ne consomme pas la séquence**.

```sql
-- Intégrité globale
SELECT (SELECT dernier FROM nis_compteurs WHERE pays_code='CI' AND annee=YEAR(CURDATE())%100) AS compteur,
       (SELECT COUNT(*) FROM nis_journal) AS journal,
       (SELECT COUNT(DISTINCT nis) FROM nis_journal) AS distincts;
```

✅ **Attendu** : `compteur = journal = distincts`.

---

## D. Qualité (G3) — à rejouer avant toute reprise

```bash
# Parité TS ↔ PHP : la MÊME source de vecteurs alimente les deux suites
pnpm --filter @masante/shared test        # node --test, runner natif Node 22, 0 dépendance

# Backend
cd services/api
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan test

# Types
pnpm typecheck
```

✅ **Attendu** : suites vertes des deux côtés. Référence du 2026-08-11 : **47 tests, 13 138
assertions** côté PHP.

> **Ce que garantit `packages/shared/src/nis/vecteurs.json`** : il est **généré**, et consommé à la
> fois par la suite TypeScript et par `NisVecteursPartagesTest` (PHP). Si l'une des deux
> implémentations dérive de l'autre — ne serait-ce que d'un chiffre — **le build casse**. La règle
> « source unique » cesse d'être une consigne : elle devient vérifiable par la machine.
>
> Ne **jamais** éditer ce fichier à la main.

---

## E. Checklist de clôture

**Mobile (cœur du G4)**
- [ ] A.1 Carte « Créez votre dossier de santé », famille et ajout masqués
- [ ] A.2 Complétion → retour au Carnet avec « Mon dossier de santé » + « 0/15 »
- [ ] A.2 Validation locale (« Sélectionnez le sexe. ») avant tout appel réseau
- [ ] A.3 Titulaire **hors** du compteur `x/15`
- [ ] A.4 NIS lisible sur le dossier
- [ ] A.5 Hors-ligne : erreur claire, **aucun** faux succès
- [ ] A.6 Aucune régression sur les écrans validés G5

**Backend**
- [ ] B.1 Six vecteurs de vérification conformes (dont `SNS…` multi-pays et `…2547` invalide)
- [ ] B.2 **Anti-énumération** : NIS inexistant et NIS réel → réponses identiques
- [ ] B.3 Limiteur ~30×200 puis 429
- [ ] B.4 401 / 200 / **403** anti-IDOR
- [ ] B.5 Création 201, double création **409**, identité client ignorée
- [ ] B.5 `GET /auth/me` expose `a_dossier_titulaire`

**Base**
- [ ] C.1 `uq_membres_un_seul_titulaire` + `ck_membres_titulaire_coherent` rejettent
- [ ] C.2 Journal conservé, orphelins en hausse après suppression
- [ ] C.3 Concurrence : NIS distincts, compteurs consécutifs, **0 deadlock**
- [ ] C.4 Backfill dry-run → réel → rejeu neutre ; `compteur = journal = distincts`

**Qualité**
- [ ] D. `pnpm --filter @masante/shared test`, `artisan test`, `pnpm typecheck` verts

> Tout coché sans écart → écrire **« Module P6.1 validé »** (G5) et consigner en mémoire.

---

## F. Pièges rencontrés (à relire avant de re-tester)

| Piège | Symptôme | Parade |
|-------|----------|--------|
| MySQL **1215** | « Cannot add foreign key constraint » sur la migration | Ne pas dériver une colonne générée d'une colonne `ON DELETE CASCADE` ; colonne maintenue + `UNIQUE` + `CHECK` |
| Deadlock **1213** | Attribution concurrente qui échoue par intermittence | Verrou **exclusif dès le premier accès**, jamais `INSERT IGNORE` puis `FOR UPDATE` |
| Route `/membres/titulaire` en **404** | Captée par `/membres/{membre}` | Déclarer les routes titulaire **avant** l'`apiResource` |
| Jeton Sanctum tronqué | 401 alors que la connexion a réussi | Guillemeter `"$TOKEN"` : le `|` est un tube pour le shell |
| `RefreshDatabase` masque une garde | Un test « passe » alors que la garde ne se déclenche pas | Sortir ce test dans une classe **sans** le trait (`NisGardeTransactionTest`) |
| ADR-001 | L'exemple `CIS241200012547` du document est **faux** | Référence = `CIS241200012535` ; l'ancienne valeur est conservée comme **cas invalide** |

---

## Références

- **CDC_09 §3** — Identifiant National de Santé
- **CDC_10 §5** — anti-énumération, IDOR
- **ADR-021** — `docs/adr/ADR-021-identifiant-national-sante.md` (décisions et §6 correction d'ADR-001)
- **ADR-001** — amendé par ADR-021
- Suite : **ADR-022** (MPI), **ADR-023** (rapprochement flou), **ADR-024** (référentiels additifs)
