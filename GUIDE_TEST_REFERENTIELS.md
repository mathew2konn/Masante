# GUIDE DE TEST — Référentiels nationaux (CDC_09)

> **Parties** : **1** P6.3 Socle référentiel · **2** P6.4a Référentiel des établissements ·
> **3** P6.4b Villes et géolocalisation · **4** P6.4c Images des établissements.
> Un domaine, un guide : les référentiels d'annuaire à venir (professionnels P6.5, médicaments
> P6.6, laboratoires P6.7) s'ajouteront ici en parties, pas en fichiers nouveaux.

---

# PARTIE 1 — Socle référentiel (P6.3)

**Module** : P6.3 — registre, versionnage, gouvernance §10, audit §11, diffusion.
**Corpus** : CDC_09 §10, §11, §12, §14.1 · **Décisions** : [ADR-025](docs/adr/ADR-025-socle-referentiel.md), [ADR-024](docs/adr/ADR-024-referentiels-nationaux.md) · **Plan G1** : [docs/PLAN_G1_P6_3_Socle_Referentiel.md](docs/PLAN_G1_P6_3_Socle_Referentiel.md).

---

## 1. Périmètre — et ce que ce module ne fait PAS

### Ce qu'il fait

Il donne à un référentiel national un **responsable**, un **cycle de décision à deux personnes**, un **historique immuable**, un **audit détectable en cas d'altération** et une **diffusion en lecture**. Une décision peut désormais citer une version, et cette version reste rejouable.

Deux référentiels sont placés sous gouvernance : **`seuils_mesure`** (table `referentiels_mesure` — bornes de plausibilité, normalité, seuils critiques) et **`symptomes_triage`** (table `symptomes` — poids de sévérité, drapeaux rouges, questions). Ce sont les deux qui portent de **vraies règles cliniques**.

### Ce qu'il ne fait pas — à lire avant de tester

| # | Limite | Pourquoi |
|---|---|---|
| **L1** | La diffusion cachée sert la **nouvelle API**. Le triage, les mesures et l'annuaire **continuent de lire leur table en direct**. | Ces modules sont validés G5 ; leur bascule est un incrément additif ultérieur. **Conséquence à ne pas confondre avec un bug** : après une modification directe de la table, `/api/v1/symptomes` change mais `/api/v1/referentiels/symptomes_triage` ne change pas — c'est le comportement attendu. Statut de dette : [ADR-025 §5](docs/adr/ADR-025-socle-referentiel.md). |
| **L2** | L'estampille de version est **fournie et testée**, mais branchée sur **aucune** décision. `triages` ne stocke toujours pas la version du protocole. | Le brancher modifierait un module validé G5 ; estamper rétroactivement les décisions passées serait pire — elles n'ont eu aucune version. **L2 n'est pas recevable avant L1** : estamper « v7 » en lisant encore la table produirait une mention fausse — [ADR-025 §5.2](docs/adr/ADR-025-socle-referentiel.md). |
| **L3** | « Lecture < 50 ms » (§12) n'est **pas déclaré atteint**. | Le cache Laravel est `database`, pas Redis. Le temps est **mesuré et rapporté tel quel** (§6.3). |
| **L4** | Pas de MFA exigé à l'écriture. | Gouverné par la bascule `MFA_ENFORCE` de P1, **OFF en MVP**. |
| **L5** | Ni synchronisation nationale, ni événements inter-services, ni FHIR/SNOMED/CIM/LOINC/DICOM. | Étapes 9 et 10 de l'ordre CDC_09 §14. |
| **L6** | L'instantané JSON convient à des référentiels de **règles**. Sa pertinence pour un référentiel volumineux sera réexaminée en P6.6. | Pas présumée. |
| **L7** | **Aucun écran.** La gouvernance s'exerce par API. | L'écran d'administration viendra avec le portail des référentiels (P6.4+). Il n'y a donc **pas de scénario front** dans ce guide, et c'est volontaire. |

### Référentiels non gouvernés en P6.3

`structures_sanitaires` (P6.4), `medecins` (P6.5), `medicaments` (P6.6), laboratoires (P6.7), `etapes_prenatales`. Un code hors liste blanche répond **404**, jamais 500 — c'est un cas de test (§4.9).

---

## 2. Prérequis

```bash
# Backend Laravel
cd C:\wamp64\www\IVOIRESANTE\services\api
set PHP=C:\wamp64\bin\php\php8.3.28\php.exe

# 1. Migration (3 tables neuves, strictement additive)
XDEBUG_MODE=off %PHP% artisan migrate --force

# 2. Permissions (idempotent — ajoute referentiel.proposer / referentiel.publier)
XDEBUG_MODE=off %PHP% artisan db:seed --class=PortailRolesSeeder --force

# 3. Registre (idempotent — n'ajoute AUCUN maillon d'audit au rejeu)
XDEBUG_MODE=off %PHP% artisan db:seed --class=ReferentielRegistreSeeder --force

# 4. Serveur
XDEBUG_MODE=off %PHP% artisan serve --host=0.0.0.0 --port=8000
```

**Deux comptes sont nécessaires, et c'est le cœur du module** : le quatre-yeux impose que l'auteur d'une proposition ne soit pas celui qui la décide.

```bash
XDEBUG_MODE=off %PHP% artisan tinker
>>> $a = App\Models\User::firstOrCreate(['telephone'=>'+2250799000001'], ['nom'=>'Test','prenom'=>'Auteur','email'=>'auteur@masante.ci','password'=>bcrypt('Test@2026!'),'date_naissance'=>'1990-01-01']);
>>> $b = App\Models\User::firstOrCreate(['telephone'=>'+2250799000002'], ['nom'=>'Test','prenom'=>'Decideur','email'=>'decideur@masante.ci','password'=>bcrypt('Test@2026!'),'date_naissance'=>'1990-01-01']);
>>> $a->givePermissionTo('referentiel.proposer');
>>> $b->givePermissionTo('referentiel.publier');
>>> echo $a->createToken('g4')->plainTextToken;   // → $TOKEN_A
>>> echo $b->createToken('g4')->plainTextToken;   // → $TOKEN_B
```

> **Note d'habilitation.** `referentiel.proposer` et `referentiel.publier` ne sont attachées à **aucun rôle métier** — le gestionnaire les accorde nominativement (précédent `urgence.bris_de_glace`, `dossier.ecrire`). En revanche `admin_ivoirsante` les reçoit, comme toutes les autres, par le `syncPermissions(Permission::all())` de son seeder. Cela n'affaiblit pas le quatre-yeux, qui sépare deux **utilisateurs**, pas deux rôles.

---

## 3. Scénarios front

**Aucun** — voir limite **L7**. Ce module est backend seul ; sa validation G4 se fait par API, comme pour les incréments du service de paiement.

---

## 4. Scénarios backend (curl reproductibles)

> Toutes les commandes supposent `BASE=http://localhost:8000/api/v1`.

### 4.1 — Le registre est lisible sans authentification

