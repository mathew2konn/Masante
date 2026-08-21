# ADR-041 — Registre des protocoles médicaux et moteur de règles (CDC_08)

- **Statut** : accepté — G1 validé par le propriétaire le 2026-08-19. **P10b-1 : VALIDÉ G5 le
  2026-08-19** — G2 (live MySQL) et G3 prouvés, G4 propriétaire OK. **P10b-2 : VALIDÉ G5 le
  2026-08-20** (§B2). **P10b-3-i : VALIDÉ G5 le 2026-08-20** (§B3). **P10b-3-ii : VALIDÉ G5 le 2026-08-21** (§B4) — **P10b est COMPLET (b-1, b-2, b-3-i, b-3-ii) : l'étape 4 de l'ordre CDC_08 §13 est achevée.**
- **Contexte** : P10b, premier incrément (`b-1`). Suit P10a (ADR-040), précède P10b-2 (sélecteur et
  conflits), P10b-3 (questionnaire adaptatif) et P10c (IA, CDC_05 §5).
- **Corpus** : CDC_08 en entier ; CDC_05 §5.3 (niveaux) ; CDC_04 §115 ; CDC_09 §10 (gouvernance) ;
  CDC_00 §4 (interdits absolus).

---

## 1. Le problème

CDC_08 §1.2 est catégorique : *« Aucune règle médicale codée en dur. Interdit :
`if temperature > 39: urgence = True`. »*

Le G0 a trouvé cet interdit **en vigueur dans le code**, à l'endroit le plus lourd :

```php
// TriageService
return match (true) {
    $score <= 30 => 'leger',
    $score <= 65 => 'modere',
    default      => 'urgent',
};

if ($drapeauRouge) { $score = max($score, 90); }
```

P10a avait sorti l'**orientation** du code et laissé le **niveau** dedans. L'orientation dit vers
quelle spécialité aller ; le niveau dit **s'il faut courir aux urgences**. C'était la plus lourde
des deux, et c'est celle qui restait codée — au point que le nombre de niveaux de soin du pays
était une contrainte de colonne MySQL (`ENUM('leger','modere','urgent')`).

Deux constats l'accompagnaient :

- **Le vocabulaire avait divergé, et c'est la source unique qui dormait.** `@masante/shared` porte
  les 4 niveaux patient de CDC_05 §5.3 et les 5 hospitaliers depuis P0, `palette.json` en peint les
  9 couleurs — **rien ne les consommait**, pendant que le mobile **redéfinissait** localement une
  version à 3 valeurs. Première clé dormante du projet dont la version endormie était la **juste**.
- **Aucune ligne de protocole n'existait.** Terrain vierge, posé sur un socle de gouvernance mûr.

---

## 2. Décision N1 — le moteur vit dans Laravel

Le projet a deux microservices (paiement Java, ADR-013 ; fraude Python, ADR-017) et P10c en prévoit
un troisième. La question méritait d'être posée plutôt que réglée par habitude.

**Laravel**, pour trois raisons vérifiées :

1. le moteur consomme les référentiels CDC_09 — symptômes, médicaments, maladies, spécialités —
   qui vivent tous dans Laravel sous la gouvernance P6.3 ;
2. **CDC_08 §11 interdit tout appel réseau pendant l'évaluation** ; sortir le moteur mettrait un
   réseau entre lui et ses données ;
3. le quatre-yeux, la chaîne d'audit et l'anti-substitution existent déjà ici — les refaire
   ailleurs ferait un second moteur de gouvernance, avec la divergence qui vient toujours avec.

L'IA (P10c) sort, elle, comme la fraude, et pour la même raison : c'est elle qui a besoin d'une
autre pile.

---

## 3. Décision N2 — le registre est un FRÈRE du socle P6.3, pas une entrée de son registre

Le socle versionne un **référentiel entier** : une version en vigueur à la fois, un instantané JSON
global (sa décision D1, prise pour ne pas modifier les tables des modules déjà G5).

CDC_08 demande l'inverse, **et pas par préférence** :

| Exigence | Référence |
|---|---|
| La version appartient au protocole (`2026.2`), avec son propre état | §6.1 |
| La validation appartient au protocole : quatre couches, chacune nommant son validateur | §7 |
| `protocole_versions` et `protocole_validations` sont des tables à part entière | §4.4 |
| « Chaque décision conserve la version exacte du protocole utilisée » | §6.1 |

Sous le socle, corriger une posologie du paludisme **républierait les vingt autres protocoles**, et
le dossier de validation clinique de l'un serait rattaché à une version qui parle des autres.

**Mêmes principes, granularité différente parce que le corpus l'impose.** Ce qui est réutilisé sans
être recopié : `EmpreinteReferentiel` (un second algorithme de hachage serait deux endroits où
« comment on hache » peut diverger), le motif d'anti-substitution, la chaîne d'audit incluant
`acteur_nom`, le quatre-yeux vérifié **en service** et non par le middleware spatie (piège P4).

**Deux chaînes d'audit distinctes**, en revanche : les mélanger lierait la validité de l'audit des
protocoles à celle de l'audit des référentiels, et il deviendrait impossible de dire lequel a bougé.

---

## 4. Décision N3 — brouillons non validés, cycle prouvé sur le triage

**La décision la plus importante de l'incrément**, prise par le propriétaire après discussion.

Tous les référentiels de P6 **décrivent**. Une erreur y produit une gêne, un guichet perdu, une
liste vide — déjà sérieux, et le projet y a répondu par la même honnêteté : jeu de démonstration
étiqueté, provenance obligatoire, jamais attribué à une autorité qu'on n'a pas vue.

**Un protocole prescrit.** « Paludisme simple → ACT, hydratation, contrôle à J3 » n'est pas une
description : c'est une conduite à tenir.

### Ce qui a été retenu

- Les protocoles **thérapeutiques** sont seedés à l'état **BROUILLON, sans aucune validation**. Ils
  démontrent la structure §4.1/§4.3 et l'authoring, et le moteur **refuse de les appliquer** —
  `DiffusionProtocole` ne sait pas lire un brouillon. Le §1.6 devient un **comportement prouvé par
  un vecteur** au lieu d'une promesse.
- Le **cycle complet** (rédaction → 4 validations → publication → évaluation) est prouvé de bout en
  bout sur les protocoles de **triage** (§5.4), dont le contenu existe déjà et est gouverné depuis
  P10a.
- **Aucune attribution n'est forgée** : `organisme` dit que la source manque, `auteur` est `NULL`.

### L'argument qui a tranché

Publier un protocole thérapeutique exigerait de seeder ses **quatre validations**, donc d'inscrire
dans une chaîne d'audit immuable qu'un médecin spécialiste et le Ministère de la Santé ont validé
une posologie. Le §7 dit **« opposable »** : c'est exactement la pièce qu'on produirait devant un
tribunal.

> Partout ailleurs dans ce projet, un jeu de démonstration fabrique une donnée fausse ; **ici il
> fabriquerait une validation clinique fausse.**

B9/B10 (chargement des protocoles ivoiriens, OMS, spécialisés) restent **de la donnée, zéro code**
— comme les 33 régions sanitaires, le calendrier PEV et les codes CIM. À une différence près, qui
est dite : ils exigent en plus des **validateurs nommés** (§7), que seule l'institution peut fournir.

