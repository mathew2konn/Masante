# ADR-037 — Référentiel national des maladies (CDC_09 §8)

- **Statut** : accepté — P6.8c validé G5 le 2026-08-15
- **Contexte** : étape 8 de l'ordre de construction du §14, troisième incrément de P6.8
- **Décisions propriétaire** : E1 périmètre · E2 une maladie n'appartient à aucun pays ·
  E3 libellés multilingues livrés · E4 l'alerte garde une porte ouverte
- **Remplace** : rien. **Complète** : ADR-024 (enrichissement additif), ADR-025 (socle référentiel),
  ADR-033/034/035/036 (les référentiels précédents dont les critères sont ici *reposés*, pas recopiés)

---

## 1. Le problème

La maladie était nommée à **cinq** endroits du projet, dans autant de vocabulaires libres — le plan
de P6.8 en annonçait trois :

| # | Où | Forme | Ce que ça coûtait |
|---|---|---|---|
| 1 | `AlerteEpidemiqueController::MALADIES` | 7 libellés **en dur** dans un `<datalist>` | le menu **ressemblait** à une contrainte : la validation acceptait `required\|string\|max:100`, et le code l'avouait (« champ libre malgré tout ») |
| 2 | `alertes_epidemiques.maladie` | texte libre | affiché **brut** dans la bannière du mobile ; « combien d'alertes de choléra cette année ? » insoluble |
| 3 | `symptomes.maladies_probables_json` | libellés mêlant maladies, syndromes et un état physiologique | **lu par personne**, sauf l'instantané publié du référentiel `symptomes_triage` |
| 4 | `antecedents.description` | texte libre **chiffré** | c'est cette chaîne que la fiche vitale montre à un secouriste **sans authentification** |
| 5 | `vaccins.maladies_evitees` | texte | une **promesse écrite** en P6.8b désignant cet incrément |

Le constat 3 mérite d'être isolé : *le seul endroit où ces libellés sortent du serveur est celui qui
leur donne le plus d'autorité* — une version publiée, scellée par une chaîne d'audit, présentée comme
référentiel national. C'est la quatrième colonne dormante du projet, et la seule qui soit **publiée**.

---

## 2. Décision E2 — une maladie n'appartient à aucun pays

**Première rupture assumée** avec `ETS` (P6.4a), `PRO` (P6.5a), `MED` (P6.6a), `ANA` (P6.7a) et
`VAC` (P6.8b), qui portent tous `UNIQUE(pays_code, code)`.

Ces cinq-là numérotent des objets **nationaux** : cet hôpital-ci, ce praticien-là, ce produit
autorisé ici. Le paludisme est le paludisme partout — c'est la raison d'être même d'une
classification internationale. Écrire `pays_code` sur une maladie affirmerait **dans le schéma** que
le paludisme ivoirien diffère du paludisme sénégalais.

**Ce qui est national, c'est la liste sous surveillance**, pas la maladie : les maladies à
déclaration obligatoire diffèrent d'un pays à l'autre. D'où `maladie_surveillance`, portée par pays,
et publiée dans le **même instantané** que les maladies — motif des interactions de P6.6a, des
strates de P6.7a et des échéances de P6.8b : les publier séparément laisserait une surveillance
désigner une maladie absente de la version en vigueur, donc une référence irrésoluble.

`declaration_obligatoire` et `surveillance_prioritaire` sont **deux faits distincts** : une maladie
peut être suivie de près sans être à déclaration obligatoire, et l'inverse existe. Les fondre en une
colonne aurait fait dire au référentiel quelque chose de faux.

**Conséquence** : la séquence des codes est globale (`maladie_compteurs` a une ligne unique), et le
contrôle qualité de doublon ne met **pas** le pays dans sa clé — il doit être aussi strict que
l'index `uq_maladie_code`, ni plus ni moins (leçon inverse du G2 de P6.5a).

---

## 3. `MAL` + 6, et le code CIM n'est pas ce code-là

Le critère posé en P6.8b — « instance → numéro ; terme de nomenclature → code littéral » —
plaiderait pour `fievre_typhoide`, comme `orl` en P6.8a. **Il ne s'applique pas ici**, et la raison
est propre à ce référentiel :