```bash
curl -s $BASE/referentiels | jq
```
✅ **Attendu** : `referentiels` contient `seuils_mesure` et `symptomes_triage`, chacun avec `"version": null` (rien n'est publié d'office — le seeder n'enregistre que le registre, publier depuis un seeder contournerait la gouvernance dès le premier jour).

### 4.2 — Un référentiel jamais publié ne diffuse rien

```bash
curl -s -o /dev/null -w "%{http_code}\n" $BASE/referentiels/seuils_mesure
```
✅ **Attendu** : `404` — « n'a aucune version publiée : il n'y a rien à diffuser ».

### 4.3 — État de qualité du contenu actuel (détection seule)

```bash
curl -s -H "Authorization: Bearer $TOKEN_A" $BASE/referentiels/seuils_mesure/controle | jq
```
✅ **Attendu** : `nb_entrees: 7`, `erreurs: []`, une `empreinte` SHA-256. **Cet appel ne publie ni ne corrige rien.**

### 4.4 — Proposer sans habilitation → 403 ; anonymement → 401

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST $BASE/referentiels/seuils_mesure/propositions \
  -H "Content-Type: application/json" -d '{"motif":"Tentative anonyme de changement."}'

curl -s -o /dev/null -w "%{http_code}\n" -X POST $BASE/referentiels/seuils_mesure/propositions \
  -H "Authorization: Bearer $TOKEN_QUIDAM" -H "Content-Type: application/json" \
  -d '{"motif":"Tentative sans habilitation."}'
```
✅ **Attendu** : `401` puis `403`.

### 4.5 — Déposer une proposition

```bash
curl -s -X POST $BASE/referentiels/seuils_mesure/propositions \
  -H "Authorization: Bearer $TOKEN_A" -H "Content-Type: application/json" \
  -d '{"motif":"Premiere mise en vigueur des seuils nationaux."}' | jq
```
✅ **Attendu** : `201`, `version.numero: 1`, `version.statut: "proposition"`, `version.nb_entrees: 7`.
`contenu_json` **n'apparaît pas** dans la réponse : l'instantané se relit par l'endpoint de diffusion.

Un motif de moins de 10 caractères → **422** (une version sans motif est une version qu'on ne saura pas expliquer dans six mois).

### 4.6 — Une seule proposition à la fois → 409

Rejouer la commande 4.5.
✅ **Attendu** : `409` — « Une proposition est déjà en cours ». Garanti aussi en base par `UNIQUE(verrou_unicite)`.

### 4.7 — Quatre-yeux : l'auteur ne peut pas se valider

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST $BASE/referentiels/seuils_mesure/publication \
  -H "Authorization: Bearer $TOKEN_A" -H "Content-Type: application/json" \
  -d '{"motif":"Je valide ma propre proposition."}'
```
✅ **Attendu** : `403` si le compte A ne porte pas `referentiel.publier` ; **`409`** s'il la porte — c'est alors le quatre-yeux, et non le droit, qui l'arrête. Testez la seconde forme : c'est celle qui prouve la règle §10.

### 4.8 — Publier avec un second habilité

```bash
curl -s -X POST $BASE/referentiels/seuils_mesure/publication \
  -H "Authorization: Bearer $TOKEN_B" -H "Content-Type: application/json" \
  -d '{"motif":"Controles qualite conformes, mise en vigueur nationale."}' | jq
```
✅ **Attendu** : `200`, `version.statut: "publiee"`, `version.decide_par` = id du compte B.

Puis :
```bash
curl -s $BASE/referentiels/seuils_mesure | jq '{version, nb_entrees, empreinte}'
```
✅ **Attendu** : `version: 1`, 7 entrées.

### 4.9 — Un code inconnu répond 404, jamais 500

```bash
curl -s -o /dev/null -w "%{http_code}\n" $BASE/referentiels/table_arbitraire
```
✅ **Attendu** : `404`. Le code arrive par l'URL ; sans la liste blanche fermée `RegistreReferentiels`, il serait une porte vers n'importe quelle table.

### 4.10 — La diffusion sert la version publiée, pas la table en direct

```bash
# Modifier la table métier SANS passer par la gouvernance
XDEBUG_MODE=off %PHP% artisan tinker --execute="App\Models\ReferentielMesure::where('type_mesure','glycemie')->update(['normal_max'=>1.25]);"

curl -s $BASE/referentiels/seuils_mesure | jq '.contenu[] | select(.type_mesure=="glycemie") | .normal_max'
```
✅ **Attendu** : l'**ancienne** valeur (`1.1`). Ce n'est pas un cache périmé : c'est l'instantané publié, et c'est ce décalage assumé qui permet à une décision de citer une version. Voir **L1**.

### 4.11 — Anti-substitution : un contenu modifié après relecture n'est pas publiable

```bash
# 1. proposer sur le contenu courant
curl -s -X POST $BASE/referentiels/seuils_mesure/propositions -H "Authorization: Bearer $TOKEN_A" \
  -H "Content-Type: application/json" -d '{"motif":"Relevement du maximum normal de glycemie."}' | jq .version.numero

# 2. quelqu'un modifie la table APRÈS la relecture
XDEBUG_MODE=off %PHP% artisan tinker --execute="App\Models\ReferentielMesure::where('type_mesure','glycemie')->update(['normal_max'=>9.99]);"

# 3. publier
curl -s -X POST $BASE/referentiels/seuils_mesure/publication -H "Authorization: Bearer $TOKEN_B" \
  -H "Content-Type: application/json" -d '{"motif":"Publication du contenu relu."}' | jq
```
✅ **Attendu** : `409` — « Le contenu a changé depuis la proposition […] : ce qui serait publié n'est plus ce qui a été relu ». **C'est le vecteur le plus important du module** : sans lui, on publierait un contenu que personne n'a relu, et le référentiel diffusé cesserait de correspondre à la table lue par le triage.

### 4.12 — Contrôles qualité bloquants à la publication → 422

`9.99` sort de la plage plausible (`valeur_max = 6.0`) : la plage normale déborde la plage de plausibilité, donc une glycémie normale serait rejetée à la saisie comme une faute de frappe.

```bash
curl -s -X POST $BASE/referentiels/seuils_mesure/rejet -H "Authorization: Bearer $TOKEN_B" \
  -H "Content-Type: application/json" -d '{"motif":"Contenu modifie en cours de route, a re-proposer."}' >/dev/null

curl -s -X POST $BASE/referentiels/seuils_mesure/propositions -H "Authorization: Bearer $TOKEN_A" \
  -H "Content-Type: application/json" -d '{"motif":"Nouvelle proposition sur le contenu courant."}' >/dev/null

curl -s -X POST $BASE/referentiels/seuils_mesure/publication -H "Authorization: Bearer $TOKEN_B" \
  -H "Content-Type: application/json" -d '{"motif":"Tentative de publication du contenu incoherent."}' | jq
```
✅ **Attendu** : `422`, `error.details` non vide, contenant « la plage normale sort de la plage plausible ».
⚠️ **La proposition, elle, avait été acceptée** : un auteur doit pouvoir soumettre un contenu à discuter. C'est à la **publication** que les contrôles font barrage.

### 4.13 — Publier une version saine : archivage et bascule de diffusion

```bash
curl -s -X POST $BASE/referentiels/seuils_mesure/rejet -H "Authorization: Bearer $TOKEN_B" \
  -H "Content-Type: application/json" -d '{"motif":"Seuils aberrants, proposition refusee."}' >/dev/null

XDEBUG_MODE=off %PHP% artisan tinker --execute="App\Models\ReferentielMesure::where('type_mesure','glycemie')->update(['normal_max'=>1.25]);"

curl -s -X POST $BASE/referentiels/seuils_mesure/propositions -H "Authorization: Bearer $TOKEN_A" \
  -H "Content-Type: application/json" -d '{"motif":"Relevement mesure du maximum normal de glycemie."}' >/dev/null
curl -s -X POST $BASE/referentiels/seuils_mesure/publication -H "Authorization: Bearer $TOKEN_B" \
  -H "Content-Type: application/json" -d '{"motif":"Valide par le comite clinique national."}' | jq .version.numero

curl -s $BASE/referentiels/seuils_mesure | jq '{version, glycemie: (.contenu[]|select(.type_mesure=="glycemie")|.normal_max)}'
```
✅ **Attendu** : la nouvelle version est diffusée avec `normal_max = 1.25`. **Aucune invalidation n'a été écrite** : la clé de cache porte le numéro de version, donc la lecture suivante interroge une autre clé.

### 4.14 — Une version archivée reste rejouable

```bash
curl -s $BASE/referentiels/seuils_mesure/versions/1 | jq '{version, statut, glycemie: (.contenu[]|select(.type_mesure=="glycemie")|.normal_max)}'
```
✅ **Attendu** : `statut: "archivee"`, `normal_max: 1.1` — la valeur d'origine. C'est ce qui rend explicable une décision prise sous la version 1.

Une **proposition** (jamais décidée) n'est pas servie ici → **404** : aucune décision n'a pu s'appuyer dessus.

### 4.15 — Historique et journal d'audit

```bash
curl -s -H "Authorization: Bearer $TOKEN_B" $BASE/referentiels/seuils_mesure/versions | jq '.versions[] | {numero, statut, motif_decision}'
curl -s -H "Authorization: Bearer $TOKEN_B" $BASE/referentiels-journal | jq '{chaine, nb: (.entrees|length)}'
```
✅ **Attendu** : l'historique montre `publiee` / `archivee` / `rejetee` ; `chaine.intacte: true`.

---

## 5. Invariants base de données

```sql
USE ivoirsante;

-- (a) Les 6 triggers de cycle de vie (MySQL refuse un CHECK sur ces colonnes — voir §8).
SHOW TRIGGERS LIKE 'referentiel_versions';
-- ✅ ck_ref_version_verrou_insert/update, _quatre_yeux_insert/update, _decision_insert/update

-- (b) L'unicité reste déclarative.
SHOW INDEX FROM referentiel_versions WHERE Non_unique = 0;
-- ✅ PRIMARY, uq_ref_version_numero (referentiel_id, numero), uq_ref_version_verrou (verrou_unicite)

-- (c) Au plus une proposition ET une version publiée par référentiel.
SELECT referentiel_id, statut, COUNT(*) FROM referentiel_versions
 WHERE statut IN ('proposition','publiee') GROUP BY referentiel_id, statut;
-- ✅ aucun compte > 1

-- (d) Le registre ne ment pas sur la version en vigueur.
SELECT r.code, r.version_publiee_numero, v.numero, v.statut
  FROM referentiels r LEFT JOIN referentiel_versions v
    ON v.referentiel_id = r.id AND v.statut = 'publiee';
-- ✅ version_publiee_numero = v.numero pour chaque référentiel publié

-- (e) Le journal ne recopie jamais le contenu du référentiel.
SELECT COUNT(*) FROM referentiel_journal
 WHERE details_json LIKE '%conseil%' OR details_json LIKE '%poids_severite%';
-- ✅ 0
```

**Quatre-yeux garanti par le moteur** — écriture SQL directe, hors du service :

```sql
UPDATE referentiel_versions
   SET statut='publiee', verrou_unicite=CONCAT('V:',referentiel_id),
       decide_par=propose_par, decide_le=NOW()
 WHERE statut='proposition' LIMIT 1;
```
✅ **Attendu** : `ERROR 1644 (45000): ck_ref_version_quatre_yeux`.

**Altération du journal détectée** :

```sql
UPDATE referentiel_journal SET acteur_nom='Quelqu''un d''autre' WHERE id = 3;
```
puis
```bash
curl -s -H "Authorization: Bearer $TOKEN_B" $BASE/referentiels-journal | jq .chaine
```
✅ **Attendu** : `intacte: false`, `rupture.type: "CONTENU"`, `rupture.id: 3`.
Remettre la valeur d'origine rétablit la chaîne — **le module ne répare jamais un audit**, il le constate.

Supprimer une entrée (`DELETE FROM referentiel_journal WHERE id = 3;`) donne `rupture.type: "CHAINAGE"`.

---

## 6. Commandes de qualité (G3)

### 6.1 Suite de tests

```bash
XDEBUG_MODE=off %PHP% artisan test --filter=SocleReferentielTest   # 39 tests
XDEBUG_MODE=off %PHP% artisan test                                  # suite complète
cd C:\wamp64\www\IVOIRESANTE && pnpm typecheck                      # 3 workspaces
```
✅ **Référence au G5 (2026-08-13)** : 39/39 pour le module · **456 tests / 14 517 assertions** au total, dont **1 échec non lié** (`PrixMedicamentTest` — Tesseract dépasse son délai de 20 s sous charge ; **vert en exécution isolée**) · typecheck ×3 verts.

### 6.2 Commande de contrôle (détection seule)

```bash
XDEBUG_MODE=off %PHP% artisan masante:referentiel:controler
```
✅ **Attendu** : par référentiel, la qualité du contenu actuel, l'écart éventuel entre la table et la version en vigueur (« DIVERGENTE de la table » = un changement attend d'être proposé, ce n'est **pas** une faute), puis l'état de la chaîne d'audit. Sortie non nulle si quelque chose demande une décision humaine.

### 6.3 Mesure de la lecture (§12 — rapportée, pas déclarée atteinte)

```bash
curl -s -o /dev/null -w "%{time_total}s\n" $BASE/referentiels/seuils_mesure
```
Relever la valeur telle quelle. Le budget « < 50 ms » suppose Redis (**L3**) ; le cache est ici `database`.

---

## 7. Checklist de clôture

- [ ] Migration passée sur MySQL, 3 tables et 6 triggers présents (§5a)
- [ ] Registre lisible sans authentification, `version: null` avant toute publication (§4.1)
- [ ] Référentiel non publié → 404 en diffusion (§4.2)
- [ ] Proposition anonyme → 401 · sans habilitation → 403 (§4.4)
- [ ] Proposition déposée, `contenu_json` absent de la réponse (§4.5) · motif court → 422
- [ ] Seconde proposition → 409 (§4.6)
- [ ] Auteur qui se valide → 409, même en portant `referentiel.publier` (§4.7)
- [ ] Publication par un second habilité → 200 (§4.8)
- [ ] Code inconnu → 404, jamais 500 (§4.9)
- [ ] `UPDATE` direct de la table ne change pas ce qui est diffusé (§4.10)
- [ ] **Anti-substitution** : contenu modifié après relecture → 409 (§4.11)
- [ ] **Contrôles qualité** : contenu incohérent → 422 avec détails, alors que la proposition avait été acceptée (§4.12)
- [ ] Nouvelle publication → ancienne archivée, diffusion bascule sans invalidation écrite (§4.13)
- [ ] Version archivée toujours rejouable · proposition jamais diffusée (§4.14)
- [ ] Chaîne d'audit intacte (§4.15) · altération → `CONTENU` · suppression → `CHAINAGE` (§5)
- [ ] Quatre-yeux refusé par le moteur en SQL direct (§5)
- [ ] Journal sans aucun contenu de référentiel (§5e)
- [ ] `masante:referentiel:controler` cohérent (§6.2)
- [ ] Suite complète + typecheck ×3 (§6.1)
- [ ] **Limites L1→L7 relues et acceptées** (§1)

---

## 8. Pièges rencontrés

**MySQL 8.4 — erreur 3823, le piège central du module.**
`Column 'decide_par' cannot be used in a check constraint: needed in a foreign key constraint referential action.` Un `CHECK` ne peut pas porter sur une colonne subissant une action référentielle ; or `propose_par`/`decide_par` sont `nullOnDelete` et `referentiel_id` est `cascadeOnDelete`. **Cousin exact de l'erreur 1215 de P6.1.** Renoncer aux `nullOnDelete` aurait été le mauvais échange (la suppression d'un compte aurait été bloquée par l'historique, ou l'aurait emporté). Retenu : des **triggers** dans les deux dialectes, voie prévue par CDC_04 §139.

