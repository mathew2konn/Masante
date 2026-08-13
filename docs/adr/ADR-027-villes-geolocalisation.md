# ADR-027 — Villes couvertes et géolocalisation : la ville est un calcul, pas un `if` (P6.4b)

**Statut : Accepté** — 2026-08-13 · Contexte : CDC_09 §4 · CDC_11 §3.1 · CDC_04 §20 · CDC_01 §0.1 (règle de frontière) · Suit [ADR-026](ADR-026-referentiel-etablissements.md) · Contraint par [ADR-009](README.md) (Expo Go).

---

## 1. Contexte

Demande du propriétaire, le 2026-08-13 :

> « Pour le moment on n'a que la ville d'Abidjan, on va ajouter Yamoussoukro et Bouaké. En fonction de sa localisation on affichera la ville dans laquelle il se trouve avec l'icône de géolocalisation ; lorsqu'il clique dessus on affiche juste en bas "vous êtes à X". S'il est à Abidjan on affichera les communes ; en dehors d'Abidjan on affichera uniquement les structures, vu qu'il n'y a pas de communes. »

Le G0 a établi quatre choses.

**G-a — Deux listes vivaient en dur côté mobile, et l'une avait déjà divergé.** `apps/mobile/src/types/structure.ts` portait les **7 communes d'Abidjan** en constante TypeScript, et `LIBELLE_TYPE` ne connaissait que les **7 catégories historiques** — or P6.4a en a ajouté **6**. Dès qu'un `centre_dialyse` serait créé, l'écran aurait affiché **« undefined · Cocody »**, sans que le typecheck ne le voie : la donnée arrive à l'exécution. C'est la répétition exacte du constat **F1 de P7-D2** (« les libellés vivaient en dur côté mobile et avaient divergé de la base »). P6.4b devait le corriger, sinon **P6.4d — qui ouvre la création d'établissements — aurait amorcé le défaut**.

**G-b — La géolocalisation était câblée, mais mal calibrée pour cet usage.** `obtenirPosition()` alimentait déjà le tri par proximité, mais à la demande (bouton « près de moi »), et **sans explication préalable**.

**G-c — OpenStreetMap ne géolocalise pas.** C'est un fond de tuiles. Ce qui localise est le téléphone, ou une base d'adresses IP.

**G-d — Il n'existe pas de repli « hybride » après un refus.** Android et iOS **fusionnent** GPS, Wi-Fi et cellulaire derrière **une seule autorisation** : refusée, le système ne donne rien. Lire les identifiants de cellules ou scanner les Wi-Fi exigerait un module natif, donc **impossible en Expo Go** (ADR-009). En revanche, Android 12+ permet d'accorder la **localisation approximative** seulement (~1–3 km) : c'est précisément l'hybride Wi-Fi + cellulaire, et c'est largement suffisant pour trouver une ville.

> **G-c et G-d corrigent la formulation initiale de la demande**, qui supposait qu'OpenStreetMap localise et qu'un repli hybride resterait disponible après refus. Ni l'un ni l'autre n'est vrai. La demande reste satisfaite — la ville est déterminée, la phrase s'affiche, les communes suivent la ville — mais par un chemin exact plutôt que par un chemin supposé.

---

## 2. Décision principale : la ville est déterminée par le **backend**

Le mobile envoie `lat`/`lng` ; le serveur répond quelle ville contient la position, si elle affiche des communes, lesquelles, et — hors zone — l'ordre de proximité.

C'est la **règle de frontière** (CDC_01 §0.1) appliquée à la lettre : un rattachement géographique est un **calcul**. Si le mobile le refaisait, ouvrir une quatrième ville exigerait de **publier une nouvelle version de l'application**, et deux versions installées répondraient **différemment à la même question** — les anciennes ignorant la ville neuve, les nouvelles la connaissant. Test de fin de module : « quelles règles métier ce module calcule-t-il côté front ? » → **aucune**.

Le contrat est délibérément **complet et sans ambiguïté**, pour que l'écran n'ait rien à déduire :

```json
{ "ville": {"code","nom","affiche_communes"} | null,
  "hors_zone": bool,
  "communes": [],
  "villes_par_proximite": [{"code","nom","distance_km"}] }
```

---

## 3. Décisions secondaires

### 3.1 Une ville est un **centre et un rayon**, en données *(V2)*

`villes.latitude`, `longitude`, `rayon_km`. Ajouter Korhogo demain est **une ligne de données, zéro code** (§1.2.5) — c'est vérifié comme vecteur de test. Le rayon fournit aussi la distance nécessaire au tri hors zone, calculée par un **Haversine en PHP pur** : aucune extension, aucune fonction spatiale, portable entre MySQL et SQLite.

Les **polygones** de limites administratives ont été écartés : ils exigeraient des données officielles GeoJSON et une dépendance de calcul géométrique, pour répondre à une question — « dans quelle ville ? » — qu'un rayon tranche déjà.

### 3.2 « Abidjan a des communes, les autres non » est une **donnée** *(V3)*

Colonne `villes.affiche_communes`. CDC_04 §20 : « aucune règle métier codée en dur ». Un `if ville === 'Abidjan'` dans l'écran serait **exactement** la faute que le corpus interdit — et il faudrait le retrouver et le corriger le jour où une quatrième ville subdivisée arrive.

### 3.3 Les communes sont **dérivées**, pas un référentiel *(V7)*

Elles sortent d'un `DISTINCT` sur les établissements de la ville. Le mobile cesse de les porter en dur, et la liste **ne peut plus diverger de la base puisqu'elle en sort**.

