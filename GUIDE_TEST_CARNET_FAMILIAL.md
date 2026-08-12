# Guide de test — Carnet familial partagé

> Module issu du plan G1 du 2026-08-11 ([docs/PLAN_G1_Carnet_Familial_Partage.md](docs/PLAN_G1_Carnet_Familial_Partage.md)).
> Remplace la fusion de dossiers du MPI : au lieu de réparer un doublon, on l'empêche de naître.

Ce guide grandit d'une partie par sous-incrément :

| Partie | Sous-incrément | État |
|--------|----------------|------|
| **A** | Partage du carnet en lecture | ✅ **VALIDÉ G5 le 2026-08-11** — procédure de non-régression |
| **B** | Revendication du carnet | ✅ **VALIDÉ G5 le 2026-08-11** — procédure de non-régression |
| **C** | Contributions au brouillon + responsables | ✅ **VALIDÉ G5 le 2026-08-11** — procédure de non-régression |
| **D1** | Notifications en application | ✅ **VALIDÉ G5 le 2026-08-12** — procédure de non-régression |
| D2 | Fiche de parcours | à venir |

---

# Partie A — Partage du carnet en lecture

## Ce que fait A — et ce qu'il ne fait pas

**Il fait** : un proche à qui l'on confie un carnet peut le **consulter** — dossier, antécédents,
vaccinations, ordonnances, résultats, documents, mesures, grossesse, photo, carte CMU, fiche
vitale, NIS, médecin référent. Chaque lecture laisse une trace nominative.

**Il ne fait pas** :
- **écrire** — le délégué ne peut rien modifier. L'écriture arrive en **C**, avec son circuit de
  brouillon et de validation ;
- **revendiquer** un carnet comme étant le sien — c'est **B**, et c'est ce qui empêchera le
  doublon ;
- **notifier** — aucun message n'est envoyé au délégué : il découvre l'invitation en ouvrant
  « Partages reçus ». Les notifications arrivent en **D**.

## Le point de vigilance de cet incrément

A modifie `MembreFamillePolicy::view` — **la barrière anti-IDOR de P2**, qui gouverne toutes les
lectures du carnet. C'est la modification la plus sensible du projet à ce jour. Les scénarios
« ce qui doit rester fermé » (§A.3) comptent autant que ceux qui vérifient l'ouverture ; un écart
sur l'un d'eux est bloquant, pas cosmétique.

---

## A.0 Prérequis

### Backend

```bash
PHP="C:/wamp64/bin/php/php8.3.28/php.exe"
cd services/api

XDEBUG_MODE=off "$PHP" artisan migrate     # migration additive : le défaut reste qr_generation
XDEBUG_MODE=off "$PHP" artisan serve --host=0.0.0.0 --port=8000
```

Contrôle du schéma :

```sql
SHOW COLUMNS FROM delegations LIKE 'droits';
-- attendu : enum('qr_generation','lecture','lecture_ecriture'), défaut 'qr_generation'
```

> ⚠️ **Le défaut compte.** Il reste `qr_generation` pour que les délégations existantes conservent
> exactement le droit qu'elles avaient. Personne ne gagne un accès au dossier du fait de la
> migration — seules les invitations créées ensuite portent `lecture`.

### Deux comptes réels

Ce test **ne se fait pas avec un seul compte**. Il faut :

| Rôle | Compte | Doit avoir |
|------|--------|-----------|
| **Responsable** | compte A | au moins deux carnets (son dossier titulaire + un enfant) |
| **Proche** | compte B | un compte vérifié, téléphone connu |

Sur un seul téléphone, on peut enchaîner : se connecter en A, partager, se déconnecter, se
connecter en B. Avec deux appareils, c'est plus proche du réel — c'est la façon recommandée.

---

## A.1 Partager ses carnets (compte A)

1. Onglet **« Carnet »** → descendre sous « Ajouter un membre » → bouton **« Partager mes carnets »**.
2. Écran **« Partager mes carnets »**, sous-titre **« Confier l'accès à un proche »**.
   - Texte : le proche pourra **consulter** les carnets choisis, *y compris ce qu'un médecin y
     ajoute*, et **ne pourra rien modifier**.
   - Mention : il devra accepter, et **l'accès est retirable à tout moment, sans justification**.
   - Champ **« Numéro du proche »**, pré-rempli **`+225`**.
   - Section **« Carnets à partager »** : tous les carnets, **tous cochés par défaut**, avec
     **« Tout décocher »** en regard.
3. Saisir le numéro du compte B → **« Envoyer l'invitation »**.

✅ **Attendu** : message vert **« N invitation(s) envoyée(s). »**

❌ **Numéro mal formé** : **« Format attendu : +225 suivi de 10 chiffres. »**, aucun appel réseau.
❌ **Numéro inconnu** : **« Aucun compte MaSanté associé à ce numéro. »**
❌ **Son propre numéro** : refusé.

### A.1b Rejouable
4. Renvoyer **exactement la même invitation**.

✅ **Attendu** : **« Ces carnets étaient déjà partagés avec ce proche. »** — pas une erreur. Un
partage en masse doit pouvoir être rejoué sans que l'utilisateur ait à savoir ce qui existe déjà.

---

## A.2 Accepter et consulter (compte B)

1. Se connecter en B → onglet **« Carnet »** → **« Partages reçus »**.
2. Écran **« Partages reçus »**, sous-titre **« Carnets confiés par vos proches »**.
   - Une carte par carnet : nom, **« Partagé par [prénom nom] »**.
   - Sous le nom : **« En acceptant, vous pourrez consulter ce carnet — sans pouvoir le modifier. »**
   - Boutons **« Accepter le partage »** et **« Refuser »**.

> Le consentement est **éclairé** : la portée est écrite **avant** qu'on demande d'accepter.

3. **« Accepter le partage »**.

✅ **Attendu** : la carte propose maintenant **« Ouvrir le carnet »**, **« Générer le QR »** et
**« Retirer ce partage »**.

4. **« Ouvrir le carnet »** → le dossier du membre s'ouvre.
5. Parcourir les sections : antécédents, vaccinations, ordonnances, résultats, documents, mesures.

✅ **Attendu** : tout est **lisible**.