**SQLite refuse `ALTER TABLE … ADD CONSTRAINT`.** Un `CHECK` n'y existe qu'à la création de la table, forme que le Blueprint de Laravel n'exprime pas. Même parade — triggers `RAISE(ABORT)` —, ce qui garde la garantie **du moteur** dans les tests : sans elle, la suite ne pourrait plus prouver qu'une écriture SQL directe est bien refusée.

**`COALESCE(condition, 0) = 0` et non `NOT(condition)`.** Une comparaison avec NULL vaut NULL, et un `WHEN NULL` ne déclenche rien : la violation passerait **sans bruit**. C'est le genre de garde qui a l'air vert et ne protège rien.

**Une migration DDL qui échoue à mi-parcours ne se rejoue pas telle quelle.** MySQL n'est pas transactionnel sur le DDL : après l'échec du premier `ALTER`, `referentiels` et `referentiel_versions` existaient mais la migration n'était pas enregistrée. Il faut `Schema::dropIfExists()` les tables partielles avant de relancer `migrate`.

**`acteur_nom` doit entrer dans le calcul de l'empreinte.** Le test de détection d'altération l'a révélé : en ne hachant que `acteur_id`, on pouvait réécrire le nom d'un agent en « Système » sans rompre la chaîne — or c'est ce nom-là qu'un humain lit dans un audit.

**`assertDatabaseCount()` n'accepte pas de message.** Son troisième argument est la **connexion** ; y passer une phrase produit « Database connection [...] not configured ».

**Le seeder du registre ne publie rien, volontairement.** Publier depuis un seeder contournerait la gouvernance dès le premier jour, et il n'y aurait personne à qui rattacher la décision. Après `db:seed`, `version` vaut `null` : ce n'est pas un défaut d'installation.

---
---

# PARTIE 2 — Référentiel national des établissements (P6.4a)

**Module** : P6.4a — enrichissement, identifiant national, découpage sanitaire, gouvernance par projection.
**Corpus** : CDC_09 §4 · CDC_11 §3 · **Décision** : [ADR-026](docs/adr/ADR-026-referentiel-etablissements.md) · **Plan G1** : [docs/PLAN_G1_P6_4_Referentiel_Etablissements.md](docs/PLAN_G1_P6_4_Referentiel_Etablissements.md).

## 2.1 Périmètre — et ce que ce module ne fait PAS

### Ce qu'il fait

Les établissements reçoivent un **identifiant national** (`ETS000152`, §4.3), un rattachement au **découpage sanitaire** (région, district), et **22 colonnes d'identité administrative** au sens du §4.2 et de CDC_11 §3.1. Le référentiel entre sous la gouvernance du socle P6.3 — **par une projection**, pas par la table entière.

### Ce qu'il ne fait pas — à lire avant de tester

| # | Limite | Pourquoi |
|---|---|---|
| **M1** | **L'onboarding « Méthode 2 » (demande d'inscription, CDC_11 §3) n'est pas livré.** | Parcours applicatif relevant du **portail Next**, dont ADR-011 programme déjà la migration ; le construire en Blade puis le refaire serait du gaspillage. **CDC_11 §3 affirme que les deux méthodes sont implémentées : tant que M1 tient, cette affirmation est fausse dans ce projet.** |
| **M2** | Le découpage sanitaire seedé est un **jeu partiel** : 1 région, 5 districts. Le pays en compte **33 et 113**. | Le seeder n'essaie **délibérément pas** de reproduire la répartition d'Abidjan entre ses deux régions sanitaires réelles, faute de l'arrêté qui la fixe. Une liste inventée qui a l'air juste ne se fait pas corriger. Charger la liste officielle = **données, zéro code**. |
| **M3** | **Aucun écran.** Les formulaires du portail restent sur leurs 11 champs d'origine. | P6.4a est le backend (G2 avant tout écran). **P6.4b** les alignera. |
| **M4** | Ni images/logo, ni niveau de soins par service, ni PKI. | Stockage de fichiers ; P6.5 pour la PKI. |
| **M5** | `StructureService` (P3) lit toujours la table en direct, pas la version publiée. | C'est **L1** d'[ADR-025 §5](docs/adr/ADR-025-socle-referentiel.md), qui s'applique ici aussi. |

## 2.2 Prérequis

