# GUIDE DE TEST — Référentiels nationaux (P6.3 « Socle référentiel »)

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
