# Guide de test — Laboratoires et catalogue des analyses (P6.7)

> CDC_09 §7, étape 7 de l'ordre §14. **Module complet.**
> **Partie 0** ce que contient un référentiel biologique réel · **Partie 1** le catalogue (P6.7a) ·
> **Partie 2** laboratoires, liens d'un résultat et la correction de P6.7a (P6.7b).

---

# PARTIE 0 — Ce que contient un référentiel biologique, et ce que nous livrons

> **Section à lire avant toute démonstration, et utilisable telle quelle en soutenance.**
> Elle existe parce qu'un catalogue d'analyses touche à l'interprétation d'un résultat médical :
> présenter des valeurs sans dire d'où elles viennent serait la faute la plus grave du module.

## 0.1 Ce que contient un référentiel biologique réel

| Élément | Ce que c'est | Chez nous |
|---|---|---|
| **Code international (LOINC)** | Le standard que CDC_09 §9.1 recommande. Il décrit une analyse selon **six axes** : ce qui est mesuré, la grandeur, le moment, le **milieu prélevé**, l'échelle, la **méthode**. C'est pourquoi « glycémie » n'est pas une analyse : à jeun sur plasma veineux et capillaire sont deux codes. | Colonne `loinc` **présente et vide**. Le jeu LOINC n'est pas en notre possession ; inventer des codes qui auraient l'air vrais serait pire. |
| **Code national** | Clé locale du pays. | ✅ `ANA` + 6 chiffres, unique par pays. |
| **Unité normalisée (UCUM)** | `g/L` et `mmol/L` désignent la même chose avec des nombres sans rapport. | ✅ Unité **obligatoire**, saisie libre (UCUM non imposé). |
| **Intervalles de référence stratifiés** | Par **sexe**, par **tranche d'âge**, par **état physiologique** (grossesse), parfois selon la méthode du laboratoire. | ✅ **Structure complète.** Valeurs = jeu de démonstration. |
| **Valeurs critiques** | Le seuil qui impose d'appeler le prescripteur — distinct de « hors norme ». | ✅ Colonnes présentes, **jamais utilisées pour conclure**. |
| **Conditions pré-analytiques** | Tube, jeûne, conservation, transport. | ✅ Champs présents. |
| **Délai de rendu** | Ce que le patient attend. | ✅ Champ présent. |

## 0.2 Pourquoi les intervalles devront être établis LOCALEMENT

Les valeurs usuelles **dépendent de la population**. Le cas le mieux documenté est celui des
**polynucléaires neutrophiles** : leur taux usuel est plus bas dans plusieurs populations d'Afrique
subsaharienne, au point qu'un intervalle établi ailleurs classe « anormaux » des sujets parfaitement
sains — et peut déclencher des explorations inutiles.

Le jeu de démonstration **inclut délibérément ce paramètre** pour rendre la question visible.

C'est la raison pour laquelle la structure est stratifiée **dès maintenant** : elle est prête à
recevoir des intervalles ivoiriens sans aucune migration.

## 0.3 Ce que nous livrons, dit sans enjoliver

- **8 analyses**, **14 strates de référence**, toutes portant `source = 'demonstration'`.
- Ces valeurs sont des **ordres de grandeur usuels**. Elles ne sont **ni validées cliniquement**,
  **ni attribuées** à une autorité sanitaire, une société savante ou un laboratoire, **ni établies
  sur la population ivoirienne**.
- Le choix de ne les attribuer à personne est délibéré : *un intervalle inventé qui porterait le nom
  d'une autorité serait pire qu'un intervalle inventé qui l'avoue.*
- L'écran du portail affiche un **bandeau rouge** avec le compte exact des strates concernées, et le
  répète dans chaque réponse de l'API (`source_libelle`).

## 0.4 Ce que coûtera le remplacement

**De la donnée, zéro code.** Charger un catalogue officiel revient à remplacer les lignes et à
publier une nouvelle version. Le contrôle qualité **refuse toute strate sans source**, et le
compteur du bandeau tombera à zéro quand la provenance aura changé — c'est le témoin visible du
passage de la démonstration au référentiel réel.

---

# PARTIE 1 — Le catalogue des analyses (P6.7a)

## 1.1 Périmètre — et ce que ce module ne fait PAS

