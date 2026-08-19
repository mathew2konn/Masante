# ADR-041 — Registre des protocoles médicaux et moteur de règles (CDC_08)

- **Statut** : accepté — G1 validé par le propriétaire le 2026-08-19. **P10b-1 : VALIDÉ G5 le
  2026-08-19** — G2 (live MySQL) et G3 (tests, typecheck, mutation) prouvés, G4 propriétaire OK.
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

## 6. Les quatre gardes de publication

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