6. Revenir à l'onglet **« Carnet »**.

✅ **Attendu** : une section **« Carnets partagés avec moi »** apparaît **sous** « Membres de la
famille », avec le compteur du nombre de carnets reçus. Chaque carte porte **« Partagé par
[prénom nom] »** et une pastille d'initiales d'une teinte différente.

❗ **Vérifier** : le compteur **« Membres de la famille » `x/15` n'a PAS bougé.** Un carnet reçu ne
consomme pas le quota du compte B — il ne lui appartient pas.

---

## A.3 Ce qui doit rester fermé (le cœur du test)

Depuis le compte B, sur un carnet partagé :

| Tentative | Attendu |
|-----------|---------|
| Modifier le membre (nom, date, groupe sanguin) | **Refusé** — aucune option de modification, ou erreur d'autorisation |
| Supprimer le membre | **Refusé** |
| Ajouter un antécédent, une ordonnance, une mesure | **Refusé** |
| Consulter **« Historique des accès »** du membre | **Refusé** — qui a consulté le dossier regarde le patient, pas ses proches |

Depuis un **troisième compte**, sans aucune délégation :

| Tentative | Attendu |
|-----------|---------|
| Ouvrir le carnet par son identifiant | **403** |

---

## A.4 Retrait immédiat

1. Compte B → **« Partages reçus »** → **« Retirer ce partage »** → confirmer.
   *(ou compte A → membre → écran des délégués → révoquer)*
2. Compte B → onglet **« Carnet »**.

✅ **Attendu** : la section **« Carnets partagés avec moi »** ne contient plus ce carnet, et
rouvrir le dossier échoue. **Effet immédiat, sans délai ni cache.**

> **Pourquoi ce scénario est le plus important du lot** : le partage familial est un choix légitime,
> mais il expose grossesses, consultations et résultats à des proches. Ce qui rend ce choix
> acceptable, c'est qu'il se défait aussi vite qu'il s'est fait — sans avoir à se justifier.

---

## A.5 Les délégations d'avant l'incrément A n'ouvrent rien

Si des délégations existaient déjà (droit `qr_generation`) :

```sql
SELECT id, membre_id, delegue_user_id, droits FROM delegations WHERE droits = 'qr_generation';
```

Depuis le compte délégué, tenter d'ouvrir l'un de ces carnets.

✅ **Attendu** : **403**. Le carnet n'apparaît pas non plus dans « Carnets partagés avec moi ».
Le bouton **« Générer le QR »** continue de fonctionner — le droit accordé à l'époque, ni plus,
ni moins.

Pour en fabriquer une à la main :

```sql
UPDATE delegations SET droits = 'qr_generation' WHERE id = <ID>;
```

---

## A.6 Le journal (backend)

Après quelques lectures déléguées :

```sql
SELECT id, membre_id, agent_id, type_acces, sections_consultees, ip_address, created_at
FROM acces_dossier
WHERE type_acces = 'delegation'
ORDER BY created_at DESC LIMIT 20;
```

✅ **Attendu** : une ligne **par lecture**, avec `agent_id` = le compte du délégué et
`sections_consultees` nommant la section (`dossier`, `antecedents`, `ordonnances`…).

✅ **Attendu aussi** :
- la lecture par le **propriétaire** n'écrit **rien** (ce n'est pas un accès de tiers) ;
- une lecture **refusée** n'écrit **rien** (un accès refusé n'est pas un accès) ;
- le journal est **immuable** — un `UPDATE` ou un `DELETE` par l'application est rejeté.

> Le journal est posé en **middleware** sur tout le groupe authentifié, pas contrôleur par
> contrôleur : une route `{membre}` ajoutée demain est tracée sans que personne ait à y penser.

---

## A.7 Backend en direct (curl)

```bash
API="http://localhost:8000/api/v1"
# Jetons des deux comptes (guillemeter : un jeton Sanctum contient un « | »)
A=$(curl -s -X POST "$API/auth/login" -H 'Content-Type: application/json' \
     -d '{"telephone":"+225XXXXXXXXXX","password":"..."}' | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
B=$(curl -s -X POST "$API/auth/login" -H 'Content-Type: application/json' \
     -d '{"telephone":"+225YYYYYYYYYY","password":"..."}' | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
```

| # | Commande | Attendu |
|---|----------|---------|
| 1 | `curl -s -X POST "$API/delegations/en-masse" -H "Authorization: Bearer $A" -H 'Content-Type: application/json' -d '{"telephone":"+225YYYYYYYYYY"}'` | `201`, `invitations_creees` > 0 |
| 2 | `curl -s -H "Authorization: Bearer $B" "$API/membres/partages"` | `partages: []` — **non accepté = rien** |
| 3 | `curl -s -X POST "$API/delegations/<ID>/accepter" -H "Authorization: Bearer $B"` | `200` |
| 4 | `curl -s -H "Authorization: Bearer $B" "$API/membres/partages"` | le carnet, avec `partage_par` |
| 5 | `curl -s -H "Authorization: Bearer $B" "$API/membres/<ID>"` | `200` |
| 6 | `curl -s -o /dev/null -w '%{http_code}\n' -X PUT "$API/membres/<ID>" -H "Authorization: Bearer $B" -H 'Content-Type: application/json' -d '{"prenom":"Pirate"}'` | **403** |
| 7 | `curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $B" "$API/membres/<ID>/acces"` | **403** |
| 8 | `curl -s -H "Authorization: Bearer $B" "$API/membres"` | **liste vide** — `/membres` reste « mes carnets » |

> Le point 8 n'est pas cosmétique : `GET /membres` est le contrat de P2 sur lequel s'appuie le
> cache hors-ligne chiffré. Les carnets partagés vivent sur un **endpoint séparé** ; c'est l'écran
> qui compose les deux listes.

---

## A.8 Qualité (G3)

```bash
cd services/api
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan test
cd ../.. && pnpm typecheck
```

✅ Référence du 2026-08-11 : **330 tests / 14 180 assertions** verts, dont **20 tests dédiés** au
partage (`CarnetPartageTest`) écrits dans les deux sens — ce qui s'ouvre, et tout ce qui reste
fermé.

---

## A.9 Checklist de clôture