**Ce qui est livré.** Le catalogue (§7.3) avec code national, unité, milieu prélevé, méthode,
conditions de prélèvement, conservation, délai de rendu ; les **valeurs de référence stratifiées** ;
la mise sous **gouvernance §10** ; le lien résultat → catalogue sur les **trois** chemins
d'écriture ; l'écran du portail réservé à l'autorité sanitaire.

**Ce qui n'est PAS livré :**

- **Le serveur ne conclut jamais.** Aucun statut « normal » / « anormal » sur un résultat de
  laboratoire. Décision du propriétaire : §7.3 ne décrit aucune stratification, donc conclure sur une
  référence unique reviendrait à affirmer sur une base qu'on sait insuffisante.
- **La traçabilité des prélèvements (§7.4)** — les huit étapes, l'identifiant de prélèvement, le
  code-barres — est un **module séparé**. C'est un workflow, pas un référentiel, et il suppose la
  *prescription biologique*, entité qui n'existe pas encore.
- ~~Le référentiel des laboratoires (§7.2) est en P6.7b.~~ **LIVRÉ en P6.7b** — voir partie 2.
- ~~Aucun écran citoyen n'affiche encore les références.~~ **LIVRÉ en P6.7b** (§2.3.1) : le carnet
  les montre sous chaque ligne rattachée, sans jamais comparer.
- **La grossesse n'est pas lue** pour choisir une strate : elles sont **toutes** affichées.

## 1.2 Prérequis

```bash
XDEBUG_MODE=off %PHP% artisan migrate                              # 3 tables + 2 triggers
XDEBUG_MODE=off %PHP% artisan db:seed --class=PortailRolesSeeder   # crée `analyse.referentiel`
XDEBUG_MODE=off %PHP% artisan db:seed --class=CatalogueAnalysesSeeder
XDEBUG_MODE=off %PHP% artisan masante:analyses:backfill
```

> ⚠️ La permission n'existe qu'**après** le seeder, et le cache spatie doit être vidé.
> ⚠️ **La permission ne suffit pas à entrer dans le portail** : un rôle est aussi exigé.

## 1.3 Scénarios front (portail — c'est ici que se joue le G4)

### 1.3.1 Une officine ou un agent sans droit n'ouvre pas le catalogue

- ✅ Compte sans `analyse.referentiel` → `/portail/analyses` répond **403**.
- ✅ Compte porteur de la permission → **200**, et la tuile apparaît au tableau de bord.

### 1.3.2 Le bandeau qui dit la vérité sur les valeurs

- ✅ Un **bandeau rouge** annonce « **14** valeur(s) de référence sur 14 proviennent du **jeu de
  démonstration** », précise qu'elles ne sont ni validées ni attribuées, et mentionne les
  **intervalles établis localement**.
- ❌ Son absence signifierait qu'un agent peut prendre ces plages pour des valeurs officielles.

### 1.3.3 La fiche d'une analyse

- ✅ Code national **affiché, non saisissable** ; LOINC saisissable et **vide**.
- ✅ Le **milieu prélevé** est présenté comme faisant partie de l'identité (« deux milieux = deux
  analyses »).
- ✅ Chaque strate montre sa **provenance** : badge rouge « démonstration », vert sinon.

### 1.3.4 Ajouter une strate

- ✅ La **source est obligatoire** — le formulaire ne laisse pas passer une strate anonyme.
- ✅ Une strate **sans aucune borne** est refusée : « elle n'affirme rien ».
- ✅ Une borne basse supérieure à la borne haute est refusée.

## 1.4 Scénarios backend (curl reproductibles)

### 1.4.1 LE VECTEUR DE DÉMONSTRATION — la même analyse, quatre patients

```bash
for cas in 10:F 1095:M 12000:F 12000:M; do
  curl -s "$API/api/v1/analyses/1/references?age_jours=${cas%%:*}&sexe=${cas##*:}" | jq -c '.references[] | {libelle_strate, plage, conditionnelle}'
done
```

✅ Résultat attendu :

| Patient | Références renvoyées |
|---|---|
| Nouveau-né (10 j) | Nouveau-né : **14 – 22** g/dL |
| Enfant de 3 ans | Enfant et adolescent : **11 – 15** |
| Femme adulte | Femme adulte : **12 – 16** · Grossesse T2 : **10,5 – 14** *(conditionnelle)* · Grossesse T3 : **11 – 14** *(conditionnelle)* |
| Homme adulte | Homme adulte : **13 – 17** |

**C'est la démonstration du module** : **11 g/dL** est bas chez l'homme, normal chez la femme
enceinte, normal chez l'enfant. Une plage unique aurait affirmé le contraire pour deux d'entre eux.

