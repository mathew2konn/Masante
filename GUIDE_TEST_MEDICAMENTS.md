# Guide de test — Référentiel National des Médicaments (P6.6)

> CDC_09 §6, étape 6 de l'ordre §14. **Module complet.**
> **Partie 1** — le référentiel (P6.6a) · **Partie 2** — le lien ordonnance → référentiel et la
> consultation des interactions (P6.6b).

---

# PARTIE 1 — Le référentiel (P6.6a)

## 1.1 Périmètre — et ce que ce module ne fait PAS

**Ce qui est livré.** Les 15 données du §6.2 (code national, DCI, nom commercial, laboratoire, forme,
dosage, voie, classe, indications, contre-indications, interactions, effets secondaires, prix
homologué, statut générique, statut de commercialisation), la table des **interactions**, la mise
sous **gouvernance §10** du référentiel, les contrôles qualité, le backfill des codes, et l'écran du
portail réservé à l'autorité sanitaire.

**Ce qui n'est PAS livré, et qu'il faut savoir avant de tester :**

- **Une ordonnance ne désigne toujours aucun médicament du référentiel.** `medicaments_json.*.nom`
  reste du texte libre. C'est le défaut central du G0 — il est traité en **P6.6b**, pas ici.
- **Aucun moteur d'interactions.** Le référentiel *rapporte* ce qui est déclaré ; il n'analyse pas,
  ne propose aucune alternative et n'adapte aucune dose. C'est le `interaction-service` de CDC_05 §2.
- **Aucune propagation de retrait** (§6.5) : le statut `retire` existe, l'alerte aux pharmacies et
  aux prescripteurs est un incrément séparé.
- **Le contenu est un jeu de démonstration** — 18 lignes. Charger la base DPM/CENAME réelle est de la
  **donnée, zéro code** ; tant que ce n'est pas fait, ce n'est pas un référentiel national.
- **Aucun écran mobile** : les écrans citoyens restent ceux du module 5 (comparateur, ruptures).

## 1.2 Prérequis

```bash
XDEBUG_MODE=off %PHP% artisan migrate                       # colonnes §6.2 + interactions + triggers
XDEBUG_MODE=off %PHP% artisan db:seed --class=PortailRolesSeeder   # crée `medicament.referentiel`
XDEBUG_MODE=off %PHP% artisan masante:medicaments:backfill --dry-run
XDEBUG_MODE=off %PHP% artisan masante:medicaments:backfill
```

> ⚠️ La permission `medicament.referentiel` n'existe qu'**après** le seeder, et le cache spatie doit
> être vidé (`forgetCachedPermissions`) — piège déjà rencontré en P6.5a.

> ⚠️ **La permission ne suffit pas à entrer dans le portail** : `AuthController` exige aussi un
> rôle. Un compte habilité doit donc porter un rôle de portail **et** la permission.

## 1.3 Scénarios front (portail — c'est ici que se joue le G4)

### 1.3.1 Une officine n'ouvre pas le catalogue national

Connectez-vous avec un compte de **gestionnaire d'établissement** (il porte `medicament.manage`).

- ✅ « Prix & stock » est accessible s'il s'agit d'une pharmacie — c'est SON officine.
- ✅ `/portail/medicaments` répond **403**. C'est le point : la permission d'une officine sur ses
  prix n'est pas celle de l'autorité sanitaire sur le catalogue.

### 1.3.2 L'écran de l'autorité

Avec un compte portant `medicament.referentiel` :

- ✅ La tuile « Référentiel médicaments » apparaît au tableau de bord.
- ✅ Un **bandeau** annonce que ce qui est saisi est le *contenu de travail*, et qu'il faut publier
  une nouvelle version pour le diffuser. Sans ce bandeau, un agent croirait qu'enregistrer suffit.
- ✅ Si des produits n'ont pas de code, un **bandeau rouge** le dit et nomme la commande.