---

## 5. Le moteur d'inférence

`MoteurProtocole` est une **classe pure** (motif `ReglesReversement`, `ReglesIntervalleReference`,
`ReglesCalendrierVaccinal`, `ReglesOrientation`) : aucun accès base, aucune horloge, tout par
paramètre — ce que le §12 exige en demandant des « tests unitaires du moteur d'inférence ».

Elle **applique des règles écrites par d'autres**. Retirer le protocole ne la rend pas permissive,
elle la rend inopérante — même propriété que la clé privée de P6.5b.

### Trois listes blanches fermées

Les **faits**, les **opérateurs** et les **types d'action** arrivent par la donnée, donc par
l'écran d'authoring. Sans liste blanche, un opérateur deviendrait un choix libre du rédacteur —
c'est-à-dire une expression écrite en base et évaluée sur l'écran d'un patient. Motif
`RegistreSectionsCarnet` (P7-C) et `RegistreReferentiels` (P6.3).

`DIAGNOSTIQUER` est **délibérément absent** du registre des actions : CDC_05 §1 (« le triage n'est
jamais un diagnostic ») et CDC_00 §4. Une action de ce nom serait la porte par laquelle l'interdit
rentrerait, venue de la donnée.

### Un fait inconnu LÈVE — il ne vaut pas « faux »

C'est la décision centrale du moteur. Traiter l'inconnu comme « condition non remplie » rendrait un
protocole entier inapplicable **sans qu'aucun écran ne change** : les règles ne se déclencheraient
jamais, tout semblerait normal, et personne ne saurait que la garantie est morte.

C'est la forme de défaut que P10a vient de refermer (« orienter vers un terme désactivé ne fait
aucun bruit ») et que P6.8e a refermée sur les numéros d'urgence. Le contrôle qualité refuse cette
donnée à la publication ; si elle arrivait quand même, **on préfère l'exception au silence**.

La nuance qui va avec : un fait **connu du registre mais non renseigné pour ce patient** ne lève
pas — un triage anonyme ne renseigne pas toujours l'âge. La différence est entre un défaut de
conception et un cas normal.

### Chaînage avant

`DEFINIR_SCORE_MINIMUM` relève le fait `score` pour les règles suivantes. C'est ce qui sort
`max($score, 90)` du code **sans le remplacer par une exception codée ailleurs** : la priorité du
drapeau rouge devient simplement **l'ordre d'une règle**, donnée relue par deux agents.

---

## 6. Les gardes de publication (quatre en b-1, six depuis b-2)

Aucune ne rattrape les autres.

1. **Les quatre validations du §7**, présentes et favorables. Le refus **nomme celle qui manque** :
   un refus « validation incomplète » obligerait le rédacteur à deviner laquelle.
2. **Le quatre-yeux** (§10). Vérifié en service **et** par un déclencheur de base — le service peut
   être contourné par un import, la base non. **409 et non 403** : le publieur a le droit de
   publier, c'est *cette* publication-là qu'il ne peut pas faire (précédent P7-C).
3. **L'anti-substitution.** Chaque validation fige l'empreinte du contenu **au moment de la
   signature** ; la publication les confronte. Sans elle, il suffirait de faire signer un texte
   anodin puis d'en changer les seuils. Transposition du contrôle central de P6.3 et du
   « destination révoquée depuis le figeage » de P5.5b-2 — là il s'agissait d'argent, ici de
   conduites à tenir.
4. **Les contrôles techniques du §7.4**, bloquants — dont le **contrôle de couverture**.

### Le contrôle de couverture, seul défaut de la famille qui ne fait aucun bruit

Un protocole de triage dont les bandes laisseraient un trou — rien entre 51 et 55 — se publierait
sans erreur, et un patient tombant dedans n'obtiendrait **aucun niveau**. Le défaut n'apparaîtrait
qu'au premier cas concerné.

Le contrôle prouve la couverture **au moment où un humain décide** ; l'exécution **refuse** plutôt
que d'inventer un niveau. Même famille que « aucun numéro d'urgence actif » (P6.8e).

Ce qui n'est **délibérément pas** contrôlé : la pertinence clinique d'une règle. Que « score ≥ 76 »
doive valoir « urgence » est un arbitrage médical, que le §7 confie à la validation **clinique**.
Prétendre le rendre donnerait à une machine l'apparence d'un avis médical.

---

## 7. Six permissions, portées par aucun rôle métier — treizième occurrence

`protocole.rediger`, `protocole.publier`, et **une permission par type de validation du §7**.

Le §7 confie la validation clinique à des médecins spécialistes et la réglementaire au Ministère :
ce sont des instances différentes. Une permission unique laisserait un technicien signer les quatre
couches — le §7 serait formellement respecté et **matériellement vide**.

Ce qui n'est **pas** ajouté : l'interdiction pour un même agent de porter plusieurs de ces
permissions. Le §7 ne l'exige pas, et un garde-fou plus strict que sa propre règle est un défaut
même quand il refuse par prudence (leçon de la collation, P6.8c). Le journal **nomme** qui a signé
quoi — c'est la transparence, pas l'interdiction, qui est due ici.

---

## 8. Le message vient du protocole, le numéro du référentiel

Le texte de recommandation portait `'… appelez le SAMU au ' . $this->numeros->numero('samu')`.
Deux règles du corpus s'y rencontrent et tirent en sens opposés : **CDC_08 §1.2** veut la consigne
dans le protocole ; **CDC_02 §37** veut le numéro dans le référentiel, où P6.8e l'a placé pour
qu'il soit corrigeable sans republier quoi que ce soit.

Coller le numéro dans le texte du protocole satisferait la première en **détruisant la seconde** :
le corriger exigerait alors de refaire passer le protocole par les quatre validations du §7, pour
un changement qui n'a rien de clinique.

D'où le marqueur `{urgence:samu}`, résolu à l'affichage. Syntaxe **fermée** : une syntaxe générale
ferait du contenu d'un message une expression à interpréter, écrite par un rédacteur, exécutée sur
l'écran d'un patient. Un marqueur non résolu n'est **jamais** affiché tel quel.

---

## 9. Les quatre niveaux patient (décision N5)

L'ENUM `triages.niveau` est **élargi, jamais réécrit** (précédent P6.4a : `type` passé de 7 à 13
valeurs sans perdre une ligne). Les triages antérieurs **gardent** `leger`/`modere`/`urgent` :
les convertir changerait ce qu'un patient a réellement lu sur son écran — mensonge d'archive,
refusé pour la même raison que `mesures_sante.referentiel_version` laissée `NULL` en L1+L2.

Le mobile cesse de redéfinir `Niveau` et importe `@masante/shared` ; les 9 couleurs de
`palette.json`, dormantes depuis P0, trouvent enfin leur lecteur.

**Les niveaux hospitaliers (5, Manchester/ESI) ne sont pas livrés** (décision N6) : ils supposent un
écran soignant de triage à l'accueil qui n'existe pas — le portail est en Blade et ADR-011 le
condamne (même impasse que M1 de P6.4a et O1 de P6.4c). Les livrer sans consommateur referait le
socle à vide refusé par D3 de P6.3.

---

## 10. Défauts trouvés, et par quel moyen