✅ Les strates de grossesse sont **ajoutées**, jamais choisies : la plateforme ne décide pas qu'une
patiente est enceinte.

### 1.4.2 La réponse ne conclut jamais

```bash
curl -s "$API/api/v1/analyses/1/references?age_jours=12000&sexe=F" | grep -c '"statut"'
```
✅ **0** — de même pour `anormal`, `verdict`, `interpretation`, `diagnostic`.
✅ La réponse porte une phrase disant qu'elle **ne qualifie pas** le résultat.

### 1.4.3 Ce qu'on ne sait pas est DIT

Sans `age_jours` ni `sexe` :
✅ **0** strate renvoyée, et deux lignes d'`incertitude` qui expliquent pourquoi.
❌ Renvoyer les strates de l'homme et de la femme laisserait le lecteur choisir la plus flatteuse.

### 1.4.4 Le moteur refuse une strate incohérente

```sql
INSERT INTO analyse_references (analyse_id, sexe, etat_physiologique, valeur_min, valeur_max,
       libelle_strate, source, created_at, updated_at)
VALUES (1, 'tous', 'standard', 20, 5, 'Incohérente', 'demonstration', NOW(), NOW());
```
✅ `ERROR 1644 ck_analyse_reference_bornes`.
❌ Un `CHECK` n'était pas possible : `analyse_id` est `cascadeOnDelete`, donc **erreur 3823** — le mur
de P6.3. D'où des triggers dans les deux dialectes.

### 1.4.5 Le lien résultat → catalogue

✅ Sans `analyse_id` → accepté, la ligne reste libre.
✅ Avec `analyse_id` → `code_national`, `libelle_catalogue` et **`unite_catalogue`** viennent du
catalogue, même si le client en envoie d'autres.
✅ `analyse_id` inconnu → **422** nommant l'analyse.
✅ Corriger le catalogue ensuite **ne réécrit pas** le résultat.

> **L'unité figée est le point critique** : une unité qui changerait après coup rendrait le résultat
> faux d'un facteur 10 ou 100 sans que rien ne le signale.

### 1.4.6 LA SECONDE PORTE DU PRESCRIPTEUR

Un soignant consigne un résultat en envoyant `medecin_prescripteur: "Dr Quelqu'un d'Autre"` :
✅ La base contient **le nom de sa fiche professionnelle**.
✅ Le chemin du **patient** n'est pas touché : il continue de nommer le médecin qui lui a remis le
compte rendu.

> P6.5 avait refermé `ordonnances.medecin_nom` en testant cette clé-là. `resultats_analyses` porte
> `medecin_prescripteur` — un autre nom pour la même chose — et la section est ouverte au soignant.
> **Il y avait deux portes ; une seule avait été fermée.**

### 1.4.7 Gouvernance

✅ Proposition (201) puis publication (200) par **deux comptes distincts**.
✅ `UPDATE` direct sur `analyses` → le **diffusé ne change pas**.
✅ La réponse des références **cite la version** en vigueur une fois le catalogue publié.

## 1.5 Invariants base de données

```sql
-- a. Unicité du code par pays
SHOW INDEX FROM analyses WHERE Key_name = 'uq_analyse_code_pays';        -- 2 colonnes

-- b. Les triggers de bornes
SHOW TRIGGERS LIKE 'analyse_references';                                  -- 2

-- c. Aucune borne inversée
SELECT COUNT(*) FROM analyse_references
WHERE valeur_min IS NOT NULL AND valeur_max IS NOT NULL AND valeur_min > valeur_max;
-- attendu : 0

-- d. Aucune strate anonyme
SELECT COUNT(*) FROM analyse_references WHERE source IS NULL;             -- attendu : 0

-- e. Le témoin du remplacement : combien de strates reposent encore sur la démonstration
SELECT source, COUNT(*) FROM analyse_references GROUP BY source;
```

## 1.6 Commandes de qualité (G3)

```bash
XDEBUG_MODE=off %PHP% artisan test --filter=CatalogueAnalysesTest
XDEBUG_MODE=off %PHP% artisan test
pnpm typecheck
```

✅ **Référence au G5 (2026-08-14)** : **36 vecteurs dédiés** · suite **736 tests / 15 287
assertions, 0 échec** · typecheck ×3 verts.

**Vérification par mutation** — trois gardes neutralisées une à une, chacune tuant exactement le
vecteur qui porte la décision correspondante :

