# ADR-039 — Numéros d'urgence nationaux (P6.8e, CDC_09 §8)

- **Statut** : accepté — G5 le 2026-08-15
- **Contexte** : dernier incrément de **P6.8** → l'étape **8** de l'ordre de construction du §14 est
  complète.
- **Décisions propriétaire** : C1 à C5 (2026-08-15), plan
  [`docs/PLAN_G1_P6_8e_Numeros_Urgence.md`](../PLAN_G1_P6_8e_Numeros_Urgence.md).

---

## 1. Le problème, et il était écrit dans le corpus

> CDC_02 §37 : « multi-pays par profil national (référentiels CDC_09, **rien en dur — y compris les
> numéros d'urgence** : SAMU 185 en CI) ».

C'est le seul incrément de P6 dont l'exigence **nomme le site précis** au lieu de découler d'un
principe général.

Le G0 a trouvé **quatre familles de sites**, là où le plan de P6.8 en annonçait deux :

| # | Site | Nature | Consommateurs |
|---|---|---|---|
| 1 | `TriageService::NUMERO_SAMU` | constante backend | 1 |
| 2 | `apps/mobile/src/config/constants.ts` | constante client | **5** |
| 3 | `packages/shared/src/i18n/{fr,en}.ts` — `'SOS 185'` | **numéro collé dans une traduction** | **0 (clé morte)** |
| 4 | six conseils cliniques seedés | **donnée déjà publiée** sous gouvernance | les écrans de mesures |

Le site (3) est le plus instructif : il vit dans le paquet qui sert de **source unique**, et
personne ne l'appelle. *Une chaîne de traduction qui porte une donnée a l'apparence de la source
unique et se périme en silence.* Sixième clé/colonne dormante du projet.

**Faux positif écarté** : les neuf « 185 » de `welcome.blade.php` sont des coordonnées de chemins
SVG du logo Laravel par défaut. Vérifié plutôt que supposé.

---

## 2. Le point de conception — ce référentiel n'est pas comme les neuf autres

Tous les référentiels de P6 répondent à « **qu'est-ce qui fait autorité ?** ».
Celui-ci répond d'abord à « **que compose-t-on quand plus rien ne fonctionne ?** »

Deux propriétés qu'aucun autre n'a réunies :

1. **il doit être disponible en défaillance totale** — son consommateur central n'a ni réseau, ni
   session, ni compte : `CarteVitaleEcran` est atteignable **depuis l'écran de connexion**, pour un
   secouriste qui ramasse le téléphone d'un inconscient (FN2), et `urgence/sos.ts` dit que
   « demander au serveur qui alerter supposerait un réseau que l'on n'a précisément pas » ;
2. **il se périme** — renumérotation, second pays —, et sa péremption a un coût vital.

La première interdit le refus bruyant. La seconde interdit la constante en dur. **La conception doit
satisfaire les deux.**

---

## 3. C1 — Le motif « refus bruyant » n'est pas abandonné, il est DÉPLACÉ

L1+L2, P6.8b et P6.8d posent un **503 avant la v1**, pour qu'un oubli de publication ne passe pas
inaperçu. Cet argument suppose qu'on puisse réessayer plus tard. **Devant un blessé, il n'y a pas de
plus tard.**

La résolution retenue sépare les deux exigences au lieu de les arbitrer :

| Côté | Comportement | Ce qu'il porte |
|---|---|---|
| **Serveur** | 503 tant que rien n'est publié ; **jamais** la table de travail | l'**honnêteté** envers l'exploitant |
| **Client** | référentiel → cache `SecureStore` → valeur livrée avec l'app | la **disponibilité** envers le secouriste |

*L'honnêteté est due à l'exploitant, la disponibilité au secouriste — et les deux tiennent ensemble
parce qu'elles ne vivent pas au même endroit.*

**Le repli n'est acceptable que parce qu'il est visible**, et il l'est de trois façons :

1. **journalisé en `warning`** à chaque requête où il joue (une seule ligne par requête : trois
   lignes identiques feraient dire au journal « il s'est passé beaucoup de choses » au lieu de
   « une version manque », et c'est ainsi qu'un avertissement devient invisible) ;
2. **`estEnVigueur()` ne replie ni ne journalise** — c'est la vérité brute que lit le portail ;
3. **l'écran du portail l'affiche** en bandeau rouge, avec la valeur exacte que composent les
   téléphones.

**L'écran citoyen, lui, ne dit rien de la provenance.** Un avertissement « numéro par défaut, non
vérifié » présenté à quelqu'un qui compose des secours est du bruit au pire moment.

---

## 4. Ce que le repli contient — et ce qu'il ne contient PAS

**Un seul numéro est compilé dans l'application** : le SAMU, seul que le corpus nomme (CDC_00 §4
l'oppose explicitement au « 15 » français).

**Le 100 et le 180 n'y sont délibérément pas.** Ils ont été déclarés par le propriétaire sans être
confrontés à un arrêté : les compiler reviendrait à refaire, en plus discret, le défaut que cet
incrément referme. Ils vivent dans le référentiel, d'où ils peuvent être corrigés sans republier
l'application — ce qui est tout l'objet du module.

**Aucun repli n'est inventé pour les autres secours** : `numero()` lève plutôt que de fabriquer une
valeur. *Un numéro d'urgence faux est plus dangereux qu'un numéro absent, parce qu'il sera composé.*

---

## 5. C2 — La provenance dit exactement ce qui s'est passé

Une valeur d'ENUM neuve, `declaration_projet`, à côté de `demonstration`, `autorite_nationale` et
`publication`. Elle existe parce qu'aucune des trois autres ne serait vraie :

- `autorite_nationale` affirmerait une vérification qui **n'a pas eu lieu** ;
- `demonstration` dirait que les valeurs sont **inventées**, ce qui est faux aussi.

Elle dit donc : *quelqu'un d'identifié les a déclarées, et personne ne les a vérifiées.*

Quatrième application du motif `analyses.loinc` (P6.7a) après `code_cim10` (P6.8c) et
`numero_agrement` (P6.8d) — **poussée d'un cran** : ici on ne compte pas seulement ce qui manque, on
**qualifie ce qui est là**. Le contrôle qualité n'exige aucune provenance officielle (*un contrôle
qu'on ne peut pas satisfaire n'est pas une exigence, c'est un mur*), mais l'écart est compté et
affiché.

---

## 6. Le piège évité — `SecureStore` et non le cache chiffré P2

Le réflexe aurait été de faire comme `chargerVilles` (P6.4b), qui passe par `lireAvecCache`. Or
`SessionContext` appelle **`viderDossierCache()` à la déconnexion**.

Un numéro d'urgence rangé là **disparaîtrait à la déconnexion**, c'est-à-dire **précisément dans
l'état où se trouve le téléphone que consulte un secouriste**. `SecureStore` — celui de la carte
vitale, choisi par FN2 pour cette raison même — survit par construction.

---

## 7. Les autres décisions

- **Terme de nomenclature, pas instance** : code littéral (`samu`, `police`, `pompiers`), **ni
  compteur, ni backfill** — précédent `regions.code` (P6.4a) et `specialites` (P6.8a), et non
  `ETS`/`PRO`/`MED`/`ANA`/`VAC`/`ASS`.
- **`UNIQUE(pays_code, code)` — réponse inverse à P6.8c, pour la raison inverse.** Une maladie
  n'appartient à aucun pays (le paludisme est le paludisme partout) ; un numéro d'urgence **n'a
  aucune existence hors d'un pays** — il est attribué par un plan national de numérotation et ne
  veut rien dire ailleurs. C'est le référentiel **le plus national** de tout P6.
- **Le code est immuable** après création : le mobile et le triage demandent un numéro *par son
  code*, et le renommer les laisserait désigner un terme disparu **sans lever d'erreur** — le repli
  jouerait en silence. Un code qui ne convient plus se désactive.
- **Projection = ligne entière**, question reposée pour la sixième fois. La table est **construite**
  pour que ce soit vrai : **aucun compteur d'appels**, alors qu'il serait facile d'en tenir un
  (`alertes_sos` journalise déjà les déclenchements). *Le référentiel dirait qu'il a changé au moment
  précis où il compte le plus qu'il n'ait pas bougé.* Deux vecteurs en miroir : un SOS ne fait pas
  diverger l'empreinte, un numéro modifié si.
- **Permission `urgence.referentiel`, portée par aucun rôle métier — 12ᵉ occurrence**, et l'enjeu
  est le plus direct de la série : un code de spécialité faux produit une liste vide, un numéro
  d'agrément faux une incohérence de guichet ; **un numéro d'urgence faux produit un appel qui
  n'aboutit nulle part.**
- **Contrôle qualité central** : une version où **plus aucun numéro n'est actif** est refusée. Elle
  ne casserait rien de visible — les téléphones retomberaient sur la valeur compilée, en silence,
  sans que personne ne l'ait décidé.
- **Une seule garde du moteur** (numéro vide, déclencheurs dans les deux dialectes). La
  *composabilité* vit dans le contrôle qualité PHP et non en `REGEXP` : MySQL 8 sait le faire,
  SQLite non — la garde serait **plus stricte en production qu'en test**, la divergence exacte
  relevée en P6.8c avec la collation. **C'est dit plutôt que laissé croire.**

---

## 8. C4 — Ce que l'incrément NE referme PAS, et pourquoi

Les six conseils cliniques seedés portent le « 185 » en dur. Ce sont des **données déjà publiées**
sous gouvernance depuis L1+L2. Trois voies, et la troisième a été retenue :

- réécrire le seeder → **sans effet** sur ce qui est diffusé (c'est exactement la garantie construite
  par L1+L2) ;
- publier une version corrigée → **acte de gouvernance clinique**, qui modifie des conseils médicaux
  publiés et n'appartient pas à un incrément portant sur les numéros d'urgence ;
- **le dire comme limite, avec un porteur nommé : P10**, qui refond déjà le triage.

*Nommer un manque ne le comble pas, mais un manque nommé ne s'oublie pas.*

---

## 9. Limites annoncées

1. **Aucun des trois numéros n'a été confronté à un arrêté** — le corpus ne nomme que le SAMU 185,
   le 100 et le 180 viennent d'une déclaration du propriétaire. Charger les valeurs officielles est
   **de la donnée, zéro code** ; tant que ce n'est pas fait, **ce n'est pas un référentiel national**,
   et l'écran le dit.
2. **Les conseils cliniques seedés gardent le numéro en dur** (§8) — porteur **P10**.
3. **Le repli de niveau 3 est une valeur compilée** : une installation neuve jamais connectée compose
   ce que l'application a été livrée avec. C'est voulu, c'est dit, et c'est le prix de la contrainte
   du §2.
4. **Les « compléments du découpage »** (communes/quartiers en texte libre, jeu partiel de régions)
   sont **hors périmètre** (C5) : de la donnée, pas du code — ils restent à ADR-026 (limite M4).
5. **Aucun écran mobile de gouvernance** — comme tous les référentiels depuis P6.3.
6. **L1/L2 d'ADR-025 ne s'appliquent pas ici** : les consommateurs sont neufs ou ont été basculés,
   il n'en reste aucun qui lise la table en direct.
