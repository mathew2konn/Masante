# Plan G1 — P6.8 Référentiels transverses (CDC_09 §8)

> Étape **8** de l'ordre de construction du §14 : « référentiels transverses : maladies (CIM),
> symptômes (SNOMED), actes et tarifs, découpage sanitaire, spécialités, vaccins, assurances,
> numéros d'urgence ».
>
> Statut : **G1 en attente de validation écrite du propriétaire.**
> Décisions propriétaire déjà prises (2026-08-14) : **D1** périmètre des spécialités · **D2** sort
> des actes et tarifs · **D3** honnêteté sur la CIM · **D4** découpage en cinq incréments.

---

## 1. G0 — ce que le code dit réellement

Audit conduit en lisant les migrations, les seeders, les contrôleurs et les clients — pas les
commentaires, qui se sont révélés faux sur trois points.

### T1 — La spécialité médicale vit à **cinq** endroits, dans **trois** vocabulaires incompatibles

| # | Où | Forme | Exemple réel |
|---|---|---|---|
| 1 | `symptomes.specialite_hint` | libellé d'affichage | `ORL (Oto-Rhino-Laryngologie)`, `Cardiologie / Urgences` |
| 2 | `services_etablissement.specialite` | code snake_case | `orl`, `cardiologie`, `medecine_generale` |
| 3 | `medecins.specialite` | libellé libre | saisi au portail |
| 4 | `apps/mobile/src/api/donSang.ts` | **constante en dur du client** | `don_sang` |
| 5 | `structures_sanitaires.specialites_json` | colonne **morte** (constat J4 de P6.4d) | écrite, lue par personne |

Deux conséquences vérifiées :

- **L'orientation après triage (F1.5) est annoncée dans trois commentaires de migration et ne peut
  pas fonctionner.** `TriageService::deduireSpecialite()` renvoie la forme (1) ; le filtre annuaire
  fait `where('specialite', $valeur)` en **égalité exacte** contre la forme (2). Le mobile se
  contente aujourd'hui d'afficher le libellé — le défaut est donc **latent**, et c'est ce qui le
  rend dangereux : le jour où quelqu'un branche le filtre, il obtient une liste vide, sans erreur.
- **Le vocabulaire est défini par ce qui a été tapé en premier.** `Portail\ServiceController`
  valide `regex:/^[a-z_]+$/` — n'importe quel mot en minuscules passe — et le formulaire propose
  « les codes déjà en base ». Une faute de frappe devient une spécialité permanente d'apparence
  légitime. C'est mot pour mot l'argument de P6.4a pour le découpage sanitaire
  (`Abidjan` / `ABIDJAN` / `Abidjan 1`), et l'avertissement laissé ouvert par P6.4d.

Le cas (4) est une **récidive du constat G-a de P6.4b** : une valeur du domaine recopiée dans le
client, qu'aucun typecheck ne relie à la base. Si un gestionnaire crée son service de collecte sous
le code `donsang`, l'écran « centres de collecte proches » est vide et personne n'est prévenu.

### T2 — Le statut d'une vaccination est **déclaré par le client**

`VaccinationController::regles()` : `statut => required|in:fait,a_faire,en_retard`.
`en_retard` n'est pas une déclaration, c'est un **calcul** (date de rappel + calendrier). Et
`obligatoire => nullable|boolean` fait déclarer par l'utilisateur un **fait de politique nationale**.
`vaccin_nom` est `required|string|max:200`. Aucun calendrier vaccinal n'existe : rien dans ce projet
ne sait qu'un nourrisson de six semaines a un rendez-vous vaccinal.

### T3 — Les maladies : trois vocabulaires libres, dont un **en dur dans un contrôleur**

`Portail\AlerteEpidemiqueController::MALADIES` fige sept libellés qui alimentent un `<select>`,
pendant que `valider()` accepte `maladie => required|string|max:100` — donc **n'importe quoi**.
En face, `symptomes.maladies_probables_json` porte un troisième vocabulaire, qui mêle des maladies
et des syndromes (`Problème cardiaque`, `Détresse respiratoire`). Aucun code CIM nulle part.

### T4 — Le numéro d'urgence existe en **deux copies**, et il n'y en a qu'un seul

`TriageService::NUMERO_SAMU = '185'` d'un côté, `apps/mobile/src/config/constants.ts:9` de l'autre —
les deux se citent mutuellement en commentaire, ce qui documente la duplication sans la supprimer.
Changer le numéro, ou l'adapter à un second pays, exige de **republier l'application** : c'est
exactement l'argument **V1 d'ADR-027** (« la ville est déterminée par le backend »), ici sur un sujet
où une valeur périmée a un coût vital. Le §8 dit « numéros » au pluriel ; ni police ni pompiers.

### T5 — Les assurances n'ont aucune identité

`membres_famille` porte `cmu_numero` / `cmu_statut` / `cmu_validite` et rien d'autre : la CMU est
codée en dur dans les noms de colonnes, et **aucun assureur privé agréé n'est représentable**.
Côté paiement (Java), `CreerFactureRequete.PriseEnChargeRequete` fait **déclarer par l'appelant** le
type, le taux de couverture et le plafond — le moteur les valide (0–100, plafond ≥ 0) mais ne les
confronte à rien.