| Mutation | Vecteur mort |
|---|---|
| La résolution ne renvoie qu'une strate | `test_la_resolution_renvoie_TOUTES_les_strates_applicables` |
| La réécriture du prescripteur est retirée | `test_le_soignant_ne_declare_PLUS_le_prescripteur` |
| La source n'est plus obligatoire | `test_une_strate_sans_source_empeche_la_publication` |

## 1.7 Checklist de clôture

- [ ] **Partie 0 relue** — ce que contient un référentiel réel, ce que nous livrons (§0)
- [ ] Sans permission → 403 ; avec → 200 (§1.3.1)
- [ ] **Bandeau de démonstration affiché avec le compte exact** (§1.3.2)
- [ ] Code national non saisissable, LOINC vide (§1.3.3)
- [ ] Strate sans source / sans borne / bornes inversées → refusées (§1.3.4)
- [ ] **Quatre patients, quatre réponses** (§1.4.1)
- [ ] Aucune clé de verdict dans la réponse (§1.4.2)
- [ ] Âge et sexe inconnus → 0 strate **et** l'incertitude dite (§1.4.3)
- [ ] `ERROR 1644` sur borne inversée (§1.4.4)
- [ ] Lien figé, unité comprise ; analyse inconnue → 422 (§1.4.5)
- [ ] **Seconde porte du prescripteur refermée**, chemin patient intact (§1.4.6)
- [ ] Gouvernance à deux agents, `UPDATE` sans effet (§1.4.7)
- [ ] Invariants a→e (§1.5)
- [ ] Suite complète + typecheck ×3 (§1.6)
- [ ] **Limites relues** (§1.1) — dont §7.4 et l'absence d'écran citoyen

## 1.8 Pièges rencontrés

**`nullable` n'ajoute pas la clé au tableau validé.** Le contrôleur plantait sur « Undefined array
key » au lieu d'afficher son message quand le client omettait `valeur_min`. Trouvé par le vecteur de
la strate sans borne — corrigé par `?? null`.

**Un `CHECK` était impossible sur `analyse_references`** : `analyse_id` est `cascadeOnDelete`, donc
soumise à une action référentielle — **erreur 3823**, le mur exact de P6.3. Triggers dans les deux
dialectes, comme CDC_04 §139 le prévoit.

**Deux strates du même état qui se recouvrent sont un conflit ; deux strates d'états différents ne
le sont pas.** Une femme adulte a légitimement une référence standard et une de grossesse : les
signaler comme un chevauchement rendrait tout catalogue impubliable.

**Ne pas bâtir un vecteur de permission sur `admin_ivoirsante`** : ce rôle reçoit toutes les
permissions, le vecteur serait vert quoi qu'il arrive (leçon de P6.6a).

**Monter un soignant pour le G2 demande un `service_id`** : `medecins.service_id` est non nul.

---

# PARTIE 2 — Laboratoires, liens d'un résultat, et la correction de P6.7a (P6.7b)

> **Dernier incrément de P6.7 → l'étape 7 du corpus est complète.**
> Cette partie commence par une correction : P6.7a écrivait une fausseté, et elle a été retirée.

## 2.1 Ce que P6.7a affirmait, et pourquoi c'était faux

P6.7a réécrivait `medecin_prescripteur` avec le nom du soignant qui consignait le résultat, en le
présentant comme le miroir de `ordonnances.medecin_nom`.

**Ce n'en était pas un.** Pour une ordonnance, celui qui écrit **est** le prescripteur — rédiger
l'ordonnance *est* l'acte de prescrire. Pour un résultat, celui qui consigne est souvent **quelqu'un
d'autre** : un biologiste, ou un médecin hospitalier qui classe un résultat prescrit par un
généraliste de ville.

Le serveur inscrivait alors **le nom du mauvais médecin** — et une affirmation fausse portée par le
système est plus difficile à contester qu'une saisie humaine non vérifiée.

Le G2 de P6.7a l'avait même montré (« Dr Quelqu'un d'Autre » → « Dr Kablan Koffi ») et cela avait été
présenté comme une réussite.

**La réécriture est retirée. Un vecteur dédié empêche son retour.**

## 2.2 Périmètre — et ce que ce module ne fait PAS

