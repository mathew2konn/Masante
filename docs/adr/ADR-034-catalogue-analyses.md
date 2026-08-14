# ADR-034 — Catalogue national des analyses et valeurs de référence (P6.7a)

**Statut : Accepté** — 2026-08-14 · Contexte : CDC_09 §7.3 · Applique [ADR-024](ADR-024-referentiels-nationaux.md) et [ADR-025](ADR-025-socle-referentiel.md) · Suit [ADR-033](ADR-033-referentiel-medicaments.md).

---

## 1. Contexte

Étape **7** de l'ordre CDC_09 §14. Le G0 a établi quatre faits.

**Un résultat d'analyse ne désignait rien de normalisé.** `resultats_json` était validé en
`nullable|array`, **sans aucune structure** : le mobile y envoyait des couples `{parametre, valeur}`
en texte libre. Ni unité, ni référence, ni milieu prélevé. Deux laboratoires pouvaient rendre la même
analyse sous deux noms et deux unités — l'inverse exact de ce que §7.3 demande.

Troisième instance de la même famille de défauts, après `ordonnances.medecin_nom` (P6.5) et
`medicaments_json.*.nom` (P6.6).

**P6.5 avait refermé UNE porte du prescripteur ; il y en avait DEUX.** `EcritureSoignantService`
teste la clé `medecin_nom`. `resultats_analyses` porte `medecin_prescripteur` — un autre nom pour la
même chose — et cette section **est ouverte au soignant**. Un résultat consigné par un soignant
pouvait donc encore nommer n'importe quel prescripteur.

**`laboratoire` est un texte libre** alors que le référentiel des établissements porte le type
`laboratoire` depuis P6.4a. Traité en P6.7b.

**§7.4 (traçabilité des prélèvements) : rien.** Aucun identifiant, aucune des huit étapes.

---

## 2. Décisions du propriétaire

| # | Décision |
|---|---|
| **1** | **§7.4 est un module séparé** — c'est un workflow, pas un référentiel, et il suppose la *prescription biologique*, entité qui n'existe pas. |
| **2** | **Les valeurs de référence sont AFFICHÉES, jamais conclusives**, et **stratifiées** dès maintenant. Le jeu livré est un jeu de démonstration honnêtement étiqueté, remplaçable sans migration. |
| **3** | **P6.7 referme la seconde porte du prescripteur.** |

---

## 3. Le point de conception : pourquoi des strates

Une plage biologique **dépend de la personne**. Le même **11 g/dL** d'hémoglobine est bas chez
l'homme adulte, **normal** chez la femme enceinte, **normal** chez l'enfant de deux ans.

Une colonne `reference_min` / `reference_max` unique aurait donc dit à une patiente que son résultat
est anormal alors qu'il est normal pour elle — avec l'autorité d'une machine, dans un carnet de
santé. D'où `analyse_references` : une ligne par strate (sexe × tranche d'âge × état physiologique).

**C'est cette structure qui rend le remplacement possible sans migration** le jour où un référentiel
biologique réel sera chargé.

### 3.1 La plateforme ne conclut pas, et les strates conditionnelles sont ajoutées sans être choisies

`ReglesIntervalleReference` est une **classe pure** : elle sélectionne les strates dont les critères
démographiques correspondent, et **ne compare jamais un résultat à une plage**.

Une femme adulte reçoit sa strate standard **et** les strates de grossesse, marquées
`conditionnelle`. Le carnet connaît pourtant la grossesse — la tentation de choisir pour elle est
donc réelle. On s'en abstient : c'est le motif des **trois silences** de P7-D2, où une information
annoncée pour ce qu'elle est vaut mieux qu'un choix fait à la place de quelqu'un.

De même, un **âge inconnu** écarte les strates bornées en âge et un **sexe inconnu** ne garde que les
strates communes — et la réponse **dit ce qui manque** plutôt que de le deviner.

### 3.2 « Glycémie » n'est pas une analyse

Le milieu prélevé et la méthode font partie de l'**identité** : glycémie à jeun sur plasma veineux et
glycémie capillaire sont deux entrées, avec des références différentes. C'est l'un des six axes de
LOINC, que CDC_09 §9.1 recommande. Les fondre reproduirait l'incohérence que §7.3 combat.