### 1.3.3 La fiche

- ✅ Le **code national** est affiché et **non saisissable**.
- ✅ Forme et voie sont des listes ; le **dosage est un texte libre** (« 500 mg », « 1 g / 5 mL »).
- ✅ Enregistrer affiche « … ne sera diffusée qu'après publication d'une nouvelle version ».

### 1.3.4 Les interactions

- ✅ Formulaire **séparé** de celui de l'identité : un refus sur l'un ne fait pas perdre l'autre.
- ✅ Un encart rappelle que le référentiel **constate** et ne décide pas.
- ✅ Déclarer la même interaction dans l'autre sens → **refusée**.
- ✅ Déclarer un produit avec lui-même → **refusé**.

## 1.4 Scénarios backend (curl / tinker reproductibles)

### 1.4.1 Le code national

```bash
XDEBUG_MODE=off %PHP% artisan masante:medicaments:backfill --dry-run   # n'écrit rien
XDEBUG_MODE=off %PHP% artisan masante:medicaments:backfill             # MED000001…
XDEBUG_MODE=off %PHP% artisan masante:medicaments:backfill             # « rien à faire »
```
✅ Codes consécutifs, rejeu sans effet, aucun trou dans la séquence.

### 1.4.2 Le moteur refuse un code déjà pris — pour le même pays

```sql
INSERT INTO medicaments (nom_generique, categorie, code, pays_code, created_at, updated_at)
VALUES ('Doublon', 'Test', 'MED000001', 'CI', NOW(), NOW());
```
✅ `ERROR 1062 Duplicata du champ 'CI-MED000001'`.

Puis le même code pour un **autre pays** :
✅ accepté — CI et SN peuvent tous deux avoir `MED000001`.

### 1.4.3 Le couple d'interaction est ordonné, et le moteur le garantit

```sql
-- (A, B) existe ; on tente (B, A) en SQL direct
INSERT INTO interactions_medicamenteuses (medicament_a_id, medicament_b_id, niveau, description, source, created_at, updated_at)
VALUES (<b>, <a>, 'precaution', 'À l''envers', 'Import brut', NOW(), NOW());
```
✅ `ERROR 1644 ck_interaction_couple_ordonne`.
❌ Une insertion **acceptée** signifierait que le référentiel peut porter deux affirmations
différentes sur le même fait clinique.

### 1.4.4 Les contrôles qualité

```bash
XDEBUG_MODE=off %PHP% artisan masante:referentiel:controler
```
✅ Signalés : produit sans code, DCI absente, dosage sans forme, statut inconnu, prix négatif,
interaction sans description, **interaction sans source**, interaction désignant un produit sans
code, auto-interaction, **même produit saisi deux fois**.
✅ **Non signalé** : un générique et sa marque partageant DCI et dosage — ce sont deux produits.

### 1.4.5 Mise sous gouvernance

```bash
curl -X POST $API/api/v1/referentiels/medicaments/propositions \
  -H "Authorization: Bearer $JETON_AGENT_1" -H 'Content-Type: application/json' \
  -d '{"motif":"Mise en vigueur initiale."}'

curl -X POST $API/api/v1/referentiels/medicaments/publication \
  -H "Authorization: Bearer $JETON_AGENT_2" -H 'Content-Type: application/json' \
  -d '{"motif":"Controles conformes."}'
```
✅ 201 puis 200 ; le quatre-yeux s'applique (deux comptes distincts).

### 1.4.6 Le diffusé ne bouge pas sur un `UPDATE` direct

```sql
UPDATE medicaments SET dosage = '1000 mg MODIFIE EN DIRECT' WHERE code = 'MED000001';
```
```bash
curl -s $API/api/v1/referentiels/medicaments | jq '.contenu[] | select(.code=="MED000001") | .dosage'
```
✅ Le dosage **publié** est inchangé.

### 1.4.7 Les deux vecteurs en miroir — le cœur de la décision de projection