| Défaut | Trouvé par |
|---|---|
| **`User` n'a pas d'attribut `name`** : `JournalReferentiel` (P6.3) écrivait `$acteur?->name ?? 'Système'` → **la chaîne d'audit du socle référentiel a enregistré « Système » pour chaque acteur humain depuis P6.3**, alors que son propre commentaire pose que ce nom est « celui qu'un humain lit dans un audit ». `JournalSignature` (P6.5b) avait résolu le problème correctement, mais chez lui. | Un vecteur de P10b-1 (`validateur_nom` est `NOT NULL`, l'insertion a échoué là où le journal se rabattait sans bruit). Corrigé par `User::nomLisible()`, source unique appelée par les trois journaux. |
| **Ordre non total sur les validations** : `latest('valide_le')` seul laissait deux signatures posées dans la même seconde départagées par le moteur — un relecteur corrigeant son avis pouvait voir le **précédent** faire autorité. | Un vecteur. Corrigé par un second critère `id` (leçon `ReglesOrientation` / `NumeroUrgence::scopeOrdonne`). |
| **Un vecteur qui prouvait autre chose** : le 404 sur un protocole thérapeutique venait d'un `firstOrFail`, pas du refus délibéré — la garde neutralisée, le test passait toujours. | **La mutation.** Corrigé en vérifiant le refus **par son motif** (3ᵉ instance après P6.5b et P6.8e). |
| **Un test qui interdisait de documenter** : le vecteur « aucun seuil dans le code » lisait le fichier entier et tombait sur le commentaire expliquant la suppression de `niveauDepuisScore()`. | Le test lui-même. Corrigé en écartant les commentaires — il porte sur le **code**, pas sur la prose. |
| **Un vecteur d'anti-substitution qui refusait pour la mauvaise raison** : la modification de test créait un recouvrement, donc le contrôle qualité refusait *avant* l'anti-substitution. | La vérification du motif. Corrigé par une modification **valide** (changer le niveau d'une bande, pas ses bornes). |

---

## 11. Limites annoncées

1. **Aucun protocole thérapeutique applicable** (décision N3), et c'est prouvé par un vecteur.
2. **Sélecteur, ordre de priorité §3 et conflits §8 non livrés** → P10b-2, avec le journal
   d'exécution `protocole_applications` (§10).
3. **Questionnaire adaptatif §4.3b non livré** → P10b-3. C'est aussi là qu'arrivera le fait
   `reponse.<cle>`, absent du registre faute de pouvoir en vérifier la clé aujourd'hui.
4. **Aucun écran d'authoring** : la gouvernance passe par l'API, comme les dix référentiels de P6.
5. **`PLAFOND_ANTECEDENTS = 20` reste dans le code.** Il ne décide d'aucun niveau — il borne une
   part du score — mais c'est un seuil, et son porteur est nommé : P10b-3, où l'assemblage du score
   devient protocolaire. *Nommer un manque ne le comble pas, mais un manque nommé ne s'oublie pas.*
6. **MFA non exigé** sur les routes de protocole alors que le §10 le demande : `MFA_ENFORCE` est
   fermé depuis P1 — « prêt à activer », dit comme tel, pas déguisé en garantie active.
7. **Évaluation sous 100 ms P95 non déclarée atteinte** : le cache est `database` et non Redis
   (constat F5 de P6.3, inchangé) — même honnêteté que le « sous 50 ms non déclaré atteint »
   d'ADR-025.
8. **Le contenu du protocole de triage est un jeu de démonstration**, et il l'était déjà : les
   bandes reprennent les seuils du Module 1, redécoupés en quatre. `niveau_preuve = 'D'`. Le gain
   n'est pas qu'ils soient justes — c'est qu'ils soient **relisibles, signés et corrigibles sans
   déploiement**.
9. **Une imprécision du corpus est tranchée et dite** : le tableau du §6.1 montre deux versions
   « Active » du même protocole. On en impose **une seule**, parce que deux rendraient « laquelle
   s'applique ? » insoluble — or c'est le §6.1 lui-même qui exige de conserver **la** version exacte
   utilisée, ce qui présuppose une réponse unique.
10. **Deux étapes de déploiement** sont désormais nécessaires avant qu'un triage fonctionne
    (le référentiel des symptômes depuis P10a, le protocole depuis P10b-1).

---

## B2 — Sélecteur, ordre de priorité §3, conflits §8, journal d'exécution §10 (P10b-2)

- **Statut** : **VALIDÉ G5 le 2026-08-20** — G2 (live MySQL W2→W10) et G3 (1106 tests, typecheck
  ×3, mutation 9/9) prouvés, G4 propriétaire OK. Décisions O1→O4 prises le 2026-08-19 ; plan G1 validé
  (`docs/PLAN_G1_P10b2_Selecteur_Conflits.md`).
- **Migration** : `2026_08_20_000001_protocoles_selecteur_conflits` — `protocoles.contextes_json`,
  `protocole_applications`, `protocole_conflits`. Additive.

### B2.1 — Le constat qui commandait tout : le sélecteur n'avait rien à sélectionner

Un seul protocole était publiable ; les deux thérapeutiques sont des brouillons **par la décision
N3** et doivent le rester. Un sélecteur qui rend toujours le même protocole et un résolveur de
conflits qui ne se déclenche jamais auraient été le « socle à vide » refusé par D3 de P6.3, et
« un contrôle toujours vert ne prouve rien » (P5.3b-4).

**Décision O1** : un second protocole de triage de démonstration, publié par un **acte de
gouvernance réel** (quatre validations §7 + quatre-yeux §10), jamais par un seeder.

La différence avec N3 est réelle et dite : `TRIAGE-NIVEAU` **transcrivait** des seuils qui
existaient déjà dans le code ; `TRIAGE-NIVEAU-REGIONAL` est du contenu **inventé**. Ce qui le rend
acceptable, et qui manquait à un protocole thérapeutique : ce n'est pas une posologie — la pièce
qu'on produirait devant un tribunal pour une dose n'est pas de même nature qu'une règle
d'orientation ; il porte les mêmes étiquettes d'honnêteté (`D`, source non fournie, auteur absent) ;
et c'est le régime de tous les référentiels de P6.

### B2.2 — La sélection est grossière, l'applicabilité vient des règles

Le sélecteur retient sur des **métadonnées de registre** (pays, contexte déclaré, version en
vigueur, non périmée). Il ne juge jamais si un protocole convient *à ce patient-là* : c'est le
moteur qui tranche, et un protocole sélectionné dont aucune règle ne se déclenche ne recommande
rien.

*L'alternative — un second langage d'applicabilité à côté du langage de règles — aurait créé deux
façons d'écrire « ce protocole s'applique quand… », donc deux endroits où se tromper, et un
protocole applicable selon l'un et pas selon l'autre.*

Le **contexte** (§9.1) est une **donnée du protocole** (`contextes_json`), pas une correspondance
`contexte → domaines` écrite en PHP : celle-ci aurait été une règle en dur de plus, à corriger par
un déploiement. Colonne **nullable**, et l'absence a un sens précis — un protocole qui ne dit pas
quand il s'applique n'est **jamais** sélectionné.

### B2.3 — Chaque protocole est évalué indépendamment

Le chaînage avant reste entier **à l'intérieur** d'un protocole ; il ne franchit jamais la frontière
entre deux. *Sinon l'ordre d'évaluation changerait le résultat, et le §3 deviendrait un ordre de
CALCUL alors qu'il est un ordre de DÉPARTAGE.*

### B2.4 — Cumul par défaut, départage par exception

Un conflit n'existe que sur une action dont **une seule valeur peut prévaloir**.
`RegistreActionsProtocole` gagne `EXCLUSIVES` ; `DEFINIR_NIVEAU` seule y figure. Deux `ORIENTER`
s'additionnent — c'est déjà ce que P10a fait. Sans cette distinction, le journal du §8 se
remplirait de faux conflits et les vraies divergences y seraient noyées.

Et deux protocoles **d'accord** ne sont pas en conflit : la divergence suppose des valeurs
différentes, pas seulement deux émetteurs.

### B2.5 — La cascade §8, et les deux critères qu'on n'automatise pas

`ReglesResolutionConflit`, classe **pure**. Rang §3 → récence → niveau de preuve. Les critères 4
(avis de la spécialité) et 5 (validation du médecin) **ne sont pas implémentés** : ce sont des actes
humains devant un dilemme clinique, et prétendre les rendre donnerait à une machine l'apparence
d'un avis médical.

**L'ordre 2 avant 3 est celui du corpus et il surprend** — on aurait pu juger qu'un niveau de preuve
A vaut mieux qu'un D publié le mois dernier. Le §0 fait du CDC_08 le document qui fait autorité ;
corriger le corpus par préférence est ce que CLAUDE.md interdit.

**L'ordre est total** : `publie_le`, puis le numéro, puis le code. Hors des critères du §8, ce n'est
plus un arbitrage mais un **déterminisme** — la garantie que deux audits du même cas ne se
contrediront pas. C'est le défaut de b-1 (deux signatures dans la même seconde) traité avant qu'il
ne survienne.

### B2.6 — L'interdiction de publication : ce qui est refusé n'est pas « l'insoluble »

Avec la récence dans la cascade, un conflit *insoluble* est presque impossible : deux versions ne
sont jamais publiées au même instant. Un contrôle qui refuserait « les conflits qu'on ne sait pas
trancher » serait **toujours vert**.

Ce qui est refusé est réel : **une version que seule la date départagerait**. Être départagé par le
rang ou par la preuve reflète une propriété *écrite, relue, signée* ; être départagé par la date ne
reflète **aucune décision** — le résultat bascule à la publication, pour des cas que les quatre
validateurs n'ont pas examinés, puisqu'ils ont relu le protocole **isolément**.

**Divergence assumée avec le §8** : on est plus strict à la publication qu'à l'exécution. Le §8 dit
comment le moteur *résout*, le §7/§10 comment une version *entre en vigueur* ; le moteur, lui,
implémente le §8 en entier. Précédent : P6.4d, région/district passé de la détection à
l'interdiction.

### B2.7 — Un défaut de conception de b-1, révélé par b-2

**Le contrôle de couverture des bandes vérifiait 0-100 protocole par protocole.** Exact tant qu'un
seul protocole existait ; **interdisant toute surcouche** dès qu'il y en a deux — un protocole
régional traitant un cas particulier aurait été refusé parce qu'il « ne couvre pas 0-100 », alors
que c'est le national qui couvre et que les faire cohabiter est l'objet même du §3.

*Une garde plus stricte que sa propre règle est un défaut même quand elle refuse par prudence*
(leçon de la collation, P6.8c).

La question change de **portée**, elle ne disparaît pas : `ControleCouvertureNiveau` vérifie que
**l'ensemble** des protocoles en vigueur pour le contexte `triage` couvre toute la plage. Le
**recouvrement**, lui, ne change pas de camp : erreur à l'intérieur d'un protocole (il se contredit
lui-même, aucune cascade ne peut aider), **cas normal** entre protocoles — c'est ce que le §8 existe
pour trancher.

Seul le contexte `triage` est concerné : c'est lui dont le consommateur ne peut pas rester sans
réponse (`ServiceNiveauTriage` refuse d'inventer un niveau). En consultation, un protocole qui ne
dit rien sur un cas est légitime — le professionnel décide.

### B2.8 — Le journal d'exécution, et pourquoi l'estampille ne suffisait plus

La migration de b-1 argumentait l'inverse : « le seul consommateur est le triage, dont `triages` EST
déjà le registre ». C'était exact **tant qu'un seul protocole décidait**.

`triages.protocole_code` porte **un** code : il nomme celui qui a emporté l'action exclusive, et
reste muet sur les autres protocoles évalués, sur les divergences et sur ce qui a été recommandé.
Deux faits distincts, deux endroits — pas la même vérité écrite deux fois.

**Ce journal contient du contenu clinique, contrairement aux trois autres chaînes du projet.** Le
§10 l'exige (« recommandations affichées »), et c'est sa raison d'être : un journal d'exécution qui
tairait ce qui a été recommandé ne servirait à rien le jour d'un litige. Les recommandations entrent
donc **dans l'empreinte** — hors d'elle, elles seraient le seul élément réécrivable sans rompre la
chaîne, et c'est précisément l'élément qu'un litige discute (leçon d'`acteur_nom`, P6.3).

**Append-only à deux niveaux** : garde Eloquent (message clair) et déclencheurs (résistants à un
client MySQL). Conséquence assumée : la décision finale du §10 ne se « complète » pas après coup —
un professionnel qui décide plus tard produira une **nouvelle entrée**, jamais une réécriture.


### B2.9 — Défauts trouvés, et par quel moyen

| # | Défaut | Trouvé par |
|---|---|---|
| 1 | **La couverture des bandes était vérifiée protocole par protocole** — exact avec un seul protocole, **interdisant toute surcouche** dès qu'il y en a deux (§B2.7). | un vecteur de b-2 : le protocole régional refusait de se publier |
| 2 | **Le contrôle de publication dépendait de la granularité de l'horloge** : deux publications dans la même seconde donnaient une égalité de dates, la cascade descendait au niveau de preuve, et le contrôle laissait passer ce qu'il refusait une seconde plus tard. | la **suite complète**, plus lente que le run filtré — invisible autrement |
| 3 | **La sélection lisait la table `protocoles`, pas l'instantané publié** : un `UPDATE` sur `contextes_json` aurait élargi le champ d'application d'un protocole en vigueur sans quatre-yeux ni relecture. C'est le défaut que L1+L2 a refermé pour `seuils_mesure` et P10a pour `symptomes_triage`. | le **G2 live**, en préparant la bascule |
| 4 | **Deux vecteurs prouvaient autre chose** : celui de la couverture n'exerçait que la borne haute (la branche du trou intérieur lui survivait) ; celui de l'append-only Eloquent se laissait satisfaire par le **déclencheur de base**, `QueryException` héritant de `RuntimeException`. | la **mutation** — 4ᵉ et 5ᵉ instances de cette famille après P6.4c, P6.5b, P6.8e, P6.6b |
| 5 | **Les identifiants du journal étaient des clés étrangères `nullOnDelete` ET dans l'empreinte** : supprimer un compte — acte ordinaire, et un droit (loi 2013-450) — aurait fait crier « entrée modifiée » sur un journal que personne n'a touché. Corrigé : ce sont des identifiants, pas des relations vivantes. | le G2 live, en constatant le défaut identique dans `protocole_journal` |

### B2.10 — Un constat sur b-1 et P6.3, fait ici, non corrigé

`protocole_journal.acteur_id` (b-1) et `referentiel_journal.acteur_id` (P6.3) sont **`nullOnDelete`
et pris dans l'empreinte**. La restauration du G2 de b-1, qui a supprimé ses comptes temporaires, a
donc **rompu la chaîne de gouvernance** : 16 entrées portent `acteur_id = NULL`, et
`GET /protocoles/journal/integrite` répond `intacte: false`, rupture de type `CONTENU` sur
l'entrée #1.

*Le G5 de b-1 disait vrai au moment où il a été prononcé* — la chaîne était intacte quand elle a été
vérifiée. C'est **l'étape de restauration**, jouée après, qui l'a rompue, sans que rien ne le
signale.

**Ce n'est pas corrigé ici, et c'est délibéré.** On ne « répare » pas une chaîne de hachage :
recalculer les empreintes reviendrait à réécrire l'histoire, précisément ce que la chaîne existe
pour rendre impossible. Le choix — vivre avec une rupture datée et documentée, ou repartir d'une
chaîne neuve en archivant l'ancienne — appartient au propriétaire.

Ce qui est fait ici : **le journal d'exécution de b-2 ne reproduit pas le défaut** (§B2.10-5), et le
constat est écrit là où on le cherchera.

### B2.11 — Ce qui reste ouvert

- rang **`hospitalier`** sans portée réelle (décision O4 : pas de `structure_id` sans écran) ;
- critères 4 et 5 du §8, actes humains ;
- présentation d'un conflit à un médecin : **conçue, pas activée** (aucun écran soignant) ;
- `decision_finale` / `ecart_justification` vides tant qu'aucun professionnel n'est dans la boucle ;
- contenu de démonstration ; §11 (< 100 ms) toujours non déclaré atteint ;
- questionnaire adaptatif et `PLAFOND_ANTECEDENTS` → **P10b-3**.

Et une **conséquence de déploiement**, qui n'est pas une limite mais une étape : une version
publiée avant b-2 ne déclare aucun contexte dans son instantané. Elle cesse d'être sélectionnée,
et le triage répond **503** tant qu'une nouvelle version n'a pas été publiée. Le refus est
bruyant — préférable à un protocole qui s'appliquerait sur la foi d'une colonne que personne
n'a relue.

---

# B3 — Questionnaire adaptatif, bornes opposables, `triage_reponses` (P10b-3-i)

- **Statut** : **VALIDÉ G5 le 2026-08-20** — G2 (live MySQL W1→W15, base restaurée compte pour
  compte) et G3 (1138 tests / 16 313 assertions, typecheck ×3, expo-doctor 18/18, Pint, mutation
  9/9) prouvés, **G4 propriétaire OK**. Plan G1 : `docs/PLAN_G1_P10b3_Questionnaire_Adaptatif.md`
  (décisions Q1-Q3 puis R1-R9, validées le 2026-08-19).
- **Migration** : `2026_08_20_000002_protocoles_questionnaire` — `protocole_questions`,
  `protocole_reponses`, `triage_reponses` + un déclencheur de bornes dans les deux dialectes.
  Additive.

## B3.1 — Ce que cet incrément referme, et pourquoi ce n'est pas cosmétique

P10b-1 a sorti du code les **seuils de niveau** et les a soumis aux quatre validations du §7. Il a
laissé derrière lui, dans le référentiel des symptômes, une règle **de même nature et de même
gravité** :

```php
['cle' => 'fievre_sup_40', 'type' => 'booleen',
 'impact' => ['points_si_vrai' => 15, 'drapeau_rouge_si_vrai' => true]]
```

C'est **mot pour mot** le contre-exemple du §1.2 (`if temperature > 39: urgence = True`), exprimé
en donnée. Un drapeau rouge force le niveau `urgence` : cette ligne décidait autant que la bande de
score qu'on venait d'extraire du code. Elle était gouvernée par le quatre-yeux du §10 — **deux
signatures administratives** — mais jamais validée au sens du §7, qui en exige **quatre** dont la
clinique et la réglementaire.

**Cette asymétrie n'avait pas été vue au G1 de P10b.** Elle est le constat X3 du G0 de b-3, et c'est
elle qui justifie l'incrément — pas l'adaptativité, qui à l'échelle actuelle (8 questions) ne
produisait aucune des « 100 questions inutiles » de CDC_05 §5.5.2.

## B3.2 — Le point de conception : question, condition et impact voyagent ensemble

Les questions vivaient dans l'instantané publié de `symptomes_triage`. Le §4.4 les nomme, lui, dans
le registre des protocoles.

On a cherché à les y laisser en ne déplaçant que l'arborescence. **Cela ne tient pas** :

> Les deux artefacts ont des cycles de publication **indépendants**. Ajouter une question au
> référentiel puis son nœud d'arbre au protocole ne peut jamais se faire atomiquement : chaque
> contrôle qualité bloquerait l'autre, dans les deux sens, sans ordre qui débloque.

C'est l'argument déjà retenu trois fois — interactions + produits dans un seul instantané (P6.6a),
strates + analyses (P6.7a), vaccins + échéances (P6.8b) : *les publier séparément laisserait une
référence irrésoluble.*

## B3.3 — R1 : la conditionnalité est une RÈGLE, pas une colonne

```
SI symptome_id contient 6         ALORS POSER_QUESTION(au_repos)
SI reponse.au_repos = vrai        ALORS POSER_QUESTION(intensite)
```

Aucune table de conditions nouvelle : les règles, conditions et actions de b-1 portent
l'arborescence telle quelle. L'arbre hérite donc **des trois listes blanches fermées**, du contrôle
qualité, du quatre-yeux, de l'anti-substitution et de la chaîne d'audit — **sans une ligne de moteur
nouvelle**.

C'est aussi la lecture juste du §4.3, qui présente les règles déclaratives (a) et le graphe
décisionnel (b) comme *« deux représentations complémentaires »*, pas comme deux moteurs.

**Test de la conception, posé au G1 et tenu : `MoteurProtocole` n'est pas modifié.** S'il avait dû
bouger, c'est que l'arborescence n'était pas une règle.

**Écarté** : une colonne `condition_json` sur la question (un second chemin d'évaluation, donc une
seconde façon d'écrire une condition, capable de diverger). **Écarté aussi** : `question_id` nullable
sur `protocole_conditions` avec un `CHECK` d'exclusivité — **impossible sous MySQL 8.4** (erreur
3823, colonne en `cascadeOnDelete` : le mur de P6.3, cousin du 1215 de P6.1).

## B3.4 — R2/R3 : deux tables, et l'impact devient une règle

`protocole_reponses` porte les réponses **possibles** (le §4.4 nomme la table sans dire ce qu'elle
contient ; les réponses **données** vivent dans `triage_reponses`, exigée par CDC_04 §115 — deux
tables pour le même fait auraient été la « deux vérités » refusée depuis P6.6a).

Elle referme le constat X5 **par construction**. Le référentiel portait :

```php
'options' => ['seche', 'grasse'],
'impact'  => ['points_par_option' => ['seche' => 3, 'grasse' => 5]]
```

**Deux listes du même fait.** Elles coïncidaient ; rien ne l'imposait. `UNIQUE(question_id, valeur)`
rend la divergence **inexprimable** plutôt qu'interdite — le geste de P6.8c.

L'impact **non énumérable** (coefficient d'échelle, seuil d'un nombre) devient une règle via
l'action neuve `AJOUTER_SCORE`. Deux gains, le second non cherché :

1. Tout impact passe sous les quatre validations du §7.
2. **`drapeau_rouge_si_vrai` disparaît comme mécanisme** : faire primer une réponse se dit déjà avec
   `DEFINIR_SCORE_MINIMUM`, créée en b-1 pour le drapeau rouge des symptômes. Une seule façon
   d'écrire « ceci prime », au lieu de deux qui pouvaient diverger.

## B3.5 — R5 : le piège central, et sa parade

Le questionnaire est itératif : une réponse débloque la suivante. **Une même règle peut donc se
déclencher au tour 1 et au tour 3.** Cumuler les `AJOUTER_SCORE` de chaque tour ferait dépendre le
score du **nombre d'allers-retours** — c'est-à-dire de la façon dont le patient a répondu, pas de ce
qu'il a répondu.

Parade : les tours intermédiaires servent **uniquement** à savoir quoi demander ; le score vient
d'**une évaluation finale unique** sur le jeu de faits complet. Le moteur reste pur — c'est
l'appelant qui décide ce qu'il consomme.

Vecteur obligatoire : *mêmes réponses en 1 tour ou en 4 → même score, même niveau.*

## B3.6 — Un contexte neuf, et le plan G1 disait le contraire

Le plan annonçait `RegistreContextesProtocole` **inchangé**. L'implémentation a montré que c'était
faux, pour une raison de fond : **le questionnaire et le niveau ne s'évaluent pas au même moment.**
Les règles de bande *lisent* `score` ; les règles de questionnaire l'*alimentent*. Les évaluer
ensemble ferait lire aux bandes un score auquel les réponses n'ont pas encore été ajoutées —
circularité qu'aucun ordre de règles ne résout, puisque les deux jeux vivent dans des protocoles
différents.

**Pourquoi deux protocoles et non un seul** (qui supprimerait la circularité par chaînage avant) :
un protocole unique ferait re-signer les seuils de niveau par quatre validateurs à chaque correction
d'un énoncé de question, et l'inverse. C'est **exactement** ce que W5 du G0 de P10b a refusé pour le
socle P6.3 : la version et le dossier de validation appartiennent AU protocole (§6.1, §7).

**Pourquoi un contexte et non une constante de code** : lire `TRIAGE-QUESTIONNAIRE` par son code
régresserait l'acquis de b-2 (« ajouter un protocole régional ne demande plus aucune ligne de
code »). Le contexte est une **donnée de l'instantané publié**, relue par deux agents.

Corollaire : **un protocole de questionnaire ne peut pas conditionner sur `score`** — il n'est pas
encore clos. Le contrôle qualité le refuse en nommant `score_symptomes` et `score_antecedents`, qui
le sont. Refuser sans dire par quoi remplacer ramènerait la faute qu'on ferme (précédent P6.8a).

## B3.7 — R7 : les bornes publiées deviennent opposables

Le référentiel publiait `min:1 max:10` et le serveur ne les regardait pas :

```php
$points = (int) round(((float) $valeur) * $coef);   // aucun contrôle de plage
```

`intensite = 100` × `coef 1.2` = 120 points, score saturé à 100, **niveau le plus urgent obtenu avec
une valeur hors de la plage publiée**. Une clé inconnue, elle, valait 0 point en silence.

**On refuse, on n'écrête pas.** Ramener 100 à 10 accepterait une saisie fausse en la corrigeant sans
le dire : le patient croirait avoir répondu 100 et son dossier porterait 10.

Le contrôle vit dans le **service**, pas dans la `FormRequest` — un vecteur qui ne passerait que par
HTTP prouverait le validateur et non la garde (parade établie en P6.6b, après quatre occurrences du
piège). Les vecteurs sont dédoublés : un par la requête, un appelant le service directement.

## B3.8 — Ce que l'implémentation a fait tomber du plan

**`triage_reponses` n'a pas de colonne `points`.** Le plan en prévoyait une (« l'impact réellement
retenu »). Cette valeur **n'existe plus** : depuis que l'impact est une règle, une seule règle peut
porter sur plusieurs réponses — `SI reponse.a = x ET reponse.b = y ALORS AJOUTER_SCORE 10` — et ces
10 points ne se répartissent entre `a` et `b` par aucun partage défendable.

