# ADR-034 — Catalogue des analyses, laboratoires et valeurs de référence (P6.7)

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

**`medecin_prescripteur` est déclaré par le client**, y compris quand un soignant consigne le
résultat — la section est ouverte au soignant.

> ⚠️ **CE CONSTAT A ÉTÉ MAL INTERPRÉTÉ EN P6.7a**, qui y a vu « une seconde porte » symétrique de
> `ordonnances.medecin_nom` et a réécrit le champ. C'était faux, et le §8.1 explique pourquoi : celui
> qui consigne un résultat n'est pas forcément celui qui l'a prescrit. La réécriture a été retirée en
> P6.7b et remplacée par un lien vérifié.

**`laboratoire` est un texte libre** alors que le référentiel des établissements porte le type
`laboratoire` depuis P6.4a. Traité en P6.7b.

**§7.4 (traçabilité des prélèvements) : rien.** Aucun identifiant, aucune des huit étapes.

---

## 2. Décisions du propriétaire

| # | Décision |
|---|---|
| **1** | **§7.4 est un module séparé** — c'est un workflow, pas un référentiel, et il suppose la *prescription biologique*, entité qui n'existe pas. |
| **2** | **Les valeurs de référence sont AFFICHÉES, jamais conclusives**, et **stratifiées** dès maintenant. Le jeu livré est un jeu de démonstration honnêtement étiqueté, remplaçable sans migration. |
| **3** | **P6.7 traite le prescripteur d'un résultat.** *Révisée en P6.7b* : la réécriture posée par P6.7a écrivait un nom faux ; elle est retirée et remplacée par un lien vérifié au référentiel (§8). |

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
| *(retirée en P6.7b — la garde elle-même était fautive, voir §8.1)* | — |
| La source n'est plus obligatoire | `test_une_strate_sans_source_empeche_la_publication` |

---

## 7. Limites

1. **§7.4 non livré** : aucun identifiant de prélèvement, aucune des huit étapes. Module séparé.
2. **Les intervalles sont un jeu de démonstration** (§4), non validés et non attribués.
3. **Aucun code LOINC.**
4. **Le serveur ne conclut jamais** — pas de statut sur un résultat de laboratoire.
5. ~~Le référentiel des laboratoires est en P6.7b.~~ **LEVÉE en P6.7b** (§8.2, §8.3).
6. ~~Aucun écran citoyen n'affiche les références.~~ **LEVÉE en P6.7b** (§8.4) : le carnet montre
   les strates applicables sous chaque ligne rattachée au catalogue, sans jamais comparer.
7. **La grossesse n'est pas lue** pour choisir une strate (§3.1).
8. **Intervalles ivoiriens absents** — la structure les attend, les valeurs livrées ne le sont pas.

---

## 8. P6.7b — Laboratoires, liens d'un résultat, et LA CORRECTION DE P6.7a (2026-08-14)

### 8.1 Ce que P6.7a affirmait, et pourquoi c'était faux

P6.7a réécrivait `resultats_analyses.medecin_prescripteur` avec le nom du soignant qui consignait le
résultat, en le présentant comme le miroir de `ordonnances.medecin_nom`.

**Ce n'en était pas un.** Pour une ordonnance, celui qui écrit **est** le prescripteur : rédiger
l'ordonnance *est* l'acte de prescrire. Pour un résultat d'analyse, celui qui consigne est souvent
**quelqu'un d'autre** — un biologiste, ou un médecin hospitalier qui classe un résultat prescrit par
un généraliste de ville.

L'écrasement était inconditionnel. Dans ce cas très courant, le serveur remplaçait « Dr A » par
« Dr B » et **affirmait une chose fausse, avec son autorité**. C'est pire que le défaut d'origine :
celui-ci était une déclaration humaine non vérifiée, celui-là une assertion du système.

Le G2 de P6.7a l'avait même montré — « Dr Quelqu'un d'Autre » remplacé par « Dr Kablan Koffi » — et
je l'avais présenté comme une réussite.

**Le G0 de P6.7b l'a trouvé. La réécriture est retirée, et un vecteur dédié empêche son retour.**

### 8.2 Ce qui la remplace : vérifier au lieu de deviner

