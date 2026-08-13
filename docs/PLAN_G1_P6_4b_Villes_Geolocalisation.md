# PLAN G1 — P6.4b « Villes et géolocalisation » (CDC_09 §4, CDC_11 §3.1)

**Statut : ✅ VALIDÉ (G5, 2026-08-13) — G2 et G3 prouvés, G4 propriétaire OK.**
Décision consignée : [ADR-027](adr/ADR-027-villes-geolocalisation.md) · Guide : [GUIDE_TEST_REFERENTIELS.md](../GUIDE_TEST_REFERENTIELS.md) **partie 3**.
Suit P6.4a (livré) · Précède P6.4c (images) puis P6.4d (formulaires du portail).

---

## 1. Le besoin, tel qu'exprimé

> « Pour le moment on n'a que la ville d'Abidjan, on va ajouter Yamoussoukro et Bouaké. En fonction
> de sa localisation on affichera la ville dans laquelle il se trouve avec l'icône de
> géolocalisation ; lorsqu'il clique dessus on affiche juste en bas "vous êtes à X". S'il est à
> Abidjan on affichera les communes ; en dehors d'Abidjan on affichera uniquement les structures,
> vu qu'il n'y a pas de communes. »

---

## 2. G0 — ce que la lecture du code a établi

### G-a — `LIBELLE_TYPE` et `COMMUNES` sont en dur côté mobile *(défaut latent, amorcé par P6.4a)*
[`apps/mobile/src/types/structure.ts:235`](../apps/mobile/src/types/structure.ts) : les 7 communes d'Abidjan sont une constante TypeScript, et `LIBELLE_TYPE` ne connaît que les **7 types historiques**. P6.4a en a ajouté **6**. Dès que le portail permettra de créer un `centre_dialyse`, l'écran affichera **« undefined · Cocody »** — et le typecheck ne l'attrape pas, la donnée arrivant à l'exécution.

C'est la répétition exacte du constat **F1 de P7-D2** (« les libellés vivaient en dur côté mobile et avaient divergé de la base »). **P6.4b doit le corriger**, sans quoi P6.4d amorcerait le défaut.

### G-b — La géolocalisation est déjà câblée, mais mal calibrée
`obtenirPosition()` existe et alimente le tri par proximité (`carte.tsx`). Elle demande la position **à la demande** (bouton « près de moi »), pas à l'ouverture, et sans explication préalable.

### G-c — OpenStreetMap ne géolocalise pas
C'est un fond de tuiles. Ce qui localise est le téléphone (GPS/Wi-Fi/réseau) ou une base d'adresses IP.

### G-d — Il n'existe pas de repli « hybride » sans permission
Android et iOS **fusionnent** GPS, Wi-Fi et cellulaire derrière **une seule autorisation**. Refus = rien du tout. Lire les identifiants de cellules ou scanner les Wi-Fi exigerait un module natif, donc **impossible en Expo Go** (ADR-009).
**En revanche**, Android 12+ permet d'accorder la **localisation approximative** seulement (~1–3 km) : c'est exactement l'hybride Wi-Fi + cellulaire, et c'est **largement suffisant pour déterminer une ville**.

---

## 3. Décisions

| # | Décision | Justification |
|---|---|---|
| **V1** | **La ville est déterminée par le BACKEND** — le mobile envoie `lat`/`lng`, le serveur répond la ville. | Règle de frontière : un rattachement géographique est un **calcul**. Le front affiche, il ne déduit pas. Test de fin de module : « quelles règles métier ce module calcule-t-il côté front ? » → aucune. |
| **V2** | **Centre + rayon, en données** (`villes.latitude`, `longitude`, `rayon_km`). | Ajouter Korhogo demain = **une ligne de données, zéro code** (§1.2.5). Aucune dépendance. Fournit aussi la distance nécessaire au tri hors zone. |
| **V3** | **« Abidjan a des communes, les autres non » est une DONNÉE** : colonne `affiche_communes`. | CDC_04 §20 : « aucune règle métier codée en dur ». Un `if ville === 'Abidjan'` dans le front serait la faute exacte que le corpus interdit. |
| **V4** | **Permission demandée à l'ouverture**, avec l'explication « les contenus dépendent de votre position », en précision **équilibrée** et non haute. | Plus rapide, moins gourmande, mieux acceptée — et la précision approximative suffit à trouver une ville. |
| **V5** | **Refus total → choix manuel de la ville, mémorisé et modifiable.** | Exact par construction, zéro dépendance. Le repli par adresse IP a été **écarté** : le CGNAT des opérateurs ivoiriens rattacherait la plupart des utilisateurs à Abidjan quelle que soit leur position réelle — une information fausse présentée comme certaine. |
| **V6** | **Hors des villes couvertes** : le dire, puis montrer **toutes** les structures **en commençant par la ville la plus proche**. | L'absence se dit plutôt qu'elle ne se comble (précédent : les trois silences assumés de P7-D2). |
| **V7** | **Les communes sont DÉRIVÉES des données**, pas un référentiel. | Le mobile cesse de les porter en dur ; la liste ne peut plus diverger de la base **puisqu'elle en sort**. Promouvoir `commune` en table imposerait de changer le contrat `?commune=` de P3 (validé G5) — reporté, voir limite N3. |