| Action | Empreinte du référentiel |
|---|---|
| Un citoyen relève un prix en officine | **inchangée** |
| Une autorité corrige un dosage | **change** |

❌ Si le relevé de prix faisait diverger, le référentiel affirmerait qu'un prix observé en pharmacie
est une donnée de référence nationale — et l'anti-substitution refuserait toute publication.

### 1.4.8 Les énumérations viennent du serveur

```bash
curl -s $API/api/v1/medicaments | jq '.enumerations | keys'
```
✅ `formes`, `voies`, `statuts_marche`, `statuts_generique`, `niveaux_interaction`.
Aucun client ne recopie ces libellés — c'est le défaut de P6.4b, où sept d'entre eux vivaient en dur
côté mobile et avaient divergé de la base sans que le typecheck le voie.

## 1.5 Invariants base de données

```sql
-- a. Unicité du code par pays
SHOW INDEX FROM medicaments WHERE Key_name = 'uq_medicament_code_pays';   -- 2 colonnes

-- b. Les triggers d'ordre du couple
SHOW TRIGGERS LIKE 'interactions_medicamenteuses';                        -- 2 (insert, update)

-- c. Aucun couple à l'envers
SELECT COUNT(*) FROM interactions_medicamenteuses WHERE medicament_a_id >= medicament_b_id;
-- attendu : 0

-- d. Aucune interaction orpheline
SELECT COUNT(*) FROM interactions_medicamenteuses i
LEFT JOIN medicaments a ON a.id = i.medicament_a_id
LEFT JOIN medicaments b ON b.id = i.medicament_b_id
WHERE a.id IS NULL OR b.id IS NULL;
-- attendu : 0

-- e. Tout produit codé a un code bien formé
SELECT COUNT(*) FROM medicaments WHERE code IS NOT NULL AND code NOT REGEXP '^MED[0-9]{6}$';
-- attendu : 0
```

## 1.6 Commandes de qualité (G3)

```bash
XDEBUG_MODE=off %PHP% artisan test --filter=ReferentielMedicamentsTest
XDEBUG_MODE=off %PHP% artisan test
pnpm typecheck
```

## 1.7 Checklist de clôture

- [ ] Officine → **403** sur `/portail/medicaments` ; habilité → **200** (§1.3.1, §1.3.2)
- [ ] Bandeau « contenu de travail » présent (§1.3.2)
- [ ] Code national affiché, **non saisissable**, et **ignoré** s'il est envoyé (§1.3.3)
- [ ] Interaction inverse et auto-interaction refusées (§1.3.4)
- [ ] Backfill : dry-run muet → réel → rejeu sans effet (§1.4.1)
- [ ] `ERROR 1062` sur code dupliqué ; CI et SN partagent un code (§1.4.2)
- [ ] `ERROR 1644` sur couple écrit à l'envers (§1.4.3)
- [ ] Contrôles qualité : générique/marque **non** signalés, vrai doublon **signalé** (§1.4.4)
- [ ] Publication à deux agents (§1.4.5) ; `UPDATE` direct sans effet sur le diffusé (§1.4.6)
- [ ] **Vecteurs en miroir** : prix → pas de divergence · dosage → divergence (§1.4.7)
- [ ] Énumérations servies par l'API (§1.4.8)
- [ ] Invariants a→e (§1.5)
- [ ] Suite complète + typecheck ×3 (§1.6)
- [ ] **Limites relues et acceptées** (§1.1)

## 1.8 Pièges rencontrés

**`medicament.manage` n'est pas la permission du catalogue national.** Elle appartient au
gestionnaire d'établissement pour « les prix et les ruptures de SA pharmacie ». La réutiliser aurait
laissé une officine écrire indications et interactions, et un laboratoire fabricant serait devenu
juge et partie sur son propre produit. D'où `medicament.referentiel`, portée par aucun rôle.

