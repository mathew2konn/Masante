# Plan G1 — P10a : Orientation après triage + gouvernance du triage

> **P10 est découpé en trois incréments** (décision propriétaire 2026-08-15) :
> **a** orientation + gouvernance du triage · **b** protocoles médicaux CDC_08 · **c** microservice
> `triage-service` (IA, CDC_05 §5).
>
> L'ordre est **imposé par le corpus**, pas choisi : CDC_08 §9 dit que le moteur de protocoles
> **encadre** l'IA — sans protocoles, l'IA déciderait seule, ce que CDC_00 §4 interdit ; et un
> protocole de triage (CDC_08 §5.4) alimente un questionnaire qui doit déjà être gouverné.
>
> Statut : **G1 VALIDÉ par le propriétaire le 2026-08-15.**
> Décisions : **D1** périmètre · **D2** cardinalité ordonnée · **D3** codes **et** établissements ·
> **D4** coordonnées retenues avec fraîcheur + temps de trajet · **D5** `maladies_probables_json`
> **retirée de l'instantané publié** · **D6** fiche = mention + réponses + hôpitaux + **QR** dans
> P10a, PDF sorti · **D7** **le logo de l'application au centre de tous les QR**.

---

## 1. G0 — ce que le code dit réellement

Audit conduit en lisant le service, les seeders, les contrôleurs et les écrans — pas les
commentaires, dont trois avaient déjà dû être corrigés en P6.8a.

### U1 — `specialite_hint` ne porte pas *une* spécialité, il en porte parfois **deux**

Les 7 valeurs distinctes du jeu réel :

```
Urgences (×3)              Urgences / Traumatologie (×2)     Cardiologie / Urgences (×2)
Ophtalmologie              ORL (Oto-Rhino-Laryngologie)      Gynécologie / Maternité
Dentisterie
```

**Trois valeurs sur sept en contiennent deux**, séparées par ` / `. Un `specialite_id` unique ne
peut pas représenter `Cardiologie / Urgences`. Le G0 de P6.8 décrivait un problème de **forme**
(libellé contre code) ; c'est aussi un problème de **cardinalité**, et c'est celui-là qui commande
le modèle.

### U2 — Les règles d'orientation sont **en dur**, et elles portent sur du **texte libre**

`TriageService::deduireSpecialite()` :

```php
->reject(fn ($h) => str_contains(mb_strtolower($h), 'gyn') && $sexe !== 'F')
$prioritaire = $hints->first(fn ($h) => str_contains(…, 'urgenc') || str_contains(…, 'cardio'));
if ($age !== null && $age < 15) { return 'Pédiatrie'; }
```

Trois comparaisons de sous-chaînes et un littéral. **Un agent qui corrige un libellé au portail
change silencieusement une règle d'orientation** — écrire « Urgence » au singulier ferait perdre la
priorité. C'est une règle médicale en dur (CDC_00 §4), cachée dans une comparaison de chaînes :
personne ne l'avait vue parce qu'elle ne ressemble pas à une règle.

### U3 — Le défaut est bien **latent**, et c'est ce qui le rend dangereux

`ResultatScreen.tsx` **affiche** `specialite_requise` sous « Spécialité conseillée » et ne s'en sert
pour rien. Le brancher tel quel sur `?specialite=` donnerait une **liste vide, sans erreur**.

### U4 — Le triage lit **la table**, pas la version publiée

`Symptome::actif()->whereIn(...)` dans le service, `Symptome::actif()` dans le contrôleur : aucune
lecture de `DiffusionReferentiel`. C'est **l'autre moitié du défaut du G0 de P6.3**, laissée ouverte
sous le nom L1/L2 avec pour foyer P10 — celui-ci.

### U5 — `triages` ne stocke **aucune version de référentiel**

La table porte `score_severite`, `niveau`, `specialite_requise`, `recommandation_texte`… et **pas
de version**. Corriger un `poids_severite` rend donc **tout triage antérieur inexplicable**. C'était
le **constat F3 du G0 de P6.3** (« CDC_04 §115 prévoit la version du protocole »), jamais refermé.

### U6 — **La fiche de triage, « livrable obligatoire » du CDC_05 §5.4, est incomplète — et le manque le plus grave est une phrase**

Le §5.4 exige : *numéro, date et heure, symptômes déclarés, **réponses au questionnaire**, niveau,
recommandation, **service recommandé**, **hôpitaux proches proposant ce service**, **QR Code**, et
la mention obligatoire* :

