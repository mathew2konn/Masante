# Plan G1 — P10b : Protocoles médicaux (CDC_08)

> **P10 est découpé en trois incréments** (décision propriétaire 2026-08-15) :
> **a** orientation + gouvernance du triage (✅ VALIDÉ G5 2026-08-19) · **b** protocoles médicaux
> CDC_08 · **c** microservice `triage-service` (IA, CDC_05 §5).
>
> L'ordre est **imposé par le corpus** : CDC_08 §9 dit que le moteur de protocoles **encadre**
> l'IA — sans protocoles, l'IA déciderait seule, ce que CDC_00 §4 interdit.
>
> Statut : **G1 VALIDÉ par le propriétaire le 2026-08-19.**
> Décisions : **N1** moteur en **Laravel** · **N2** registre **frère** du socle P6.3 · **N3**
> protocoles thérapeutiques en **BROUILLON non validé**, cycle complet prouvé sur le **triage** ·
> **N4** découpage **b-1 / b-2 / b-3** · **N5** les **4 niveaux patient dans b-1** · **N6** niveaux
> hospitaliers différés.

---

## 1. G0 — ce que le code dit réellement

Audit conduit en lisant le service de triage, le socle référentiel, les migrations, `@masante/shared`,
les tokens et les écrans mobiles. Six constats, dont trois n'étaient pas anticipés par le plan de P10a.

### W1 — Il n'existe **aucune ligne de protocole**, et c'est le seul constat rassurant

`grep -i protocol` sur `app/`, `database/`, `routes/`, `apps/`, `packages/` : **deux occurrences**,
toutes deux des commentaires de P10a citant CDC_04 §115. Aucune table, aucun modèle, aucun service,
aucun écran. P10b est un terrain vierge — mais posé **sur** un socle de gouvernance mûr (P6.3) et un
triage qui vient d'être assaini (P10a).

### W2 — Le niveau de soin est décidé par une **règle en dur**, et c'est l'interdit du §1.2 *en vigueur aujourd'hui*

`TriageService::niveauDepuisScore()` :

```php
return match (true) {
    $score <= 30 => 'leger',
    $score <= 65 => 'modere',
    default      => 'urgent',
};
```

Plus, dans le même service : `PLAFOND_ANTECEDENTS = 20` et `if ($drapeauRouge) { $score = max($score, 90); }`.

CDC_08 §1.2 donne son contre-exemple littéral : *« Interdit : `if temperature > 39: urgence = True` »*.
C'est **la même famille**. P10a a sorti l'**orientation** du code et laissé le **niveau** dedans —
or l'orientation dit vers quelle spécialité aller, le niveau dit **s'il faut courir aux urgences**.
C'est la plus lourde des deux, et c'est celle qui reste codée.

Elle est en plus **dans le schéma** : `triages.niveau` est un `ENUM('leger','modere','urgent')`.
Le nombre de niveaux de soin de la Côte d'Ivoire est aujourd'hui une contrainte de colonne MySQL.

### W3 — Le vocabulaire des niveaux a **divergé**, et c'est la source unique qui dort

| Où | Valeurs | Consommé par |
|---|---|---|
| `@masante/shared` → `TriageNiveauPatient` | `FAIBLE`, `RECOMMANDEE`, `RAPIDE`, `URGENCE` (les **4** de CDC_05 §5.3) | **personne** |
| `@masante/shared` → `TriageNiveauHospitalier` | `ROUGE`, `ORANGE`, `JAUNE`, `VERT`, `BLEU` (les **5**) | **personne** |
| `palette.json` → `triage` | les **9** couleurs correspondantes, depuis P0 | **personne** |
| `apps/mobile/src/types/triage.ts` | `Niveau = 'leger' \| 'modere' \| 'urgent'` — **redéfini localement** | tout le mobile |
| `triages.niveau` (MySQL) | `ENUM('leger','modere','urgent')` | tout le backend |

Trois faits en découlent, et aucun n'est cosmétique :