**Partage**
- [ ] A.1 Partage en masse depuis « Partager mes carnets », tous cochés par défaut
- [ ] A.1b Rejeu → « déjà partagés », pas une erreur
- [ ] A.2 Portée annoncée **avant** l'acceptation
- [ ] A.2 Après acceptation : carnet ouvrable, sections lisibles
- [ ] A.2 Section « Carnets partagés avec moi » au Carnet, avec l'origine
- [ ] A.2 Le compteur `x/15` **n'a pas bougé**

**Ce qui reste fermé**
- [ ] A.3 Modification, suppression, ajout de section : **refusés**
- [ ] A.3 Historique des accès : **refusé**
- [ ] A.3 Compte tiers sans délégation : **403**
- [ ] A.5 Délégation `qr_generation` : **403** sur le dossier, QR toujours fonctionnel

**Retrait**
- [ ] A.4 Retrait par le délégué → effet immédiat
- [ ] A.4 Révocation par le titulaire → effet immédiat

**Journal**
- [ ] A.6 Une ligne par lecture déléguée, nominative, avec la section
- [ ] A.6 Lecture du propriétaire : aucune ligne
- [ ] A.6 Lecture refusée : aucune ligne

**Backend et qualité**
- [ ] A.7 Les 8 vecteurs curl
- [ ] A.8 Suite complète verte + typecheck

> Tout coché sans écart → écrire **« Incrément A validé »** et ouvrir **B (revendication)**.

---

## A.10 Pièges

| Piège | Symptôme | Parade |
|-------|----------|--------|
| Route `/membres/partages` en **404** | Captée par `/membres/{membre}` | Déclarée **avant** l'`apiResource` — même piège qu'en P6.1 avec `titulaire` |
| Jeton Sanctum tronqué | 401 après une connexion réussie | Guillemeter `"$TOKEN"` : le `|` est un tube pour le shell |
| Route mobile inconnue de TypeScript | `TS2345` sur `/(app)/partager-carnets` | Types de routes générés par Expo : démarrer le serveur de dev une fois |
| Test avec un seul compte | « ça marche » alors que rien n'est prouvé | Le partage exige **deux comptes** ; un compte ne peut pas se déléguer à lui-même |

---

# Partie B — Revendication du carnet

## Ce que B répare

À la fin de A, le partage fonctionnait mais **le doublon n'était pas empêché** : la personne à qui
l'on partageait son propre carnet voyait quand même « Créez votre dossier de santé » et en créait
un second, avec un **second numéro national**.

B ajoute l'étape qui manquait : **reconnaître** le carnet avant d'en créer un.

## Sur quoi la reconnaissance s'appuie

Pas sur un score de ressemblance — c'était la question du propriétaire, et deux personnes peuvent
porter le même nom et la même date de naissance. Sur **deux actes humains indépendants** :

1. le responsable partage ce carnet à ce numéro **en affirmant** qu'il est celui de la personne
   invitée — il connaît sa famille ;
2. la personne s'authentifie sur ce numéro et le **reconnaît** comme sien.

Deux homonymes stricts ne revendiqueront jamais le même carnet : une seule des deux l'a reçu.

## L'ordre est impératif

La revendication passe **avant** l'écran de création. Après, il existe deux NIS pour une personne,
et **un NIS ne se libère jamais** (P6.1). L'ordre n'est pas une préférence d'ergonomie : c'est ce
qui empêche le doublon d'exister.

---

## B.1 Affirmer, au moment du partage (compte A)

1. **« Carnet »** → **« Partager mes carnets »**.
2. Sous chaque carnet coché apparaît un bouton radio :
   **« C'est le carnet de la personne que j'invite »**.
3. Le sélectionner sur **un seul** carnet.

✅ **Attendu** : un avertissement apparaît en bas —
**« Cette personne pourra reconnaître ce carnet comme le sien et en devenir propriétaire. Vous
garderez l'accès en lecture, qu'elle pourra retirer. »**

❗ **Vérifier** : le bouton radio **n'apparaît pas** sur ton propre dossier (marqué « (vous) ») —
il t'appartient, il ne peut pas être celui d'un autre. Décocher un carnet retire aussi sa
désignation.

4. Saisir le numéro du compte B → **« Envoyer l'invitation »**.

---

## B.2 Reconnaître son carnet (compte B, sans dossier de santé)

> Pré-requis : le compte B **ne doit pas avoir** de dossier titulaire. Pour le remettre dans cet
> état, voir `GUIDE_TEST_NIS.md` §0.2.

1. Compte B → **« Partages reçus »** → **« Accepter le partage »**.
2. Onglet **« Carnet »**.

✅ **Attendu** : **à la place** de « Créez votre dossier de santé », la carte affiche
**« Un carnet à votre nom existe déjà »** avec le texte
**« Un proche a créé un dossier de santé à votre nom. S'il est bien le vôtre, reconnaissez-le
plutôt que d'en créer un second. »** et le bouton **« Voir ce carnet »**.

3. **« Voir ce carnet »** → écran **« Est-ce votre carnet ? »**, sous-titre **« Un proche a créé un
   dossier à votre nom »**. La fiche montre nom, âge, sexe, groupe sanguin et **« Créé par
   [prénom nom] »**.
4. **« C'est mon carnet »** → une alerte **« Ce carnet est bien le vôtre ? »** annonce que le
   proche pourra continuer à le consulter et que **l'accès sera retirable à tout moment**.
5. Confirmer.

✅ **Attendu** : retour au Carnet. Le carnet apparaît sous **« Mon dossier de santé »**, avec son
historique et **son numéro national d'origine**.

❗ **Le point à vérifier absolument** : le compte B a **un seul** dossier, pas deux. Le NIS est
celui que le carnet portait déjà.

### B.2b « Aucun de ces carnets n'est le mien »
L'écran propose toujours **« Créer mon dossier de santé »** en bas. Ce chemin doit rester ouvert :
un proche peut se tromper de personne.

---

## B.3 Le renversement de propriété