> « Ce document constitue une aide à l'orientation et ne remplace pas un diagnostic médical. »

`TriageController::fiche()` renvoie aujourd'hui : identifiant, patient, symptômes, score, niveau,
libellé, couleur, spécialité, recommandation, date, et un `texte_partage`. **Manquent : les réponses
au questionnaire, les hôpitaux proches, le QR Code, le PDF — et la mention obligatoire.**

*Cette phrase absente est le manque le plus sérieux du lot.* La fiche est explicitement conçue pour
être **partagée et transmise au médecin** : c'est exactement le document où un triage risque d'être
lu comme un diagnostic, et c'est la seule ligne qui l'en empêche. CDC_00 §4 range « triage présenté
comme diagnostic » parmi les interdits absolus.

### U7 — `Maternité` n'existe pas au vocabulaire national

Les 13 termes de P6.8a ne portent pas `maternite`. Rattacher les hints obligera à trancher ce qu'on
fait des notions sans code — **je n'en inventerai pas** (précédent P6.4a : *une liste inventée qui a
l'air juste ne se fait jamais corriger*).

### U8 — Le store de localisation ne conserve **aucune coordonnée**

`useLocalisation` garde `ville`, `communes`, `villesParProximite`. Les `lat/lng` servent une fois
puis sont **jetées** (ADR-027 : « une seule mesure au moment du tap, jamais de suivi continu »).
En revanche `initialiser()` est bien appelé depuis `app/(app)/_layout.tsx` : **la position est
demandée à l'entrée dans l'application, le triage n'aura pas à la redemander.**

Bonne nouvelle vérifiée : `api/itineraire.ts` calcule déjà `duree_min` via **OSRM** (Module 3,
F3.7), hôte fixe, gratuit, sans clé — donc **aucune dépendance nouvelle** pour un temps de trajet.

---

## 2. Le point de conception — « sur quoi se base l'orientation ? »

> **Aujourd'hui ce n'est pas une déduction, c'est une annotation.**

« Mal à la dent » oriente vers le dentiste **parce que quelqu'un a écrit `'Dentisterie'` sur ce
symptôme**. Le système ne raisonne pas du symptôme vers la spécialité : il lit une étiquette.

**Et c'est le bon niveau, pas un pis-aller.** CDC_05 §1 : « *le triage n'est jamais un diagnostic :
il oriente vers le niveau de soins approprié* ». Inférer la spécialité *depuis* les symptômes
serait poser un diagnostic ; router via une annotation portée par une donnée gouvernée, non.

**Ce qui manque, c'est l'agrégation.** Un triage prend plusieurs symptômes, plus le questionnaire,
plus l'âge et le sexe. La règle de fusion est aujourd'hui le `str_contains('urgenc')` de U2.

Et le cas qui éclaire tout : **un abcès dentaire avec un drapeau rouge ne va pas chez le dentiste,
il va aux urgences.** C'est précisément pourquoi la donnée contient `Cardiologie / Urgences`. Donc
« mal à la dent → dentiste », **sauf quand le niveau prime** — et cette priorité doit devenir une
**donnée du référentiel gouverné**, jamais une comparaison de chaînes.

---

## 3. Décisions propriétaire (2026-08-15)

### D1 — Périmètre : orientation **et** gouvernance du triage

Les deux vivent dans le même service et le même écran. Les séparer toucherait **deux fois** un
module validé G5 — ce que le report en P10 voulait précisément éviter.

### D2 — Plusieurs spécialités, **ordonnées**

Table de liaison `symptome → spécialités` avec un rang. `Cardiologie / Urgences` devient deux
lignes ordonnées, **et l'ordre remplace le `str_contains` en dur** : la priorité devient une donnée
du référentiel gouverné. C'est ce qui supprime la règle médicale cachée de U2.

### D3 — Le serveur renvoie **les codes ET les établissements**

Ce n'est pas une préférence : **CDC_05 §5.4 l'impose** (« service recommandé, hôpitaux proches
proposant ce service »). La recherche n'est pas réécrite — elle réutilise `StructureService`, seul
endroit qui sait chercher un établissement (précédent P4 : source unique Blade + API).

### D4 — Coordonnées retenues **avec leur fraîcheur**, et temps de trajet affiché

