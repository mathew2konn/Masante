# ADR-038 — Assurances et organismes agréés (CDC_09 §8)

- **Statut** : accepté — P6.8d validé G5 le 2026-08-15
- **Contexte** : étape 8 de l'ordre de construction du §14, quatrième incrément de P6.8
- **Décisions propriétaire** : F1 table de couvertures · F2 provenance déclarée dite comme telle ·
  F3 conventionnement hors périmètre
- **Remplace** : rien. **Complète** : ADR-024 (enrichissement additif), ADR-025 (socle référentiel),
  ADR-026/031/033/034/035/036/037 — dont les critères sont ici **reposés**, pas recopiés

---

## 1. Le problème

`membres_famille` portait `cmu_numero`, `cmu_statut` et `cmu_validite` : **la CMU était codée dans
les noms de colonnes**. Trois conséquences vérifiées au G0 :

| # | Constat | Ce que ça coûtait |
|---|---|---|
| U1 | trois colonnes, une seule couverture | le §8 du CDC_06 enchaîne « CNAM, **puis** assurances privées » sur la même facture — irréalisable |
| U1 | `non_inscrit` | **un statut qui affirme qu'il n'y a pas de couverture**, porté par une ligne qui existe toujours |
| U2 | `TypePriseEnCharge` (Java) portait déjà les six familles du §8.2 | il y avait un vocabulaire **à adopter**, pas à inventer |
| U3 | le rôle `assurance` existe depuis P1, soumis à MFA, traduit dans `@masante/shared` | **aucun objet, aucune permission, aucune porte** — un acteur déclaré depuis quatre modules |
| U4 | `cmu_statut` déclaré par le client, **signé** par `CarteCmuService`, présenté comme « il **confirme** votre statut CMU » | une auto-déclaration présentée à un agent d'accueil comme une confirmation |
| U5 | `PriseEnChargeRequete` fait déclarer taux et plafond par l'appelant ; **Laravel n'appelle jamais le paiement** | le moteur ne peut rien confronter |
| U6 | `structures_sanitaires.agrements_json` (« Agrément CNAM, Convention CMU ») est **dans la projection publiée** des établissements | un conventionnement diffusé comme donnée nationale, en chaîne libre |

**U4 mérite d'être isolé, et sa nature n'est pas celle de P6.8b.** Là-bas, `obligatoire` et `statut`
étaient déclarés par l'intéressé alors qu'un **calcul** était possible (âge + calendrier publié) : la
correction fut de calculer. Ici le calcul est impossible — **l'étape 2 du §8.1 du CDC_06 (« le
système vérifie son éligibilité, API CNAM ») n'existe pas dans ce projet**, et rien ne peut
l'inventer. Et la conception de F2.3, elle, était honnête : le document disait « restitue le statut
**déclaré** ». *C'est l'écran qui promettait plus que le code ne savait.* Le seul correctif honnête
porte donc sur **le mot**.

---

## 2. Le point de conception

> **Une couverture n'est pas un attribut de la personne : c'est un contrat entre une personne et un
> organisme.**

Trois colonnes `cmu_*` disent l'inverse — elles en font une propriété du corps, comme le groupe
sanguin, et elles nomment l'organisme **dans le schéma**. C'est ce qui rendait inexprimable la
situation la plus banale qui soit : un fonctionnaire à la CMU **et** à la mutuelle de son ministère.

Bascule strictement parallèle à celle de P6.8b (« un calendrier ne répond pas *ce vaccin est-il
fait ?* mais *qu'est-ce qui est dû pour cette personne-là, aujourd'hui ?* ») et à celle d'ADR-034
(« une plage biologique dépend de la personne ») : la variable est ici **l'organisme**, et il y en a
plusieurs.

---

## 3. Décision F1 — une table de couvertures

