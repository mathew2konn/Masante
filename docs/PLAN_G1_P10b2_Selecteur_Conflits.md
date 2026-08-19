# Plan G1 — P10b-2 : sélecteur, ordre de priorité §3, conflits §8, journal d'exécution §10

> **P10b est découpé en trois incréments** (décision N4, 2026-08-19) : **b-1** registre + moteur +
> niveau de triage (✅ VALIDÉ G5 2026-08-19) · **b-2** ce document · **b-3** questionnaire adaptatif
> §4.3b + écran d'authoring.
>
> Couvre l'**étape 3** du §13 : « sélecteur de protocoles + ordre de priorité + gestion des
> conflits + journal ».

---

## 1. G0 — ce qui a été lu, et ce qui a été trouvé

Lecture réelle : CDC_08 §3, §4.4, §8, §9.1, §10, §11 ; le plan G1 de P10b ; la migration livrée en
b-1 ; `DiffusionProtocole`, `ServiceNiveauTriage`, `MoteurProtocole`, `ControleQualiteProtocole`,
`ProtocoleController`, `TriageService`, `ProtocoleSeeder`, `routes/api.php`.

### X1 — Mon propre plan G1 se contredit sur `protocole_applications`

Son tableau de périmètre (§3, ligne 3) l'assigne à **b-2** ; son tableau des tables (§4 bis) le
liste parmi les **neuf** tables de b-1. La migration livrée en crée **huit**, et `protocole_journal`
y est la chaîne de **gouvernance** (qui a proposé, validé, publié), pas le journal d'**exécution**
du §10. C'est donc b-2 qui le porte.

*Dit plutôt que corrigé en silence* : une contradiction de plan qu'on rattrape sans la nommer se
retrouve plus tard sous la forme « pourquoi cette table n'existe-t-elle pas ? ».

### X2 — Le sélecteur n'aurait rien à sélectionner *(le constat central)*

Un seul protocole est publiable : `TRIAGE-NIVEAU`. Les deux thérapeutiques sont des brouillons
**par la décision N3**, et doivent le rester. Un sélecteur qui rend toujours le même protocole et un
résolveur de conflits qui ne se déclenche jamais seraient :

- le **socle à vide** refusé par la décision D3 de P6.3 ;
- et « un contrôle toujours vert ne prouve rien » — la leçon de P5.3b-4.

### X3 — Les deux derniers départages du §8 ne sont pas automatisables

Le §8 en énumère cinq. Les items **4** (« avis de la spécialité concernée ») et **5** (« validation
finale par le médecin ») sont des **actes humains**. Et « un conflit non résolu automatiquement est
présenté au médecin » suppose un médecin au point de soin — or l'unique consommateur est le
**triage citoyen**. Présenter deux niveaux divergents à un citoyen serait au mieux inutile, au pire
dangereux. Même impasse que N6 (pas d'écran soignant).

### X4 — Le §10 est à moitié tenu, et la moitié qui manque est réelle

`triages` porte déjà `protocole_code` + `protocole_version` (estampille posée en b-1). Ne sont
historisés **nulle part** :

| §10 exige | État |
|---|---|
| identifiant du patient | ✅ `triages.membre_id` / `user_id` |
| version exacte du protocole | ✅ estampille b-1 |
| date et heure d'exécution | ✅ `triages.created_at` |
| identifiant du professionnel | ❌ inexistant |
| **recommandations affichées** | ❌ les règles déclenchées sont calculées, rendues à l'écran, puis **jetées** |
| décision finale | ❌ inexistant |
| justification en cas d'écart | ❌ inexistant |

Et rien n'existe pour une évaluation **hors triage**.

### X5 — `POST /protocoles/evaluer` (§9.1) n'existe pas

Les `GET` du même §9.1 existent depuis b-1, filtres compris (`pays`, `domaine`, `specialite`,
`statut`). Le contrat d'évaluation, lui, n'a aucune surface.

### X6 — La notion de `contexte` n'existe nulle part

Le §9.1 la nomme (`triage|consultation|urgence`) ; zéro occurrence dans le code.

### X7 — Un protocole « hospitalier » ne peut être rattaché à aucun établissement

`niveau_source` accepte `hospitalier` depuis b-1, mais `protocoles` n'a pas de `structure_id`. Un
protocole « spécifique à l'établissement » (§3, rang 5) s'appliquerait aujourd'hui **au pays
entier** — exactement l'inverse de ce que le mot veut dire.

---

## 2. Décisions propriétaire (2026-08-19)

### O1 — Le contenu : **un second protocole de triage de démonstration**

Pour que le sélecteur sélectionne et que la cascade départage, il faut **deux** protocoles en
vigueur. Le second sera un protocole de triage supplémentaire, publié par un **acte de gouvernance
réel** — les quatre validations du §7 par des comptes distincts, comme `TRIAGE-NIVEAU` l'a été au
G2 de b-1 — et **jamais par un seeder**.

**La différence avec la décision N3, dite franchement.** `TRIAGE-NIVEAU` **transcrivait** des seuils
qui existaient déjà dans le code (le `match` de `TriageService`) ; le second protocole sera du
contenu clinique **inventé**. Ce qui le rend acceptable, et qui manquait à un protocole
thérapeutique :

- ce n'est **pas une posologie** — le §7 dit « opposable », et la pièce qu'on produirait devant un
  tribunal pour une posologie n'est pas de même nature qu'une bande de score ;
- il porte les **mêmes étiquettes d'honnêteté** que `TRIAGE-NIVEAU` : `niveau_preuve = 'D'`,
  organisme « Source non fournie — aucun document d'autorité consulté », auteur `NULL` ;
- c'est le **régime de tous les référentiels de P6** (18 médicaments, 21 maladies, 9 vaccins,
  8 analyses) : contenu de démonstration, étiqueté comme tel, remplaçable sans migration.