Y écrire 0 serait une colonne qui ment par omission ; y écrire une part inventée serait pire.
L'explication du score vit là où le §10 l'a mise en b-2 : le journal d'exécution, qui nomme les
**règles déclenchées** — le vrai grain de la décision.

*C'est le prix de la bascule, et il est cohérent : on ne peut pas à la fois sortir la règle du code
et continuer d'attribuer ses points réponse par réponse.*

## B3.9 — Écarts de transcription, annoncés plutôt que découverts

**1. Le coefficient linéaire devient trois bandes.** L'ancien impact était `round(valeur × 1,2)` —
une formule. Un moteur à liste blanche fermée n'en exprime pas, et lui ajouter une action
« multiplier » ouvrirait dans la donnée une arithmétique que personne ne relirait. **Certains scores
diffèrent d'un ou deux points de ceux du Module 1.** En contrepartie, « douleur forte → +11 » se
relit et se signe.

**2. Les conditions de déclenchement sont neuves.** Il n'en existait aucune. Contenu de démonstration
au même titre que les bandes de niveau — `niveau_preuve = 'D'`, source non fournie, **aucun
validateur forgé** (décision N3, inchangée).

**3. Le protocole désigne les symptômes par `symptome_id`.** C'est la transcription exacte (la
question appartenait à un symptôme précis, pas à sa famille : conditionner sur la catégorie
demanderait « depuis combien de jours ? » à qui déclare une perte de connaissance). Le prix est réel
et dit : un identifiant technique ne veut rien dire hors de cette base — le reproche que P10a faisait
à `specialite_id` avant de porter les orientations par code. Les symptômes n'ont pas de code
national.