**Un vecteur bâti sur `admin_ivoirsante` n'aurait rien vérifié.** Ce rôle reçoit **toutes** les
permissions (`syncPermissions(Permission::all())`, le seeder le dit lui-même) : le premier essai
passait quoi qu'il arrive. Le vecteur a été refait sur un rôle réel.

**L'index unique ne protégeait que le couple déjà ordonné.** Le G2 l'a montré : (B, A) passait en SQL
direct. Un `CHECK` était impossible — **erreur 3823**, les deux colonnes étant `cascadeOnDelete`,
exactement le mur de P6.3. Fermé par des **triggers dans les deux dialectes**.

**Un contrôle de doublon sur la seule DCI aurait rendu le référentiel impubliable.** « Amoxicilline
500 mg » existe deux fois dans le jeu de développement : le générique et « Clamoxyl ». Ce sont deux
produits. La clé de doublon est le **produit complet**.

**`PrixMedicamentService` refuse une structure qui n'est pas une pharmacie** (« les prix et les
ruptures ne se signalent que dans une pharmacie ») : le vecteur en miroir doit viser une vraie
officine, pas le premier établissement venu.

---

# PARTIE 2 — Le lien ordonnance → référentiel (P6.6b)

> **Referme le défaut central du G0** : une ordonnance peut désormais DÉSIGNER un produit du
> référentiel national, au lieu de le nommer en texte libre. **P6.6 est complet (a, b) = étape 6.**

## 2.1 Périmètre — et ce que ce module ne fait PAS

**Ce qui change.** Chaque ligne de `medicaments_json` peut porter un `medicament_id`. Quand il est
fourni, le serveur relit le référentiel et **fige** le code national, la DCI et le dosage. Un produit
retiré du marché est **signalé** au prescripteur. Un endpoint public permet de **demander** les
interactions déclarées entre plusieurs produits.

**Ce que ce module ne fait PAS :**

- **Le lien reste FACULTATIF.** Un patient qui recopie une ordonnance papier n'a pas de liste sous
  les yeux, et le référentiel est incomplet : l'imposer ferait de ses lacunes un blocage clinique.
- **Les interactions ne sont PAS calculées à la prescription.** Choix du propriétaire : « donnée du
  référentiel + consultation explicite », et non « signalement au moment de prescrire ». Les
  calculer à l'écriture rapprocherait P6.6 d'une aide à la décision — terrain de CDC_05 et CDC_08.
- **Rien n'est bloqué.** Ni un produit retiré, ni une contre-indication déclarée. Refuser serait une
  décision médicale prise par une machine (CDC_00 §4).
- **Aucun moteur d'analyse** : pas d'alternative thérapeutique, pas d'adaptation de dose.
- **Aucune migration** : P6.6b est du comportement, pas du schéma.

## 2.2 Prérequis

Ceux de la partie 1 (codes nationaux attribués). Pour voir l'estampille de version dans la réponse
des interactions, il faut en plus avoir **publié** le référentiel (§1.4.5).

## 2.3 Scénarios front (Expo Go — c'est ici que se joue le G4)

### 2.3.1 La saisie libre n'a pas bougé

Carnet → un membre → **Ordonnances** → Ajouter.

- ✅ Le champ « Médicament 1 » se remplit à la main comme avant.
- ✅ Tant qu'on tape moins de 3 caractères, **rien n'apparaît** : le formulaire ne harcèle pas.

### 2.3.2 La proposition du référentiel

- ✅ À partir de 3 caractères, une ligne discrète annonce « *N produit(s) au référentiel national —
  appuyez pour les voir* ». Elle **propose**, elle n'impose rien.
- ✅ Appuyer déroule jusqu'à 5 produits, chacun avec son **code national**.
- ✅ Choisir un produit remplace le nom par le libellé du référentiel et affiche un bandeau vert
  « **Référentiel national · MED000001** ».