---

## 4. Périmètre

1. Table `villes` + `structures_sanitaires.ville_id` (migration additive).
2. Seeder : Abidjan (`affiche_communes` = **vrai**), Yamoussoukro, Bouaké (**faux**) ; rattachement des 12 structures existantes.
3. `LocalisateurVille` — service backend : position → ville, ou « hors zone » + villes par proximité.
4. `GET /api/v1/villes` et `GET /api/v1/villes/localiser?lat=&lng=` (publics, comme le reste de l'annuaire).
5. `StructureService` : filtre `ville` en plus de `commune`.
6. Mobile : demande de permission à l'ouverture · bandeau « Vous êtes à X » au tap sur l'icône · chips de communes **seulement si** `affiche_communes` · sélecteur de ville en repli · message hors zone.
7. **Correctif G-a** : `LIBELLE_TYPE` complété aux 13 types ; `COMMUNES` supprimée au profit des données du serveur.

## 5. Hors périmètre

| # | Limite |
|---|---|
| **N1** | Pas de repli par adresse IP *(V5)*. |
| **N2** | Pas de polygones de limites communales : exigerait des données officielles GeoJSON et une dépendance de calcul géométrique. |
| **N3** | `commune` reste un texte libre sur `structures_sanitaires`. Sa promotion en table de référence changerait le contrat `?commune=` de P3 — incrément ultérieur. **Conséquence : une faute de frappe dans une commune créerait un filtre fantôme.** |
| **N4** | Images (P6.4c) et formulaires du portail (P6.4d). |

## 6. Preuves attendues

- **G2 live** : rattachement des 12 structures ; localisation d'un point d'Abidjan, de Yamoussoukro, de Bouaké, et d'un point hors zone (ordre de proximité correct) ; `affiche_communes` respecté ; filtre `ville`.
- **G3** : tests dédiés dans les deux sens ; suite complète (référence : **480 tests / 14 599 assertions**) ; typecheck ×3 ; `expo-doctor`.
- **G4** : partie 2 du guide, complétée.

## 7. Preuves obtenues (2026-08-13)

- **G3** — `VilleGeolocalisationTest` **20/20** · suite complète **500 tests / 14 649 assertions, 0 échec** · `pnpm typecheck` vert sur les 3 espaces de travail · `expo-doctor` **18/18**.
- **G2 live MySQL** — **15/15** : trois villes seedées, seeder idempotent, 12 structures rattachées, Plateau → `ABJ`, Yamoussoukro et Bouaké **distinguées** malgré 95 km, Man → hors zone avec `YAM` en tête à 258 km, 7 communes à Abidjan et **0** à Yamoussoukro, `types_etablissement` conforme à l'ENUM. **Base restaurée.**
- **G4** — **partie 3** du guide (renumérotée : la partie 2 reste P6.4a), à dérouler en Expo Go.

**Deux défauts réels trouvés en chemin** *(consignés en §3.8 du guide)* : `StructureController` validait `type` contre les **7** catégories historiques (`?type=centre_dialyse` → **422** alors que la base l'accepte depuis P6.4a), et le paramètre `ville` était **effacé par `validate()`** faute d'être déclaré dans les règles.

**Écart au plan, assumé** : la limite **N6** a été ajoutée après coup — le repli mémoire (mode avion) n'est pas prouvé en test automatisé, il exige un débranchement réseau réel et se constate au G4.