## B3.10 — Conséquence de déploiement

Le triage exige désormais **quatre** mises en vigueur : `seuils_mesure`, `symptomes_triage`,
`TRIAGE-NIVEAU`, `TRIAGE-QUESTIONNAIRE`. Trente vecteurs antérieurs se sont mis à répondre 503 d'un
coup lors de la bascule.

**C'est la preuve que le refus bruyant fonctionne, pas une régression** — même effet qu'en b-1 et,
avant lui, en L1+L2. Les vecteurs ont été complétés en un seul endroit (le trait
`PublieLeProtocoleDeTriage`) ; aucun n'a été rendu tolérant au 503, aucune assertion n'a été retirée.

Le refus vaut **même quand le patient ne répond à aucune question** : sans lui, un oubli de
publication ferait trier des patients sans jamais les interroger, avec un score systématiquement plus
bas et rien pour le signaler.

## B3.11 — Défauts trouvés APRÈS le premier vert, et par quel moyen

### (1) Le plancher d'une réponse était perdu en silence — trouvé par la SUITE COMPLÈTE

`ServiceQuestionnaire` lisait le plancher dans les faits que rend `SelecteurProtocoles::evaluer()`.
Or ce sélecteur ne restitue les faits issus du chaînage avant que **du protocole RETENU**, et
« retenu » veut dire *celui qui a emporté une action exclusive*. Un questionnaire n'en produit
aucune — ni `POSER_QUESTION` ni `AJOUTER_SCORE` ne sont exclusives, et c'est voulu. Il n'y avait
donc **jamais** de protocole retenu, le sélecteur rendait les faits **initiaux**, et
`DEFINIR_SCORE_MINIMUM` valait toujours 0.