---

## 4. L'honnêteté sur les valeurs livrées

**8 analyses, 14 strates, toutes portant `source = 'demonstration'`.** Ces valeurs sont des ordres de
grandeur usuels. Elles ne sont **ni validées cliniquement**, **ni attribuées** à une autorité, **ni
établies sur la population ivoirienne**.

Le choix de ne les attribuer à personne est délibéré : *un intervalle inventé qui porterait le nom
d'une autorité serait pire qu'un intervalle inventé qui l'avoue.*

**La source est OBLIGATOIRE en base**, et le contrôle qualité refuse de publier un catalogue qui
contient une strate anonyme — même règle qu'en P6.6a pour les interactions. L'écran du portail
affiche le **compte exact** des strates encore issues de la démonstration : c'est le témoin visible
du remplacement.

**Le cas des neutrophiles est inclus délibérément.** Leur taux usuel est plus bas dans plusieurs
populations d'Afrique subsaharienne, au point qu'un intervalle établi ailleurs classe « anormaux »
des sujets sains. C'est l'argument qui rend la stratification nécessaire **maintenant** : la
structure est prête à recevoir des intervalles ivoiriens.

**`loinc` existe et reste vide.** Le jeu LOINC n'est pas en notre possession ; inventer des codes qui
auraient l'air vrais serait pire que de laisser la colonne nulle et de le dire.

---

## 5. Les gardes du moteur

**Une borne basse supérieure à la borne haute est refusée par la base.** Le contrôle qualité ne joue
qu'à la publication : une strate incohérente pourrait vivre des semaines dans le contenu de travail
et s'afficher entre-temps à côté d'un résultat réel. *Une garantie qui ne tient qu'au chemin
applicatif n'en est pas une* — leçon du G2 de P6.6a.

Un `CHECK` était impossible : `analyse_id` est `cascadeOnDelete`, donc soumise à une action
référentielle — **erreur 3823**, le mur exact de P6.3. D'où des triggers dans les deux dialectes,
recours que CDC_04 §139 prévoit. Prouvé live : `ERROR 1644 ck_analyse_reference_bornes`.

**Une permission neuve, `analyse.referentiel`, portée par aucun rôle** — septième occurrence du
précédent `urgence.bris_de_glace`. La raison est ici plus nette qu'ailleurs : **un laboratoire ne
peut pas fixer les valeurs de référence nationales**, il serait juge et partie sur les résultats
qu'il rend lui-même.

---

## 6. Vérification par mutation

Trois gardes neutralisées une à une, chacune tuant exactement le vecteur qui porte la décision
correspondante :

| Mutation | Vecteur mort |
|---|---|
| La résolution ne renvoie qu'une strate | `test_la_resolution_renvoie_TOUTES_les_strates_applicables` |
| La réécriture du prescripteur est retirée | `test_le_soignant_ne_declare_PLUS_le_prescripteur` |
| La source n'est plus obligatoire | `test_une_strate_sans_source_empeche_la_publication` |

---

## 7. Limites

1. **§7.4 non livré** : aucun identifiant de prélèvement, aucune des huit étapes. Module séparé.
2. **Les intervalles sont un jeu de démonstration** (§4), non validés et non attribués.
3. **Aucun code LOINC.**
4. **Le serveur ne conclut jamais** — pas de statut sur un résultat de laboratoire.
5. **Le référentiel des laboratoires est en P6.7b** : `resultats_analyses.laboratoire` reste du
   texte libre.
6. **Aucun écran citoyen n'affiche les références.** L'API les sert, le portail les montre à
   l'autorité, mais le carnet n'a pas d'écran de détail d'un résultat. Rattaché à **P6.7b**, qui
   touche déjà cet écran pour le lien laboratoire — un foyer réel, pas un report.
7. **La grossesse n'est pas lue** pour choisir une strate (§3.1).
8. **Intervalles ivoiriens absents** — la structure les attend, les valeurs livrées ne le sont pas.
