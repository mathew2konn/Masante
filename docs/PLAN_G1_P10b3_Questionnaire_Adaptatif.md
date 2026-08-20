# PLAN G1 — P10b-3 · Questionnaire adaptatif (CDC_08 §4.3b, §4.4, §13 étape 4 ; CDC_05 §5.5.2)

> Statut : **plan soumis au G1**. Décisions Q1/Q2/Q3 validées par le propriétaire le 2026-08-19.
> Aucune ligne de code écrite avant validation écrite de ce document.
>
> Prédécesseurs : **P10b-1** (registre, moteur, niveau de triage gouverné — ADR-041),
> **P10b-2** (sélecteur, ordre §3, conflits §8, journal §10 — ADR-041 §B2), **P10a** (orientation
> gouvernée — ADR-040).

---

## 0. Ce que cet incrément doit refermer, en une phrase

P10b-1 a sorti du code les **seuils de niveau** et les a soumis aux quatre validations du §7.
Il a laissé derrière lui, dans le référentiel des symptômes, une règle **de même nature et de même
gravité** — « Température > 40 °C → +15 points **et drapeau rouge** » — qui décide de l'urgence
d'un citoyen sans avoir jamais été relue par un médecin. P10b-3 referme cette asymétrie, et c'est
en la refermant qu'il obtient l'adaptativité que le corpus demande par ailleurs.

---

## 1. G0 — ce qui a réellement été lu

Lecture effective de `TriageService`, `AnalyserTriageRequest`, `QuestionsScreen.tsx`,
`TriageFlow.tsx`, `SourceSymptomesTriage`, `SymptomeSeeder`, les deux migrations `protocole*`, les
quatre registres (`Faits`, `Operateurs`, `Actions`, `Contextes`), `MoteurProtocole`,
`CompilateurProtocole`, `ControleQualiteProtocole`, et du corpus CDC_08 §4.3/§4.4/§5.4/§13,
CDC_05 §5.4/§5.5, CDC_04 §115.

### X1 — Le questionnaire est plat. W4 est confirmé, sa portée est plus petite qu'annoncée

`QuestionsScreen` pose **toutes** les questions de **tous** les symptômes cochés, sans aucune
conditionnalité. Le catalogue réel : **20 symptômes**, **8 clés de question distinctes**
(`duree_jours` et `intensite` étant partagées), soit au pire une dizaine de questions posées d'un
bloc.

**Ce qu'il faut dire honnêtement** : à cette échelle, les « 100 questions inutiles » de
CDC_05 §5.5.2 ne se produisent pas. Le défaut est **structurel** — *il n'existe aucun endroit où
écrire une condition* — et non encore douloureux. Il ne justifierait pas seul un incrément ; ce qui
le justifie est X3.

### X2 — Il n'existe aucun endroit où écrire « pose cette question si… »

Ni colonne, ni table. `protocole_questions` / `protocole_reponses` du §4.4 n'ont pas été créées,
délibérément : la migration de b-1 l'écrit noir sur blanc (*« les créer vides serait le socle à
vide refusé par D3 de P6.3 »*). L'incrément qui les crée est celui qui leur donne un consommateur.

### X3 — Le constat central, et il n'était **pas** au plan G1 de P10b

L'impact d'une réponse sur le score **est une règle médicale**, et il échappe au régime du §7.
Dans `SymptomeSeeder` :

```php
['cle' => 'fievre_sup_40', 'libelle' => 'Température supérieure à 40°C ?',
 'type' => 'booleen', 'impact' => ['points_si_vrai' => 15, 'drapeau_rouge_si_vrai' => true]],
```

C'est **mot pour mot** le contre-exemple du §1.2 (`if temperature > 39: urgence = True`), exprimé
en donnée. Un drapeau rouge force le niveau `urgence` : cette ligne décide **autant** que la bande
de score que b-1 a sortie du code.