1. **La règle « source unique » de CLAUDE.md est enfreinte** : `Niveau` est redéfini dans le mobile
   alors que `@masante/shared` porte l'enum. C'est la famille du constat **G-a de P6.4b** (les
   communes d'Abidjan en dur) et de **U5 de P6.8b** (les libellés de statut vaccinal), à ceci près
   que la copie locale est **la mauvaise version** : elle a 3 valeurs, la bonne en a 4.
2. **Le projet produit 3 niveaux là où le corpus en exige 4** (CDC_05 §5.3, côté patient) et
   **n'a aucun niveau hospitalier** (les 5 de Manchester/ESI).
3. Le design system a peint les 9 couleurs dès P0 et **rien ne les importe** : septième famille de
   clé dormante du projet, après `loinc`, les codes CIM, `numero_agrement`, `specialites_json`,
   `maladies_probables_json` et `urgence.sos` — **la première où c'est la version dormante qui est
   juste**.

Et CDC_08 §5.4 tranche l'endroit où cela doit vivre : ces niveaux sont
**« paramétrables par pays »**. Ce ne sont donc pas des constantes de code : **ce sont des données
de protocole**. C'est précisément ce que P10b est fait pour porter.

### W4 — Le questionnaire n'est **pas adaptatif** ; il n'a aucune conditionnalité

`questions_complementaires_json` est une **liste plate** de 3 à 5 questions par symptôme, et
`TriageFlow.tsx` (route `'questions'`) les pose **toutes**, pour **tous** les symptômes cochés.
Aucune question n'en déclenche une autre ; aucune n'est écartée.

Le corpus en demande trois fois l'inverse : CDC_08 §2.1 nomme un composant `questionnaire-engine`
à « questions **adaptatives** » ; §4.3b montre un questionnaire **arborescent**
(`Fièvre → Durée ? → Âge ? → Difficulté respiratoire ? → …`) ; §13 étape 4 en fait ce qui
**« permet le triage sans IA »** ; CDC_05 §5.5.2 en donne la raison — *« éviter 100 questions
inutiles »*.

### W5 — Le socle P6.3 **ne peut pas** porter les protocoles tel quel, et il faut le dire avant de coder

Le socle versionne un **référentiel entier** : une version en vigueur à la fois, un instantané JSON
global (décision **D1 de P6.3**, prise parce qu'un SCD-2 ligne à ligne aurait obligé à modifier
chaque table des modules déjà G5).

CDC_08 demande l'inverse, et pas par préférence :

- **§6.1** — la version appartient **au protocole** (`2026.2`), avec son propre historique et son
  propre état (Actif / Archivé / Brouillon) ; le tableau du §6.1 montre plusieurs versions
  coexistantes.
- **§7** — la validation appartient **au protocole** : quatre validations distinctes (clinique,
  réglementaire, scientifique, technique), chacune enregistrée avec validateur, rôle, date, avis,
  commentaires, et **opposable**.
- **§4.4** — nomme `protocole_versions` et `protocole_validations` comme des tables à part entière.
- **§6.1** — *« chaque décision clinique conserve la version exacte du protocole utilisée »* : c'est
  un épinglage **par protocole**, pas par référentiel.

Sous le socle actuel, publier une correction de posologie sur le protocole du paludisme
**republierait du même coup** tous les autres protocoles, et le dossier de validation clinique de
l'un serait rattaché à une version qui parle des vingt autres. Ce n'est pas tenable.

**Conclusion à assumer** : le registre des protocoles est un **frère** du socle référentiel, pas une
onzième entrée de son registre. Mêmes principes (versionné, quatre-yeux, chaîne d'audit,
anti-substitution, instantané immuable), **granularité différente parce que le corpus l'impose**.
C'est le raisonnement de **P5.5c** (service de rapprochement séparé, décidé à G1, pour garder
l'auditeur interne honnêtement « interne ») et de **P6.8c** (`pays_code` retiré parce que le fait
change de nature).

### W6 — CDC_04 §115 reste partiellement dû

Le §115 attend sur `triages` : *« numéro, date/heure, réponses, niveau de priorité, recommandation,
service recommandé, établissements proposés, QR code, **version du protocole**, **version du modèle
IA** »*, plus une table **`triage_reponses`**.

