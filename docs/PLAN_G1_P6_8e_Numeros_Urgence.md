# Plan G1 — P6.8e Numéros d'urgence nationaux (CDC_09 §8)

> Dernier incrément de **P6.8** → l'étape **8** de l'ordre de construction du §14 sera complète.
> Referme **T4**.
>
> Statut : **G5 — MODULE VALIDÉ le 2026-08-15** (G4 propriétaire OK). G1 validé le même jour.
> Décisions : **C1** chaîne de repli à trois niveaux, sans avertissement à l'écran · **C2** trois
> numéros livrés (SAMU 185 + **police 100 et pompiers 180, déclarés par le propriétaire**) ·
> **C3** entrée sous gouvernance §10 · **C4** conseils cliniques seedés = limite annoncée, porteur
> **P10** · **C5** compléments du découpage **hors périmètre**, laissés à ADR-026.

---

## 1. G0 — ce que le code dit réellement

Audit conduit en lisant les fichiers, pas les commentaires. **Le plan de P6.8 annonçait « deux
copies ». Il y en a quatre familles, et la plus dangereuse n'était pas celle qui était nommée.**

### E1 — Le `185` vit dans **quatre familles de sites**, pas deux

| # | Site | Nature | Consommateurs réels |
|---|---|---|---|
| 1 | `TriageService::NUMERO_SAMU = '185'` | constante **backend** | 1 (le texte de recommandation `urgent`) |
| 2 | `apps/mobile/src/config/constants.ts:9` `SAMU_NUMERO` | constante **client** | **5** : `SosButton`, `SosEcran`, `CarteVitaleEcran`, `GrossesseEcran`, `urgence/sos.ts` |
| 3 | `packages/shared/src/i18n/fr.ts:39` + `en.ts:38` — `urgence.sos: 'SOS 185'` | **le numéro collé dans une chaîne de traduction** | **aucun** — clé **morte** |
| 4 | Textes de conseil **seedés** : `ReferentielMesureSeeder` (×6), `EtapePrenataleSeeder` (×1) | **donnée**, dont 6 lignes **déjà sous gouvernance** (`seuils_mesure`, L1+L2) | les écrans de mesures et de grossesse |

Deux constats qui ne figuraient pas au plan de P6.8 :

- **(3) est une clé morte dans `@masante/shared`** — donc dans la *source unique*. Personne ne
  l'appelle (`grep` sur `urgence.sos` : zéro consommateur ; les deux résultats sont des imports du
  module `urgence/sos.ts`, sans rapport). C'est la **6ᵉ colonne/clé dormante** du projet après
  `donnees_ajoutees`, la provenance des vaccinations, `tokens_qr.used_by_etablissement`,
  `specialites_json` et `maladies_probables_json`. *Une chaîne de traduction avec un numéro collé
  dedans est le pire des sites : elle a l'apparence de la source unique et se périme en silence.*
- **(4) place le numéro dans un référentiel DÉJÀ GOUVERNÉ.** Depuis L1+L2, les six conseils de
  `seuils_mesure` ne sont plus servis depuis la table mais depuis **l'instantané publié**. Le `185`
  y est donc figé dans une version signée. Ce site-là ne se corrige pas par du code (voir §5, C4).

**Faux positif vérifié et écarté** : `welcome.blade.php` contient neuf « 185 » — ce sont des
**coordonnées de chemins SVG** du logo Laravel par défaut. Vérifié plutôt que supposé.

### E2 — Le corpus nomme exactement ce défaut