- ✅ « Détacher » rend la ligne libre à nouveau.

### 2.3.3 Hors ligne, le formulaire ne se plaint pas

Mode avion, puis saisir un nom :
- ✅ Aucune suggestion, **aucune erreur**. La saisie libre reste entière — une recherche impossible
  n'est pas une panne, et afficher une erreur ferait croire que le formulaire est cassé.

## 2.4 Scénarios backend (curl reproductibles)

### 2.4.1 Sans lien : le nom libre suffit toujours

```bash
curl -s -X POST $API/api/v1/membres/$M/ordonnances -H "Authorization: Bearer $T" \
  -H 'Content-Type: application/json' \
  -d '{"medecin_nom":"Dr Aya Koffi","structure_sanitaire":"CHU","date_prescription":"2026-08-14",
       "medicaments_json":[{"nom":"Doliprane 500 (papier)","posologie":"3/jour"}]}'
```
✅ 201, et la ligne stockée ne porte **ni** `medicament_id` **ni** `code_national`.

### 2.4.2 Avec lien : le serveur fige, et ne croit rien du client

```bash
… -d '{…,"medicaments_json":[{"nom":"Doliprane 500","medicament_id":1,
       "code_national":"MED999999","dci":"Molecule inventee"}]}'
```
✅ La ligne porte `code_national: MED000001` et `dci: Paracétamol` — **ceux du référentiel**.
❌ Retrouver `MED999999` signifierait qu'une ordonnance peut porter une référence nationale que
personne n'a vérifiée.

### 2.4.3 Un médicament inconnu est refusé, et le message le nomme

```bash
… -d '{…,"medicaments_json":[{"nom":"Fantôme","medicament_id":4242}]}'
```
✅ **422** — « Le médicament n°4242 n'existe pas au référentiel national. »

### 2.4.4 Un produit retiré est PRESCRIT et SIGNALÉ

✅ L'ordonnance est **créée**, et la réponse porte un `avertissements[]` disant que le produit est
retiré du marché.
❌ Un refus serait une décision médicale prise par le serveur.

### 2.4.5 Les valeurs figées ne bougent plus

Après la prescription, corriger la DCI ou le dosage au référentiel :
✅ L'ordonnance **garde** ce qui a été prescrit ce jour-là. Une ordonnance signée doit continuer de
dire ce qu'elle disait.

### 2.4.6 La consultation des interactions, résolue PAR MOLÉCULE

Interaction déclarée entre **Warfarine** et **Aspirine générique** ; on interroge Warfarine + une
**marque** de la même aspirine :

```bash
curl -s "$API/api/v1/medicaments/interactions?medicament_id[]=28&medicament_id[]=30" | jq
```
✅ L'interaction est **trouvée**. Ne chercher que les identifiants prescrits produirait un silence
qui ressemblerait à « aucune interaction ».
✅ La réponse cite la **version du référentiel** (`{"referentiel":"medicaments","version":1}`) et
porte un avertissement disant qu'elle **ne remplace pas** l'analyse d'un professionnel.
✅ Avec un seul médicament → **422** : la question n'a pas de sens.

### 2.4.7 La route littérale n'est pas captée

```bash
curl -s -o /dev/null -w '%{http_code}\n' "$API/api/v1/medicaments/interactions?medicament_id[]=1&medicament_id[]=2"
curl -s -o /dev/null -w '%{http_code}\n' "$API/api/v1/medicaments/1/prix"
```
✅ **200** et **200** — `interactions` est déclarée avant `{medicament}` (piège de P7-D0 et P6.5b).

### 2.4.8 LE VECTEUR OBLIGATOIRE — les signatures déjà posées

✅ Une ordonnance signée **dans la forme d'avant P6.6b** reste `integre = true`.
✅ Une ordonnance **portant le lien** se signe et se vérifie de la même façon.
✅ Modifier une valeur figée → `integre = false` : la signature **révèle** toujours (§5.3).