Elle est gouvernée (quatre-yeux §10 depuis P10a) mais **pas validée** : deux signatures
administratives là où le §7 en exige quatre, dont la clinique et la réglementaire. b-1 a soumis
les seuils de niveau à ces quatre validations et a laissé celle-ci derrière — **asymétrie non vue
au G1 de P10b**.

### X4 — Une règle publiée que le serveur n'applique pas

`AnalyserTriageRequest` valide `reponses.*.cle` en `string|max:100` et `reponses.*.valeur` en
`present`. Conséquence vérifiée dans `TriageService::evaluerReponses()` : une échelle publiée
`min:1 max:10` **n'est pas bornée** —

```php
$points = (int) round(((float) $valeur) * $coef);   // aucun contrôle de plage
```

`intensite = 100` × `coef 1.2` = 120 points, le score sature à 100, le niveau devient `urgence`.
Une clé inconnue, elle, vaut 0 point **en silence**.

Portée modeste — le triage est déclaratif de bout en bout, un patient peut de toute façon cocher
des symptômes qu'il n'a pas. Mais le référentiel **publie** une plage et le serveur **l'ignore** :
c'est une garantie affichée et inactive, la famille de défaut que ce projet ferme depuis P6.3.

### X5 — Deux listes parallèles que rien ne tient ensemble

```php
['cle' => 'type_toux', 'type' => 'choix',
 'options' => ['seche', 'grasse'],
 'impact'  => ['points_par_option' => ['seche' => 3, 'grasse' => 5]]],
```

`options` et `points_par_option` sont **deux listes du même fait**. Aujourd'hui elles coïncident ;
rien ne l'impose. Une option présente dans l'une et absente de l'autre marque **0 point sans
bruit** ; une entrée d'impact sans option est **inatteignable**. C'est la « deux vérités » refusée
en P6.6a (interactions en colonne JSON *et* en table) et en P6.8c (libellé officiel).

### X6 — `reponse.<cle>` est absent du registre des faits, avec sa raison déjà écrite

`RegistreFaitsProtocole` le dit : *« il arrive en P10b-3 avec le questionnaire adaptatif, où la clé
pourra être confrontée aux questions de la version publiée. L'ajouter aujourd'hui ouvrirait un
suffixe libre sans rien pour le vérifier. »* Cet incrément doit fournir ce « rien ».

### X7 — `triage_reponses` (CDC_04 §115) n'existe pas

Les réponses vivent dans la colonne `triages.reponses_json`. C'est le reliquat W6 du plan de P10b :
P10a a livré le QR et `referentiel_version`, b-1 `protocole_code`/`protocole_version` ; il reste la
table et — hors périmètre ici — `model_version` (P10c).

### X8 — Aucun écran d'authoring, et le §7 fait signer des médecins