Conséquence : **le drapeau rouge d'une réponse ne primait plus**, c'est-à-dire exactement la
garantie que cet incrément était censé reprendre à `drapeau_rouge_si_vrai`.

**Ce n'est pas un défaut de P10b-2** : son comportement est juste pour son consommateur, qui affiche
un score à côté du niveau que ce même protocole a décidé — *« prendre ceux d'un autre protocole
afficherait un score qui contredit la décision »*. Le défaut est d'avoir supposé que ce contrat
convenait à un consommateur d'une autre nature.

Correction locale, sans toucher un module G5 : `ServiceQuestionnaire` évalue les protocoles
lui-même, sur les mêmes instantanés publiés (cache partagé), et prend le **maximum** des planchers.
Ce maximum n'invente rien — c'est la sémantique que b-2 déclare en excluant `DEFINIR_SCORE_MINIMUM`
des actions exclusives : *« deux planchers ne se contredisent pas, le plus haut s'applique »*. Rien
n'est perdu de la cascade §3, qui sert à **départager des actions exclusives** ; un questionnaire
n'en a pas.

### (2) Une mutation qui « tuait » un vecteur déjà rouge — SIXIÈME leçon du harnais

Le vecteur du plancher avait été **ajouté après** le dernier run vert de la suite. La campagne de
mutation l'a vu échouer et l'a compté comme « mort », alors qu'il était **rouge avant la mutation**.
La mutation n'a donc rien prouvé, et le défaut (1) est passé sous son nez.