Après la revendication, depuis le **compte A** (l'ancien propriétaire) :

| Tentative | Attendu |
|-----------|---------|
| Ouvrir le carnet | **200** — il garde la lecture, par délégation |
| Modifier le carnet | **403** — il n'en est plus propriétaire |
| Le voir dans **« Membres de la famille »** | **Absent** — il est passé dans « Carnets partagés avec moi » |

Depuis le **compte B** (nouveau propriétaire) : **« Partages reçus »** ou l'écran des délégués →
**retirer l'accès** au compte A.

✅ **Attendu** : le compte A reçoit **403** sur le carnet.

> **C'est le cœur de B.** Avant, le responsable restait propriétaire du dossier médical d'une autre
> personne adulte, qui n'en était que déléguée : elle ne pouvait ni le modifier, ni lui en retirer
> l'accès. Après, elle décide.

---

## B.4 Ce qui doit être refusé

| Situation | Attendu |
|-----------|---------|
| Partage **sans** l'assertion | Aucune proposition de reconnaissance ; revendication → **409** |
| Invitation **non acceptée** | Aucune proposition — le consentement manque |
| Compte tiers **sans délégation** | **409** |
| Le carnet est le **dossier titulaire** du responsable | Non proposé ; revendication → **409** |
| Le compte a **déjà** un dossier titulaire | Non proposé ; revendication → **409** |
| **Rejouer** la revendication | **409**, une seule trace de transfert |

---

## B.5 Base de données

```sql
-- Avant / après revendication
SELECT id, user_id, est_titulaire, titulaire_du_compte, nis FROM membres_famille;

-- LE contrôle : aucun second NIS n'a été créé
SELECT COUNT(*) AS journal, COUNT(DISTINCT nis) AS distincts FROM nis_journal;

-- La trace, en ajout seul
SELECT id, membre_id, ancien_user_id, nouveau_user_id, delegation_id, motif FROM carnet_transferts;

-- La délégation qui portait l'assertion est CONSOMMÉE ; la délégation inverse est née
SELECT id, titulaire_user_id, delegue_user_id, droits, est_le_dossier_du_delegue,
       acceptee_at IS NOT NULL AS acceptee, revoquee_at IS NOT NULL AS revoquee
FROM delegations;
```

✅ **Attendu** : `user_id` a changé, `est_titulaire = 1`, `titulaire_du_compte = user_id`, **`nis`
inchangé** ; `journal` et `distincts` **inchangés** ; une trace de transfert ; l'ancienne
délégation révoquée et une nouvelle, inverse, active.

**Deux invariants à éprouver :**

```sql
-- Le CHECK refuse un transfert en deux temps
UPDATE membres_famille SET user_id = <AUTRE> WHERE id = <ID_TITULAIRE>;
-- attendu : ERROR 3819 « Check constraint 'ck_membres_titulaire_coherent' is violated »
```

> C'est exactement pourquoi le service n'écrit **qu'une seule fois** : deux écritures successives
> passeraient par un état intermédiaire que la base refuse. Le garde-fou déclaratif de P6.1 attrape
> l'erreur avant qu'elle n'existe.

La trace de transfert est **immuable** : une tentative de modification par l'application est
rejetée (« Trace de transfert immuable »).

---

## B.6 Backend en direct (curl)

Mêmes jetons qu'en A.7.

| # | Commande | Attendu |
|---|----------|---------|
| 1 | partage **sans** assertion, puis `GET /membres/revendicables` (compte B) | `revendicables: []` |
| 2 | `POST /membres/<ID>/revendiquer` | **409** `REVENDICATION_IMPOSSIBLE` |
| 3 | partage **avec** `membre_id_du_delegue`, **avant** acceptation | `revendicables: []` |
| 4 | après acceptation | le carnet, avec `propose_par` |
| 5 | `POST /membres/<ID>/revendiquer` | `200`, `membre.est_titulaire = true`, **même `nis`** |
| 6 | `GET /membres/titulaire` (compte B) | `existe: true` |
| 7 | `GET /membres/<ID>` (compte A) | `200` |
| 8 | `PUT /membres/<ID>` (compte A) | **403** |
| 9 | rejeu de la revendication | **409**, une seule trace |

---

## B.7 Qualité (G3)

✅ Référence du 2026-08-11 : **343 tests / 14 218 assertions** verts, dont **13 dédiés**
(`RevendicationCarnetTest`). Typecheck vert sur les trois workspaces.

---

## B.8 Checklist de clôture

- [ ] B.1 Le bouton radio n'apparaît pas sur son propre dossier ; avertissement affiché
- [ ] B.2 La carte « Un carnet à votre nom existe déjà » **remplace** celle de création
- [ ] B.2 Alerte de confirmation explicite avant le transfert
- [ ] B.2 Après reconnaissance : **un seul** dossier, **NIS d'origine conservé**
- [ ] B.2b « Créer mon dossier de santé » reste accessible
- [ ] B.3 L'ancien propriétaire lit (200) mais ne modifie plus (403)
- [ ] B.3 Le nouveau propriétaire peut lui retirer l'accès
- [ ] B.4 Les six situations refusées
- [ ] B.5 Aucun second NIS ; trace présente ; délégation consommée + inverse née
- [ ] B.5 Le CHECK rejette le transfert en deux temps ; trace immuable
- [ ] B.6 Les 9 vecteurs curl
- [ ] B.7 Suite complète + typecheck verts

> Tout coché sans écart → écrire **« Incrément B validé »** et ouvrir **C (contributions au
> brouillon)**.

---

## B.9 Pièges

| Piège | Symptôme | Parade |
|-------|----------|--------|
| Transfert en deux écritures | `ERROR 3819` sur `ck_membres_titulaire_coherent` | `user_id` et `est_titulaire` posés dans **une seule** sauvegarde |
| Revendication proposée après création | Deux NIS pour une personne, irréversible | L'écran passe **avant** la complétion — le backend ne propose rien si un dossier titulaire existe |
| Route `/membres/revendicables` en **404** | Captée par `/membres/{membre}` | Déclarée **avant** l'`apiResource` |

---

# Partie C — Contributions au brouillon et responsables

## Le scénario, dans les mots du propriétaire

Les parents sont en voyage. Un enfant de trois ans est malade. La personne restée à la maison
l'emmène à l'hôpital — elle ne peut pas attendre le retour des parents. Elle note ce qui s'est
passé dans le carnet de l'enfant. **Son ajout part au brouillon** ; un responsable relit, vérifie
auprès d'elle, puis valide. Ce n'est qu'alors qu'il entre au dossier.

## La séparation qui ne doit jamais être effacée

| Qui écrit | Traitement |
|-----------|------------|
| **Le médecin**, à l'hôpital (QR, référent, bris de glace) | **Direct au dossier.** Jamais de brouillon. |
| **Un délégué de la famille**, depuis son application | **Brouillon**, validé par un responsable |

Une ordonnance de médecin ne peut pas attendre l'accord d'un parent absent des semaines. Le
brouillon encadre la **contribution familiale auto-déclarée**, jamais l'acte médical.

## La règle de sécurité clinique

**Un brouillon est visible, jamais caché** — y compris de qui a accès au dossier. Un fait médical
non validé reste un fait médical : si l'enfant est revu deux jours plus tard, ce qui a été noté
doit se voir, même sans l'accord du parent. **La validation est un acte de gouvernance familiale,
pas un critère de vérité clinique.**

## Ce que C ne fait pas

- **Aucune notification** n'est envoyée : le responsable découvre les ajouts en ouvrant
  « Ajouts à valider ». Les notifications et la fiche de parcours sont l'incrément **D**.
- Les contributions ne visent que **cinq sections** : antécédents, vaccinations, ordonnances,
  résultats d'analyses, rappels. Contacts d'urgence, notes, documents, mesures et grossesse
  gardent leur logique de création propre et restent réservés au propriétaire. Les ouvrir plus
  tard est purement additif.

---

## C.0 Prérequis

```bash
cd services/api
XDEBUG_MODE=off "C:/wamp64/bin/php/php8.3.28/php.exe" artisan migrate
```

```sql
SHOW TABLES LIKE 'contributions';
SHOW TABLES LIKE 'responsables_famille';
SHOW COLUMNS FROM delegations LIKE 'droits';
-- attendu : les délégations créées désormais portent 'lecture_ecriture'
```

> **Changement de comportement introduit par C** : une invitation de partage porte maintenant
> `lecture_ecriture` — le délégué peut **proposer**. Ce droit n'ouvre toujours **pas** l'écriture
> directe : les scénarios « ce qui reste fermé » de la partie A restent vrais.

Trois comptes : **A** le parent propriétaire, **B** le délégué (la personne restée à la maison),
**C** un second responsable (facultatif, pour C.4).

---

## C.1 Proposer un ajout (compte B, sur un carnet partagé)

1. Compte B → **« Carnet »** → section **« Carnets partagés avec moi »** → ouvrir le carnet de
   l'enfant.
2. Ouvrir une section (ex. **Antécédents**) → **« Ajouter »**.

✅ **Attendu** : sous le formulaire, la mention
**« Ce carnet vous a été confié. Votre ajout sera soumis à un responsable de la famille avant
d'entrer au dossier. »**, et le bouton porte **« Proposer cet ajout »** — pas « Ajouter ».

> La portée est annoncée **avant** la saisie, pas après coup.

3. Remplir et **« Proposer cet ajout »**.

✅ **Attendu** : retour à la liste. **L'ajout n'y figure pas encore** — il n'est pas au dossier.

❗ **Vérifier sur le carnet du compte A** : la section ne contient toujours rien.

## C.1b Le propriétaire, lui, écrit directement

Compte A, sur **son** carnet ou celui d'un membre qui lui appartient → **« Ajouter »**.

✅ **Attendu** : le bouton porte **« Ajouter »**, aucune mention de validation, et l'entrée
apparaît **immédiatement**. Lui faire valider ses propres brouillons n'aurait aucun sens.

---

## C.2 Valider (compte A)

1. Compte A → **« Carnet »** → bouton **« Ajouts à valider (1) »**.

✅ **Attendu** : le compteur vient du backend — il ne se déduit pas localement.

2. Écran **« Ajouts à valider »**, sous-titre **« Proposés par vos proches »**. La carte montre :
   le nom du membre, la section, **« Proposé par [prénom nom] »**, et le détail champ par champ.
3. **« Valider »** → alerte **« Valider cet ajout ? »** rappelant de **vérifier auprès de l'auteur
   que la consultation a bien eu lieu**.
4. Confirmer.

✅ **Attendu** : la contribution disparaît de la file, et l'entrée **apparaît dans la section du
carnet**, marquée comme auto-déclarée.

## C.2b Rejeter

Proposer un second ajout depuis B, puis depuis A → **« Rejeter »**.

✅ **Attendu** : **rien n'est écrit** au carnet. La contribution reste consultable — un rejet doit
rester explicable.

---

## C.3 Le brouillon n'est pas caché

Pendant qu'une contribution est **en attente** (avant toute décision) :

| Depuis | Attendu |
|--------|---------|
| Compte A (propriétaire) | Voit la contribution en attente |
| Compte B (auteur) | Voit sa propre contribution en attente |
| Compte tiers sans accès | **403** |

> C'est le point le plus important de la partie C, et le plus contre-intuitif. Cacher une
> contribution jusqu'à validation exposerait le patient suivant à un fait médical invisible.

---

## C.4 Le second responsable

1. Compte A → désigne le compte C comme responsable (par téléphone).
2. Compte B propose un ajout.
3. Compte C ouvre **« Ajouts à valider »**.

✅ **Attendu** : il voit la contribution et peut la valider.

4. Compte A retire la désignation, puis compte B propose à nouveau.

✅ **Attendu** : compte C ne voit plus rien et ne peut plus valider (**409**).

❗ **Vérifier aussi** : le compte C peut **se retirer lui-même**. Renoncer doit être aussi simple
qu'accepter.

---

## C.5 Ce qui doit être refusé

| Tentative | Attendu |
|-----------|---------|
| L'**auteur** valide sa propre contribution | **409** — c'est tout l'objet du circuit |
| Un tiers valide | **409** |
| Valider **deux fois** | **409**, une seule entrée créée |
| Rejeter une contribution déjà validée | **409** |
| Un délégué en **lecture seule** propose | **403** |
| Une **section hors liste blanche** (ex. `user`) | **403** |
| Le **propriétaire** passe par le brouillon | **403** |

---

## C.6 La garantie structurelle

Depuis le compte B, proposer un ajout **en forçant** `source: medecin` (via curl) :

```bash
curl -s -X POST "$API/membres/<ID>/contributions" -H "Authorization: Bearer $B" \
  -H 'Content-Type: application/json' \
  -d '{"section":"antecedents","donnees":{"type":"allergie","description":"test","source":"medecin","added_by":"medecin"}}'
```

```sql
SELECT donnees FROM contributions ORDER BY id DESC LIMIT 1;
```

✅ **Attendu** : `"source":"patient"` et `"added_by":"patient"`. **Un délégué ne peut pas faire
passer son ajout pour un acte de soignant, quoi qu'il envoie.** Ce n'est pas une validation qu'on
peut oublier : le service réécrit ces deux champs.

---

## C.7 Base de données

```sql
SELECT id, membre_id, auteur_user_id, section, statut, decide_par_user_id, entree_id, motif_rejet
FROM contributions ORDER BY id;

SELECT id, titulaire_user_id, responsable_user_id, designe_le, revoque_le FROM responsables_famille;
```

✅ **Attendu** : une contribution `VALIDEE` porte `decide_par_user_id` **et** `entree_id` (le lien
vers la ligne réellement créée) ; une `REJETEE` porte son motif et **aucun** `entree_id`.

> Contrôle utile : le texte médical est **chiffré au repos**. Une requête SQL sur `antecedents`
> renverra du chiffré pour `description` — c'est normal, il faut relire par l'application.

---

## C.8 Qualité (G3)

✅ Référence du 2026-08-11 : **362 tests / 14 278 assertions** verts, dont **19 dédiés**
(`ContributionCarnetTest`). Typecheck vert sur les trois workspaces.

---

## C.9 Checklist de clôture

**Proposer**
- [ ] C.1 Mention de portée + bouton « Proposer cet ajout » sur un carnet partagé
- [ ] C.1 L'ajout n'entre pas au dossier avant validation
- [ ] C.1b Le propriétaire écrit directement, sans mention

**Décider**
- [ ] C.2 Compteur « Ajouts à valider (n) » au Carnet
- [ ] C.2 Alerte rappelant de vérifier auprès de l'auteur
- [ ] C.2 Validation → l'entrée apparaît dans la section
- [ ] C.2b Rejet → rien n'est écrit, la contribution reste consultable

**Visibilité**
- [ ] C.3 Le brouillon est visible du propriétaire ET de l'auteur
- [ ] C.3 Un tiers reçoit 403

**Responsables**
- [ ] C.4 Un second responsable désigné peut valider
- [ ] C.4 Désignation retirée → 409
- [ ] C.4 Le désigné peut se retirer lui-même

**Refus**
- [ ] C.5 Les sept situations refusées
- [ ] C.6 `source` et `added_by` forcés à `patient`

**Base et qualité**
- [ ] C.7 `entree_id` sur une validée, motif sur une rejetée
- [ ] C.8 Suite complète + typecheck verts

> Tout coché sans écart → écrire **« Incrément C validé »** et ouvrir **D (notifications et fiche
> de parcours)**.

---

## C.10 Pièges

| Piège | Symptôme | Parade |
|-------|----------|--------|
| Assertion SQL sur un champ médical | Le test échoue en comparant du clair à du chiffré | Relire par le modèle (`$membre->antecedents()->first()->description`) |
| Section libre côté client | Un nom de section deviendrait une porte vers n'importe quelle relation Eloquent | Liste blanche fermée `RegistreSectionsCarnet` |
| `contributions/{id}` capté | La file `GET /contributions` masquée | Route de la file déclarée **avant** les routes paramétrées |
| Délégation d'avant C | Le délégué ne peut pas proposer (droit `lecture`) | Renvoyer une invitation : elle portera `lecture_ecriture` |

---
---

# Partie D1 — Notifications en application

> ✅ **VALIDÉ G5 le 2026-08-12** — conservé comme **procédure de non-régression**.

## Ce que D1 répare

L'incrément C fonctionnait, mais **personne n'était prévenu** : un responsable devait penser à
ouvrir « Ajouts à valider » pour découvrir qu'un proche avait emmené un enfant à l'hôpital. D1
branche les notifications — et lève au passage **quatre stubs** `Log::info` qui attendaient depuis
les modules 2 à 5, dont celui du **scan QR à l'accueil d'un hôpital** (CDC §4.3 étape 6) et celui du
**bris de glace** (§5.3, notification décrite comme « IMMÉDIATE »).

## Les six événements

| Événement | Qui est prévenu | Qui ne l'est PAS |
|---|---|---|
| `CONTRIBUTION_DEPOSEE` | le propriétaire + ses responsables désignés | **l'auteur** (il sait ce qu'il vient de faire) |
| `CONTRIBUTION_VALIDEE` | l'auteur + les autres responsables | **celui qui vient de décider** |
| `CONTRIBUTION_REJETEE` | l'auteur (avec le motif) + les autres responsables | idem |
| `DELEGATION_RECUE` | le délégué invité | — |
| `RESPONSABLE_DESIGNE` | le désigné | — |
| `DOSSIER_CONSULTE` | le propriétaire **et tous les délégués en lecture** | **le soignant qui consulte**, et les délégations `qr_generation` |