`couvertures_membre` porte des couvertures **cumulables**, chacune rattachée à un organisme du
registre. Les trois colonnes `cmu_*` sont **conservées** (ADR-024 ; une migration destructive
perdrait de l'information réelle pour un gain nul — précédent P6.4d-K2) mais **plus personne ne les
écrit** : motif exact de `vaccinations.statut` en P6.8b.

**Le statut devient un calcul** (`resiliee` → `expiree` → `active`), dérivé des seules colonnes de la
ligne — leçon P6.8b : *une valeur qui change avec la façon dont on la demande n'est pas un calcul,
c'est un hasard*. L'ordre est délibéré : une résiliation l'emporte sur une date de fin lointaine,
parce que répondre « expirée » à un contrat résilié dirait la bonne conclusion **pour la mauvaise
raison**, et un assuré ne pourrait pas la contester utilement au guichet.

**`non_inscrit` disparaît** : l'absence de couverture se dit par **l'absence de ligne**. Il ne
subsiste qu'à un seul endroit — la dérivation du contrat de P2 (§5).

---

## 4. Trois questions reposées, dont une réponse s'inverse

La méthode est celle de P6.6a : *reposer la question, ne pas recopier la conclusion.*

### 4.1 `pays_code` — il revient

P6.8c venait de rompre avec `pays_code` parce qu'une maladie est un **fait de nature**. Un organisme
d'assurance est une **personne morale agréée par un État** : son agrément est délivré, suspendu et
retiré par une autorité nationale. Donc `UNIQUE(pays_code, code)`, et CI comme SN peuvent porter
`ASS000001`.

### 4.2 La projection — la **ligne entière**, et la table est construite pour que ça reste vrai

Vérification : **rien n'écrit automatiquement dans `organismes_assurance`**. Ce n'est pas un constat
passif — la table ne porte **aucun compteur d'assurés**, alors qu'il serait utile à l'écran ; il
aurait rendu la phrase fausse (précaution née de `note_moyenne` en P6.4a). Elle ne porte **ni
téléphone, ni adresse** : les y mettre ferait d'un changement de standard un **acte d'autorité soumis
au quatre-yeux**. C'est le critère d'ADR-026 transposé à l'envers — là-bas on excluait de la
projection, ici on exclut de la **table**, parce que la projection ne trie pas.

**Deux vecteurs en miroir, aucun ne suffit seul** : un citoyen déclare une couverture → l'empreinte
**ne change pas** ; un agrément passe à `suspendu` → elle **change**.

### 4.3 Le figeage du nom — **la réponse s'inverse**

P6.6b, P6.7b et P6.8c figent code et libellé au moment de l'écriture. Ici, non — et la raison est de
nature :

> ceux-là inscrivaient un fait **historique** dans un carnet : une ordonnance signée, un résultat
> rendu, un antécédent daté. Une couverture est un **état courant** — « je suis assuré chez X
> aujourd'hui ». Si X est renommé, la phrase reste vraie sous le nouveau nom, et afficher l'ancien
> ferait porter à l'assuré **un nom que le guichet ne reconnaît plus**.

Le nom vient donc du référentiel **à la lecture**. Corollaire tenu par le schéma : `restrictOnDelete`
— supprimer un organisme qui couvre des assurés **échoue bruyamment**, parce qu'en `SET NULL`
personne ne saurait plus chez qui ces gens étaient assurés. Le chemin normal est la désactivation.

---

## 5. Le contrat de P2 survit — par **dérivation**, pas par recopie

`GET /membres` (module validé G5, avec son cache hors-ligne chiffré) expose `cmu_statut`,
`cmu_validite` et `cmu_numero_masque`. Ces trois valeurs sont désormais **dérivées de la couverture
CNAM**, par accesseurs. Ni les clés, ni le vocabulaire, ni le format ne bougent.

**Pourquoi dériver plutôt que laisser les colonnes** : elles seraient figées au jour du backfill
pendant que le citoyen modifie sa couverture → **deux vérités dans la même réponse**.

**Le type fait foi, jamais le nom** : « CMU » est le régime, la CNAM l'organisme qui le gère
(CDC_06 §8.1). Chercher « CMU » dans un libellé rendrait la carte dépendante d'une chaîne de
caractères — exactement ce que ce module supprime. Une mutuelle ne se présente donc pas comme une
carte CMU.

**Conséquence de déploiement, dite plutôt que masquée** : tant que `masante:couvertures:backfill` n'a
pas tourné, un membre dont la colonne dit « actif » répond `non_inscrit`. **Aucun repli sur la
colonne** — il ressusciterait une valeur périmée le jour où un citoyen supprime sa couverture. La
bascule est une **étape de déploiement**, comme la publication de la v1 en L1+L2.

---

## 6. Décision F2 — la provenance est déclarée, et l'écran le dit

`provenance` vaut `declare`. `verifie` est **réservé et inatteignable** : aucun chemin d'écriture ne
peut le poser, et deux vecteurs le prouvent — l'un par HTTP, l'autre **en appelant le service
directement**, parce que le premier resterait vert si l'on retirait la garde du service
(`validate()` écarte déjà les clés non déclarées : il prouverait le validateur, pas le service —
leçon des mutations de P6.6b).

**Pourquoi la colonne existe quand même**, alors qu'une valeur inatteignable ressemble à une
décoration : sans elle, l'écran dirait « statut déclaré » comme un **commentaire** ; avec elle, il le
dit comme une **donnée**. Et le jour où une vérification existera, la distinction sera déjà portée
par les lignes antérieures. `MENTION_PROVENANCE` vit sur le modèle — trois surfaces l'affichent, et
recopiée elle divergerait le jour où elle changera.

---

## 7. Décision F3 — le conventionnement reste dehors

Le §8 du CDC_09 demande un registre d'**organismes**, pas la relation. Le rattacher ferait changer
l'empreinte du référentiel des établissements (module G5) et ouvrirait une question qu'aucun document
ne tranche : *qui déclare la convention, l'hôpital ou l'assureur ?* Le constat U6 est écrit, le
porteur est nommé, le texte libre reste.

---

## 8. La permission — onzième occurrence, et la plus littérale

`assurance.referentiel` n'est portée par **aucun rôle métier**. Sa raison lui est propre : **le rôle
`assurance` désigne précisément les organismes que ce registre recense.** La lui donner ferait
décider de la liste des agréés par un assureur — juge et partie sur son propre agrément.
`gestionnaire_etablissement` non plus : il gère les conventions de **son** établissement, et la liste
nationale deviendrait la somme des conventions de chacun. Un agrément est délivré par un État.

---

## 9. L'honnêteté du contenu

- **`numero_agrement` existe et reste vide.** Ces numéros sont délivrés par une autorité (ministère,
  CIMA) et **aucun n'a été inventé**. Le contrôle qualité ne l'exige pas — *un contrôle qu'on ne peut
  pas satisfaire n'est pas une exigence, c'est un mur* — mais l'absence est **comptée et affichée**.
  Troisième application du motif `analyses.loinc` (P6.7a) puis `code_cim10` (P6.8c).
- **Le jeu livré ne nomme aucun assureur privé réel.** La CNAM est nommée parce que le corpus la
  nomme (CDC_06 §8.1) ; les cinq autres familles portent des noms **explicitement fictifs**. Nommer
  une compagnie réelle comme « agréée » affirmerait un agrément que personne n'a vu — et *une liste
  inventée qui a l'air juste ne se fait jamais corriger* (P6.4a, découpage sanitaire).
- **`agrement_statut` est nullable**, et l'absence est une réponse légitime : *un organisme sans
  agrément renseigné n'est pas « probablement agréé »* (raisonnement d'`autorisation_statut`, P6.5a).