> **Vérifier qu'un vecteur est VERT avant de le muter.** Un vecteur rouge meurt sous n'importe
> quelle mutation, y compris sous une mutation sans rapport avec lui.

C'est la sixième leçon accumulée sur ce harnais, après :

| # | Leçon | Incrément |
|---|---|---|
| 1 | asserter que la mutation est **appliquée** | P6.7b |
| 2 | asserter le **site** (`s///` remplace la première occurrence) | P6.8d |
| 3 | ancre sur **une seule ligne**, restauration vérifiée | P6.8e |
| 4 | la restauration se vérifie contre la **copie pré-mutation**, jamais contre `git diff` — le travail n'est pas commité, donc `git diff` n'est jamais vide et criait « non restauré » sur huit mutations parfaitement restaurées ; *un garde-fou qui alerte à tort finit par être ignoré* | **P10b-3-i** |
| 5 | l'ancre ne doit **jamais être un préfixe du remplacement**, sinon le contrôle « appliquée » la retrouve dans le texte muté et abandonne à tort | **P10b-3-i** |
| 6 | **vérifier le vert avant de muter** | **P10b-3-i** |

### (3) Un vecteur qui prouvait autre chose — cinquième occurrence de la famille

Le vecteur de la condition sur une question inexistante survivait à la neutralisation de sa garde :
sans elle, le contrôle tombe plus bas sur la compatibilité fait/opérateur (le type d'une question
inconnue vaut chaîne vide, donc aucun opérateur ne l'accepte) et refuse **quand même**, en parlant
d'un type au lieu d'une question absente.

Après les `expectExceptionCode` de P6.4c, le contrôle de révocation de P6.5b, le quatre-yeux de
P6.8e et celui de P10b-1 : **un refus se vérifie par son MOTIF.** Le vecteur a été réécrit pour
exiger la phrase exacte *et* la liste des questions disponibles.

## B3.12 — Limites

Voir `GUIDE_TEST_TRIAGE.md` partie 4 §5. Les deux principales : **le poids des symptômes et
`PLAFOND_ANTECEDENTS` restent dans le code** (X3 n'est refermé que pour les réponses), et **aucun
écran §7** — un médecin spécialiste signe toujours par curl un document que le §7 qualifie
d'*opposable*. Les deux sont le périmètre de **P10b-3-ii**.

---

# B4 — Assemblage du score sous protocole et écran §7 de lecture et signature (P10b-3-ii)

- **Statut** : **VALIDÉ G5 le 2026-08-21** — G1 validé le 2026-08-20 (décisions A, B, C) ; G0
  d'implémentation le 2026-08-21 (constat Z1) ; G3 (1179 tests / 16 430 assertions, 23 vecteurs
  dédiés, Pint, mutation 6 tueuses + 1 verte) et G2 live MySQL W1→W11 prouvés (base restaurée compte
  pour compte) ; **G4 propriétaire OK**. Dernier incrément de P10b.
- **Plan G1** : `docs/PLAN_G1_P10b3ii_Antecedents_Ecran7.md`.

## B4.1 — Le périmètre annoncé a été réduit, et c'est la décision A

`CLAUDE.md` annonçait « poids des symptômes sous protocole ». Le plan G1 a conclu que **ce serait
une erreur**, pour une raison qui vient de P10b-3-i lui-même : cet incrément a déplacé les questions
**parce que** question, condition et impact ne peuvent pas vivre dans deux artefacts aux cycles de
publication indépendants. Déplacer les poids reproduirait le même défaut un cran plus bas — **un
symptôme neuf publié au référentiel pèserait 0 tant que le protocole ne l'aurait pas rattrapé**,
sans erreur et sans signal.

> **Ce qui est un attribut de l'objet reste avec l'objet. Ce qui est une règle combinant des objets
> va au protocole.**

Une question **est** la substance du questionnaire ; un poids est un attribut du symptôme, à côté de
son nom, de sa catégorie et de ses orientations.

**Ce que cela laisse ouvert et qui est nommé** : `poids_severite` et `drapeau_rouge` restent publiés
sous le cycle **§10** du socle (deux agents) alors qu'ils décident de l'urgence autant qu'un seuil.
La réponse honnête n'est pas de déplacer la donnée mais **d'élever la gouvernance de ce
référentiel** — ce qui touche le cycle de P6.3, partagé par les dix référentiels. Incrément à part,
nommé plutôt qu'oublié.

## B4.2 — Z1 : l'assemblage des faits existait en trois exemplaires

Trouvé au G0 d'implémentation, pas au G1. `TriageController::questions()` et
`TriageService::analyser()` (deux fois, une par phase) composaient chacun à la main le tableau de
faits.

**Ce n'était pas une redite bénigne** : `score_antecedents` était déjà un fait déclaré, passé par
les deux sites du service et **pas** par celui du contrôleur. Or, depuis P10b-1, **un fait inconnu
lève**. Une règle de questionnaire conditionnée sur les antécédents aurait donc fonctionné dans
`POST /triage/analyser` et **rendu `POST /triage/questions` inopérant** — défaut actif, simplement
non déclenché faute de règle qui l'emprunte. Même famille que le `centre_dialyse` en dur de P6.4b.

Ajouter deux faits à trois endroits aurait reproduit la faute. D'où `FaitsTriage`, source unique.

## B4.3 — Un contexte propre, contre ce que disait le plan

Le plan logeait `TRIAGE-ANTECEDENTS` dans `triage_questionnaire`. Cela **recréait Z1** :
`POST /triage/questions` ne connaît pas le membre, donc pas ses antécédents. Une même règle aurait
répondu différemment selon l'endpoint.

Le contexte `triage_antecedents` rend la frontière **vérifiable** au lieu de conventionnelle : le
contrôle qualité refuse qu'un protocole de questionnaire conditionne sur les antécédents, et qu'un
protocole d'antécédents conditionne sur un score pas encore assemblé — ou sur la valeur qu'il est
lui-même en train de décider. **Chaque refus nomme le fait à utiliser à la place.**

Corollaire corrigé au passage : le message de b-3-i proposait `score_antecedents` comme repli pour
un questionnaire. **C'est devenu faux**, et le message a été rectifié plutôt que laissé.

## B4.4 — `BORNER` et non `DÉFINIR`, après avoir essayé l'inverse

La première écriture disait `SI brut > 20 ALORS DEFINIR 20`. Elle ne tient pas : il faut une seconde
règle pour le cas contraire — « sinon, garder la somme telle quelle » — et **cette valeur est
dynamique**, donc inexprimable par une action à valeur littérale. Y mettre 0 aurait effacé les
antécédents des patients qui en déclarent peu : le contraire exact de la règle.

La borne s'écrit en **une seule règle sans condition** (le moteur prévoit explicitement ce cas), et
le service applique un `min`. **La décision — le chiffre — est dans le protocole ; l'arithmétique
reste où vivent déjà la somme et les bornes 0-100.**

## B4.5 — Décision B : le plafond est la réponse à `impact_triage`, pas une incohérence

`impact_triage` est saisi par le patient (constat Y1). J'avais avancé qu'il serait indéfendable de
faire signer une borne posée sur une saisie libre. **C'est l'inverse** : la borne existe précisément
*parce que* l'entrée n'est pas vérifiée. La gouverner, c'est gouverner la seule moitié qui puisse
l'être.

Les deux autres voies, écartées avec leur raison : dériver l'impact d'une gravité gouvernée
supposerait d'inventer dans le référentiel des maladies une échelle que personne n'a validée (ce que
P6.8c avait refusé pour `categorie`) ; refuser la déclaration du patient ferait tomber cette part à
0 pour tout le carnet, donc **baisserait** les scores — un défaut qui pousse vers le **sous-triage**,
la direction dangereuse.

## B4.6 — L'écran §7 : lire et signer, jamais éditer

Le §7 qualifie le dossier de validation d'**opposable**. Demander à un médecin spécialiste de signer
par `curl` revient à lui faire signer un texte qu'il n'a pas lu sous une forme lisible.

L'écran rend les règles **en français**, depuis les libellés des trois listes blanches — un
relecteur clinique n'a pas à lire du JSON. Il montre les contrôles §7.4 en échec **avant** la
signature, pour qu'on ne relise pas pour rien.

> **Une validation caduque doit avoir l'air caduque.** C'est l'information dont un signataire a le
> plus besoin : le texte a bougé depuis qu'un confrère l'a relu. L'afficher discrètement laisserait
> signer par-dessus une relecture périmée — ce que l'anti-substitution existe pour empêcher.

**Aucun bouton « modifier »** (décision Q2). La garde du groupe de routes accepte l'une quelconque
des cinq permissions ; celle qui **fait autorité** reste celle du service, qui exige la permission
**exacte** du type signé — sans quoi un relecteur clinique apposerait la signature technique.

## B4.7 — Une divergence assumée avec le §8

Deux protocoles bornant différemment : le service **refuse** au lieu de les départager. À rang égal,
P10b-2 refuse déjà la publication d'une version que seule la date départagerait. À rangs différents,
le §8 saurait départager, et nous ne le faisons pas — **plus strict que le corpus, délibérément** :
la cascade du §8 départage des recommandations qu'un clinicien lit, alors qu'ici la valeur retenue
modifierait un score **en silence**. Un refus se voit ; un départage tacite, non.

## B4.8 — Ce que la mutation a trouvé, et que les tests verts ne voyaient pas

La première campagne a laissé **trois mutations survivre**, chacune disant autre chose :

1. **Le refus bruyant** — neutraliser la garde « aucun protocole en vigueur » faisait tomber
   l'exécution sur l'**autre** refus (« aucune règle ne s'applique »), qui rend aussi un 503 parlant
   de borne. Le vecteur cherchait le mot « borne » : il restait vert en ayant perdu ce qu'il
   gardait. **Sixième instance** de cette famille (P6.4c, P6.5b, P6.8e, P10b-1, P10b-3-i) — corrigé
   en vérifiant le motif exact, et **dédoublé** : les deux refus ont désormais chacun son vecteur.
2. **Deux bornes divergentes** — la garde existait, **aucun vecteur ne la tenait**. Ajouté ; et
   l'écrire a montré qu'à rang égal la publication est déjà refusée en amont (B4.7).
3. **La source unique** — fausser `score_symptomes` à 0 ne faisait rien tomber : les vecteurs
   existants comparaient deux scores qui se décalaient ensemble. *Une source unique n'est prouvée
   que si l'on vérifie ce qu'elle produit, pas seulement que deux appelants en dépendent.*

Une quatrième mutation était **prévue pour rester verte** (`array_merge` → `array_replace`, même
sémantique ici) : un harnais qui ne prévoit que des mutations tueuses ne se teste jamais lui-même.

**Piège attrapé à l'écriture, avant toute mutation** : l'union de tableaux `+` garde la valeur de
**gauche**. Le `drapeau_rouge` relevé par le plancher d'une réponse aurait été ignoré en silence —
il aurait disparu pour la **seconde fois**. `array_merge`, et un vecteur dédié.

## B4.9 — Conséquence de déploiement

**Cinq mises en vigueur** : `seuils_mesure`, `symptomes_triage`, `TRIAGE-NIVEAU`,
`TRIAGE-QUESTIONNAIRE`, `TRIAGE-ANTECEDENTS`. Le refus vaut **même pour un patient sans aucun
antécédent** : sinon un oubli de publication passerait inaperçu sur la majorité des triages et ne se
signalerait que sur les autres.

## B4.10 — Limites

1. **`poids_severite` et `drapeau_rouge` restent sous deux signatures** (§10) — porteur : un
   incrément de gouvernance du socle.
2. **`impact_triage` reste déclaré par le patient** — porteur : chemin soignant, ou gravité
   gouvernée au référentiel des maladies le jour où une source existe.
3. **Aucun écran d'authoring** : un brouillon se construit toujours par seeder ou par API.
4. Le nom du contexte `triage_questionnaire` porte désormais autre chose qu'un questionnaire.
5. Contenu de démonstration : `niveau_preuve = 'D'`, aucun validateur forgé, aucune autorité nommée.
6. **§11 (< 100 ms)** toujours non déclaré atteint.
