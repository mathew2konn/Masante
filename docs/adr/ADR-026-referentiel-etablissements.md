# ADR-026 — Référentiel des établissements : gouverner une projection, pas la table (P6.4a)

**Statut : Accepté** — 2026-08-13 · Contexte : CDC_09 §4, §1.2, §8 · CDC_11 §3 · Applique [ADR-024](ADR-024-referentiels-nationaux.md) · Étend [ADR-025](ADR-025-socle-referentiel.md).

---

## 1. Contexte

Étape **4** de l'ordre CDC_09 §14. `structures_sanitaires` est la table la plus référencée du projet après `membres_famille` — **40 fichiers**, dont P3 (annuaire, carte) et P4 (rendez-vous), tous deux **validés G5**.

Le G0 a établi :

- **l'écart au corpus est massif** : le formulaire d'administration collecte **11 champs**, CDC_09 §4.2 et CDC_11 §3.1 en exigent environ **25**. Ni identifiant national, ni région, ni district sanitaire, ni statut juridique, ni niveau de soins, ni information légale ;
- **`type` mélange deux axes que le CDC sépare** : CDC_11 §3.1 distingue le *type* (public/privé/universitaire/militaire) de la *catégorie* (Hôpital, Clinique, Laboratoire…). L'ENUM existant est une catégorie, et `clinique_privee` fusionne les deux à elle seule ;
- **le périmètre §4.1 est plus large que l'ENUM** : manquent hôpitaux généraux, centres de santé urbains/ruraux, centres d'imagerie, de dialyse et de vaccination ;
- **l'onboarding « Méthode 2 » n'existe pas**, alors que CDC_11 §3 affirme que « les deux méthodes sont implémentées » ;
- **bonne nouvelle vérifiée** : le contrat de lecture sérialise le modèle Eloquent entier, et `@masante/shared` ne porte aucun type de structure — l'enrichissement est donc **automatiquement additif**.

---

## 2. Décision principale : le référentiel gouverne une **projection d'identité administrative**

`structures_sanitaires` mélange deux natures de données que le socle P6.3 ne peut pas traiter de la même façon :

| Nature | Exemples | Rythme |
|---|---|---|
| **Identité administrative** | identifiant national, nom officiel, catégorie, statut juridique, niveau de soins, district, agréments, autorité de tutelle | rare, **sur décision d'une autorité** |
| **État opérationnel** | `note_moyenne`, `nb_avis`, horaires, tarifs, téléphone, GPS, disponibilités | continu, souvent **automatique** |

`note_moyenne` et `nb_avis` sont recalculés par `NoteStructureService` **à chaque avis déposé par un citoyen**. Versionner la table entière rendrait l'instantané **divergent en permanence** : l'anti-substitution d'ADR-025 refuserait toute publication dès qu'un avis arrive, et la commande de contrôle signalerait « DIVERGENTE » sans discontinuer.

Le mécanisme serait alors non seulement inexploitable, mais **mensonger** — il affirmerait qu'une note d'étoiles est une donnée de référence nationale.

**Retenu** : une `SourceEtablissements` qui n'extrait que l'identité administrative. Sont **exclus** téléphone, e-mail, site web, GPS, adresse, horaires, tarifs, spécialités, description, note et nombre d'avis. Ces données sont réelles et utiles — elles servent la carte et la fiche de P3 — mais **elles n'engagent aucune autorité** : soumettre la correction d'un numéro de téléphone à un cycle de proposition et de double validation serait absurde.

La projection répond à la question du §4.4 : « *quels établissements existent, avec quel statut, quel niveau de soins et dans quel district ?* » — la matière des statistiques nationales et de la planification sanitaire. Pas « lequel est ouvert cet après-midi ».

**Deux vecteurs de test en miroir la prouvent**, et aucun ne suffit seul : un avis déposé **ne doit pas** faire diverger le référentiel ; un changement de statut juridique **doit** le faire. Une projection insensible à tout ne servirait à rien.

> Cette décision **répond par anticipation à la limite L6 d'ADR-025** (« l'instantané JSON convient à des référentiels de règles ; sa pertinence pour un référentiel volumineux sera réexaminée en P6.6 »). La question s'est présentée dès P6.4, et la réponse n'est pas « l'instantané ne convient pas » mais « **il ne faut pas y mettre l'état opérationnel** ».

---

## 3. Décisions secondaires

### 3.1 Identifiant national : `ETS` + 6 chiffres, littéral, sans clé *(G1 D2)*

L'exemple imposé du §4.3 est `ETS000152`. **§3.2 impose explicitement un checksum pour le NIS et ne le fait pas ici** ; ajouter une clé de contrôle rendrait l'exemple du corpus **invalide**. Le risque qu'une clé traite — la faute de frappe d'un citoyen saisissant son identifiant de tête — n'existe pas pour un établissement, qui est toujours choisi dans une liste.

**Le pays qualifie l'identifiant, il ne s'écrit pas dedans** : l'unicité porte sur `(pays_code, identifiant_national)`. Deux pays peuvent tous deux avoir un `ETS000152`, et c'est cohérent — l'identifiant est *national*, pas mondial. C'est la différence avec le NIS, qui devait discriminer les pays **dans** sa valeur parce qu'un patient traverse les frontières.

**Pas de journal de non-réutilisation**, contrairement au NIS : §3.2 l'exige pour une personne, le corpus ne demande rien de tel pour un établissement. Une table de plus pour une garantie que personne ne réclame serait de la symétrie décorative.

`identifiant_national` et `pays_code` sont **hors `$fillable`** : les laisser assignables en masse permettrait à un client de choisir son propre numéro national.

### 3.2 `type` reste la catégorie, `statut_juridique` prend sa colonne *(G1 D3)*