❌ Un `integre = false` sur le premier cas signifierait qu'on a cassé des signatures existantes — et
*une signature qui casse toute seule ne prouve plus rien, pire, elle accuse*.

## 2.5 Invariants base de données

```sql
-- a. Aucune ligne ne porte un code national sans lien (le code viendrait d'où ?)
--    `medicaments_json` étant chiffré, ce contrôle se fait par le service, pas en SQL :
--    php artisan tinker
--    >>> App\Models\Ordonnance::get()->flatMap->medicaments_json
--    ...     ->filter(fn ($l) => isset($l['code_national']) && ! isset($l['medicament_id']))->count();
--    attendu : 0

-- b. Tout `medicament_id` cité désigne un produit existant — même contrôle, côté service.
```

> ⚠️ `medicaments_json` est un cast `encrypted:array` : aucun de ces contrôles ne se fait en SQL
> direct. C'est voulu — une ordonnance est une donnée de santé.

## 2.6 Commandes de qualité (G3)

```bash
XDEBUG_MODE=off %PHP% artisan test --filter=LienOrdonnanceMedicamentTest
XDEBUG_MODE=off %PHP% artisan test
pnpm typecheck
npx expo-doctor
```

## 2.7 Checklist de clôture

- [ ] Saisie libre inchangée, aucune suggestion sous 3 caractères (§2.3.1)
- [ ] Proposition du référentiel, rattachement, bandeau avec code, détachement (§2.3.2)
- [ ] **Hors ligne : aucune suggestion et AUCUNE erreur** (§2.3.3)
- [ ] Sans lien → accepté ; avec lien → code et DCI **du référentiel** (§2.4.1, §2.4.2)
- [ ] `medicament_id` inconnu → **422 nommant le produit** (§2.4.3)
- [ ] Produit retiré → **prescrit et signalé** (§2.4.4)
- [ ] Valeurs figées insensibles aux corrections ultérieures (§2.4.5)
- [ ] Interactions résolues **par molécule**, version citée, 422 à un seul produit (§2.4.6)
- [ ] Route littérale non captée (§2.4.7)
- [ ] **Ordonnance signée avant P6.6b toujours INTÈGRE** (§2.4.8)
- [ ] Suite complète, typecheck ×3, expo-doctor 18/18 (§2.6)
- [ ] **Limites relues** (§2.1) — dont « aucune interaction calculée à la prescription »

## 2.8 Pièges rencontrés

**Un vecteur passait sans rien éprouver — troisième occurrence.** Les premiers vecteurs « le client
ne peut pas déclarer le code » restaient verts quand on retirait la garde du service : `validate()`
écarte déjà les clés non déclarées, si bien qu'ils prouvaient le comportement du **validateur** et
non celui du service. Le vecteur a été dédoublé — une couche, un vecteur — et le second appelle le
service **directement**, comme le ferait un import.

**La garantie doit valoir sur les TROIS chemins d'écriture** (patient, délégué, soignant). Ils
partagent les règles de validation depuis P7-C/D0 mais écrivent chacun de leur côté : le point
d'accroche est appelé aux trois endroits.

**La résolution a lieu au DÉPÔT pour une contribution**, pas à la validation. Re-résoudre des
semaines plus tard pourrait présenter au responsable une DCI différente de celle que l'auteur avait
sous les yeux — il validerait alors autre chose que ce qui lui est affiché.

**Le type mobile du catalogue n'avait pas suivi P6.6a** : `code` manquait, et le typecheck l'a
attrapé au moment de l'afficher. C'est exactement l'écart que P6.4b avait dû rattraper à la main.

**Monter un praticien pour le G2 demande quatre choses** : un compte relié, une autorisation valide,
un **numéro national** (sans lui, pas de certificat) et une **profession prescriptrice** — le
contrôle d'habilitation de P6.5b a refusé la première tentative, ce qui est son rôle.