> CDC_02 §37 : « multi-pays par profil national (référentiels CDC_09, **rien en dur — y compris les
> numéros d'urgence** : SAMU 185 en CI) ».

L'exigence n'est donc pas déduite d'un principe général : elle est **écrite**, et elle nomme ce
site précis. C'est le seul incrément de P6.8 dans ce cas.

### E3 — **Le consommateur central n'a ni réseau, ni session, ni compte** — et c'est délibéré

C'est le constat qui commande toute la conception, et il n'a **aucun précédent dans P6**.

- `CarteVitaleEcran` : *« LECTURE SEULE, HORS CONNEXION, SANS AUTHENTIFICATION […] Elle ne lit QUE
  le cache local, jamais l'API : ni réseau, ni token, ni PIN. »* Elle est atteignable depuis
  **l'écran de connexion**, pour un secouriste qui ramasse le téléphone d'un inconscient (FN2).
- `urgence/sos.ts` : *« c'est le TÉLÉPHONE qui alerte, pas notre serveur […] demander au serveur qui
  alerter supposerait un réseau que l'on n'a précisément pas. »*
- `SosEcran` : *« RIEN N'ATTEND LE RÉSEAU. »*

Tous les référentiels de P6 ont pu poser un **refus bruyant** (503 avant la v1 : L1+L2, P6.8b,
P6.8d) parce que leur consommateur était un écran ordinaire, en ligne, authentifié. **Ici, un refus
bruyant signifie : pas de numéro d'urgence, dans une urgence.** Le motif ne se recopie pas.

### E4 — Le cache hors ligne existant **ne convient pas**, et le réutiliser casserait l'écran

`chargerVilles` (P6.4b) passe par `lireAvecCache` du cache chiffré P2 — le réflexe serait de faire
pareil. Or `SessionContext` appelle **`viderDossierCache()` à la déconnexion** (purge P2, voulue).
Un numéro d'urgence rangé là **disparaîtrait à la déconnexion**, c'est-à-dire précisément dans
l'état où se trouve le téléphone que consulte un secouriste. Le stockage doit donc être celui de la
carte vitale — `expo-secure-store`, qui **survit à la déconnexion** par construction.

### E5 — Le précédent d'endpoint public existe déjà

`GET /v1/villes` est **public**, avec le commentaire : *« l'écran a besoin de la liste des villes
AVANT toute connexion »*. Il n'y a donc rien à inventer sur ce point : P6.4b a déjà tranché qu'un
référentiel d'affichage peut être servi sans jeton.

### E6 — Le §8 dit « numéro**s** », et ce projet n'en connaît qu'un

`grep` sur tout le corpus : **le SAMU 185 est le seul numéro d'urgence nommé**, dix fois. Ni police,
ni pompiers, ni centre antipoison n'apparaissent nulle part. *Je n'en inventerai aucun* — un numéro
d'urgence faux est plus dangereux qu'un numéro absent, parce qu'il sera composé.

---

## 2. Le point de conception

> Tous les référentiels de P6 répondent à « **qu'est-ce qui fait autorité ?** ».
> Celui-ci répond d'abord à « **que compose-t-on quand plus rien ne fonctionne ?** ».

Un numéro d'urgence a deux propriétés qu'aucun autre référentiel de ce projet n'a réunies :

1. **il doit être disponible en défaillance totale** (pas de réseau, pas de compte, pas de cache) ;
2. **il se périme** (renumérotation, second pays) — et sa péremption a un coût vital.

La première interdit le refus bruyant. La seconde interdit la constante en dur. **La conception
doit satisfaire les deux à la fois**, et c'est la seule chose qui rend cet incrément différent des
neuf référentiels précédents.

---

## 3. Décisions demandées au propriétaire

### **C1 — La chaîne de repli : ce qu'on compose quand tout manque** ⟵ *décision centrale*

Ma recommandation : **trois niveaux, dans cet ordre, et l'écran ne dit rien de la source.**

| Ordre | Source | Quand |
|---|---|---|
| 1 | Référentiel publié, mis en cache dans `SecureStore` au dernier passage en ligne | cas normal |
| 2 | Cache précédent, même périmé | hors ligne |
| 3 | **Valeur livrée avec l'application** (`185`, constante de dernier recours) | installation neuve jamais connectée |

Pourquoi je recommande ceci plutôt que le refus bruyant des incréments précédents : le refus
bruyant existe pour qu'**un oubli de publication ne passe pas inaperçu**. Cet argument tient quand
l'utilisateur peut réessayer plus tard. Devant un blessé, il n'y a pas de « plus tard ». *Le repli
n'est pas une entorse à la rigueur des neuf incréments précédents : c'est la même rigueur appliquée
à un consommateur dont la défaillance ne se rattrape pas.*

Et **l'écran ne dira pas « numéro par défaut, non vérifié »** : un avertissement affiché à quelqu'un
qui compose un numéro d'urgence est du bruit au pire moment. L'honnêteté sur le repli est due à
**l'exploitant** (elle sera visible côté portail et dans les journaux), pas au secouriste.

**Alternative que je ne recommande pas** : refus bruyant homogène avec P6.8b/P6.8d. Cohérent sur le
papier, mais il rendrait l'écran SOS inutilisable hors ligne — une régression sur un module validé
G5.

### **C2 — Le contenu livré**

Ma recommandation : **la structure accepte N numéros par pays ; le jeu livré n'en contient qu'un,
le SAMU 185**, seul numéro que le corpus nomme (E6). L'absence des autres est **comptée et
affichée** au portail — 4ᵉ application du motif `loinc` (P6.7a) / `code_cim` (P6.8c) /
`numero_agrement` (P6.8d). Charger la liste officielle sera **de la donnée, zéro code**, et tant que
ce n'est pas fait, **ce n'est pas un référentiel national** — l'écran le dira.

### **C3 — Entrée sous gouvernance §10 (quatre-yeux)**

Ma recommandation : **oui.** Changer un numéro d'urgence est l'acte d'autorité le plus littéral de
tout P6 — plus qu'un agrément d'assureur. Le repli de C1 lève l'objection qui aurait pu s'y opposer
(l'indisponibilité avant la v1). Permission **`urgence.referentiel`, portée par aucun rôle métier**
— **12ᵉ application** du précédent.

### **C4 — Les quatre familles de sites : lesquelles sont refermées ?**

| Site | Ce que je propose | Pourquoi |
|---|---|---|
| (1) `TriageService` | **refermé** — lit le référentiel | c'est du code, il est en ligne par nature |
| (2) constante mobile | **refermée** — devient le **niveau 3** de C1, plus jamais lue directement par un écran | un seul endroit compose le numéro |
| (3) clé i18n morte | **le numéro sort de la chaîne** (`'SOS'` + valeur injectée) | une traduction ne doit pas porter une donnée |
| (4) conseils seedés | **NON refermé — et c'est dit** | voir ci-dessous |

**Le site (4) mérite d'être expliqué plutôt que traité en silence.** Les six conseils de
`seuils_mesure` sont de la **donnée déjà publiée sous gouvernance**. Trois façons de faire :

- réécrire les textes du seeder → **sans effet** sur ce qui est diffusé, puisque L1+L2 fait lire
  l'instantané publié, pas la table (c'est exactement la garantie qu'on a construite) ;
- publier une nouvelle version du référentiel avec des textes paramétrés → **c'est un acte de
  gouvernance clinique**, pas une correction technique : il modifie des conseils médicaux publiés,
  et il n'appartient pas à cet incrément de le décider ;
- **le dire comme limite**, avec un porteur nommé.

Je recommande la troisième, et **le porteur est P10** — qui refond déjà le triage et devra
republier ces textes. *Nommer un manque ne le comble pas, mais un manque nommé ne s'oublie pas.*

---

## 4. Conception (sous réserve de C1→C4)

- **Table `numeros_urgence`** : `pays_code`, `code` (littéral `samu`, `police`… — c'est un **terme
  de nomenclature**, pas une instance : précédent `specialites`/`regions`, **pas** `ETS`/`VAC`),
  `numero`, `libelle`, `description`, `ordre`, `actif`, `source` **obligatoire**, `source_detail`.
  `UNIQUE(pays_code, code)` — un numéro d'urgence est **national par nature** (à l'inverse des
  maladies de P6.8c : question reposée, réponse opposée, pour la raison inverse).
- **`SourceNumerosUrgence`** + une ligne dans `RegistreReferentiels`. Projection = **ligne entière**
  (rien n'écrit automatiquement dans cette table, et elle est construite pour que ça reste vrai :
  aucun compteur d'appels — `alertes_sos` porte déjà les déclenchements).
- **Contrôles qualité bloquants** : provenance obligatoire ; **au moins un numéro actif** (publier
  un référentiel d'urgence vide serait pire que ne rien publier) ; numéro non vide et composable.
- **`GET /v1/numeros-urgence` public** (précédent E5), servi depuis la **version publiée**.
- **Mobile** : un seul module compose le numéro ; cache **`SecureStore`** (E4), rafraîchi à chaque
  passage en ligne ; les 5 consommateurs actuels lisent ce module. Aucun écran ne change de forme.
- **Portail** : écran de gouvernance + bandeau d'honnêteté (C2).

### Vecteurs en miroir exigés

1. Publier un numéro modifié → **l'écran SOS le compose** après rafraîchissement.
2. `UPDATE` direct en base → **aucun effet** sur le diffusé (garantie L1+L2).
3. **Mode avion, jamais connecté** → l'écran compose **185** (niveau 3) et **reste utilisable**.
4. **Déconnexion** → le numéro **survit** (E4 : ce serait le défaut si on avait pris le cache P2).
5. Second pays → un `SN` publié ne change pas ce que voit un utilisateur `CI`.

---

## 5. Preuves attendues

- **G3** — vecteurs dédiés dans les deux sens ; suite complète verte ; typecheck ×3 ;
  `expo-doctor` ; **mutation obligatoire**, chaque mutation **assertée appliquée ET sur le bon
  site** (piège de P6.7b, raffiné par P6.8d : `perl s///` remplace la **première** occurrence).
- **G2 live MySQL** — schéma et contraintes ; backfill dry-run → réel → rejeu ; doublon refusé par
  le moteur ; gouvernance à deux agents ; refus **par son motif** ; `UPDATE` direct sans effet ;
  portail 403/200 ; les cinq vecteurs du §4 ; **base restaurée compte par compte**.
- **G4** — `GUIDE_TEST_TRANSVERSES.md` **partie 5**, écrite avant le G4.

---

## 6. Limites qui seront annoncées

1. **Contenu = un seul numéro** (C2) — ni police, ni pompiers, ni antipoison : le corpus ne les
   nomme pas et je n'en invente aucun.
2. **Les conseils cliniques seedés gardent le `185` en dur** (C4), porteur **P10**.
3. **Le repli de niveau 3 est une valeur compilée** : une installation neuve jamais connectée
   compose ce que l'application a été livrée avec. C'est voulu, c'est dit, et c'est le prix de E3.
4. Aucun écran mobile de gouvernance — comme tous les référentiels depuis P6.3.
5. **« Compléments du découpage »** (communes/quartiers en texte libre, jeu partiel 1 région/33) :
   le plan de P6.8 les rattachait à cet incrément. Ils sont de la **donnée** (limite M4 d'ADR-026),
   pas du code — je propose de **ne pas les mélanger** à un sujet vital et de les laisser où
   ADR-026 les a mis. *À confirmer en C5 si le propriétaire veut les traiter ici.*