### O2 — Conflit non départageable : **interdire en amont, consigner, rendre le champ `conflits`**

Trois pièces, chacune à sa place :

1. **À la publication** — refus bloquant si la version entrerait en compétition sur une action
   exclusive avec un protocole déjà en vigueur **et que la seule chose qui les départagerait est la
   date de publication**.
2. **À l'exécution** — la cascade §8 est implémentée **littéralement**, récence comprise, et chaque
   divergence est consignée dans `protocole_conflits` (§8 : « toutes les divergences sont consignées
   dans un journal d'audit »).
3. **Dans la réponse** — le champ `conflits` du contrat §9.1 est rendu. La **présentation à un
   médecin** reste « conçue », faute d'écran soignant.

### O3 — Le journal d'exécution est **chaîné**, comme `referentiel_journal`

Cohérent avec les trois chaînes existantes et avec « journal immuable (CDC_10) » du §10. Une ligne
supprimée devient **détectable**, pas seulement bloquée.

**Coût assumé et écrit** : chaque écriture lit le maillon précédent sous verrou, ce qui sérialise
les triages concurrents. À mettre en regard du §11 (< 100 ms), déjà non déclaré atteint à cause du
cache `database`.

### O4 — La portée d'établissement est **différée**, et écrite comme limite

Pas de `structure_id`. Le rang « hospitalier » du §3 reste nommable mais sans portée réelle ; la
cascade est prouvée sur les rangs qui ont un sens aujourd'hui. L'ajouter sans écran où un
établissement rédige son protocole referait le socle à vide (précédents N6, M1 de P6.4a, O1 de
P6.4c).

---

## 3. Conception

### 3.1 La sélection est GROSSIÈRE ; l'applicabilité est décidée par les RÈGLES

Le sélecteur retient un protocole sur des **métadonnées de registre** : pays, `actif`, une version
en vigueur, non expirée, et le **contexte déclaré**. Il ne juge jamais si le protocole convient *à
ce patient-là*.

C'est le moteur qui tranche : un protocole sélectionné dont aucune règle ne se déclenche ne
recommande simplement **rien**. Un protocole réservé aux femmes enceintes l'exprime comme une
**condition de ses règles**, dans la liste blanche fermée qui existe déjà.

*L'alternative — un second langage d'applicabilité, à côté du langage de règles — aurait créé deux
façons d'écrire « ce protocole s'applique quand… », donc deux endroits où se tromper.*

### 3.2 Le contexte est une DONNÉE du protocole, pas une correspondance en code

`protocoles.contextes_json` (colonne additive) énumère les contextes où le protocole s'applique,
parmi une liste blanche fermée `triage | consultation | urgence` (§9.1).

Faire correspondre contexte → domaines **en code** aurait été une règle en dur de plus. Et la
colonne est **nullable** : un protocole qui ne dit pas quand il s'applique n'est **jamais**
sélectionné automatiquement — le contrôle qualité l'exige à la publication, jamais avant (motif des
métadonnées de b-1).