Le prescripteur et le laboratoire d'un résultat sont des déclarations sur des **TIERS**. On ne les
devine pas : on les fait **vérifier** quand elles sont faites.

`medecin_prescripteur_id` et `laboratoire_id` sont facultatifs — un patient qui recopie un compte
rendu papier n'a pas de liste sous les yeux. Mais quand ils sont fournis, le serveur relit le
référentiel et **fige** le nom. Même forme que le lien médicament (P6.6b) et le lien analyse
(P6.7a) : *ce que le serveur peut vérifier n'a pas à être cru, et ce qu'il a vérifié doit rester
stable.*

**On ne déduit pas non plus le laboratoire de l'établissement du soignant** : un résultat vient très
souvent d'un laboratoire externe, et le déduire serait la même erreur transposée.

**Un établissement qui n'est pas un laboratoire est refusé.** Sans ce contrôle, « laboratoire »
deviendrait « établissement », et le référentiel des laboratoires ne voudrait plus rien dire.

### 8.3 §7.1 / §7.2 — ce qui entre dans la projection gouvernée, et ce qui n'y entre pas

La moitié du §7.2 existait déjà depuis P6.4a (identifiant national, nom officiel, statut juridique,
adresse, GPS, contacts, `agrements_json`, `certifications_json`, `horaires_json`). On n'ajoute que ce
qui est propre au laboratoire.

**`type_laboratoire` entre dans la projection** : c'est un **second axe** de la catégorie —
`type = 'laboratoire'` dit *ce que c'est*, `type_laboratoire` dit *lequel* (§7.1). Les fondre rendrait
insoluble « combien de laboratoires de santé publique dans ce district ? ». Même raisonnement qu'en
P6.4a entre `type` et `statut_juridique`.

**Le responsable scientifique, les équipements, le délai de rendu et les analyses réalisées n'y
entrent PAS.** Le critère est refait, pas recopié : une **accréditation** est délivrée par une
autorité (gouvernée, déjà dans `certifications_json`) ; ces données-là changent avec le personnel et
les automates. Les gouverner ferait de l'arrivée d'un appareil une décision ministérielle — même
critère que `directeur`, déjà exclu en P6.4a.

**Deux vecteurs en miroir le prouvent** : la typologie fait diverger le référentiel · les données
d'exploitation ne le font pas.

### 8.4 L'affichage citoyen des références — la limite 6 de P6.7a est levée

Le carnet montre désormais, sous chaque ligne de résultat rattachée au catalogue, les strates
applicables au patient — âge et sexe résolus depuis sa fiche. **Il ne compare jamais** la valeur
saisie à la plage : aucune couleur, aucun verdict. Les strates conditionnelles sont marquées
« selon votre situation », la provenance de démonstration est dite, et l'avertissement du serveur est
affiché tel quel. Hors ligne, le bloc disparaît sans message d'erreur.

### 8.5 Le piège de la vérification par mutation

La première passe a laissé croire que le vecteur anti-régression survivait à la mutation. En
réalité **la mutation ne s'était pas appliquée** : le `str.replace` n'avait pas trouvé sa cible et
échouait en silence. Une mutation qui ne s'applique pas ressemble exactement à un vecteur qui
survit — d'où l'assertion obligatoire avant de conclure quoi que ce soit d'une mutation.

Rejouée correctement, elle tue bien son vecteur.

### 8.6 Un vecteur réécrit, pas supprimé

`test_le_soignant_ne_declare_PLUS_le_prescripteur` affirmait le comportement fautif. Il a été
**réécrit pour dire la garantie juste** — le prescripteur déclaré est conservé — et non retiré pour
que la suite passe. Précédent P6.4d.

### 8.7 Limites de P6.7b

1. **§7.4 (traçabilité des prélèvements) toujours non livré** — module séparé.
2. **Le lien reste facultatif** des deux côtés : le texte libre demeure la voie normale du patient.
3. **`laboratoire_code` est nul tant que l'établissement n'a pas de `identifiant_national`** : le
   code figé ne peut pas être meilleur que le référentiel qui le porte.
4. **La liste des analyses réalisées n'est pas gouvernée** (§8.3) : elle n'entre dans aucune version
   publiée, donc aucune décision ne peut la citer.
5. **Aucun écran mobile ne montre les laboratoires** : le lien se pose par l'API et par le portail.