P10a a livré le QR et `referentiel_version`. Restent : `protocole_version` (P10b),
`model_version` (P10c), et `triage_reponses` en table — aujourd'hui les réponses vivent dans la
colonne JSON `triages.reponses_json`.

---

## 2. Le point de conception — qu'est-ce qu'un protocole, ici, et qu'est-ce qu'il n'est pas

> **Un protocole n'est pas une donnée de référence de plus. C'est une décision clinique écrite
> d'avance, et la machine l'exécute sur un patient.**

Tous les référentiels de P6 partagent une propriété : **ils décrivent**. Un code d'établissement,
une plage biologique, un calendrier vaccinal, un numéro d'urgence — chacun affirme un fait, et une
erreur y produit une gêne, un guichet perdu, une liste vide. C'est déjà sérieux, et le projet a
répondu à chaque fois par la même honnêteté : jeu de démonstration étiqueté, provenance obligatoire,
jamais attribué à une autorité qu'on n'a pas vue.

**Un protocole prescrit.** « Paludisme simple → ACT, hydratation, contrôle à J3 » n'est pas une
description : c'est une **conduite à tenir**, et si elle est fausse ou périmée, la conséquence n'est
pas une statistique bancale. C'est pourquoi CDC_08 §1.6 pose que **« aucun protocole n'est
utilisable sans validation »** et §7 en exige **quatre**, dont deux (clinique et réglementaire)
qu'aucune équipe technique ne peut rendre.

**Cela commande une ligne que ce plan ne franchira pas** (voir §4 — décision **N3**) : le moteur, le
cycle de vie, le versionnage et l'audit se construisent ; **le contenu thérapeutique ne se seede
pas**. Le §4.2 du corpus donne pourtant un exemple tout prêt, signé « Programme National de Lutte
contre le Paludisme ». Je n'ai vu **aucun document du PNLP**. Le seeder et l'attribuer reviendrait à
faire dire à une autorité sanitaire nationale une posologie que personne dans ce projet n'a lue —
c'est un cran au-dessus de tout ce que le projet a refusé jusqu'ici (`loinc` vide, codes CIM vides,
aucun assureur réel nommé), parce qu'ici le lecteur est un soignant et l'objet un traitement.

**Ce qui, en revanche, existe déjà honnêtement et n'attend qu'un moteur** : les règles de **triage**
(§5.4). Poids de sévérité, drapeaux rouges, questions, seuils de niveau — ce sont des règles
d'**orientation**, pas de traitement ; elles sont déjà en base, déjà gouvernées depuis P10a, déjà
relues par deux agents. Et le §13 en fait **l'étape 4**, celle qui « permet le triage sans IA ».

> **D'où la forme de P10b : le moteur naît en sortant du code les règles de triage qui y sont
> restées (W2), pas en inventant des traitements.**

---

## 3. Ce que P10b couvre, et ce qu'il ne couvre pas

CDC_08 §13 énumère dix étapes. Elles ne tiennent pas dans un incrément — le projet a découpé P5.5 en
quatre et P6.8 en cinq pour moins que cela.

| §13 | Étape | Sort proposé |
|---|---|---|
| 1 | Modèle de données + registre + versionnage + cycle de vie | **P10b-1** |
| 2 | Moteur de règles (conditions, actions, opérateurs) + compilation + cache | **P10b-1** |
| 3 | Sélecteur + ordre de priorité §3 + conflits §8 + journal | **P10b-2** |
| 4 | Questionnaire dynamique adaptatif | **P10b-3** |
| 5 | Chargement des protocoles ivoiriens prioritaires | **hors périmètre** — voir N3 |
| 6 | Interface d'authoring + validation multicouche §7 | **P10b-1** (workflow) / **P10b-3** (écran) |
| 7 | Intégration IA puis RAG | **P10c** / CDC_07 |
| 8-10 | OMS, spécialités, multi-pays | **données**, zéro code |

**Pourquoi 1+2 ensemble et pas 1 seul** : un registre de protocoles sans moteur qui les évalue serait
exactement le **« socle à vide » refusé par la décision D3 de P6.3** — et la leçon de P5.3b-4 (« un
contrôle toujours vert ne prouve rien »). Le registre doit naître avec un consommateur réel.