Sept référentiels ont un écran de portail (analyses, assurances, maladies, médicaments, numéros
d'urgence, spécialités, vaccins). `symptomes_triage`, `seuils_mesure` et **les protocoles** n'en ont
aucun : les quatre validations du §7 s'obtiennent aujourd'hui par `POST` curl. Un médecin
spécialiste ne signe pas par curl un document que le §7 qualifie d'**opposable**.

---

## 2. Le point de conception — où vit la conditionnalité

Les questions vivent aujourd'hui dans `questions_complementaires_json`, **dans l'instantané publié**
du référentiel `symptomes_triage`. Le §4.4 les nomme, lui, dans le registre des **protocoles**.

On a cherché à les y laisser en ne déplaçant que l'arborescence. **Cela ne tient pas**, et
l'argument est décisif :

> Les deux artefacts ont des cycles de publication **indépendants**. Ajouter une question au
> référentiel puis son nœud d'arbre au protocole ne peut jamais se faire atomiquement : chaque
> contrôle qualité bloquerait l'autre, dans les deux sens, sans ordre qui débloque.

C'est exactement l'argument déjà retenu **trois fois** dans ce projet — interactions + produits dans
un seul instantané (P6.6a), strates + analyses (P6.7a), vaccins + échéances (P6.8b) : *les publier
séparément laisserait une référence irrésoluble*.

**Donc : question, condition et impact voyagent ensemble.** Q1 a tranché où : au protocole.

---

## 3. Décisions validées au G1 (2026-08-19)

### Q1 — Les questions déménagent vers le protocole **(validé : option A)**

Conforme au §4.4 littéral, et surtout : l'impact d'une réponse passe sous les **quatre validations
du §7**, ce qui referme X3.

**Coûts assumés, dits avant de coder** :

- `symptomes.questions_complementaires_json` **sort de l'instantané publié** de `symptomes_triage`
  et cesse d'être écrite. La colonne et ses données **restent** (ADR-024) — précédents exacts :
  `specialite_hint` (P10a), `vaccinations.statut` (P6.8b), `cmu_*` (P6.8d), `specialites_json`
  (P6.4d). Une migration destructive perdrait de l'information réelle pour un gain nul.
- **L'empreinte du référentiel `symptomes_triage` change**, et une **republication** est nécessaire.
  Ce n'est pas une dérive : c'est le geste de gouvernance qui constate le déménagement.
- **Une étape de déploiement de plus** avant qu'un triage fonctionne. Il y en a déjà deux
  (référentiel des symptômes, protocole de niveau) ; il y en aura trois.

### Q2 — L'écran §7 : **lire et signer**, pas éditer **(validé)**

Un éditeur de règles complet en Blade serait le plus gros investissement Blade du projet, dans un
portail qu'ADR-011 condamne et dont **K1 de P6.4d** a fait de la migration un module identifié.

Ce qui ne peut honnêtement pas se faire en curl, c'est **la lecture avant signature**. L'édition
reste à l'API, comme pour les dix référentiels. L'écran affichera le protocole compilé en langue
claire — les libellés du registre des faits existent pour cela : *« leur présenter `score >= 76`
sans phrase reviendrait à leur faire signer du code »*.

**Va en b-3-ii.**

### Q3 — Deux sous-incréments **(validé)**

| | Périmètre |
|---|---|
| **b-3-i** | Le **questionnaire devient un protocole** : `protocole_questions`, `protocole_reponses`, action `POSER_QUESTION`, fait `reponse.<cle>`, action `AJOUTER_SCORE`, boucle de point fixe, bornes (X4), `triage_reponses`, écran mobile adaptatif |
| **b-3-ii** | Le **reste du score devient protocolaire** (poids des symptômes, `PLAFOND_ANTECEDENTS`) + **écran §7 de lecture et signature** |

Raison du découpage, la même qui a valu quatre incréments à P5.5 et cinq à P6.8 : *un incrément
dont on ne peut pas énumérer les vecteurs au G1 n'est pas prêt*.

---

## 4. Décisions de conception de b-3-i (à valider)

### R1 — La conditionnalité est une **règle**, pas une colonne

```
SI symptome_categorie contient « respiratoire »   ALORS POSER_QUESTION(au_repos)
```

Aucune table de conditions nouvelle : les règles, conditions et actions de b-1 portent
l'arborescence telle quelle. Conséquence — l'arborescence hérite **des trois listes blanches
fermées**, du contrôle qualité, du quatre-yeux, de l'anti-substitution et de la chaîne d'audit,
**sans une ligne de moteur nouvelle**.

C'est aussi la lecture juste du §4.3 : le corpus présente les règles déclaratives (a) et le graphe
décisionnel (b) comme **« deux représentations complémentaires »**, pas comme deux moteurs.

**Écarté** : une colonne `condition_json` sur la question — elle serait évaluée par un second
chemin, donc une seconde façon d'écrire une condition, la « deux vérités » qu'on vient de refuser
au §2. **Écarté aussi** : un `question_id` nullable ajouté à `protocole_conditions` avec un `CHECK`
d'exclusivité — **impossible sous MySQL 8.4** (erreur 3823, colonnes en `cascadeOnDelete` : le mur
de P6.3, cousin du 1215 de P6.1).