## La règle inviolable de cet incrément

**Une notification ne porte AUCUN contenu médical.**

> ✅ « Aya Kouassi a proposé un ajout au carnet de Koffi Eli. »
> ❌ « Aya Kouassi a ajouté : fièvre 39, vue aux urgences. »

Un push s'affiche sur un **écran verrouillé**, visible de n'importe qui dans la pièce, et son corps
transite par les serveurs d'Expo. Le fait médical reste dans le dossier, derrière
l'authentification. Un test automatisé (`test_une_notification_ne_contient_aucun_contenu_medical`)
cherche la donnée clinique dans toute la charge utile et casse le build si elle s'y trouve.

**Ce que la notification révèle quand même, et qui est assumé** : elle nomme un proche et dit que
son dossier a été consulté. Sur un téléphone posé sur une table, c'est une divulgation. Elle est le
service demandé — « tous les autres le sauront sans même qu'on les appelle » — mais elle doit être
dite, pas découverte.

## Ce que D1 ne fait pas

- **Pas de push réel.** Le relais est écrit et prouvé côté serveur, mais **gaté OFF**
  (`masante.notifications.push.enabled = false`). Le push distant est **indisponible dans Expo Go
  sur Android depuis le SDK 53** ; l'activer exigerait un *development build* EAS. Voir D1.7.