**Le consommateur réel de P10b-1 est le niveau de triage** (W2) : les seuils quittent le `match`
PHP, deviennent un protocole de triage versionné, et les **4 niveaux patient** de CDC_05 §5.3
entrent en vigueur (W3). C'est vérifiable par un vecteur en miroir — modifier un seuil au protocole
change le niveau rendu ; modifier le code ne change rien puisqu'il n'y a plus de seuil dedans.

---

## 4. Décisions à trancher au G1

### N1 — Où vit le moteur : **Laravel**, pas un microservice *(recommandation)*

Le projet a deux précédents de sortie (paiement → Java ADR-013, fraude → Python ADR-017), et P10c
en prévoit un troisième. La question mérite donc d'être posée plutôt que réglée par habitude.

**Recommandation : Laravel (`services/api`)**, pour trois raisons vérifiées :

1. **Le moteur consomme les référentiels nationaux** — symptômes, médicaments, maladies, analyses,
   spécialités — qui vivent tous dans Laravel sous la gouvernance P6.3. Un service séparé devrait
   les lire à distance, ce qu'ADR-019 a déjà dû outiller pour la fraude, à un coût réel.
2. **CDC_08 §11 interdit tout appel réseau pendant l'évaluation** (« les référentiels nécessaires
   sont préchargés »). Sortir le moteur mettrait un réseau entre lui et ses données.
3. **La gouvernance existe déjà ici** : quatre-yeux, chaîne d'audit à hachage, anti-substitution,
   contrôles qualité bloquants. La reconstruire ailleurs ferait un second moteur de gouvernance,
   avec la divergence qui vient toujours avec.

L'IA (P10c), elle, sort — comme la fraude, et pour la même raison : c'est elle qui a besoin d'une
autre pile.

### N2 — Le registre des protocoles est un **frère** du socle P6.3, pas une entrée de son registre

Conséquence directe de W5. Tables propres (`protocoles`, `protocole_versions`, `protocole_regles`,
`protocole_validations`, `protocole_applications`), chaîne d'audit propre, cycle propre.

**Ce qui est réutilisé sans être recopié** : le motif d'**anti-substitution** (le contenu est
ré-extrait à la publication et son empreinte comparée à celle validée — sinon on publierait ce que
personne n'a relu), la **chaîne d'audit à hachage** incluant `acteur_nom` (leçon P6.3), le
**quatre-yeux** vérifié **en service** et non par le middleware spatie (piège P4), la **permission
portée par aucun rôle métier** (treizième occurrence).

### N3 — Contenu : brouillons non validés + cycle complet prouvé sur le triage ✅ *(tranché 2026-08-19)*

**Ce qui a été retenu**, après une question du propriétaire rappelant que B9/B10 sont bien au corpus :

- Les protocoles **thérapeutiques** (paludisme, HTA…) sont seedés à l'état **BROUILLON**, **sans
  aucune validation enregistrée**. Ils démontrent la structure §4.1/§4.3 et l'authoring, et **le
  moteur refuse de les appliquer** — ce qui fait du §1.6 un **comportement prouvé par un vecteur**
  au lieu d'une promesse.
- Le **cycle complet** (rédaction → 4 validations → publication → évaluation) est prouvé de bout en
  bout sur les protocoles de **triage** (§5.4), dont le contenu existe déjà et est gouverné depuis
  P10a.
- **Aucune signature clinique inventée**, et c'est l'argument qui a tranché : seeder un protocole
  thérapeutique *publié* obligerait à seeder ses quatre validations, donc à inscrire dans la chaîne
  d'audit immuable qu'un médecin spécialiste et le Ministère ont validé une posologie. Le §7 dit
  **« opposable »** — c'est exactement la pièce qu'on produirait devant un tribunal. C'est le seul
  endroit du projet où « jeu de démonstration » ne serait pas neutre : ailleurs on fabriquerait une
  donnée fausse, ici **une validation clinique fausse**.
- B9/B10 restent **de la donnée, zéro code**, comme les 33 régions sanitaires, le calendrier PEV et
  les codes CIM — à ceci près qu'ils exigent en plus des **validateurs nommés** (§7), que seule
  l'institution peut fournir.