**Ce qui est livré** : la correction ci-dessus ; les liens **vérifiés et figés** d'un résultat vers
son prescripteur et son laboratoire ; les champs §7.1/§7.2 propres au laboratoire ; les **analyses
réalisées** par un laboratoire ; et **l'affichage citoyen des valeurs de référence** — la limite 6 de
P6.7a est levée.

**Ce qui n'est PAS livré** :

- **§7.4 (traçabilité des prélèvements)** — toujours un module séparé.
- **Les liens restent facultatifs** : le texte libre demeure la voie normale du patient.
- **La liste des analyses réalisées n'est pas gouvernée** : c'est une donnée d'exploitation, aucune
  décision ne peut la citer.
- **Aucun écran mobile ne montre les laboratoires** : le lien se pose par l'API et par le portail.

## 2.3 Scénarios front

### 2.3.1 Le carnet montre les références, et ne conclut jamais (Expo Go)

Carnet → un membre → **Résultats d'analyses** → ouvrir un résultat.

- ✅ Sous « Paramètre », à partir de 3 caractères, une ligne propose les analyses du catalogue.
- ✅ Choisir une analyse affiche le bandeau vert « **Catalogue national · ANA000001** ».
- ✅ Un bloc **« Valeurs habituellement observées »** apparaît, avec la ou les strates applicables à
  ce membre — son âge et son sexe viennent de sa fiche.
- ✅ Une strate de grossesse est marquée **« (selon votre situation) »** : elle est ajoutée, jamais
  choisie à la place de la patiente.
- ✅ Si les valeurs viennent du jeu de démonstration, la ligne rouge le dit.
- ✅ **Aucune couleur, aucun « normal » ou « anormal »** — la valeur saisie n'est jamais comparée.
- ✅ **Mode avion** : le bloc disparaît, sans message d'erreur. Le résultat reste lisible.

### 2.3.2 Le portail : les analyses d'un laboratoire

Portail → Établissements → éditer un **laboratoire**.

- ✅ Le bloc « **Analyses réalisées** » apparaît, avec le délai appliqué et sa source.
- ✅ Sur un **CHU**, le bloc **n'apparaît pas** : l'afficher laisserait croire qu'un hôpital déclare
  des analyses au titre du §7.2.
- ✅ Déclarer deux fois la même analyse est refusé.

## 2.4 Scénarios backend (curl / tinker reproductibles)

### 2.4.1 LA CORRECTION — le prescripteur déclaré est conservé

Un soignant consigne un résultat en envoyant `medecin_prescripteur: "Dr Konan, généraliste de ville"` :

✅ La base contient **« Dr Konan, généraliste de ville »**.
❌ Y trouver le nom du soignant signifierait que la régression de P6.7a est revenue.

Et la non-régression dans l'autre sens : sur une **ordonnance**, le prescripteur reste réécrit avec
la fiche du soignant (P6.5b intact).

### 2.4.2 Le lien laboratoire est vérifié et figé

```bash
… -d '{"…","laboratoire":"Nom invente par le client","laboratoire_id":<id>,"laboratoire_nom":"Faux nom"}'
```
✅ `laboratoire_nom` et `laboratoire` portent le nom **du référentiel**, pas celui du client.
✅ Renommer ensuite le laboratoire **ne change pas** le résultat.

### 2.4.3 Un établissement qui n'est pas un laboratoire est refusé

```bash
… -d '{"…","laboratoire_id":<id de la pharmacie>}'
```
✅ **422** — « … n'est pas un laboratoire au référentiel national. »
❌ L'accepter ferait de « laboratoire » un champ « établissement », et le référentiel des
laboratoires ne voudrait plus rien dire.

### 2.4.4 Les deux vecteurs en miroir de la projection gouvernée

| Changement sur un laboratoire | Empreinte du référentiel |
|---|---|
| Responsable scientifique, équipements, délai de rendu, connexion SI | **inchangée** |
| `type_laboratoire` (§7.1) | **change** |

La typologie **classe** l'établissement ; les autres données changent avec le personnel et les
automates. Les gouverner ferait de l'arrivée d'un appareil une décision ministérielle — même critère
que `directeur`, exclu depuis P6.4a.

### 2.4.5 Les analyses réalisées

✅ Déclarer une analyse pour un laboratoire : accepté, avec un délai propre.
✅ Le **délai du laboratoire prime**, mais **les deux sont portés** dans la réponse (`delai_source`)
— on ne remplace jamais en silence.
✅ Deux fois la même analyse → refusé par le moteur (`UNIQUE(structure_id, analyse_id)`).
✅ Un CHU → refusé.
✅ Un gestionnaire ne déclare que pour **SON** laboratoire.