- **Donc pas d'alerte téléphone en poche, application fermée.** La liste se met à jour à
  l'ouverture de l'écran.
- **Pas de fiche de parcours** — c'est **D2**.
- **Les contacts d'urgence d'un bris de glace ne sont pas joints** : ce sont des numéros de
  téléphone, pas des comptes MaSanté, et le projet n'a pas de passerelle SMS. Le journal `warning`
  les conserve pour qu'on puisse vérifier qui aurait dû être prévenu.
- Pas de préférences par type (tout ou rien), pas de purge, pas de pagination au-delà de 50.

---

## D1.0 Prérequis

Les mêmes qu'en C (deux comptes réels, un carnet partagé en `lecture_ecriture`), plus un **compte
tiers** qui ne doit jamais rien recevoir.

```powershell
cd c:\wamp64\www\IVOIRESANTE\services\api
$env:XDEBUG_MODE='off'
$PHP='C:\wamp64\bin\php\php8.3.28\php.exe'
& $PHP artisan migrate        # 2 migrations : notifications, appareils_push + notification_envois
```

---

## D1.1 La pastille (compte A, propriétaire)

1. Compte **B** propose un ajout sur un carnet partagé (procédure **C.1**).
2. Compte **A** : ouvrir l'**Accueil**.

**Attendu** — en haut à droite, à côté de « Bonjour … 👋 », une **cloche** portant une **pastille
rouge** avec le nombre de non-lues.