*Raisonnement conservé ci-dessous, parce qu'il porte la ligne qui n'a pas bougé.*

### N3 (raisonnement) — le moteur oui, **les traitements non**

**Recommandation** : P10b charge **uniquement des protocoles de triage** (§5.4) — niveaux, seuils,
drapeaux rouges, questions — qui sont des règles d'**orientation** déjà présentes, déjà gouvernées,
et que le §13 étape 4 désigne nommément.

Les protocoles **thérapeutiques** (§5.1 : ACT, posologies, conduites à tenir) reçoivent **la
structure, le cycle de vie et le dossier de validation — et zéro ligne de contenu**, avec la raison
écrite dans le code et à l'écran : *aucun document du PNLP, du Ministère ou de l'OMS n'a été vu ; un
protocole thérapeutique attribué à une autorité qu'on n'a pas lue serait plus dangereux que pas de
protocole du tout.*

Cela rend l'exigence §1.6 (« aucun protocole utilisable sans validation ») vraie **par construction**
et non par promesse — et charger les vrais protocoles restera **de la donnée, zéro code**, comme
pour les 33 régions sanitaires, le calendrier PEV et les codes CIM.

**L'alternative** — seeder l'exemple §4.2 du corpus tel quel, signé PNLP — est explicitement
déconseillée. Si elle est retenue malgré tout, elle exigera au minimum une provenance
`declaration_projet` (valeur créée en P6.8e pour dire exactement cela) et un bandeau non masquable.

### N4 — Découpage : **b-1 / b-2 / b-3**, ou tout en un ?

**Recommandation : trois incréments** (§3), pour la raison qui a valu à P6.8 d'en avoir cinq : un
incrément dont on ne peut pas énumérer les vecteurs au G1 n'est pas prêt. Le sélecteur de conflits
(§8) n'a de sens qu'avec plusieurs protocoles ; le questionnaire adaptatif touche le mobile et le
schéma des questions.

### N5 — Les 4 niveaux patient entrent-ils **dans P10b-1** ?

**Recommandation : oui.** C'est le consommateur qui empêche le socle à vide (§3), et W3 montre que
la source unique les porte déjà. Conséquences assumées et dites avant de coder :

- `triages.niveau` doit accueillir les nouvelles valeurs — **ENUM élargi, jamais réécrit** (précédent
  exact de P6.4a : `type` passé de 7 à 13 valeurs sans invalider l'historique). Les triages
  antérieurs **gardent** `leger|modere|urgent` : les convertir serait un **mensonge d'archive**
  (précédent L2, `mesures_sante.referentiel_version` laissée `NULL`).
- Le mobile cesse de redéfinir `Niveau` et **importe `@masante/shared`** — ce qui referme
  l'infraction à la règle de source unique relevée en W3.
- Les 9 couleurs dormantes de `palette.json` trouvent enfin leur lecteur.

### N6 — Les niveaux **hospitaliers** (les 5 de Manchester/ESI) : maintenant ou plus tard ?

**Recommandation : plus tard, et le dire.** Ils supposent un **écran soignant de triage à l'accueil**
qui n'existe pas — le portail est en Blade et ADR-011 le condamne (même impasse que M1 de P6.4a et
O1 de P6.4c). Les livrer sans consommateur referait le socle à vide. La structure les accueille
(ce sont des données de protocole, §5.4 « paramétrables par pays »), le contenu attend son écran.

---

## 4 bis. Conception de P10b-1

### Les tables (§4.4)

Neuf des onze tables du §4.4 naissent ici. `protocole_questions` / `protocole_reponses` naissent en
**b-3** (questionnaire) et `protocole_conflits` en **b-2** (sélecteur) : les créer vides serait le
socle à vide refusé par D3 de P6.3.