> **La CIM occupera la place du code littéral** le jour où elle sera chargée.

Fabriquer `fievre_typhoide` créerait un pseudo-code qui **ressemble** à un code de nomenclature et
devrait ensuite cohabiter avec `A01.0` — deux codes littéraux concurrents pour la même chose. Et
contrairement à P6.8a, il n'y a **rien à adopter** : les valeurs en base sont des phrases accentuées
(« Fièvre typhoïde »), pas des codes.

Donc : `code` = identifiant de **ligne** (hors `$fillable`) ; `code_cim10` / `code_cim11` = la
**nomenclature**, et ils resteront **vides**. Le contrôle qualité n'en exige **aucun** — *un contrôle
qu'on ne peut pas satisfaire n'est pas une exigence, c'est un mur* — mais l'absence est **comptée et
affichée** (3ᵉ application du motif `analyses.loinc` de P6.7a).

---

## 4. Décision E3 — le libellé officiel vit sur la ligne, et nulle part ailleurs

Le §8 exige des « libellés multilingues ». `maladies.libelle` porte le libellé officiel **français** ;
`maladie_libelles` porte les **autres langues** et les **synonymes de recherche** (« palu »).

**Le schéma rend la seconde vérité inexprimable plutôt que simplement interdite** : aucune colonne
`type` n'existe dans la table des alternatifs, donc rien ne peut y prétendre à l'officialité en
concurrence de la ligne. Un déclencheur ferme le dernier chemin — recopier la chaîne à l'identique.

Conséquence tenue par le contrôle qualité : une ligne en **langue pivot** est forcément un synonyme,
donc **jamais** `principal` — l'afficher reviendrait à montrer le surnom à la place du nom.