## 2.5 Invariants base de données

```sql
-- a. Les colonnes du §7.2 et les liens
SHOW COLUMNS FROM structures_sanitaires LIKE 'type_laboratoire';
SHOW COLUMNS FROM resultats_analyses LIKE 'laboratoire_%';

-- b. Aucun résultat lié à un établissement qui n'est pas un laboratoire
SELECT COUNT(*) FROM resultats_analyses r
JOIN structures_sanitaires s ON s.id = r.laboratoire_id
WHERE s.type <> 'laboratoire';
-- attendu : 0

-- c. Aucune analyse déclarée par un non-laboratoire
SELECT COUNT(*) FROM laboratoire_analyses la
JOIN structures_sanitaires s ON s.id = la.structure_id
WHERE s.type <> 'laboratoire';
-- attendu : 0

-- d. Aucun nom figé sans son lien
SELECT COUNT(*) FROM resultats_analyses
WHERE (laboratoire_nom IS NOT NULL AND laboratoire_id IS NULL)
   OR (medecin_prescripteur_nom IS NOT NULL AND medecin_prescripteur_id IS NULL);
-- attendu : 0
```

## 2.6 Commandes de qualité (G3)

```bash
XDEBUG_MODE=off %PHP% artisan test --filter=ReferentielLaboratoiresTest
XDEBUG_MODE=off %PHP% artisan test
pnpm typecheck && npx expo-doctor
```

✅ **Référence au G5 (2026-08-14)** : **21 vecteurs dédiés** (57 avec ceux de P6.7a) · suite
**757 tests / 15 344 assertions, 0 échec** · typecheck ×3 · expo-doctor 18/18.

**Vérification par mutation** :

| Mutation | Vecteur mort |
|---|---|
| On remet la réécriture fautive de P6.7a | `test_le_soignant_ne_REMPLACE_PLUS_le_prescripteur_declare` |
| Le contrôle « ce n'est pas un laboratoire » saute | `test_un_etablissement_qui_n_est_PAS_un_laboratoire_est_refuse` |
| `type_laboratoire` sort de la projection | `test_la_typologie_du_laboratoire_FAIT_diverger_le_referentiel` |

## 2.7 Checklist de clôture

- [ ] **Le prescripteur déclaré est conservé** (§2.4.1) — et l'ordonnance reste réécrite
- [ ] Lien laboratoire vérifié, figé, insensible au renommage (§2.4.2)
- [ ] Pharmacie → **422** (§2.4.3)
- [ ] **Deux vecteurs en miroir** sur la projection (§2.4.4)
- [ ] Analyses réalisées : délai qui prime, doublon refusé, CHU refusé, cloisonnement (§2.4.5)
- [ ] Carnet : références affichées, **aucune conclusion**, grossesse « selon votre situation »,
      mention de démonstration, **silence hors ligne** (§2.3.1)
- [ ] Portail : bloc présent sur un laboratoire, **absent sur un CHU** (§2.3.2)
- [ ] Invariants a→d (§2.5)
- [ ] Suite complète, typecheck ×3, expo-doctor (§2.6)
- [ ] **Limites relues** (§2.2) — dont §7.4 toujours non livré

## 2.8 Pièges rencontrés

**Une mutation qui ne s'applique pas ressemble à un vecteur qui survit.** La première passe a laissé
croire que le vecteur anti-régression résistait : en réalité le remplacement de texte n'avait pas
trouvé sa cible et avait échoué **en silence**. Toute mutation doit être **assertée** avant qu'on en
conclue quoi que ce soit.

**Un test hérité affirmait le comportement fautif.** `test_le_soignant_ne_declare_PLUS_le_prescripteur`
a été **réécrit pour dire la garantie juste**, et non supprimé pour que la suite passe (précédent
P6.4d).

**Les noms figés doivent être `$fillable`.** Le chemin d'écriture des sections du carnet est une
assignation de masse : une colonne absente de `$fillable` est silencieusement perdue, et le service
les posait pour rien. La garantie ne repose donc pas sur `$fillable` mais sur deux couches — les
règles de validation qui ne déclarent pas ces clés, et le service qui les efface — chacune éprouvée
par son vecteur.

**`laboratoire_code` reste nul** tant que l'établissement n'a pas d'`identifiant_national` : le code
figé ne peut pas être meilleur que le référentiel qui le porte.