| Table | Rôle |
|---|---|
| `protocoles` | registre : code, `pays_code`, titre, domaine, spécialité, organisme, langue, mots-clés |
| `protocole_versions` | version, **état** (`brouillon`/`actif`/`archive`), niveau de preuve, population, conditions d'utilisation, expiration, **instantané compilé + empreinte**, auteur, publieur |
| `protocole_regles` | une règle = un ordre + un libellé (§4.3a) |
| `protocole_conditions` | `fait` + `operateur` + `valeur` — les trois issus de **listes blanches fermées** |
| `protocole_actions` | `type` + `valeur` — liste blanche fermée |
| `protocole_references` | bibliographie (§4.1) |
| `protocole_validations` | les **quatre** du §7 : type, validateur, rôle, date, avis, commentaires |
| `protocole_applications` | journal d'exécution (§10) : patient, professionnel, protocole, **version exacte**, recommandations, décision, écart |
| `protocole_journal` | chaîne d'audit à hachage, `acteur_nom` inclus (leçon P6.3) |

### Le moteur est une **classe pure**

`MoteurProtocole` (motif `ReglesReversement`, `ReglesIntervalleReference`, `ReglesCalendrierVaccinal`,
`ReglesOrientation`) : aucun accès base, aucune horloge, tout par paramètre. Elle reçoit des **faits**
et un **protocole compilé**, elle rend des **actions**. Elle ne lit aucune table et ne conclut rien
de médical — elle applique des règles écrites par d'autres.

**Trois listes blanches fermées**, et c'est la garde centrale : les **faits** (`age`, `sexe`, `score`,
`drapeau_rouge`, `symptome`, `reponse.*`…), les **opérateurs** (`=`, `<`, `<=`, `>`, `>=`, `entre`,
`existe`…) et les **types d'action**. Motif `RegistreSectionsCarnet` / `RegistreReferentiels` : *ces
trois valeurs arrivent par la donnée, donc par l'écran d'authoring — sans liste blanche, un opérateur
deviendrait un choix libre, donc une porte.*

**Un fait inconnu ne s'évalue pas à faux, il est REFUSÉ à la publication.** C'est le point le plus
important du moteur : une condition qui retombe silencieusement à faux rend un protocole
**inapplicable sans que rien ne le signale** — la « panne invisible » que P10a vient de refermer sur
l'orientation, et qu'on ne rouvrira pas par la porte de derrière.

### Le premier consommateur : le niveau de triage quitte le code

Les seuils deviennent des règles du protocole `TRIAGE-NIVEAU-CI` (§4.3a), avec un ordre :

```
ordre 1  SI drapeau_rouge = vrai              ALORS DEFINIR_NIVEAU URGENCE
ordre 2  SI score entre 0 et 25               ALORS DEFINIR_NIVEAU FAIBLE
ordre 3  SI score entre 26 et 50              ALORS DEFINIR_NIVEAU RECOMMANDEE
ordre 4  SI score entre 51 et 75              ALORS DEFINIR_NIVEAU RAPIDE
ordre 5  SI score entre 76 et 100             ALORS DEFINIR_NIVEAU URGENCE
```

`TriageService::niveauDepuisScore()` **disparaît**. Le `match` PHP, `PLAFOND_ANTECEDENTS` et le
`max($score, 90)` du drapeau rouge deviennent des données relues par deux agents.

**Si aucune règle ne s'applique, on refuse — on n'invente pas un niveau.** Et la **couverture est
contrôlée à la publication** : un protocole de niveau qui ne couvre pas tout l'intervalle 0-100 est
**refusé**, comme `SourceNumerosUrgence` refuse une version sans aucun numéro actif. Le contrôle
prouve la couverture au moment où un humain décide ; l'exécution refuse plutôt que de deviner.

### La diffusion : la clé porte la version, donc rien à invalider

Motif **D4 de P6.3** : `protocole:CI:TRIAGE-NIVEAU:v3`. Publier v4 fait lire une autre clé.
Le §6.2 demande « l'invalidation des caches lors d'une nouvelle version » — elle est obtenue **sans
étape d'invalidation**, et *un cache qu'on n'a pas à invalider est un cache qu'on ne peut pas
oublier d'invalider*.

### Les gardes de publication

1. **Les quatre validations du §7** présentes et favorables — le refus **nomme celle qui manque**.
2. **Quatre-yeux** (§10 « double validation pour la publication ») : le publieur ≠ l'auteur de la
   version, vérifié **en service** et non par le middleware spatie (piège P4).