---

## 10. Le repli hors référentiel — 3ᵉ application du motif E4, pour une raison différente

Le lien est facultatif : la saisie libre reste ouverte, et l'écart est **compté et affiché**.

*Ce qui distingue cet écart de celui des alertes épidémiques mérite d'être écrit, puisque c'est
pourquoi la question a été reposée* : là-bas la porte reste ouverte parce qu'une **maladie émergente
n'est dans aucune nomenclature** au moment où elle émerge — c'est **structurel**. Ici, c'est **notre
registre qui est incomplet** — c'est **temporaire**. Et c'est justement pour cela que l'écart est
compté : il doit tendre vers zéro à mesure que le registre réel est chargé.

---

## 11. Conséquences

**Positives.** Le §8 du CDC_06 devient réalisable (plusieurs couvertures) · le statut cesse d'être
une déclaration · l'écran cesse de promettre une confirmation qu'il ne peut pas donner · les six
familles du §8.2 sont représentables sous le **même vocabulaire que le moteur de paiement** · le
contrat de P2 survit sans modification côté client.

**Négatives, et assumées.** Une étape de déploiement de plus (le backfill) · les colonnes `cmu_*`
subsistent, vides de sens · une garde applicative de plus (une seule couverture vivante par
organisme, MySQL 8 n'ayant pas d'index unique partiel — **annoncée comme applicative, jamais déguisée
en garantie du moteur**, précédent du quota d'images de P6.4c) · le backfill **approxime** une
expiration sans date, et le dit.

**Limites annoncées.** Aucune vérification auprès d'un organisme · le paiement fait toujours déclarer
taux et plafond (porteur : incrément de paiement nommé, avec les actes et tarifs de D2) · aucune
garantie, aucun plafond, aucune exclusion dans le registre · contenu = jeu de démonstration · le rôle
`assurance` reste sans porte (ADR-030 : trois populations d'authentification, jamais étirées en une)
· L1/L2 d'ADR-025 s'appliquent aux écrans qui lisent la table.