Promouvoir `commune` en table de référence aurait été plus propre — mais changerait le contrat `?commune=` de **P3, validé G5**. Reporté (limite N3), avec sa conséquence énoncée : **une faute de frappe dans une commune crée un filtre fantôme**.

### 3.4 Le refus de localisation mène au **choix manuel**, jamais à une déduction *(V5)*

Le repli par **adresse IP** a été écarté. Le CGNAT des opérateurs ivoiriens rattacherait la plupart des abonnés à Abidjan **quelle que soit leur position réelle** : ce serait une information **fausse présentée comme certaine**, et l'utilisateur n'aurait aucun moyen de savoir qu'elle est fausse. Il exigerait de surcroît une base de géolocalisation IP, donc une dépendance (§2.6).

Demander à l'utilisateur est **exact par construction**. Le choix est mémorisé, donc demandé une fois.

### 3.5 Trois sources, trois phrases — et une seule est une affirmation

| Source | Phrase | Pourquoi elle diffère |
|---|---|---|
| `position` | **« Vous êtes à Abidjan »** | La position a réellement répondu. C'est une **mesure**. |
| `choix` | « Ville choisie : Bouaké » | L'utilisateur l'a déclarée. C'est une **déclaration**. |
| `memoire` | « Dernière position connue : Abidjan » | Servie hors ligne. C'est un **souvenir**. |

La distinction n'est pas cosmétique. « Vous êtes à X » servi depuis un cache devient **faux** dès que l'utilisateur se déplace hors couverture réseau — et il n'aurait rien à l'écran pour s'en douter. On garde la mémoire, sans laquelle l'écran serait vide en mode avion, mais **on ne la fait jamais passer pour une mesure**. C'est le même principe que les **trois silences assumés de P7-D2** : dire ce qu'on sait, et dire aussi *comment* on le sait.

Conséquence directe : `localiserVille` **ne passe pas** par le cache hors ligne, contrairement à `chargerVilles`. Une position est ponctuelle ; la mettre en cache reviendrait à dire « vous êtes à Abidjan » à quelqu'un qui vient d'arriver à Bouaké.

### 3.6 Hors zone : le dire, et ordonner — **jamais rattacher** *(V6)*

Quand aucune ville couverte ne contient la position, `ville` est `null`. On **ne rattache pas à la plus proche** : un utilisateur à Man serait déclaré « à Bouaké », à 300 km. L'écran l'annonce, nomme la ville la plus proche avec sa distance, et montre **toutes** les structures dans l'ordre de proximité.

### 3.7 L'explication précède l'invite du système *(V4)*

Une autorisation demandée sans motif se refuse par réflexe, et un refus est difficile à revenir sur Android comme sur iOS. L'application explique donc d'abord — « MaSante affiche les établissements de votre ville » — puis demande. **Et si l'utilisateur répond « Choisir moi-même », l'invite native n'est pas déclenchée** : la lui imposer derrière produirait précisément le refus définitif qu'on cherche à éviter.

Précision **équilibrée** et non haute : plus rapide, moins gourmande en batterie, mieux acceptée — et suffisante pour trouver une ville.

### 3.8 Les catégories d'établissement ont une **source unique** *(correctif G-a)*

`App\Support\TypesEtablissement` porte les 13 catégories et leurs libellés ; la migration, le portail, la validation de l'API et le mobile s'y adossent. La liste est exposée par `GET /villes` — au même appel que les villes, parce que l'écran en a besoin au même moment.

**Ce correctif a révélé un défaut réel** : `StructureController` validait `type` contre les **7 catégories historiques**, si bien que `?type=centre_dialyse` — pourtant accepté par la base depuis P6.4a — répondait **422**. Quatre copies de la même liste, déjà divergentes.

Corollaire : `TypeStructure` est passé de l'union fermée à `string`. L'union listait 7 valeurs sur 13 ; elle ne protégeait de rien, la donnée arrivant à l'exécution, mais elle **donnait l'illusion d'une garantie** et poussait à recopier la liste. **Un type qui ment est pire qu'un type large.**

---

## 4. Conséquences et limites

**Acquis.** Trois villes couvertes, extensibles par une ligne de données. La ville, les communes et l'ordre de proximité sont décidés par le serveur. Deux listes en dur ont disparu du mobile, et un défaut de filtrage latent depuis P6.4a est corrigé.

**Limites assumées, reportées dans le guide (partie 3).**

- **N1 — Aucun repli par adresse IP** *(§3.4)*.
- **N2 — Pas de polygones communaux** *(§3.1)*.
- **N3 — `commune` reste un texte libre** *(§3.3)*. Conséquence : **une faute de frappe crée un filtre fantôme**.
- **N4 — Images (P6.4c) et formulaires du portail (P6.4d)**, dont les colonnes `ville` et `forme_juridique` d'[ADR-026 §4 M6](ADR-026-referentiel-etablissements.md).
- **N5 — La ville d'une structure (`ville_id`) est posée par le seeder** ; aucun écran ne permet de la changer (P6.4d). Une structure sans `ville_id` reste visible partout, simplement absente du filtre `?ville=`.
- **N6 — Le repli mémoire (mode avion) n'est prouvé qu'au G4**, pas en test automatisé : il exige un vrai débranchement réseau sur l'appareil. Le code est écrit et typé ; son comportement réel se constate en avion.

**Aucune dépendance nouvelle.** `expo-location` était déjà présent depuis le Module 3.