3. **Anti-substitution** : le contenu est ré-extrait et son empreinte comparée à celle figée à la
   dernière validation → **409**. Transposition du contrôle central de P6.3, et ici il porte plus
   loin qu'ailleurs : *sans lui, on publierait des règles cliniques que personne n'a relues.*
4. **Contrôles qualité** bloquants (faits/opérateurs/actions connus, couverture, cohérence).

### Les permissions — treizième occurrence du précédent

`protocole.rediger`, `protocole.publier`, et **une permission par type de validation du §7** :
`protocole.valider.clinique`, `.reglementaire`, `.scientifique`, `.technique`. **Attachées à aucun
rôle métier.** Le §7 confie la validation clinique à des médecins spécialistes et la réglementaire au
Ministère : une permission unique laisserait un technicien signer les quatre, ce qui viderait le §7
de son objet.

*Ce qui n'est délibérément PAS ajouté* : l'interdiction pour un même agent de porter plusieurs de
ces permissions. Le §7 ne l'exige pas, et un garde-fou plus strict que sa règle est un défaut
(leçon de la collation en P6.8c). En revanche le journal **nomme qui a signé quoi**.

### MFA (§10)

« MFA obligatoire » pour l'édition des protocoles. Le MFA TOTP existe depuis P1 derrière la porte
`MFA_ENFORCE`, aujourd'hui OFF. Classé **« prêt à activer »**, et dit comme tel — pas déguisé en
garantie active.

---

## 5. Vecteurs exigés (P10b-1)

1. **Le seuil de niveau vient du protocole** : modifier un seuil dans une version publiée change le
   niveau rendu — et le **code ne contient plus aucun seuil** (mutation : neutraliser la lecture du
   protocole doit tuer ce vecteur, pas le faire retomber sur une valeur par défaut).
2. **Refus bruyant** : sans protocole de triage en vigueur, l'analyse répond **503** — jamais un
   repli sur les seuils d'hier (précédent L1+L2, et P10a l'a déjà appliqué aux symptômes).
3. **Estampille** : un triage conserve `protocole_version` ; les triages antérieurs restent `NULL`.
4. **Quatre-yeux** : l'auteur d'une version ne peut pas la publier — refus vérifié **par son motif**,
   pas seulement par son code (leçon P6.5b et P6.8e).
5. **Anti-substitution** : modifier le contenu entre la validation et la publication → **409**.
6. **Les quatre validations du §7** sont exigées avant publication ; il en manque une → refus qui
   **nomme laquelle**.
7. **Un protocole archivé reste consultable** et reste rejouable (§6.1).
8. **Les 4 niveaux patient** sont rendus ; un triage d'avant P10b garde son niveau à 3 valeurs.
9. **Zéro protocole thérapeutique publié** : un vecteur qui casse si un protocole de traitement est
   seedé (N3 tenu par un test, pas par une intention).
10. **Aucun seuil clinique dans le code** : vecteur qui parcourt `TriageService` et échoue s'il y
    retrouve une comparaison numérique de score.

---

## 6. Limites qui seront annoncées

1. **Aucun protocole thérapeutique** (N3) — le moteur est prouvé sur les règles de triage.
2. **Aucune IA** (P10c) et aucun RAG (CDC_07).
3. **Niveaux hospitaliers non livrés** (N6), faute d'écran soignant.
4. **< 100 ms P95 non déclaré atteint** : le cache est `database` et non Redis (constat F5 de P6.3,
   inchangé) — même honnêteté que le « < 50 ms non déclaré atteint » d'ADR-025.
5. **Aucun écran d'authoring** en P10b-1 : la gouvernance passe par l'API, comme les dix référentiels
   de P6. L'écran est une décision de la migration du portail, module déjà identifié en P6.4d.
6. **`triage_reponses` en table** (CDC_04 §115) reportée au questionnaire adaptatif (P10b-3), qui
   change de toute façon la forme des réponses.
7. **Le contenu de triage reste un jeu de démonstration** — hérité de P10a, aucune nomenclature
   d'orientation validée cliniquement.