### T6 — Les actes et tarifs : rien, et aucun consommateur côté Laravel

Aucun catalogue d'actes. La ligne de facture Java est un `libelle` libre avec un `prixUnitaire`
arbitraire. `structures.tarif_min_cfa` / `tarif_max_cfa` et `medecins.tarif_consultation` sont
documentés comme **indicatifs, sans aucune logique de paiement**.

### T7 — Ce qui est **déjà fait**, et qu'il ne faut pas refaire

- **Classifications** : `TypesEtablissement` (13 catégories, P6.4b), `niveau_soins`,
  `statut_juridique`, `forme_juridique` — couverts.
- **Découpage sanitaire** : `regions` + `districts_sanitaires` (P6.4a) + `villes` (P6.4b). Restent
  `commune` et `quartier` en texte libre, et le jeu partiel (1 région sur 33, 5 districts sur 113) —
  qui est de la **donnée**, pas du code (limite M4 d'ADR-026).
- **Symptômes** : la table est **déjà gouvernée** (`symptomes_triage`, P6.3) mais **sans code
  SNOMED**, et ses limites L1/L2 restent ouvertes avec pour foyer **P10**.

### T8 — Une promesse écrite qui désigne ce module

`App\Support\ProfessionsSante` dit noir sur blanc : « `specialite` **reste un libellé libre jusqu'à
l'étape 8 du corpus** (décision propriétaire P4 du plan G1) ». P6.8 est cette étape. Trois
commentaires de migration promettent par ailleurs un « matching triage » qui n'existe pas : ils
devront être corrigés en même temps que le code, comme le commentaire de `referentiels_mesure`
l'a été en L1+L2.

---

## 2. Décisions propriétaire (2026-08-14)

### D1 — Spécialités : le référentiel ici, le matching triage en P10

P6.8a crée le vocabulaire fermé et y rattache `medecins.specialite` **et**
`services_etablissement.specialite`, en **adoptant les codes snake_case déjà en base** comme codes
canoniques — donc le contrat `?specialite=orl` de **P3 (validé G5)** survit sans être touché, et le
`don_sang` du mobile continue de répondre. Le branchement `specialite_hint → services` reste à
**P10**, qui refond déjà le triage : c'est ce qui évite de modifier un module G5 deux fois.

*Cette décision tient les deux décisions passées à la fois* — le report de P6.5 (« à l'étape 8 »)
est honoré, celui de P6.4d (« la vraie table de référence à P10 ») aussi, parce qu'ils ne portaient
pas sur la même chose : l'un sur le **vocabulaire**, l'autre sur le **branchement**.

### D2 — Assurances dans P6.8 ; actes et tarifs sortent avec un porteur nommé

Les assurances ont un consommateur Laravel réel — la carte CMU de `membres_famille` — donc le
référentiel est créé **et branché** ici. Les actes et tarifs n'en ont **aucun** : les créer sans
branchement serait le « socle à vide » que **P6.3-D3** avait explicitement refusé. Ils sortent du
périmètre de P6.8 vers un **incrément de paiement nommé**, pas vers une dette anonyme — la leçon
de L1/L2 étant qu'**une dette sans porteur ne se fait jamais**.

### D3 — CIM : la structure, un jeu de démonstration étiqueté, et on le dit

CIM-11 et CIM-10 sont des publications de l'OMS ; SNOMED CT suppose une licence de membre national.
**Aucun jeu de codes n'existe dans ce projet et je n'en inventerai pas.** La table portera
`code_cim10` / `code_cim11` qui **resteront vides**, et un contenu de démonstration à **source
obligatoire** — motif exact de P6.7a, où `loinc` existe, reste vide, et où le contrôle qualité
refuse de publier une strate sans provenance. Charger la CIM réelle sera de la **donnée, zéro code**.
Tant que ce n'est pas fait, ce n'est pas un référentiel national, et l'écran l'affichera.

### D4 — Cinq incréments, le pivot d'abord

---

## 3. Découpage

| # | Incrément | Ce qu'il referme |
|---|---|---|
| **a** | **Spécialités médicales** | T1, T8 — cinq vocabulaires → un ; ferme la porte du portail |
| **b** | **Vaccins + calendrier vaccinal national** | T2 — `statut` et `obligatoire` cessent d'être déclarés |
| **c** | **Maladies (CIM)** | T3 — la liste en dur du contrôleur, les trois vocabulaires |
| **d** | **Assurances et organismes agréés** | T5 — la CMU cesse d'être codée dans des noms de colonnes |
| **e** | **Numéros d'urgence + compléments du découpage** | T4 — une seule source, plusieurs numéros, multi-pays |

Chaque incrément a son G2/G3/G4/G5 et **sa partie** du guide `GUIDE_TEST_TRANSVERSES.md`
(règle propriétaire : un domaine à incréments successifs ajoute une partie, pas un fichier).

---

## 4. Conception de P6.8a — Spécialités médicales

### 4.1 Le point de conception

Une spécialité n'est pas un libellé : c'est **le pivot entre trois questions que le système pose
déjà séparément** — « de quoi souffre ce patient ? » (triage), « que sait faire cet établissement ? »
(annuaire), « qu'exerce ce praticien ? » (professionnels). Tant que les trois répondent dans des
vocabulaires différents, aucune ne peut être rapprochée d'une autre, et c'est pourquoi F1.5 est
restée une intention pendant quatre modules.

### 4.2 Les codes sont **adoptés**, jamais réinventés

`services_etablissement.specialite` contient déjà `orl`, `cardiologie`, `medecine_generale`,
`pediatrie`, `gastro_enterologie`, `gynecologie`, `dentisterie`, `urgences`, `pharmacie`,
`biologie`, `don_sang`. Ces valeurs **sont** le vocabulaire de fait : les inventorier et les
promouvoir en codes canoniques rend la bascule **additive** (ADR-024) et laisse intacts le contrat
`?specialite=` de P3 et la constante `don_sang` du mobile — qui deviendra une valeur **vérifiée**
au lieu d'une valeur **espérée**. Inventer de nouveaux codes aurait cassé les deux.

### 4.3 Ce qui entre dans la projection gouvernée

La question est reposée, pas recopiée (méthode P6.6a) : **qu'est-ce qui engage une autorité ?**
Le code, le libellé officiel et la profession de rattachement — ce sont eux qu'un ministère
reconnaît. En sont exclus les compteurs et tout ce qui se recalcule.

**Conséquence assumée et dite avant de coder** : `specialite` figure **déjà** dans la projection du
référentiel des professionnels (`SourceProfessionnels`, ligne 89). Rattacher `medecins.specialite`
au vocabulaire fera **changer l'empreinte** de ce référentiel. Ce n'est pas une dérive — c'est le
même cas que `forme_juridique` en P6.4d, et un vecteur en miroir le prouvera.

### 4.4 Les gardes

- **Permission `specialite.referentiel`, portée par aucun rôle métier** — 8ᵉ application du
  précédent. Un établissement ne peut pas décider seul de la liste nationale des spécialités : il
  serait juge et partie sur ce qu'il déclare savoir faire.
- **`Portail\ServiceController` cesse d'accepter `regex:/^[a-z_]+$/`** et valide contre le
  référentiel. C'est le passage de la détection à l'**interdiction**, exactement comme le contrôle
  région/district de P6.4d : au formulaire, l'agent a encore l'information sous les yeux.
- **Rien n'est supprimé.** `specialites_json` (colonne morte J4) est **conservée** — une migration
  destructive perdrait de l'information réelle pour un gain nul (précédent P6.4d-K2).

### 4.5 Vecteurs en miroir exigés

1. Renommer le **libellé citoyen** d'une spécialité → l'empreinte du référentiel des spécialités
   **change** (c'est une donnée gouvernée).
2. Déposer un **avis** sur un établissement → l'empreinte **ne change pas** (rappel du critère
   d'ADR-026, rejoué ici parce que la projection touche `medecins`).
3. Un code hors référentiel envoyé au portail → **refusé**, avec le message nommant les codes admis.
4. `?specialite=orl` et `?specialite=don_sang` → **inchangés** (le contrat P3/G5 survit).

---

## 5. Preuves attendues (P6.8a)

- **G3** — vecteurs dédiés écrits dans les deux sens ; suite complète verte ; typecheck ×3 ;
  `expo-doctor` ; **mutation obligatoire** : neutraliser chaque garde doit tuer exactement son
  vecteur. *Rappel du piège de P6.7b : une mutation qui ne s'applique pas ressemble exactement à un
  vecteur qui survit — toute mutation sera assertée avant d'être interprétée.*
- **G2 live MySQL** — schéma et contraintes en base ; backfill dry-run → réel → rejeu ; doublon
  refusé par le moteur ; gouvernance à deux agents habilités ; `UPDATE` direct sans effet sur le
  diffusé ; portail 403/200 ; les quatre vecteurs du §4.5 ; **base restaurée compte par compte**.
- **G4** — guide `GUIDE_TEST_TRANSVERSES.md` partie 1, écrit avant le G4.

---

## 6. Limites qui seront annoncées

1. **Le matching triage n'est pas branché** (D1) — `specialite_hint` reste un libellé libre jusqu'à
   **P10**. Le défaut T1 sera **outillé, pas refermé**, et le porteur est nommé.
2. **Actes et tarifs hors périmètre** (D2), porteur = incrément de paiement nommé.
3. **Aucun code SNOMED / CIM** avant P6.8c, et vide même ensuite (D3).
4. **Contenu = jeu de démonstration** tant que la nomenclature officielle n'est pas chargée.
5. **L1/L2 d'ADR-025 s'appliquent** : P3 et P4 lisent les tables en direct.
6. Aucun écran mobile de gouvernance — comme tous les référentiels depuis P6.3.