### 3.3 Chaque protocole est évalué INDÉPENDAMMENT, sur les mêmes faits initiaux

Pas de chaînage avant **entre** protocoles : le `DEFINIR_SCORE_MINIMUM` d'un protocole ne relève pas
le score vu par un autre.

*Sinon l'ordre d'évaluation changerait le résultat, et le §3 deviendrait un ordre de **calcul**
alors qu'il est un ordre de **départage**.* Le chaînage avant reste entier **à l'intérieur** d'un
protocole, tel que b-1 l'a livré.

### 3.4 Une action est EXCLUSIVE ou CUMULATIVE — et c'est le registre qui le dit

Un conflit n'existe que sur une action dont **une seule valeur peut prévaloir**. Deux `ORIENTER`
vers deux spécialités ne se contredisent pas : ils s'additionnent (c'est ce que P10a fait déjà).
Deux `MESSAGE` non plus.

`RegistreActionsProtocole` — la liste blanche fermée de b-1 — gagne donc la propriété
`EXCLUSIVES`. Aujourd'hui **`DEFINIR_NIVEAU` seul** y figure : un patient a un niveau de priorité,
pas deux. `DEFINIR_SCORE_MINIMUM` reste cumulatif (deux planchers : le plus haut s'applique, ce
n'est pas une divergence).

### 3.5 La cascade §8, en classe pure

`ReglesResolutionConflit`, sur le motif de `ReglesReversement` / `ReglesOrientation` /
`ReglesCalendrierVaccinal` : aucune base, aucune horloge, tout par paramètre.

| § | Critère | Automatisé ? |
|---|---|---|
| 1 | Rang §3 : `national` < `regional` < `oms` < `societe_savante` < `hospitalier` | ✅ |
| 2 | Le plus récent — **date de publication**, jamais le libellé de version | ✅ |
| 3 | Niveau de preuve le plus élevé : A > B > C > D | ✅ |
| 4 | Avis de la spécialité concernée | ❌ acte humain |
| 5 | Validation finale par le médecin | ❌ acte humain |

Les critères 4 et 5 **ne sont pas implémentés, et ce n'est pas un manque** : ce sont des actes
humains devant un dilemme clinique, pas des étapes de moteur. Prétendre les rendre donnerait à une
machine l'apparence d'un avis médical.

**L'ordre 2 est total** : `publie_le` puis le numéro de version — la raison exacte trouvée en b-1,
où deux signatures dans la même seconde étaient départagées par le moteur de base de données.

### 3.6 Pourquoi le contrôle de publication n'est pas toujours vert

Avec la récence dans la cascade, un conflit **non départageable** est presque impossible : deux
versions ne sont jamais publiées au même instant. Un contrôle qui refuserait « les conflits
insolubles » serait donc **toujours vert** — précisément ce que P5.3b-4 a appris à ne pas livrer.

Ce qui est refusé est autre chose, et c'est réel : **une version qui ne serait départagée que par la
date**. Être départagé par le rang (§3) ou par le niveau de preuve reflète une propriété **écrite,
relue et signée**. Être départagé par la date ne reflète **aucune décision** : le résultat bascule
au moment de la publication, pour des cas que les quatre validateurs du §7 n'ont jamais vus — ils
ont relu le protocole *isolément*.

**Divergence assumée avec le corpus, et dite** : le §8 autorise la récence comme critère 2 ; on est
donc **plus strict à la publication qu'à l'exécution**. Ce n'est pas une contradiction — le §8
décrit comment le moteur *résout*, le §7/§10 comment une version *entre en vigueur*. Le moteur, lui,
implémente le §8 en entier, récence comprise : des versions publiées avant ce contrôle existent.
Précédent : P6.4d a fait passer le couple région/district de la détection à l'interdiction alors que
le §4 n'exigeait que la cohérence.

### 3.7 Le journal d'exécution, et pourquoi l'estampille ne suffisait pas

`triages.protocole_code` porte **un** code. Dès que plusieurs protocoles contribuent, l'estampille
ne peut plus dire à elle seule ce qui s'est passé : elle nomme celui qui a **emporté l'action
exclusive**, le journal dit **tout le reste**.

Ce n'est donc pas une seconde copie de la même vérité (le défaut refusé en P6.3 et en P7-D0) : deux
faits différents, à deux endroits, chacun avec son usage.

**Ce journal stocke des recommandations cliniques** — `niveau: urgence`, `orienter: cardiologie` —
là où les trois autres journaux du projet n'en stockent aucune. C'est le §10 qui l'exige
(« recommandations affichées »), et c'est sa raison d'être : un journal d'exécution qui tairait ce
qui a été recommandé ne servirait à rien. Aucune donnée nouvelle n'est exposée pour autant — elle
est déjà dans `triages` —, et la lecture sera réservée aux rôles habilités.

Les colonnes `decision_finale` et `ecart_justification` du §10 **existent et resteront vides** tant
qu'aucun professionnel n'est dans la boucle : le triage citoyen n'a personne pour décider. Elles ne
sont pas un socle à vide — ce sont des attributs nullables d'une ligne qui existe, pas une capacité
sans consommateur — mais c'est une limite, et elle est écrite.

---

## 4. Ce qui est livré

### 4.1 Migration (additive)

| Objet | Rôle |
|---|---|
| `protocoles.contextes_json` | colonne nullable — les contextes §9.1 où le protocole s'applique |
| `protocole_applications` | journal d'exécution §10, **chaîné** : `trace_id`, patient, professionnel, contexte, protocole + **version exacte**, recommandations, décision finale, écart, `empreinte` / `empreinte_precedente` |
| `protocole_conflits` | §4.4 / §8 : une divergence constatée — les deux côtés, leurs sources, **le critère qui a départagé** |

Gardes du moteur : `protocole_applications` et `protocole_conflits` en **append-only par
déclencheurs** dans les deux dialectes (`SIGNAL 45000` / `RAISE(ABORT)`), `UPDATE` et `DELETE`
refusés y compris en SQL direct — motif de `referentiel_journal` (P6.3) et de `signature_journal`
(P6.5b), avec le rappel du mur MySQL 3823 : aucun `CHECK` ne peut porter sur une colonne à action
référentielle.

### 4.2 Classes

| Classe | Nature |
|---|---|
| `ReglesResolutionConflit` | **pure** — la cascade §3/§8, aucune base, aucune horloge |
| `ReglesSelectionProtocoles` | **pure** — quels protocoles sont candidats, sur des métadonnées |
| `RegistreContextesProtocole` | liste blanche fermée `triage/consultation/urgence` |
| `SelecteurProtocoles` | va chercher les candidats, les évalue, résout, consigne |
| `JournalApplicationProtocole` | la chaîne §10 |
| `ControleConflitsPublication` | le refus de §3.6, **hors** de `ControleQualiteProtocole` qui reste pur et content-only |

`RegistreActionsProtocole` gagne `EXCLUSIVES`. `ServiceNiveauTriage` cesse d'aller chercher un code
en dur et **délègue au sélecteur** pour le contexte `triage` : une seule voie vers le moteur, donc
aucune divergence possible entre le triage et `POST /protocoles/evaluer`.

### 4.3 Surface

- `POST /api/v1/protocoles/evaluer` (§9.1) — **gardé** : authentification + permission
  `protocole.evaluer`, **portée par aucun rôle métier** (14ᵉ occurrence du précédent). Le triage
  citoyen ne passe pas par cette porte : il appelle le même service en interne.
- `GET /api/v1/protocoles/applications` et `/{trace_id}` — lecture du journal, réservée.
- `GET /api/v1/protocoles/applications/integrite` — vérification de la chaîne, comme les trois
  autres journaux.

---

## 5. Les vecteurs — énumérés avant de coder

Un incrément dont on ne peut pas énumérer les vecteurs au G1 n'est pas prêt (leçon N4).

**Sélection**
1. Un protocole sans `contextes_json` n'est **jamais** sélectionné, même publié et en vigueur.
2. Un protocole dont la version est **expirée** n'est pas sélectionné.
3. Un protocole `actif = false` n'est pas sélectionné, mais ses versions archivées restent lisibles.
4. Un protocole sélectionné dont **aucune règle** ne se déclenche ne contribue à rien — et ce n'est
   pas une erreur.

**Cascade §3/§8** — quatre vecteurs, un par critère atteignable
5. Rang : national vs régional en divergence sur `DEFINIR_NIVEAU` → **le national l'emporte**, le
   conflit est consigné, le critère enregistré est `rang`.
6. Récence : même rang, mêmes preuves → le plus récemment publié l'emporte, critère `recence`.
7. Preuve : même rang, publication plus ancienne mais niveau **A** contre **D** → le A l'emporte,
   critère `niveau_preuve` — et il passe **avant** la récence.
8. Ordre total : deux publications dans la **même seconde** sont départagées de façon déterministe,
   jamais par l'ordre d'itération du moteur de base (le défaut trouvé en b-1).

**Cumul vs exclusivité**
9. Deux `ORIENTER` de deux protocoles → **les deux** sortent, aucun conflit consigné.
10. Deux `DEFINIR_NIVEAU` divergents → un seul sort, un conflit consigné.
11. Deux `DEFINIR_NIVEAU` **identiques** → aucun conflit (ce n'est pas une divergence).

**Interdiction à la publication (§3.6)**
12. Publier un second protocole de même rang, même niveau de preuve, en compétition sur
    `DEFINIR_NIVEAU` → **refusé**, et le refus **nomme** le protocole concurrent.
13. Le même, déclaré `regional` → **accepté** (le rang départage).
14. Le même, de rang égal mais niveau de preuve supérieur → **accepté**.
15. Un protocole en compétition sur une action **cumulative** seulement → **accepté**.

**Journal §10**
16. Un triage écrit exactement **une** ligne, portant la version exacte de chaque protocole évalué.
17. `UPDATE` et `DELETE` refusés par le moteur, en SQL direct (`ERROR 1644`).
18. Chaîne intacte → altérée → détectée → rétablie.
19. `decision_finale` et `ecart_justification` **nuls** sur un triage citoyen, et l'API le dit
    plutôt que de laisser croire à un oubli.
20. Le journal ne contient **aucune donnée d'identité** au-delà des identifiants — pas de nom, pas
    de symptôme en clair.

**Non-régression sur b-1 et P10a**
21. Avec `TRIAGE-NIVEAU` seul en vigueur, les résultats du G2 de b-1 sont **inchangés** — le
    sélecteur ne modifie rien tant qu'il n'y a qu'un protocole.
22. Refus bruyant conservé : aucun protocole de triage en vigueur → **503**, jamais un niveau
    inventé.
23. L'estampille `triages.protocole_code` désigne le protocole qui a **emporté** le niveau.

**Mutation** — une garde, un vecteur : contexte, expiration, exclusivité, chaque critère de la
cascade, l'ordre total, l'interdiction de publication, l'append-only. Chaque mutation **assertée
appliquée**, sur un **site unique**, arbre restauré vérifié par `diff` (leçons P6.7b, P6.8d, P6.8e).

---

## 6. Limites annoncées avant de coder

1. **Le rang `hospitalier` n'a aucune portée réelle** (décision O4) : pas de `structure_id`, aucun
   établissement ne peut rédiger son protocole.
2. **Les critères 4 et 5 du §8 ne sont pas implémentés** — actes humains. Un conflit qui les
   atteindrait n'existe pas aujourd'hui, puisque la récence départe toujours.
3. **La présentation d'un conflit à un médecin est « conçue », pas activée** : le champ `conflits`
   est rendu par l'API, aucun écran ne l'affiche (pas d'écran soignant — même impasse que N6).
4. **`decision_finale` / `ecart_justification` resteront vides** tant qu'aucun professionnel n'est
   dans la boucle.
5. **Contenu de démonstration** : le second protocole est inventé, étiqueté `D` / source absente.
   *Le gain n'est pas qu'il soit juste, mais que la machinerie de sélection et de départage soit
   exercée par du contenu réel plutôt que par des tests seuls.*
6. **§11 (< 100 ms) toujours non déclaré atteint**, et la chaîne du journal ajoute une écriture
   sérialisée par évaluation.
7. **Le questionnaire adaptatif reste en b-3**, avec le fait `reponse.<cle>` et
   `PLAFOND_ANTECEDENTS`.
8. **MFA toujours non exigé** pour l'édition des protocoles (§10) — `MFA_ENFORCE` fermé depuis P1.

---

## 7. Ce que ce module ne calcule pas

Test de fin de module (CLAUDE.md) : « quelles règles métier ce module calcule-t-il dans le front ? »
→ **aucune**. Le mobile et le portail affichent ; la sélection, l'évaluation, le départage et la
consignation sont entièrement backend.

Et le rappel qui vaut pour tout P10 : **le triage n'est jamais un diagnostic** (CDC_00 §4). Ce
module choisit quels protocoles appliquer et lequel l'emporte en cas de divergence ; il ne nomme
aucune maladie.