```bash
cd C:\wamp64\www\IVOIRESANTE\services\api
set PHP=C:\wamp64\bin\php\php8.3.28\php.exe

XDEBUG_MODE=off %PHP% artisan migrate --force
XDEBUG_MODE=off %PHP% artisan db:seed --class=DecoupageSanitaireSeeder --force
XDEBUG_MODE=off %PHP% artisan masante:etablissement:backfill
XDEBUG_MODE=off %PHP% artisan db:seed --class=ReferentielRegistreSeeder --force
```

Les deux comptes habilités de la partie 1 (`$TOKEN_A` proposer, `$TOKEN_B` publier) servent aussi ici.

## 2.3 Scénarios front

**Aucun** — voir limite **M3**.

## 2.4 Scénarios backend

### 2.4.1 — Identifiant national au format imposé

```bash
XDEBUG_MODE=off %PHP% artisan masante:etablissement:backfill --dry-run
XDEBUG_MODE=off %PHP% artisan masante:etablissement:backfill
```
✅ **Attendu** : `--dry-run` **n'écrit rien** ; le second appel attribue `ETS000001` … `ETS000012`, un par structure, dans l'ordre des `id`. Un troisième appel dit « rien à faire » et **ne réattribue aucun identifiant**.

⚠️ Le format n'a **pas** de clé de contrôle, contrairement au NIS : §3.2 en impose une pour le NIS et pas ici, et l'exemple imposé `ETS000152` n'en porte aucune (ADR-026 §3.1).

### 2.4.2 — Découpage sanitaire

```sql
SELECT r.code AS region, d.code AS district, COUNT(s.id) AS structures
  FROM regions r
  JOIN districts_sanitaires d ON d.region_id = r.id
  LEFT JOIN structures_sanitaires s ON s.district_id = d.id
 GROUP BY r.code, d.code;
```
✅ **Attendu** : 1 région `ABJ`, 5 districts, les 12 structures réparties par commune.

Rejouer `db:seed --class=DecoupageSanitaireSeeder` **ne crée aucun doublon** et **ne réécrit aucun rattachement déjà posé** — un correctif manuel du ministère prime sur la table de correspondance approximative.

### 2.4.3 — Contrôles qualité sur données réelles

```bash
XDEBUG_MODE=off %PHP% artisan masante:referentiel:controler
```
✅ **Attendu** : `etablissements` remonte des anomalies **réelles** tant que les colonnes neuves sont vides — « statut juridique absent », « niveau de soins absent alors que la catégorie chu est hospitalière ». **Ce n'est pas un défaut d'installation** : l'absence se dit, elle ne se comble pas par une valeur inventée (ADR-026 §3.4).

Compléter les colonnes fait tomber les anomalies à zéro.

### 2.4.4 — Le district doit appartenir à la région déclarée

```sql
UPDATE structures_sanitaires SET region_id = (SELECT id FROM regions WHERE code <> 'ABJ' LIMIT 1)
 WHERE identifiant_national = 'ETS000001';
```
puis relancer `masante:referentiel:controler`.
✅ **Attendu** : « le district ABJ-CB n'appartient pas à la région déclarée … ».

C'est l'anomalie la plus sournoise du lot : **les deux références sont valides prises séparément**, seule leur combinaison est fausse — une statistique par région la propagerait sans que rien ne la signale.

### 2.4.5 — LE VECTEUR CENTRAL : la projection sépare l'identité de l'état

Publier le référentiel (cycle §10 de la partie 1), puis :

```sql
-- Ce que fait NoteStructureService à chaque avis déposé par un citoyen
UPDATE structures_sanitaires SET note_moyenne = 4.9, nb_avis = 128 WHERE id = 1;
UPDATE structures_sanitaires SET telephone = '+2250700000000', tarif_min_cfa = 9999 WHERE id = 1;
```
```bash
XDEBUG_MODE=off %PHP% artisan masante:referentiel:controler
```
✅ **Attendu** : `etablissements` reste **« conforme à la table »**. Un avis citoyen, un changement de téléphone ou de tarif **ne font PAS diverger le référentiel national**.

Puis :
```sql
UPDATE structures_sanitaires SET statut_juridique = 'prive' WHERE id = 1;
```
✅ **Attendu** : cette fois `DIVERGENTE de la table`. **Les deux moitiés du vecteur comptent** : une projection insensible à tout ne servirait à rien.

### 2.4.6 — Diffusion

```bash
curl -s $BASE/referentiels/etablissements | jq '.contenu[0]'
```
✅ **Attendu** : `identifiant_national`, `nom_officiel`, `categorie`, `statut_juridique`, `niveau_soins`, `region_code`, `district_code`, `commune`, capacités, agréments.
**Absents** : `note_moyenne`, `nb_avis`, `telephone`, `horaires_json`, `latitude`, `tarif_min_cfa`, `description`.

## 2.5 Invariants base de données

```sql
-- (a) Unicité par pays, pas mondiale.
SHOW INDEX FROM structures_sanitaires WHERE Key_name = 'uq_etablissement_identifiant';
-- ✅ (pays_code, identifiant_national)

-- (b) Deux pays peuvent porter le même identifiant.
SELECT pays_code, identifiant_national FROM structures_sanitaires
 WHERE identifiant_national = 'ETS000001';
-- ✅ possible en CI ET en SN

-- (c) L'ENUM `type` est élargi sans perte.
SHOW COLUMNS FROM structures_sanitaires LIKE 'type';
-- ✅ 13 valeurs, dont les 7 historiques inchangées

-- (d) Aucune structure perdue.
SELECT COUNT(*) FROM structures_sanitaires;  -- ✅ 12
```

**Unicité garantie par le moteur** :
```sql
UPDATE structures_sanitaires SET identifiant_national = 'ETS000001' WHERE id = 2;
```
✅ **Attendu** : `ERROR 1062 … Duplicata du champ 'CI-ETS000001'`.

**L'identifiant ne peut pas venir d'un formulaire** : `identifiant_national` et `pays_code` sont hors `$fillable` — un `create()` qui les porte les ignore silencieusement (test dédié).

## 2.6 Commandes de qualité (G3)

```bash
XDEBUG_MODE=off %PHP% artisan test --filter=ReferentielEtablissementsTest   # 24 tests
XDEBUG_MODE=off %PHP% artisan test                                          # suite complète
pnpm typecheck
```
✅ **Référence au G5 (2026-08-13)** : 24/24 pour le module · **480 tests / 14 599 assertions, 0 échec** · typecheck ×3 verts.

## 2.7 Checklist de clôture

- [ ] Migration passée sur base peuplée, **12 structures intactes**, 22 colonnes ajoutées (§2.5d)
- [ ] ENUM `type` élargi à 13 valeurs, les 7 historiques acceptées (§2.5c)
- [ ] Backfill : `--dry-run` n'écrit rien · attribution `ETS000001`… · rejeu sans réattribution (§2.4.1)
- [ ] Découpage seedé, idempotent, rattachement par commune, rattachement manuel préservé (§2.4.2)
- [ ] Contrôles qualité remontent les colonnes vides, puis zéro une fois complétées (§2.4.3)
- [ ] District hors région détecté (§2.4.4)
- [ ] **Avis / téléphone / tarif → PAS de divergence** (§2.4.5)
- [ ] **Statut juridique → divergence** (§2.4.5)
- [ ] Diffusion sans état opérationnel (§2.4.6)
- [ ] Unicité (pays, identifiant) refusée par le moteur ; deux pays peuvent partager `ETS000001` (§2.5)
- [ ] `identifiant_national` ignoré en assignation de masse
- [ ] Anti-substitution du socle active sur les établissements
- [ ] Suite complète + typecheck ×3 (§2.6)
- [ ] **Limites M1→M5 relues et acceptées** (§2.1)

## 2.8 Pièges rencontrés

**Le vecteur « ne diverge pas » ne prouve rien tout seul.** Une projection qui n'extrairait qu'une constante passerait ce test. Il faut **les deux moitiés** — insensible à l'avis, sensible au statut juridique — sinon on prouve seulement qu'on n'a rien extrait.

**`identifiant_national` hors `$fillable` est une garde, pas un oubli.** Le laisser assignable en masse permettrait à un client de choisir son propre numéro national.

**L'ENUM élargi doit se rétrécir proprement au `down()`.** Une ligne portant `centre_dialyse` violerait l'énumération restaurée : la migration ramène ces valeurs vers `centre_sante` **avant** de rétrécir la colonne (motif P7-A sur `delegations.droits`).

**Le compteur d'identifiants suit la parade anti-deadlock de P6.1** : verrou exclusif dès le premier accès par `UPDATE … dernier + 1`, insertion seulement si zéro ligne affectée. Le motif « insertOrIgnore puis `SELECT … FOR UPDATE` » deadlocke sur MySQL (1213).

**Le seeder de découpage ne réécrit jamais un rattachement existant.** `whereNull('district_id')` : un correctif du ministère prime sur une table de correspondance par commune, qui n'est qu'une approximation.

---

# PARTIE 3 — Villes et géolocalisation (P6.4b)