> La pastille se rafraîchit **au retour sur l'Accueil**, pas en temps réel : sans push, c'est la
> seule chose possible. Si elle n'apparaît pas, quitter l'onglet et y revenir.

3. Appuyer sur la cloche.

**Attendu** — écran **« Notifications »**, sous-titre « **1 non lue** », une carte à **liseré bleu**
portant un **point bleu**, titre « **Un ajout attend votre validation** », corps « *B a proposé un
ajout au carnet de …* », et une date relative (« À l'instant »).

**À vérifier immédiatement** : le corps **ne contient ni le type d'antécédent, ni sa description**.

4. Appuyer sur la carte → l'écran **« Ajouts à valider »** s'ouvre.
5. Revenir : la carte a perdu son liseré et son point ; la pastille de l'Accueil a disparu.

## D1.2 L'auteur n'est pas notifié de son propre dépôt

Sur le compte **B**, juste après avoir proposé : Accueil → **aucune pastille**.

C'est délibéré. Notifier quelqu'un de ce qu'il vient de faire est du bruit, et il ne peut de toute
façon pas valider sa propre contribution.

## D1.3 La décision, dans les deux sens

1. Compte **A** valide l'ajout.
2. Compte **B** : Accueil → pastille → « **Ajout validé** », corps « *A a validé l'ajout au carnet
   de … proposé par B* ».
3. Compte **A** : **pas** de nouvelle notification pour sa propre décision.

Puis le rejet :

4. **B** propose un second ajout ; **A** le rejette avec le motif
   `Vérification faite : pas de consultation ce jour`.
5. **B** : « **Ajout refusé** » — et **le motif figure dans le corps**. C'est ce qui évite un
   second appel téléphonique.

## D1.4 Le second responsable

Avec un troisième compte **C** désigné responsable par **A** (procédure C.4) :

- À la désignation, **C** reçoit « **Vous êtes responsable de famille** ».
- Quand **B** propose, **A** et **C** reçoivent tous les deux.
- Quand **A** valide, **C** reçoit « **Ajout validé** » — c'est le « *Tel responsable a validé
  l'ajout du carnet de X par Y* » demandé au G1.

## D1.5 Le partage : une notification, pas quinze

1. Compte **A** → Carnet → « Partager mes carnets » → tout partager avec **B**.
2. Compte **B** : **une seule** notification « **Un carnet vous a été partagé** », mentionnant le
   **nombre** de carnets.
3. Si l'un des carnets a été désigné comme celui de **B**, la phrase ajoute « *L'un d'eux serait le
   vôtre* » et l'appui ouvre **« Reconnaître mon carnet »** (incrément B) — pas « Partages reçus ».
4. **Rejouer** le partage en masse : `invitations_creees: 0` → **aucune notification nouvelle**.

## D1.6 Le scénario de l'accident (le plus important)

Il se teste depuis le **portail web** (compte agent), pas depuis le mobile.

1. Un membre de la famille a un carnet partagé en **lecture** avec **B**.
2. Compte agent → portail → **bris de glace** sur ce membre, motif « Patient inconscient ».
3. Compte **A** (propriétaire) **et** compte **B** (délégué en lecture) reçoivent :
   « **Dossier consulté** » — « *Accès d'urgence au dossier de … à [établissement]. Motif déclaré :
   Patient inconscient.* » L'icône est **rouge** (`urgent`).
4. Refaire avec un **scan QR** : même type, corps « *Le dossier de … a été consulté à …* », icône
   bleue.

**Ce qui doit rester fermé** :

- Une délégation `qr_generation` (d'avant l'incrément A) **ne reçoit rien** — elle ne lit pas le
  dossier, l'informer divulguerait un passage à l'hôpital à quelqu'un sans accès.
- Un soignant qui serait par ailleurs délégué du carnet **ne s'alerte pas lui-même**.

## D1.7 Le push (ce qu'on peut prouver, et ce qu'on ne peut pas)

**Sous Expo Go, le push ne peut pas être prouvé.** C'est une limite de l'outil, pas du code :

| Situation | Aujourd'hui |
|---|---|
| Application ouverte | pastille à jour au changement d'écran |
| Application rouverte | à jour immédiatement |
| **Téléphone en poche, application fermée** | **rien** |

À l'entrée de la zone authentifiée, l'application tente d'obtenir un jeton Expo. Sous Expo Go
Android, **l'appel échoue silencieusement** — c'est le chemin nominal. **L'application ne doit ni
planter, ni afficher d'erreur.** C'est le seul point à vérifier au G4 :

- [ ] L'application démarre normalement après l'ajout d'`expo-notifications`
- [ ] Aucune alerte d'erreur au premier écran
- [ ] `npx expo-doctor` reste **18/18**

Le relais lui-même est prouvé côté serveur (D1.8, vecteurs 22 à 25).

Pour l'activer le jour venu : *development build* EAS + `projectId` dans `app.config.ts` +
`MASANTE_PUSH_ENABLED=true`.

## D1.8 Backend en direct (curl)

```powershell
# Jetons (guillemeter : un jeton Sanctum contient un « | »)
$A = "…"; $B = "…"; $T = "…"   # A propriétaire, B délégué, T tiers

# Liste et compteur
curl.exe -s -H "Authorization: Bearer $A" http://127.0.0.1:8000/api/v1/notifications
curl.exe -s -H "Authorization: Bearer $A" http://127.0.0.1:8000/api/v1/notifications/non-lues

# Anti-IDOR : le tiers ne voit rien, et ne peut pas marquer celle d'autrui
curl.exe -s -H "Authorization: Bearer $T" http://127.0.0.1:8000/api/v1/notifications
curl.exe -s -o NUL -w "%{http_code}`n" -X POST -H "Authorization: Bearer $T" `
  http://127.0.0.1:8000/api/v1/notifications/<uuid-de-A>/lu          # attendu : 404 (jamais 403)

# Idempotence : marquer deux fois ne change pas la date de PREMIÈRE lecture
curl.exe -s -X POST -H "Authorization: Bearer $A" http://127.0.0.1:8000/api/v1/notifications/<uuid>/lu

# Jeton de push : forme vérifiée
curl.exe -s -o NUL -w "%{http_code}`n" -X POST -H "Authorization: Bearer $A" `
  -H "Content-Type: application/json" -d '{\"jeton\":\"pas-un-jeton\"}' `
  http://127.0.0.1:8000/api/v1/appareils-push                        # attendu : 422
```

### Vérification en base

```sql
-- Aucun contenu médical dans les notifications (doit renvoyer 0 ligne)
SELECT id, type FROM notifications
 WHERE data LIKE '%maladie_chronique%' OR data LIKE '%Fièvre%';

-- Un jeton, une ligne : jamais deux comptes sur le même téléphone
SELECT jeton_expo, COUNT(*) FROM appareils_push GROUP BY jeton_expo HAVING COUNT(*) > 1;

-- Trace des envois poussés (vide tant que le push est gaté OFF)
SELECT statut, COUNT(*) FROM notification_envois GROUP BY statut;
```

## D1.9 Qualité (G3)

✅ Référence du 2026-08-12 : **25 tests dédiés** (`NotificationCarnetTest`, 90 assertions), écrits
dans les deux sens. Suite complète + typecheck sur les trois workspaces + `expo-doctor` 18/18.

Les quatre vecteurs de canal méritent d'être cités, ils sont testés sans réseau (`Http::fake`) :

| Vecteur | Attendu |
|---|---|
| Push gaté OFF | notification en application écrite, **aucun appel HTTP**, `notification_envois` vide |
| Push ON, Expo répond `ok` | 1 appel, envoi `ENVOYEE`, `ticket_id` enregistré |
| Push ON, `DeviceNotRegistered` | envoi `ECHOUEE` **et appareil révoqué** |
| Push ON, **exp.host injoignable** | **la contribution est créée**, la notification est là, seul l'envoi est `ECHOUEE` |

Le dernier est le plus important du lot : un service tiers n'a jamais le droit de mettre en péril
l'écriture d'un dossier médical.

---

## D1.10 Checklist de clôture

**Recevoir**
- [ ] D1.1 Cloche + pastille sur l'Accueil, disparaissent après lecture
- [ ] D1.1 Le corps ne contient **aucun** détail médical
- [ ] D1.2 L'auteur n'est pas notifié de son propre dépôt

**Décider**
- [ ] D1.3 Validation → l'auteur est prévenu ; le décideur, non
- [ ] D1.3 Rejet → le motif figure dans le corps
- [ ] D1.4 Le second responsable reçoit dépôt et décision

**Partager**
- [ ] D1.5 Partage en masse → **une** notification mentionnant le nombre
- [ ] D1.5 Carnet revendicable → ouvre « Reconnaître mon carnet »
- [ ] D1.5 Rejeu → aucune notification nouvelle

**Le scénario de l'accident**
- [ ] D1.6 Bris de glace → propriétaire **et** délégués en lecture prévenus, icône rouge + motif
- [ ] D1.6 Scan QR → prévenus aussi
- [ ] D1.6 Délégation `qr_generation` → **rien**
- [ ] D1.6 Le soignant ne s'alerte pas lui-même

**Push et qualité**
- [ ] D1.7 L'application démarre et ne plante pas sans jeton (cas nominal sous Expo Go)
- [ ] D1.7 `expo-doctor` 18/18
- [ ] D1.8 404 (jamais 403) sur la notification d'autrui ; 422 sur un jeton malformé
- [ ] D1.8 Aucune ligne médicale en base
- [ ] D1.9 Suite complète + typecheck verts

> Tout coché sans écart → écrire **« Incrément D1 validé »** et ouvrir **D2 (fiche de parcours)**.

---

## D1.11 Pièges

| Piège | Symptôme | Parade |
|-------|----------|--------|
| Push attendu sous Expo Go | « je ne reçois rien téléphone fermé » | Normal : indisponible sur Android depuis le SDK 53, il faut un development build |
| Route non déclarée `href: null` | Un onglet parasite apparaît dans la barre du bas | Déclarer l'écran dans `app/(app)/_layout.tsx` |
| `notifications/non-lues` capté | 404 sur le compteur | Routes statiques déclarées **avant** `{notification}` |
| Push appelé dans la transaction | Un `exp.host` lent bloquerait l'écriture du dossier | `DB::afterCommit()` dans `CanalPushExpo` |
| Contenu médical concaténé | Un diagnostic sur un écran verrouillé | Le test dédié casse le build |