### R2 — `protocole_questions` porte l'énoncé, `protocole_reponses` porte les réponses possibles

Le §4.4 nomme les deux. Deux lectures étaient possibles pour `protocole_reponses` : les réponses
**possibles** d'une question, ou les réponses **données** par un patient. La seconde ferait doublon
avec `triage_reponses` du §115 — donc la première.

Et elle referme X5 **par construction** : une ligne = une réponse possible = libellé + impact, avec
`UNIQUE(question_id, valeur)`. Les deux listes parallèles deviennent **inexprimables** plutôt
qu'interdites — même geste qu'en P6.8c, où l'absence de colonne `type` rend la seconde vérité
impossible à écrire.

### R3 — L'impact **non énumérable** est une règle, pas une configuration

`echelle` porte un `coef`, `nombre` un `seuil` + `points_si_superieur` : ce ne sont pas des
réponses énumérables. Les laisser en JSON sur la question ramènerait la règle médicale hors du §7 —
c'est-à-dire ramènerait X3.

Ils deviennent donc des **règles**, via une action neuve `AJOUTER_SCORE` :

```
SI reponse.intensite      >= 8       ALORS AJOUTER_SCORE 10
SI reponse.duree_jours    > 3        ALORS AJOUTER_SCORE 8
SI reponse.fievre_sup_40  = vrai     ALORS AJOUTER_SCORE 15
                                      ET  DEFINIR_SCORE_MINIMUM 90
```

Deux gains, et le second n'était pas cherché :

1. Tout impact passe sous les quatre validations du §7 — X3 est refermé.
2. **Le drapeau rouge d'une réponse cesse d'être un booléen caché** (`drapeau_rouge_si_vrai`) et
   redevient ce qu'il est : un **plancher de score**, exprimé par l'action que b-1 a déjà créée
   pour le drapeau rouge des symptômes. Une seule façon de dire « ceci prime », au lieu de deux.

### R4 — `reponse.<cle>` : le suffixe est **vérifié contre les questions de la version publiée**

C'est la condition que b-1 posait pour ouvrir ce fait. Le contrôle qualité **refuse la publication**
d'une condition portant `reponse.X` si `X` n'est pas une question de **cette version** ; le moteur
**lève** s'il en rencontre un à l'exécution — jamais « faux », la décision centrale de b-1.

Le **type** du fait est celui déclaré par la question (`nombre`, `booleen`, `texte`), ce qui rend
le contrôle de compatibilité fait/opérateur de b-1 applicable sans modification : `>=` sur une
question `booleen` est refusé à la publication.

### R5 — La boucle de point fixe, et **une seule évaluation fait autorité**

Le questionnaire est itératif : une réponse débloque la question suivante (§4.3b,
`Fièvre → Durée ? → Âge ? → Difficulté respiratoire ? → …`).

```
faits ← {symptomes, age, sexe}
répéter :
    actions   ← MoteurProtocole::evaluer(questionnaire, faits)
    nouvelles ← POSER_QUESTION(actions) \ déjà posées
    si nouvelles = ∅ : sortir
    poser(nouvelles) ; faits ← faits + réponses
```

**Le point qui n'est pas évident et qui doit être écrit** : une règle peut se déclencher au tour 1
**et** au tour 3. Si l'on cumulait les `AJOUTER_SCORE` de chaque tour, **le score dépendrait du
nombre d'allers-retours** — c'est-à-dire de la façon dont le client a répondu, pas de ce qu'il a
répondu.

Donc : les tours intermédiaires servent **uniquement** à savoir quelles questions poser ; le score
est produit par **une évaluation finale unique** sur le jeu de faits complet. Le moteur reste pur
et inchangé — c'est l'appelant qui décide ce qu'il consomme.

**Vecteur obligatoire** : le même jeu de réponses, obtenu en 1 tour ou en 4, doit produire
**exactement le même score et le même niveau**.