**Module** : P6.4b — villes couvertes, détermination backend de la ville, communes conditionnelles, catégories servies par le serveur.
**Corpus** : CDC_09 §4 · CDC_11 §3.1 · CDC_04 §20 · **Décision** : [ADR-027](docs/adr/ADR-027-villes-geolocalisation.md) · **Plan G1** : [docs/PLAN_G1_P6_4b_Villes_Geolocalisation.md](docs/PLAN_G1_P6_4b_Villes_Geolocalisation.md).

## 3.1 Périmètre — et ce que ce module ne fait PAS

### Ce qu'il fait

Trois villes sont couvertes : **Abidjan**, **Yamoussoukro**, **Bouaké**. Le **serveur** détermine dans laquelle se trouve l'utilisateur à partir de sa position, dit si des **communes** doivent être proposées et lesquelles, et — hors zone — dans quel **ordre de proximité** présenter les structures. Le mobile affiche l'icône de géolocalisation avec le nom de la ville ; au tap, la phrase apparaît juste en dessous.

Deux listes qui vivaient **en dur** dans le mobile (les 7 communes d'Abidjan, les 7 libellés de catégorie sur 13) sont supprimées : elles viennent désormais du serveur.

### Ce qu'il ne fait pas — à lire avant de tester

| # | Limite | Pourquoi |
|---|---|---|
| **N1** | **Aucun repli par adresse IP** après refus de la localisation. | Le CGNAT des opérateurs ivoiriens rattacherait la plupart des abonnés à Abidjan quelle que soit leur position : une information **fausse présentée comme certaine**. Le repli est le **choix manuel**, exact par construction. |
| **N2** | Pas de polygones de limites communales : la ville est un **centre + un rayon**. | Les polygones exigeraient des données officielles GeoJSON et une dépendance de calcul géométrique. Un rayon suffit à trouver une ville. |
| **N3** | `commune` reste un **texte libre** sur `structures_sanitaires`. | Sa promotion en table de référence changerait le contrat `?commune=` de P3, **validé G5**. **Conséquence assumée : une faute de frappe dans une commune crée un filtre fantôme** — un chip qui ne ramène qu'une structure. |
| **N4** | Images (P6.4c) et formulaires du portail (P6.4d) — dont les colonnes `ville` et `forme_juridique` d'[ADR-026 §4 M6](docs/adr/ADR-026-referentiel-etablissements.md). | Incréments suivants. |
| **N5** | La **ville d'une structure** (`ville_id`) est posée par le seeder ; **aucun écran ne permet de la changer**. | P6.4d. Une structure sans `ville_id` reste visible partout : elle est simplement absente du filtre `?ville=`. |
| **N6** | Le repli **mémoire** (mode avion) n'est prouvé qu'au **G4**, pas en test automatisé. | Il exige un vrai débranchement réseau sur l'appareil. Le code est écrit et typé ; son comportement réel se constate en avion (§3.3.5). |

## 3.2 Prérequis

Ceux de la partie 2, plus :

```bash
cd C:\wamp64\www\IVOIRESANTE\services\api
set PHP=C:\wamp64\bin\php\php8.3.28\php.exe

XDEBUG_MODE=off %PHP% artisan migrate --force
XDEBUG_MODE=off %PHP% artisan db:seed --class=VilleSeeder --force
XDEBUG_MODE=off %PHP% artisan serve --host=0.0.0.0 --port=8000
```

Mobile : `pnpm mobile`, Expo Go SDK 54 via Ngrok, sur un **téléphone réel** (l'émulateur donne une position fixe et ne prouve rien du parcours de permission).

```bash
set BASE=http://127.0.0.1:8000/api/v1
```

## 3.3 Scénarios front (Expo Go — c'est ici que se joue le G4)

### 3.3.1 — Première ouverture : l'explication vient AVANT l'invite du système

Désinstaller puis réinstaller l'application dans Expo Go (ou révoquer la permission dans les réglages Android), puis se connecter.

✅ **Attendu** : une boîte **« Votre ville »** apparaît — *« MaSante affiche les établissements de votre ville. Autorisez la localisation pour la déterminer automatiquement — vous pourrez sinon la choisir vous-même. »* — avec **« Choisir moi-même »** et **« Autoriser »**.

⚠️ Elle apparaît **avant** l'invite du système, et **une seule fois** : une autorisation demandée sans motif se refuse par réflexe, et un refus est difficile à revenir sur Android comme sur iOS. Si la permission a déjà été accordée ou refusée, rien ne s'affiche.

### 3.3.2 — « Autoriser » → l'icône de géolocalisation porte la ville

Toucher **« Autoriser »**, accepter l'invite du système, aller sur l'onglet **Carte**.

✅ **Attendu** : sous le titre « Structures de santé », un bandeau avec **l'icône de localisation**, le nom de la ville (**Abidjan**) et un chevron.

Toucher ce bandeau.

✅ **Attendu** : **« Vous êtes à Abidjan »** s'affiche **juste en dessous** ; le chevron s'inverse. Un second tap le referme.

### 3.3.3 — Abidjan affiche ses communes, Yamoussoukro non

À Abidjan, faire défiler les filtres.

✅ **Attendu** : une rangée **« Toutes communes »** puis les communes (Cocody, Yopougon, Plateau…). Elles viennent de la **base**, pas d'une constante mobile.

Puis simuler une position à Yamoussoukro (Android : *Options pour les développeurs → Position fictive*, ou un profil GPS d'émulateur) et rouvrir l'écran.

✅ **Attendu** : le bandeau dit **Yamoussoukro** ; la rangée de communes **a disparu entièrement** — pas « Toutes communes » seule, **rien**.

⚠️ C'est le vecteur central de l'incrément : la décision vit dans `villes.affiche_communes`, **jamais** dans un `if ville === 'Abidjan'` écrit dans l'écran. Une quatrième ville subdivisée n'exigera aucun déploiement.

### 3.3.4 — « Choisir moi-même » → sélecteur de ville, mémorisé

Réinstaller, et cette fois répondre **« Choisir moi-même »**.

✅ **Attendu** : **l'invite du système ne s'affiche PAS** — l'utilisateur a dit non à l'explication, la lui imposer derrière produirait le refus définitif qu'on cherche à éviter.
Sur l'onglet Carte apparaît **« Dans quelle ville êtes-vous ? »** avec l'aide *« Sans votre position, nous ne pouvons pas le déterminer. »* et les trois villes en chips.

Toucher **Bouaké**.

✅ **Attendu** : le sélecteur disparaît ; le bandeau affiche **Bouaké** ; au tap, la phrase est **« Ville choisie : Bouaké »** — **pas** « Vous êtes à ». Fermer et rouvrir l'application : Bouaké est **retenue**, le sélecteur ne revient pas.

### 3.3.5 — Mode avion : « Dernière position connue »

Localisation autorisée, ville déterminée une fois, puis **activer le mode avion** et rouvrir l'écran.

✅ **Attendu** : le bandeau porte toujours la ville ; au tap, la phrase devient **« Dernière position connue : Abidjan »**.

⚠️ **La distinction n'est pas cosmétique.** « Vous êtes à X » est une **affirmation** ; la servir depuis un cache la rendrait fausse dès que l'utilisateur se déplace hors couverture réseau. On garde la mémoire — sans elle l'écran serait vide en avion — mais on ne la fait jamais passer pour une mesure.

### 3.3.6 — Hors des zones couvertes

Simuler une position à **Man** (7.4125, -7.5539).

✅ **Attendu** : bandeau d'information — **« Vous êtes hors des zones couvertes. La ville la plus proche est Yamoussoukro, à 258 km. »** — et **toutes** les structures restent listées. Aucun filtre de ville n'est appliqué.

⚠️ On ne rattache **pas** à la ville la plus proche : un utilisateur à Man serait déclaré « à Bouaké », à 300 km. L'absence se dit plutôt qu'elle ne se comble (précédent : les trois silences assumés de P7-D2).

### 3.3.7 — Les catégories viennent du serveur

Sur la Carte, faire défiler la rangée de types.

✅ **Attendu** : **13 catégories** après « Tous », dont *Hôpital général*, *Centre de dialyse*, *Centre d'imagerie*, *Centre de vaccination* — les six ajoutées par P6.4a. Ouvrir une fiche : la catégorie est écrite **en toutes lettres**, jamais « undefined ».

⚠️ Avant P6.4b, `LIBELLE_TYPE` n'en connaissait que 7 : une structure d'une catégorie récente se serait affichée **« undefined · Cocody »**, et le typecheck ne l'attrapait pas, la donnée arrivant à l'exécution.

## 3.4 Scénarios backend (curl reproductibles)

### 3.4.1 — Les villes couvertes sont publiques

```bash
curl -s %BASE%/villes | jq "{villes: [.villes[] | {code, nom, affiche_communes, n: (.communes|length)}], types: (.types_etablissement|length)}"
```
✅ **Attendu** : **sans authentification**, trois villes dans l'ordre métier — `ABJ` (`affiche_communes: true`, communes non vides), `YAM` et `BKE` (`false`, `communes: []`) — et **13** catégories.

L'écran a besoin de cette liste **avant** toute connexion, pour proposer le sélecteur de repli.

### 3.4.2 — « Où suis-je ? » : le serveur répond, l'écran n'en déduit rien

```bash
curl -s "%BASE%/villes/localiser?lat=5.32&lng=-4.02"     | jq   # Plateau, Abidjan
curl -s "%BASE%/villes/localiser?lat=6.8276&lng=-5.2893" | jq   # Yamoussoukro
curl -s "%BASE%/villes/localiser?lat=7.6906&lng=-5.03"   | jq   # Bouaké
```
✅ **Attendu** : `ABJ` avec `communes` peuplé · `YAM` puis `BKE` avec `communes: []` · `hors_zone: false` partout.

⚠️ Yamoussoukro et Bouaké sont à **~95 km** l'une de l'autre : un rayon mal calibré les confondrait. Les distinguer est un vecteur, pas une évidence.

### 3.4.3 — Hors zone : le dire, et ordonner par proximité

```bash
curl -s "%BASE%/villes/localiser?lat=7.4125&lng=-7.5539" | jq   # Man
```
✅ **Attendu** :
```json
{ "ville": null, "hors_zone": true, "communes": [],
  "villes_par_proximite": [ {"code":"YAM","distance_km":258.4}, {"code":"BKE"}, {"code":"ABJ"} ] }
```
`villes_par_proximite` est **toujours** renseigné et **trié par distance croissante** — c'est lui qui donne l'ordre d'affichage (décision V6).

### 3.4.4 — Une position absurde ne casse rien

```bash
curl -s "%BASE%/villes/localiser?lat=0&lng=0" | jq ".hors_zone, (.villes_par_proximite|length)"
curl -s -o nul -w "%%{http_code}\n" "%BASE%/villes/localiser?lat=91&lng=0"
curl -s -o nul -w "%%{http_code}\n" "%BASE%/villes/localiser"
```
✅ **Attendu** : golfe de Guinée → `true` et **3** villes classées · latitude 91 → **422** · paramètres absents → **422**. Jamais de 500.

### 3.4.5 — Le filtre `ville` de l'annuaire

```bash
curl -s "%BASE%/structures?ville=ABJ" | jq ".structures | length"
curl -s "%BASE%/structures?ville=BKE" | jq ".structures | length"
curl -s "%BASE%/structures?commune=Cocody" | jq ".structures | length"
```
✅ **Attendu** : `ABJ` ramène les 12 structures rattachées · `BKE` en ramène 0 (aucune n'y est encore) · **le contrat `?commune=` de P3 est intact** — c'est le vecteur de non-régression du module validé G5.

### 3.4.6 — Les catégories de P6.4a sont enfin filtrables

```bash
curl -s -o nul -w "%%{http_code}\n" "%BASE%/structures?type=centre_dialyse"
curl -s -o nul -w "%%{http_code}\n" "%BASE%/structures?type=chu"
curl -s -o nul -w "%%{http_code}\n" "%BASE%/structures?type=inexistant"
```
✅ **Attendu** : **200**, **200**, **422**.

⚠️ **Défaut réel trouvé par le test.** Avant ce correctif, `StructureController` validait `type` contre les **7 catégories historiques** : filtrer sur une catégorie pourtant acceptée par la base répondait **422**. La liste était recopiée à **quatre** endroits (migration, portail, validation API, mobile) et avait déjà divergé. Elle vit maintenant dans `App\Support\TypesEtablissement`, **source unique**, exposée par l'API.

## 3.5 Invariants base de données

```sql
-- (a) Les trois villes, avec leur rayon et leur règle d'affichage — en DONNÉES.
SELECT code, nom, latitude, longitude, rayon_km, affiche_communes, ordre, actif FROM villes;
-- ✅ ABJ 5.36 / -4.0083 / 35 / 1  ·  YAM 6.8276 / -5.2893 / 25 / 0  ·  BKE 7.6906 / -5.03 / 25 / 0

-- (b) Le rattachement des structures.
SELECT v.code, COUNT(s.id) FROM villes v
  LEFT JOIN structures_sanitaires s ON s.ville_id = v.id GROUP BY v.code;
-- ✅ ABJ 12, YAM 0, BKE 0

-- (c) Les communes sont DÉRIVÉES, pas stockées.
SELECT DISTINCT commune FROM structures_sanitaires WHERE ville_id = (SELECT id FROM villes WHERE code='ABJ');
-- ✅ la liste servie par l'API est exactement celle-ci — elle ne peut pas diverger, elle en sort

-- (d) Ajouter une ville est une ligne de données.
INSERT INTO villes (pays_code, code, nom, latitude, longitude, rayon_km, affiche_communes, ordre, actif, created_at, updated_at)
VALUES ('CI','KOR','Korhogo',9.4580,-5.6294,25,0,4,1,NOW(),NOW());
```
✅ **Attendu pour (d)** : `GET /villes` la sert **immédiatement**, une position à Korhogo la trouve, et le sélecteur mobile la propose — **sans une ligne de code ni un déploiement**. Puis :
```sql
DELETE FROM villes WHERE code = 'KOR';
```

**Désactivation** :
```sql
UPDATE villes SET actif = 0 WHERE code = 'BKE';
```
✅ **Attendu** : `BKE` disparaît de `GET /villes` **et** de `villes_par_proximite` ; une position à Bouaké répond `hors_zone`. Rétablir : `UPDATE villes SET actif = 1 …`.

**La migration est additive** : `structures_sanitaires.ville_id` est **nullable**. Une structure sans ville reste visible partout ; elle est simplement absente du filtre `?ville=` (limite N5).

## 3.6 Commandes de qualité (G3)

```bash
XDEBUG_MODE=off %PHP% artisan test --filter=VilleGeolocalisationTest   # 20 tests
XDEBUG_MODE=off %PHP% artisan test                                      # suite complète
pnpm typecheck
cd apps/mobile && npx expo-doctor
```
✅ **Référence au G5 (2026-08-13)** : 20/20 pour le module · **500 tests / 14 649 assertions, 0 échec** · typecheck ×3 verts · **expo-doctor 18/18**.

## 3.7 Checklist de clôture

- [ ] Explication **avant** l'invite du système, une seule fois (§3.3.1)
- [ ] « Autoriser » → bandeau ville, tap → **« Vous êtes à Abidjan »** (§3.3.2)
- [ ] **Abidjan affiche les communes · Yamoussoukro n'en affiche AUCUNE** (§3.3.3)
- [ ] « Choisir moi-même » → **pas d'invite système**, sélecteur, phrase **« Ville choisie »**, mémorisée (§3.3.4)
- [ ] Mode avion → **« Dernière position connue »**, jamais « Vous êtes à » (§3.3.5)
- [ ] Man → « hors des zones couvertes » + ville la plus proche + toutes les structures (§3.3.6)
- [ ] **13 catégories** dans les filtres, aucune fiche « undefined » (§3.3.7)
- [ ] `/villes` public, 3 villes, `affiche_communes` conforme, 13 types (§3.4.1)
- [ ] Yamoussoukro et Bouaké **distinguées** malgré 95 km (§3.4.2)
- [ ] Hors zone trié par distance croissante (§3.4.3)
- [ ] Position absurde → hors zone ; lat 91 et paramètres absents → **422** (§3.4.4)
- [ ] Filtre `?ville=` ; **contrat `?commune=` de P3 intact** (§3.4.5)
- [ ] `?type=centre_dialyse` → **200** (défaut corrigé), `?type=inexistant` → 422 (§3.4.6)
- [ ] Ajout de Korhogo par une **ligne de données** ; désactivation respectée (§3.5)
- [ ] Suite complète + typecheck ×3 + expo-doctor (§3.6)
- [ ] **Limites N1→N6 relues et acceptées** (§3.1)

## 3.8 Pièges rencontrés

**`/api/v1/structures` ne renvoie pas un tableau nu** mais `{ "structures": [...] }`. Deux tests ont échoué là-dessus — et cet échec a révélé **deux vrais défauts** : les catégories de P6.4a n'étaient pas filtrables (422) et le paramètre `ville` était **effacé par `validate()`** faute d'être déclaré dans les règles. Un test qui échoue pour une mauvaise raison peut en découvrir de bonnes.

**Ne pas déclencher `initialiser()` depuis deux endroits.** L'appeler à la fois dans `(app)/_layout.tsx` et dans `carte.tsx` faisait surgir l'invite du système **par-dessus** l'explication — exactement le refus réflexe qu'on cherche à éviter. L'initialisation appartient au layout, et à lui seul.

**`localiserVille` ne passe PAS par le cache hors ligne**, contrairement à `chargerVilles`. Servir une localisation en cache reviendrait à dire « vous êtes à Abidjan » à quelqu'un qui vient d'arriver à Bouaké. Hors ligne, c'est la **mémoire** de la dernière ville connue qui prend le relais — et elle s'annonce comme telle.

**`TypeStructure` est passé de l'union fermée à `string`.** L'union listait 7 valeurs quand la base en accepte 13 : elle ne protégeait de rien, la donnée arrivant à l'exécution, mais elle **donnait l'illusion d'une garantie** et poussait à recopier la liste. Un type qui ment est pire qu'un type large.

**La palette et l'échelle d'espacement n'ont pas les clés qu'on croit** : `colors.ink` va par 900/700/500 (pas de `600`), et `spacing` est numérique (`spacing[1]`…), pas `xs`/`sm`. Le typecheck les attrape ; les inventer fait perdre un cycle.

**Changer de ville doit purger le filtre de commune.** Un chip « Cocody » resté actif après un passage à Bouaké donnerait une liste **vide sans explication**.


---

# PARTIE 4 — Images des établissements (P6.4c)

**Module** : P6.4c — logo et photos, catégories en données, diffusion publique, entrée dans le référentiel gouverné.
**Corpus** : CDC_09 §4.2 · CDC_11 §3.1 · **Décision** : [ADR-028](docs/adr/ADR-028-images-etablissements.md) · **Plan G1** : [docs/PLAN_G1_P6_4c_Images_Etablissements.md](docs/PLAN_G1_P6_4c_Images_Etablissements.md).

## 4.1 Périmètre — et ce que ce module ne fait PAS

### Ce qu'il fait

Un établissement publie un **logo** et des **photos** dans les cinq catégories que CDC_11 §3.1 nomme. Les images sont servies **publiquement**, affichées sur la carte de résultat et la fiche mobile, et **elles entrent dans le référentiel gouverné** — sous la forme d'une empreinte, pas d'un fichier.

### Ce qu'il ne fait pas — à lire avant de tester

| # | Limite | Pourquoi |
|---|---|---|
| **O1** | **Aucun écran d'envoi.** L'API seule. | Le formulaire relève du **portail Next** (ADR-011), comme l'onboarding « Méthode 2 » que P6.4a a reporté pour la même raison (limite M1). Le construire en Blade puis le refaire serait le gaspillage qu'on évite. |
| **O2** | **Pas d'antivirus** sur ces images. | Symétrie explicite avec `PhotoMembreService` : image **publique**, déposée par un gestionnaire **identifié et habilité**, jamais exécutée, servie avec son type réel. Le crible reste « vraie image » + MIME réel + liste blanche. |
| **O3** | **Ni redimensionnement ni vignette.** | Ce serait du traitement d'image, donc une dépendance ou une logique neuve. La taille est bornée **à l'entrée** (4 Mo par défaut). |
| **O4** | **Pas d'images hors ligne.** | Le cache P2 stocke du JSON chiffré, pas du binaire. La fiche retombe sur l'icône — **ce n'est pas une panne**. |
| **O5** | Le quota « au plus N par catégorie » est tenu **par le service sous verrou**, pas par le moteur. | Sous MySQL, un déclencheur ne peut pas interroger la table qu'il garde (erreur 1442). Seule l'unicité *même image dans la même catégorie* est déclarative. |

## 4.2 Prérequis

Ceux des parties 2 et 3, plus :

```bash
cd C:\wamp64\www\IVOIRESANTE\services\api
set PHP=C:\wamp64\bin\php\php8.3.28\php.exe

XDEBUG_MODE=off %PHP% artisan migrate --force
XDEBUG_MODE=off %PHP% artisan db:seed --class=CategoriesImageEtablissementSeeder --force
XDEBUG_MODE=off %PHP% artisan serve --host=0.0.0.0 --port=8000
```

Un compte habilité (`etablissement.manage`) et un compte quelconque :

```bash
XDEBUG_MODE=off %PHP% artisan tinker
>>> $a = App\Models\User::firstOrCreate(['telephone'=>'+2250799000009'], ['nom'=>'G4','prenom'=>'Images','email'=>'g4img@masante.ci','password'=>bcrypt('Test@2026!'),'date_naissance'=>'1990-01-01']);
>>> $a->givePermissionTo('etablissement.manage');
>>> echo $a->createToken('g4')->plainTextToken;   // → $ADMIN
```

Quatre fichiers de test, dont deux pièges :

```bash
%PHP% -r "$d=getcwd().'/g4img/'; @mkdir($d);
$mk=function($w,$h,$r,$g,$b,$f){$im=imagecreatetruecolor($w,$h);imagefill($im,0,0,imagecolorallocate($im,$r,$g,$b));imagepng($im,$f);};
$mk(80,80,0,90,200,$d.'logo.png'); $mk(200,140,220,90,0,$d.'accueil.png');
file_put_contents($d.'menteur.png','ceci est du texte, pas une image');
$p=file_get_contents($d.'logo.png');
file_put_contents($d.'zeropixel.png', substr($p,0,16).pack('N',0).pack('N',0).substr($p,24));"
```

## 4.3 Scénarios front (Expo Go)

### 4.3.1 — Sans image, rien ne casse

Ouvrir l'onglet **Carte**, puis une fiche, **avant** tout dépôt.

✅ **Attendu** : la tuile porte l'icône bleue habituelle, la fiche porte une icône d'immeuble, et **aucun bloc « Photos »** n'apparaît. Un titre au-dessus d'une bande vide annoncerait un contenu absent.

### 4.3.2 — Le logo remplace l'icône

Déposer un logo (§4.4.1), puis tirer pour rafraîchir la liste.

✅ **Attendu** : la tuile de cet établissement affiche **le logo** à la place de l'icône générique ; les autres gardent la leur. Sur la fiche, le logo est en tête, à gauche de « Hôpital · Cocody ».

### 4.3.3 — La galerie n'apparaît qu'avec des photos, et sans le logo

Déposer une photo d'accueil et une de salle d'attente, rouvrir la fiche.

✅ **Attendu** : un bloc **« Photos »** défilant horizontalement, avec **deux** images. **Le logo n'y figure pas** — il est déjà en tête de fiche, et l'y répéter le ferait passer pour une photo des lieux.

### 4.3.4 — Mode avion : l'icône reprend la main

Charger la fiche, puis passer en **mode avion** et rouvrir l'application.

✅ **Attendu** : la fiche reste lisible depuis le cache ; **l'icône de repli remplace le logo**, et les vignettes de la galerie montrent l'icône d'image. Ni rectangle vide, ni croix, ni écran d'erreur.

⚠️ Le cache hors ligne de P2 stocke du **JSON chiffré**, pas du binaire — les images ne sont pas mises en cache (limite **O4**). Le repli est prévu, pas subi.

## 4.4 Scénarios backend (curl reproductibles)

```bash
set BASE=http://127.0.0.1:8000/api/v1
```

### 4.4.1 — Dépôt d'un logo par un compte habilité

```bash
curl -s -X POST %BASE%/structures/1/images -H "Authorization: Bearer %ADMIN%" ^
  -F "image=@g4img/logo.png" -F "categorie=logo" | jq
```
✅ **Attendu** : `201`, avec `categorie_code`, `mime: image/png`, `largeur`/`hauteur` **lues sur les octets**, une `empreinte` SHA-256, et `url: "/api/v1/structures/1/images/1"`.

**Absents de la réponse** : `chemin` (détail de stockage) et `depose_par` — la diffusion des fiches est publique, savoir quel compte a mis en ligne la photo d'un bloc opératoire ne regarde personne au-dehors. L'information reste en base.

### 4.4.2 — Les cinq gardes, une par une

```bash
:: second logo → 409 (le maximum est une DONNÉE, pas un `if`)
curl -s -o nul -w "%%{http_code}\n" -X POST %BASE%/structures/1/images -H "Authorization: Bearer %ADMIN%" -F "image=@g4img/accueil.png" -F "categorie=logo"
:: catégorie inconnue → 404
curl -s -o nul -w "%%{http_code}\n" -X POST %BASE%/structures/1/images -H "Authorization: Bearer %ADMIN%" -F "image=@g4img/accueil.png" -F "categorie=piscine"
:: fichier texte nommé .png → 422
curl -s -X POST %BASE%/structures/1/images -H "Authorization: Bearer %ADMIN%" -F "image=@g4img/menteur.png" -F "categorie=accueil"
:: PNG de zéro pixel → 422
curl -s -X POST %BASE%/structures/1/images -H "Authorization: Bearer %ADMIN%" -F "image=@g4img/zeropixel.png" -F "categorie=accueil"
:: compte non habilité → 403 ; anonyme → 401
curl -s -o nul -w "%%{http_code}\n" -X POST %BASE%/structures/1/images -H "Authorization: Bearer %QUIDAM%" -F "image=@g4img/accueil.png" -F "categorie=accueil"
curl -s -o nul -w "%%{http_code}\n" -X POST %BASE%/structures/1/images -F "image=@g4img/accueil.png" -F "categorie=accueil"
```
✅ **Attendu** : `409` · `404` · `422 « Format « text/plain » refusé »` · `422 « … dimensions illisibles ou nulles »` · `403` · `401`.

⚠️ **Le vecteur du PNG de zéro pixel a trouvé un vrai trou.** Ses huit premiers octets sont la signature PNG et son en-tête IHDR est valide : `finfo` répond « image/png », donc **le premier crible le laisse passer**. Et `getimagesizefromstring` ne répond pas `false` mais `[0, 0]` — le contrôle initial, qui ne testait que `false`, laissait entrer une image de zéro pixel dans le stockage public. C'est la moitié du contrôle qu'aucun autre vecteur n'atteint.

### 4.4.3 — La diffusion est publique, et cachable

```bash
curl -s -D - -o recu.png %BASE%/structures/1/images/1
```
✅ **Attendu, SANS aucun jeton** : `200`, `Content-Type: image/png`, `Cache-Control: public, max-age=86400`, un `ETag` égal à l'empreinte, et un fichier **identique octet pour octet** à celui envoyé.

```bash
curl -s -o nul -w "%%{http_code}\n" -H "If-None-Match: \"<empreinte>\"" %BASE%/structures/1/images/1
curl -s -o nul -w "%%{http_code}\n" %BASE%/structures/2/images/1
```
✅ **Attendu** : `304` puis `404` — une image réclamée sous un **autre** établissement n'existe pas, sinon deux chemins désigneraient la même ressource et les caches divergeraient.

### 4.4.4 — L'URL est relative, et c'est délibéré

```bash
curl -s %BASE%/structures/1 | jq -r '.structure.images[].url'
```
✅ **Attendu** : `/api/v1/structures/1/images/1` — **pas** `https://…ngrok-free.dev/…`.

⚠️ Une URL absolue serait bâtie sur `APP_URL`, qui vaut ici l'URL Ngrok : mise en cache par le mobile, elle deviendrait **fausse au prochain redémarrage du tunnel**. Le mobile la préfixe lui-même, en un seul endroit (`ImageEtablissementView`).

### 4.4.5 — La fiche porte tout, la liste seulement le logo

```bash
curl -s %BASE%/structures/1  | jq '[.structure.images[].categorie_code]'
curl -s %BASE%/structures    | jq '[.structures[] | select(.id==1) | .images[].categorie_code]'
```
✅ **Attendu** : `["logo","accueil"]` puis `["logo"]`. Charger les photos de douze structures pour une tuile qui n'affiche que le logo serait payer un transfert pour rien.

### 4.4.6 — LE VECTEUR CENTRAL : ce que le référentiel voit d'une image

Relever d'abord l'empreinte du référentiel **avant tout dépôt** :

```bash
XDEBUG_MODE=off %PHP% artisan tinker
>>> App\Services\Referentiel\EmpreinteReferentiel::duContenu((new App\Services\Referentiel\SourceEtablissements)->extraire());
```

Déposer un logo, puis reprendre l'empreinte.
✅ **Attendu** : **elle a changé**. Le propriétaire a placé les images dans le référentiel gouverné (décision I3) : déposer une image **doit** faire diverger, et le référentiel restera « DIVERGENTE de la table » jusqu'à la publication d'une nouvelle version. **C'est le comportement voulu, pas un défaut.**

Puis **supprimer** l'image et **redéposer exactement le même fichier** :

```bash
curl -s -X DELETE %BASE%/structures/1/images/1 -H "Authorization: Bearer %ADMIN%"
curl -s -X POST   %BASE%/structures/1/images   -H "Authorization: Bearer %ADMIN%" -F "image=@g4img/logo.png" -F "categorie=logo"
```
✅ **Attendu** : l'identifiant de la ligne a changé, **le fichier sur disque porte un nouvel UUID**, et **l'empreinte du référentiel est identique à celle d'avant la suppression**.

⚠️ **Les deux moitiés comptent.** C'est ce couple qui prouve que l'instantané porte **l'empreinte du contenu** et non le chemin de stockage : avec le chemin, redéposer la même image aurait fait diverger le référentiel alors que rien n'a changé.

```bash
curl -s %BASE%/referentiels/etablissements | jq '.contenu[0].images'
```
✅ **Attendu** : `[{"categorie": "...", "empreinte": "..."}]` — **ni chemin, ni octets, ni URL**.

## 4.5 Invariants base de données

```sql
-- (a) Les cinq catégories du CDC_11, et le maximum en DONNÉE.
SELECT code, libelle, max_par_etablissement, ordre, actif
  FROM categories_image_etablissement ORDER BY ordre;
-- ✅ logo(1) · accueil(5) · salle_attente(5) · bloc_operatoire(5) · parking(3)

-- (b) « Un établissement n'a qu'un logo » se change sans toucher au code.
UPDATE categories_image_etablissement SET max_par_etablissement = 2 WHERE code = 'logo';
-- ✅ un second logo est désormais accepté ; remettre 1 ensuite.

-- (c) Le nom du client n'atteint jamais le disque.
SELECT chemin FROM etablissement_images;
-- ✅ `<structure>/<uuid>.png` — l'extension vient du MIME réel, jamais du fichier envoyé.

-- (d) La même image ne peut pas entrer deux fois dans la même catégorie (garanti par le moteur).
SHOW INDEX FROM etablissement_images WHERE Key_name = 'uq_image_unique_par_categorie';

-- (e) Rien n'a bougé ailleurs.
SELECT COUNT(*) FROM structures_sanitaires;  -- ✅ 12
```

**Migration strictement additive** : `structures_sanitaires` n'est pas touchée. Une image est une **ligne**, pas une colonne — le corpus en attend plusieurs, et une colonne `photos_json` aurait rendu impossible la suppression d'une seule photo sans réécrire les autres.

## 4.6 Commandes de qualité (G3)

```bash
XDEBUG_MODE=off %PHP% artisan test --filter=ImagesEtablissementTest   # 30 tests
XDEBUG_MODE=off %PHP% artisan test                                     # suite complète
pnpm typecheck
cd apps/mobile && npx expo-doctor
```
✅ **Référence au G5 (2026-08-13)** : 30/30 pour le module · **530 tests / 14 692 assertions, 0 échec** · typecheck ×3 verts · **expo-doctor 18/18**.

## 4.7 Checklist de clôture

- [ ] Sans image : icône générique, **aucun bloc « Photos »** (§4.3.1)
- [ ] Logo déposé → il remplace l'icône en liste **et** en tête de fiche (§4.3.2)
- [ ] Galerie présente **sans le logo** (§4.3.3)
- [ ] Mode avion → repli sur l'icône, jamais une croix (§4.3.4)
- [ ] Dépôt 201 ; `chemin` et `depose_par` **absents** de la réponse (§4.4.1)
- [ ] Second logo → 409 · catégorie inconnue → 404 (§4.4.2)
- [ ] Fichier texte → 422 · **PNG de zéro pixel → 422** (§4.4.2)
- [ ] Non habilité → 403 · anonyme → 401 (§4.4.2)
- [ ] Diffusion **publique sans jeton**, octets identiques, `ETag` → 304, autre établissement → 404 (§4.4.3)
- [ ] URL **relative**, jamais l'URL Ngrok (§4.4.4)
- [ ] Fiche = toutes les images · liste = logo seul (§4.4.5)
- [ ] **Dépôt → le référentiel DIVERGE** (§4.4.6)
- [ ] **Suppression puis redépôt du même fichier → empreinte du référentiel INCHANGÉE**, malgré un UUID neuf (§4.4.6)
- [ ] Le référentiel publie `{categorie, empreinte}`, ni chemin ni octets (§4.4.6)
- [ ] `max_par_etablissement` modifiable en base sans toucher au code (§4.5b)
- [ ] Suite complète + typecheck ×3 + expo-doctor (§4.6)
- [ ] **Limites O1→O5 relues et acceptées** (§4.1)

## 4.8 Pièges rencontrés

**`getimagesize` ne renvoie pas `false` sur une image de zéro pixel** mais `[0, 0]`. La garde initiale ne testait que `false` : un PNG dont l'en-tête IHDR porte des dimensions nulles passait `finfo` (« image/png »), passait le second crible, et entrait dans le stockage public pour s'afficher en cadre vide. **C'est le vecteur de test qui l'a trouvé, pas la relecture.**

**`abort()` ne met pas son statut dans `getCode()`.** Une `HttpException` porte `getCode() === 0` et le statut dans `getStatusCode()` : dix tests écrits avec `expectExceptionCode(403)` passaient **sans rien vérifier**, et pire, un 500 s'y serait fait passer pour un 403. Assertion refaite par un helper qui compare `getStatusCode()`.

**Un commentaire qui promet plus que le code.** Le modèle annonçait « le chemin ne sort jamais ; l'empreinte non plus » alors que seul `chemin` était caché. Le G2 l'a montré en affichant la réponse réelle — et a révélé au passage que `depose_par` sortait sur un endpoint **public**.

**Un déclencheur MySQL ne peut pas interroger la table qu'il garde** (erreur 1442) : le quota « au plus N par catégorie » ne pouvait pas être déclaratif. Il est tenu par le service **sous verrou pessimiste**, et la limite est annoncée (O5) plutôt que déguisée en garantie du moteur.

**Le fichier temporaire d'un `UploadedFile` disparaît avec l'objet.** Écrire `file_get_contents($this->image()->getRealPath())` échoue : l'objet est détruit avant la lecture. Générer les octets d'abord, construire le fichier ensuite.

**Ne pas mettre le logo dans la galerie.** Techniquement c'est une image de plus ; à l'écran, c'est une photo des lieux qui n'en est pas une.