**Écart assumé à ADR-027, borné par le temps** : les coordonnées sont conservées dans le store
**avec l'instant de la mesure**, et considérées **périmées au-delà d'un seuil** (5–10 min, donnée de
configuration). Ce n'est plus du suivi continu, c'est une mesure **avec une durée de validité** —
au-delà, on remesure plutôt que de servir une position ancienne.

**Deux garde-fous que le propriétaire a validés en même temps** :

1. le **nombre d'établissements interrogés est borné** — un appel OSRM par hôpital sur un service
   public gratuit, juste après un questionnaire, ne peut pas être illimité ;
2. **un temps de trajet absent ne fait JAMAIS disparaître l'hôpital de la liste.** L'écran se tait
   sur la durée et affiche l'établissement — *une orientation qui s'efface parce qu'un routeur tiers
   n'a pas répondu serait pire que pas de durée du tout.*

---

## 4. Conception

### 4.1 Le modèle : `symptome_specialites` (liaison ordonnée)

| Colonne | Rôle |
|---|---|
| `symptome_id` | le symptôme |
| `specialite_id` | le terme du vocabulaire national (P6.8a) |
| `rang` | l'ordre de préférence — **la priorité devient une donnée** |

`specialite_hint` **est conservée** (ADR-024, précédent `vaccinations.statut` et `cmu_*`) mais
**plus personne ne l'écrit** : elle devient l'énoncé honnête de ce qui n'est plus maintenu. Une
commande de backfill l'analyse et propose le découpage ` / ` — **sans jamais deviner** : ce qui n'a
pas de code (`Maternité`, U7) est **signalé, pas inventé**.

### 4.2 L'agrégation devient une **règle pure**

`ReglesOrientation` (motif `ReglesReversement` / `ReglesCalendrierVaccinal` / `ReglesIntervalleReference`) :
classe **pure**, sans accès base, qui reçoit les spécialités rangées de chaque symptôme retenu, le
niveau, l'âge et le sexe, et rend une **liste ordonnée**. Elle **ne conclut rien de médical** — elle
ordonne des codes.

Les trois règles en dur de U2 y deviennent des données : la préférence « urgences » est un **rang**,
la restriction gynécologique une **condition portée par la liaison**, la pédiatrie un **repli
déclaré**.

### 4.3 La gouvernance mord enfin sur le triage

- `TriageService` et `TriageController` lisent la **version publiée** de `symptomes_triage`, plus la
  table — referme **L1/L2** pour ce référentiel (le dernier ouvert d'ADR-025).
- `triages` reçoit `referentiel_version`, **nullable et jamais rétroactive** : les triages antérieurs
  n'ont eu aucune version, leur en attribuer une serait **un mensonge d'archive** (précédent exact de
  L2 pour `mesures_sante`).
- **Une seule version par requête** (mémoïsation, motif L2) : les symptômes d'un même triage sont
  jugés par le même référentiel, même si une publication survient au milieu.
- **Refus bruyant avant la v1 ?** — *point à trancher au G1* : contrairement aux numéros d'urgence,
  le triage n'est pas consulté sans réseau ; le refus bruyant s'applique donc normalement. Mais le
  triage est un **écran de premier recours**, et un 503 y est plus grave qu'ailleurs. Recommandation :
  **refus bruyant**, parce que la mise en vigueur est une étape de déploiement faite une fois, et
  qu'un repli silencieux rendrait l'oubli invisible (leçon L1).

### 4.4 La fiche redevient le livrable du §5.4

Ajout de la **mention obligatoire**, des **réponses au questionnaire** et des **hôpitaux proches**.
La mention vit sur le **modèle**, pas dans un template : trois surfaces l'afficheront (écran,
`texte_partage`, PDF) et *une phrase recopiée trois fois finit par diverger deux fois* — précédent
`MENTION_PROVENANCE` de P6.8d.

**D6 — la ligne de partage n'est pas « maintenant ou plus tard », c'est le coût en dépendances.**

| | Coût vérifié | Sort |
|---|---|---|
| Mention, réponses, hôpitaux | aucun | **P10a** |
| **QR Code** | **aucun** — le serveur produit un jeton, le mobile rend le QR avec `react-native-qrcode-svg` **déjà installé** (carte CMU, QR de dossier, MFA) | **P10a** |
| **PDF** | **dépendance nouvelle** — `composer.json` ne porte **aucune bibliothèque PDF** ; le seul PDF du projet (la facture) est produit côté **Java** avec OpenPDF | **hors P10a**, décision §2.6 à part |