### R6 — Le questionnaire est un **producteur de faits**, pas un chaînage entre protocoles

b-2 a posé que *« le chaînage avant ne franchit jamais la frontière entre deux protocoles, sinon
l'ordre d'évaluation changerait le résultat »*. Un `AJOUTER_SCORE` du protocole *questionnaire* qui
alimente le fait `score` du protocole *niveau* **ressemble** à cette violation. Il ne l'est pas, et
la distinction doit être écrite :

- Ce que b-2 interdit : que **le moteur** transporte des faits d'un protocole à l'autre **au sein
  d'une même évaluation**.
- Ce qui se passe ici : `TriageService` **assemble** des faits en phases, puis appelle le protocole
  de niveau — ce qu'il fait **déjà** depuis b-1 avec les poids des symptômes.

Le questionnaire devient simplement un assembleur de faits **gouverné** là où il était un
assembleur de faits **codé**. L'ordre des phases est fixe et ne dépend d'aucune donnée :
questionnaire → assemblage du score → sélection et évaluation des protocoles de niveau.

### R7 — Les bornes publiées deviennent opposables (referme X4)

La valeur d'une réponse est confrontée à la définition de la version publiée **avant** tout calcul :
type, plage `min`/`max` d'une échelle, appartenance aux `protocole_reponses` d'un choix. Hors
plage → **422 nommant la question**, jamais un silence ni un écrêtage.

**Écarté : écrêter au maximum.** Ramener `intensite = 100` à `10` accepterait une saisie fausse en
la corrigeant sans le dire, et le patient croirait avoir répondu 100. Le refus explicite est la
seule réponse honnête — précédent des refus « par leur motif » de P6.5b et P6.8e.

Une clé **inconnue de la version publiée** est refusée de la même façon, au lieu de valoir 0 point
en silence.

### R8 — `triage_reponses` (§115) naît ; `reponses_json` garde l'histoire

Nouvelle table : `triage_id`, `question_cle`, `question_libelle` **figé**, `valeur`, `points`,
`protocole_code`, `protocole_version`.