`type` est **de facto** la catégorie — c'est ce que filtrent P3 et P4. On l'assume, on **élargit son ENUM** aux six valeurs manquantes du §4.1, et le statut juridique prend sa propre colonne. `clinique_privee` est **conservée telle quelle** : elle mélange les deux axes, mais la renommer casserait des données et des filtres prouvés pour obtenir le même contenu sous un autre nom.

### 3.3 Le découpage sanitaire est une donnée de référence, jamais un texte libre

Tables `regions` et `districts_sanitaires`, rattachement par FK. §1.2.4 : « aucune donnée de référence saisie librement dans un module métier ». Une région saisie à la main deviendrait « Abidjan », « ABIDJAN » et « Abidjan 1 » en trois semaines, et aucune statistique nationale ne serait plus possible — or c'est l'usage même que §4.4 assigne à ce référentiel.

Le contrôle qualité vérifie que **le district déclaré appartient bien à la région déclarée**. C'est l'anomalie la plus sournoise du lot : les deux références sont valides prises séparément, seule leur **combinaison** est fausse, et une statistique par région la propagerait sans que rien ne la signale.

### 3.4 Tout est nullable, et l'absence se dit plutôt qu'elle ne se comble

La base porte 12 structures dépourvues de ces informations. Une colonne `NOT NULL` casserait la migration ; une valeur par défaut inventée serait **pire** — « statut juridique : public » sur une clinique privée est une donnée *fausse*, pas une donnée *manquante*. Les contrôles qualité signalent l'absence ; ils ne la comblent pas.

---

## 4. Conséquences et limites

**Acquis.** Les établissements ont un identifiant national, un rattachement au découpage sanitaire, une identité administrative complète au sens du §4.2, et un cycle de gouvernance qui ne se déclenche que sur ce qui engage une autorité.

**Limites assumées, reportées dans le guide.**

- **M1 — L'onboarding « Méthode 2 » (CDC_11 §3) n'est pas livré** *(G1 D4)*. C'est un parcours applicatif — formulaire public, vérification, publication, notifications — qui relève de CDC_11 et du **portail Next** ; or ADR-011 programme déjà la migration du portail Blade vers Next. La construire deux fois serait du gaspillage. **Foyer désigné : la migration du portail vers Next.** CDC_11 §3 affirme que les deux méthodes sont implémentées : tant que M1 tient, **cette affirmation est fausse dans ce projet**, et c'est écrit plutôt que tu.
- **M2 — Le découpage sanitaire seedé est un JEU PARTIEL** : la Côte d'Ivoire compte **33 régions et 113 districts**. Le seeder pose une région et cinq districts, ceux qui couvrent les 12 structures d'Abidjan. Il **n'essaie délibérément pas** de reproduire la répartition réelle d'Abidjan entre ses deux régions sanitaires (« Abidjan 1 – Grands Ponts » et « Abidjan 2 »), faute de disposer de l'arrêté qui la fixe : **une liste inventée qui a l'air juste est plus dangereuse qu'une liste manifestement incomplète — elle ne se fait pas corriger.** Charger le découpage officiel sera un **chargement de données, zéro ligne de code**.
- **M3 — Aucun écran** : P6.4a est le backend. Les formulaires du portail restent sur les 11 champs d'origine ; les colonnes neuves se remplissent par la base ou par API. **P6.4d** les alignera (le découpage a été précisé après coup : **b** villes et géolocalisation, **c** images, **d** formulaires).
- **M4 — Ni images ni logo**, ni niveau de soins par service, ni PKI (P6.5). Les images demandent du **stockage de fichiers**, pas une colonne : c'est un incrément à part. **Le propriétaire a décidé le 2026-08-13 de les ajouter** — foyer désigné, plus une dette sans porteur.
- **M6 — Deux champs de CDC_11 §3.1 ne sont couverts par rien** (constaté après le G2, consigné plutôt que tu) :
  - **`ville`** — le CDC liste « adresse complète, pays, **ville**, commune, quartier ». `quartier` a été ajouté, `commune` existait, **`ville` n'existait pas**. À Abidjan la commune tient lieu de ville, ce qui masque le manque ; hors d'Abidjan il apparaît immédiatement. Devenu **structurant** depuis la demande du propriétaire du 2026-08-13 (Abidjan + Yamoussoukro + Bouaké, affichage différencié). **✅ Traité en P6.4b** — et **mieux qu'une colonne de texte** : une table `villes` et une clé étrangère `ville_id`, conformément au §1.2.4 (« aucune donnée de référence saisie librement »). Voir [ADR-027](ADR-027-villes-geolocalisation.md).
  - **La forme juridique** (SARL, SA, association, EPN…) — CDC_11 §3.1 liste un « **statut** » dans le bloc légal, **à côté** du type public/privé/universitaire/militaire. **C'est le plus discutable des choix de P6.4a** : la lecture du corpus est ambiguë, `statut_juridique` a été mappé sur l'axe public/privé du §4.2, et rien n'a été posé pour la forme juridique — sans que cette interprétation soit énoncée sur le moment. Elle l'est ici. **Colonne `forme_juridique` à ajouter en P6.4d** (l'incrément qui touche les formulaires du portail ; P6.4b ne les touche pas).

  Ces deux champs n'ont pas été ajoutés à chaud : les poser après coup aurait imposé de rejouer tout le G2 pour deux colonnes, alors que **P6.4b touche les formulaires de toute façon**.
- **M5 — L1/L2 d'ADR-025 s'appliquent ici aussi** : `StructureService` (P3) lit la table en direct, pas la version publiée. La diffusion du référentiel sert la nouvelle API. Voir [ADR-025 §5](ADR-025-socle-referentiel.md).

**Aucune dépendance nouvelle.**