### 4.6 D7 — Le logo au centre de **tous** les QR, et la contrainte qui va avec

Demande propriétaire. Un logo au centre **couvre des modules du code** : il exige de monter la
correction d'erreur au **niveau H** (30 % de récupération) — `react-native-qrcode-svg` est à `M`
(15 %) par défaut. Sans ce réglage, certains QR deviendraient **difficiles à scanner**, et deux
d'entre eux comptent : le **QR de dossier présenté à l'accueil d'un hôpital** et la **carte CMU
présentée à un guichet**. Le **QR MFA** est le plus exposé — il est lu par des applications tierces
dont les scanners sont moins tolérants.

**Un composant unique `QrMasante`** (correction `H`, logo plafonné à ~20 % de la surface, pastille
de fond derrière le logo, source unique `assets/images/logo.png`) que les **quatre** points d'appel
consomment. Ce n'est pas de la cosmétique de code : c'est ce qui fait que « tous les QR » reste vrai
pour ceux qu'on ajoutera ensuite, au lieu d'être vrai le jour de la livraison seulement.

**Les trois écrans existants appartiennent à des modules validés G5** (carte CMU F2.3, QR de dossier
Module 4, MFA P1) : la modification est **strictement additive** — même valeur encodée, même taille,
un composant de rendu à la place d'un autre. **Scannabilité vérifiée au G4 sur les quatre**, dont le
MFA avec un authentificateur réel.

### 4.5 Le mobile

`ResultatScreen` cesse d'afficher un libellé mort : il montre les spécialités conseillées **et** les
établissements de la ville qui les proposent, avec leur temps de trajet quand OSRM répond. Aucune
règle côté client : il transmet des codes qu'il n'a pas choisis.

---

## 5. Vecteurs exigés

1. `Cardiologie / Urgences` → **deux** spécialités, dans cet ordre — et le rang vient de la **base**,
   pas du code (mutation : neutraliser le tri doit tuer ce vecteur).
2. Un symptôme dentaire seul → `dentisterie`. **Le même avec un drapeau rouge → `urgences` en tête.**
3. Renommer un libellé au portail → **l'orientation ne change pas** (fin de la règle cachée de U2).
4. `UPDATE` direct sur `symptomes` → **aucun effet** avant publication ; publier → effet.
5. Un triage archivé **conserve sa version** ; les triages antérieurs restent à `NULL`.
6. Un symptôme gynécologique chez un patient masculin → **écarté**, par la donnée.
7. La **mention obligatoire** est présente dans les trois surfaces — vecteur qui casse si elle manque.
8. OSRM injoignable → **les hôpitaux restent affichés**, sans durée.
9. Position vieille de plus du seuil → **remesurée**, jamais servie telle quelle.

---

## 6. Limites qui seront annoncées

1. **Aucun protocole médical** (CDC_08) — c'est P10b. Le questionnaire reste celui de la base.
2. **Aucune IA** — c'est P10c, et elle ne peut pas précéder les protocoles (CDC_08 §9).
3. **QR Code et PDF de la fiche** hors périmètre si la recommandation du §4.4 est retenue.
4. **Le contenu reste un jeu de démonstration** : 7 valeurs de `specialite_hint`, aucune nomenclature
   d'orientation validée cliniquement.
5. **`maladies_probables_json` cesse d'être diffusée** (D5). C'est une liste d'**hypothèses
   diagnostiques** (`Fièvre → ['Paludisme', 'Fièvre typhoïde']`), mêlant maladies, syndromes, une
   catégorie floue (« Problème cardiaque ») et un **état physiologique qui n'est pas une maladie**
   (« Grossesse »). Le G0 de P6.8c avait établi qu'elle **n'a aucun lecteur** et que **sa seule
   sortie du serveur est l'instantané publié — l'endroit qui lui donne le plus d'autorité**, sans
   qu'aucun humain ne l'ait relue. L'afficher à un patient serait « triage présenté comme
   diagnostic », interdit absolu de CDC_00 §4. **La colonne et les données sont conservées**
   (ADR-024) ; on cesse seulement de les publier — précédent exact de la note d'avis exclue de la
   projection des établissements en P6.4a. Son foyer légitime reste **P10b** : CDC_08 §5.3 (« un
   moteur de décision par spécialité ») est le seul cadre où une hypothèse diagnostique a un
   lecteur — un protocole, jamais un écran patient.
6. **Le temps de trajet dépend d'un service public tiers**, sans engagement de disponibilité.