Le libellé est **figé**, jamais résolu à la lecture : motif P6.6b (DCI et dosage figés dans une
ordonnance), P7-D2 (établissement copié dans le journal d'accès), P10a (libellé d'orientation figé
dans l'instantané). Republier le questionnaire ne doit pas réécrire ce qu'un patient a lu.

`triages.reponses_json` **n'est plus écrite** pour les triages neufs et **reste** pour les anciens
(précédent L2 : `mesures_sante.referentiel_version` nullable, jamais rétroactive — *leur inventer
une valeur serait un mensonge d'archive*). La fiche §5.4 lit la table quand elle a des lignes, la
colonne sinon.

**Ce n'est pas un repli sur une règle** — c'est la lecture d'archives dans la forme où elles ont été
écrites ; la distinction avec le repli refusé en L1+L2 est dite ici pour qu'on ne la confonde pas.

### R9 — Contenu : le questionnaire actuel est **transcrit**, jamais enrichi

Les 8 questions existantes et leurs impacts deviennent le protocole `TRIAGE-QUESTIONNAIRE`,
**à l'identique**, plus les conditions minimales qui rendent l'adaptativité démontrable.

C'est la même distinction qu'en b-2 (**O1**) : `TRIAGE-NIVEAU` **transcrivait** des seuils déjà
codés — acceptable ; un protocole thérapeutique serait **inventé** — refusé (**N3**). Transcrire ne
fabrique aucune affirmation clinique neuve. Étiquetage inchangé : `niveau_preuve = 'D'`, organisme
disant que la source n'a pas été fournie, **aucun validateur forgé**.

**Publication par un acte de gouvernance réel à deux agents habilités, jamais par un seeder** —
précédent b-2.

---

## 5. Ce que b-3-i livre, objet par objet

| Objet | Nature |
|---|---|
| `protocole_questions` | `version_id`, `cle`, `libelle`, `type`, `unite`, `min`, `max`, `ordre` — `UNIQUE(version_id, cle)` |
| `protocole_reponses` | `question_id`, `valeur`, `libelle`, `ordre` — `UNIQUE(question_id, valeur)` |
| `triage_reponses` | §115 — voir R8 |
| `RegistreActionsProtocole` | += `POSER_QUESTION`, `AJOUTER_SCORE` — liste blanche fermée, **non exclusives** : deux protocoles qui posent la même question ne sont pas en conflit (motif b-2) |
| `RegistreFaitsProtocole` | += `reponse.<cle>`, **type fourni par la question**, suffixe vérifié (R4) |
| `RegistreContextesProtocole` | **inchangé** — le questionnaire est du contexte `triage` |
| `MoteurProtocole` | **inchangé**. C'est le test de la conception : si le moteur doit bouger, c'est que l'arborescence n'était pas une règle |
| `TriageService` | phases explicites (R6) ; `evaluerReponses()` **disparaît** avec ses quatre `elseif` de calcul d'impact |
| `SourceSymptomesTriage` | `questions_complementaires_json` **sort** de l'instantané |
| API | `POST /triage/questions` (tour de questionnaire) ; contrat de sortie de `POST /triage/analyser` **inchangé** |
| Mobile | `QuestionsScreen` devient adaptatif ; les définitions viennent du serveur, jamais du cache d'un ancien référentiel |

**Aucune dépendance nouvelle.**

---

## 6. Vecteurs G3 — énumérés au G1, c'est la condition posée en Q3

**Le moteur (purs, sans base)**

1. `POSER_QUESTION` sur condition remplie → question rendue ; non remplie → absente.
2. `reponse.X` inconnu du protocole → **lève**, ne vaut pas faux.
3. `AJOUTER_SCORE` cumulatif au sein d'**une** évaluation.
4. `existe` / `absent` sur `reponse.X` : question non répondue ≠ répondue « non ».

**La boucle**

5. **Point fixe** : 1 tour vs 4 tours, mêmes réponses → **même score, même niveau**.
6. Convergence : aucune nouvelle question → arrêt ; pas de boucle infinie sur une règle qui se
   redéclenche.
7. Une question déjà posée n'est jamais reposée.

**Les bornes (R7)**

8. `intensite = 100` sur une échelle 1-10 → **422 nommant la question**, aucun écrêtage.
9. Clé absente de la version publiée → **422**, jamais 0 point silencieux.
10. Valeur hors `protocole_reponses` d'un choix → **422**.
11. Type incompatible (texte sur une question `nombre`) → **422**.

**La gouvernance**

12. Condition portant `reponse.X` inexistante dans la version → **publication refusée**, message
    nommant la clé.
13. Opérateur incompatible avec le type de la question → publication refusée.
14. Question de type `choix` sans aucune réponse possible → publication refusée.
15. Quatre-yeux refusé **par son motif** — leçon P6.8e et b-1 : *un refus pour la mauvaise raison
    ne prouve rien*.
16. Anti-substitution : modifier une question après signature → les quatre validations deviennent
    caduques.

**Le déménagement**

17. `questions_complementaires_json` **absente** de l'instantané publié de `symptomes_triage`.
18. Un `UPDATE` direct sur `symptomes.questions_complementaires_json` **n'a aucun effet** sur le
    questionnaire servi.
19. Refus bruyant **503** tant que `TRIAGE-QUESTIONNAIRE` n'est pas publié — jamais de repli sur la
    colonne (précédent L1+L2 ; l'argument de P6.8e ne s'applique pas, le consommateur est en ligne).

**L'archive**

20. Un triage antérieur reste **relisible** : `reponses_json` servie, `triage_reponses` vide.
21. Libellé figé : renommer la question au protocole **ne change pas** ce qu'un triage passé
    affiche.

### Mutations prévues

Chacune **assertée appliquée**, sur **une seule ligne d'ancre**, arbre restauré vérifié par `diff`
— les trois leçons cumulées de P6.7b (asserter l'application), P6.8d (asserter le **site**) et
P6.8e (ancre mono-ligne, restauration vérifiée).

| Garde neutralisée | Vecteurs qui doivent mourir |
|---|---|
| R4 — vérification du suffixe `reponse.X` | 2, 12 |
| R5 — évaluation finale unique | 5 |
| R7 — contrôle de plage | 8, 9, 10, 11 |
| R8 — libellé figé | 21 |
| Refus bruyant avant publication | 19 |
| Sortie de l'instantané | 17, 18 |

**Piège déjà rencontré quatre fois** : un vecteur qui passe par HTTP prouve le **validateur**, pas
la garde. Les vecteurs 9/10/11 sont **dédoublés** — un par la requête, un appelant le service
**directement**, comme le ferait un import (parade de P6.6b).

---

## 7. Vecteurs G2 live (MySQL réel, base restaurée compte par compte)

| | Vecteur |
|---|---|
| W1 | Schéma + index uniques en base |
| W2 | **503** avant publication, sur `/triage/questions` **et** `/triage/analyser` |
| W3 | Publication à deux agents ; quatre-yeux refusé **par son motif** |
| W4 | Arborescence réelle : fièvre seule → 2 questions ; fièvre + « > 40 °C » → question supplémentaire **et** niveau `urgence` par plancher de score |
| W5 | `intensite = 100` → 422 nommant la question |
| W6 | Clé inconnue → 422 |
| W7 | `UPDATE` direct sur `symptomes` → **sans effet** |
| W8 | Instantané `symptomes_triage` **sans** `questions_complementaires_json` ; empreinte changée puis rétablie |
| W9 | Même jeu de réponses en 1 et 4 tours → même score |
| W10 | `triage_reponses` peuplée, libellé figé, `reponses_json` **non écrite** |
| W11 | Triage antérieur toujours lisible |
| W12 | Anti-substitution → 409 nommant les signatures caduques |
| W13 | Chaîne d'audit intacte ; **0 contenu clinique** dans `protocole_journal` |

---

## 8. Limites qui seront annoncées au G5

1. **Le poids des symptômes et `PLAFOND_ANTECEDENTS` restent dans le code** → b-3-ii. X3 n'est
   refermé **que pour les réponses**.
2. **Aucun écran §7** → b-3-ii ; les quatre validations restent en curl.
3. **Un aller-retour réseau par tour de questionnaire.** Compiler l'arbre côté client
   l'accélérerait et mettrait une **règle médicale dans le front** — la règle de frontière
   l'interdit. Atténué en rendant **toutes** les questions actuellement déblocables à chaque tour,
   pas une seule ; le coût est dit, pas déguisé.
4. **Contenu = transcription de démonstration** (8 questions, `niveau_preuve = 'D'`). Le gain n'est
   pas qu'elles soient justes, c'est qu'elles soient **relisibles, signées et corrigibles sans
   déploiement**.
5. **Aucun questionnaire hors triage** (§5.1-5.3) : la structure les accueille, le contenu attend
   ses validateurs (N3 de P10b, inchangé).
6. **`model_version` du §115 reste dû** → P10c.
7. **§11 (< 100 ms P95) toujours non déclaré atteint** : cache `database`, et la boucle multiplie
   les appels.
8. **Aucune compréhension du langage naturel** (CDC_05 §5.5.1) : c'est P10c, et le questionnaire
   adaptatif est précisément ce qui « permet le triage **sans IA** » (§13 étape 4).

---

## 9. Ce que b-3-ii portera (pour mémoire, non validé ici)

Poids des symptômes et `PLAFOND_ANTECEDENTS` sous protocole ; écran de **lecture et signature** du
§7 en Blade (Q2), avec le protocole rendu en langue claire à partir des libellés du registre des
faits ; et la question, à reposer alors, de savoir si `symptomes_triage` conserve encore autre chose
que l'identité des symptômes.