**Limite annoncée, pas déguisée** : « exactement un principal par langue » est un contrôle
**applicatif**. MySQL 8 n'a pas d'index unique partiel, et l'émuler coûterait plus qu'il ne
garantirait (précédent du quota d'images de P6.4c).

---

## 5. Décision E4 — l'alerte épidémique garde une porte ouverte

Le lien est **facultatif**, le libellé libre reste possible, et **l'écart est compté et affiché**.

La différence avec les spécialités (P6.8a, où la porte a été fermée) n'est pas le principe mais la
**conséquence d'un refus** : un service refusé est ressaisi dix secondes plus tard ; une alerte
sanitaire refusée pendant qu'on convoque deux agents habilités n'est envoyée à personne. Et *une
maladie émergente n'est dans aucune nomenclature au moment où elle émerge* — en décembre 2019 la
bonne alerte s'appelait « pneumonie atypique d'origine inconnue ».

Imposer un référentiel dont on dit soi-même qu'il est un jeu de démonstration ferait payer ses
lacunes à une alerte urgente (argument de P6.6b, transposé sur un sujet où le refus a un coût de
santé publique). **Ce qu'on ne peut pas fermer, on le rend visible.**

Quand un lien est fourni, en revanche, le serveur **ne croit rien du client** : le libellé est repris
du référentiel — c'est lui que le mobile affiche, et garder deux noms dans la même ligne laisserait
le lecteur choisir lequel croire (motif P6.7b).

---

## 6. Le consommateur clinique : `antecedents`

Deux garanties, et la seconde est la leçon d'une erreur passée :

1. **Le serveur ne devine jamais.** Aucun rapprochement automatique entre « diabète » et une entrée
   du référentiel : ce serait un **diagnostic posé par une machine** (CDC_00 §4). Le lien est
   *déclaré* par l'humain qui saisit. Vecteur dédié : une description **identique** au libellé
   officiel ne produit **aucun** rattachement.
2. **`description` n'est jamais réécrite.** C'est la correction de P6.7b appliquée par avance : là,
   la réécriture du prescripteur inscrivait le nom du **mauvais** médecin, et *une affirmation fausse
   portée par le système est plus difficile à contester qu'une saisie humaine non vérifiée*. Le lien
   s'ajoute **à côté** des mots du patient.

La fiche vitale joint le couple `{code, libellé}` sans remplacer quoi que ce soit. *Un champ de plus
sur un écran lu sans authentification se justifie ici parce qu'il ne révèle rien de neuf* : c'est la
normalisation de ce qui s'affiche déjà.

Résolution via `preparerDonnees()` sur les **trois chemins d'écriture** et sur le `PUT` — le défaut
trouvé en passant en P6.8b.

---

## 7. La permission `maladie.referentiel`

**Dixième occurrence** du précédent posé par `urgence.bris_de_glace` : portée par **aucun rôle
métier**. `sante_publique.manage` existe déjà et sert à **publier les alertes** ; l'étendre au
vocabulaire ferait de **l'auteur d'une alerte celui qui décide de ce qu'est une maladie** — et de la
liste de ce que le pays surveille.

*À dire honnêtement, comme depuis P6.3* : `admin_ivoirsante` la reçoit quand même, par le
`syncPermissions(Permission::all())` du seeder. Le filtrage réel se joue à l'attribution nominative.

---

## 8. Le défaut trouvé par le G2 live, et que les tests ne pouvaient pas voir

Le déclencheur comparait le libellé alternatif au libellé officiel avec un `=` simple — donc avec la
**collation** de la colonne, **insensible à la casse et aux accents** sous MySQL 8. « Cholera »
(anglais) et « Choléra » (français) y étaient **égaux** : le seeder de démonstration s'est arrêté sur
`ERROR 1644` en enregistrant un libellé anglais parfaitement légitime.

SQLite compare octet à octet : **la suite de tests n'a rien vu.** *Un garde-fou plus strict que sa
propre règle est un défaut, même quand il refuse « par excès de prudence »* — celui-ci aurait rendu
le multilingue du §8 inutilisable pour toute langue proche du français.

Correctif : `CAST(... AS BINARY)`. La règle écrite est « recopier la chaîne **à l'identique** », et
c'est exactement ce que le moteur vérifie. Le quasi-doublon (« paludisme » sous « Paludisme ») reste
attrapé par le **contrôle qualité** à la publication : deux gardes, deux publics, aucune ne rattrape
l'autre.

**Divergence connue et assumée** : `uq_maladie_libelle` hérite de la même collation, donc MySQL
refuserait deux maladies dont les libellés ne diffèrent que par un accent, là où SQLite les
accepterait. C'est plus strict en production qu'en test, et le cas est implausible — mais il est
écrit ici plutôt que découvert.

---

## 9. Ce qui n'est délibérément pas fait

- **Pas de colonne `categorie`** : aucun consommateur n'en a besoin, et classer une maladie est une
  affirmation clinique non sourcée. Le seul regroupement réel est porté par la surveillance. Ajouter
  une classification que personne ne lit serait le socle à vide refusé en P6.3-D3.
- **Pas de compteur** : la projection prend la ligne entière, et elle ne peut le rester que si rien
  n'écrit automatiquement dans la table (précaution de P6.8a, née du `note_moyenne` de P6.4a).
- **`symptomes.maladies_probables_json` n'est pas rattaché** : le triage est refondu en **P10**, et
  cette colonne n'a **aucun lecteur** — la rattacher aujourd'hui serait un socle à vide. Porteur
  nommé, et un commentaire de `SourceSymptomesTriage` le dit.
- **`alertes_epidemiques` n'entre pas sous gouvernance** : c'est un journal d'événements, pas un
  référentiel. Seul son **vocabulaire** est gouverné.

---

## 10. Limites

1. **Aucun code CIM**, et aucun n'a été inventé. Les charger sera de la **donnée, zéro code** — et
   tant que ce n'est pas fait, **ce n'est pas un référentiel national**. L'écran affiche le compte.
2. **Contenu = jeu de démonstration** (21 maladies), `source='demonstration'` sur chaque ligne,
   jamais attribué à une autorité.
3. **Aucun code SNOMED CT** (§8 le demande pour les symptômes) : licence de membre national requise.
4. **Aucun libellé en langue nationale ivoirienne** : je ne connais pas les dénominations en dioula
   ou en baoulé et je n'en invente pas. La structure les accueille.
5. **L1/L2 d'ADR-025 s'appliquent** aux lignes antérieures : rien n'est réécrit rétroactivement —
   *leur inventer un code serait un mensonge d'archive* (précédent L2).
6. « Un principal par langue » est **applicatif**, pas garanti par le moteur (§4).
