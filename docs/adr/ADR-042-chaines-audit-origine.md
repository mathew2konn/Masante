# ADR-042 — Chaînes d'audit : origine déclarée et identifiants non référentiels

- **Statut** : **VALIDÉ G5 le 2026-08-21** — G1 validé le 2026-08-20 ; G3 (1158 tests / 16 371
  assertions, 21 vecteurs dédiés, Pint, mutation 6/6) et G2 live MySQL W1→W10 prouvés (base de
  développement restaurée compte pour compte) ; **G4 propriétaire OK**.
- **Contexte** : incrément transverse de correction, né du constat laissé ouvert par P10b-2
  (« la restauration du G2 de b-1 a rompu la chaîne de gouvernance »). Touche trois modules validés
  G5 — P6.3 (socle référentiel), P6.5b (PKI), P10b-1 (protocoles) — et le journal d'exécution de
  P10b-2. **Corrections chirurgicales uniquement.**
- **Corpus** : CDC_10 ; CDC_09 §11 ; CDC_08 §10 ; CDC_04 §125 ; loi 2013-450.
- **Plan G1** : `docs/PLAN_G1_Chaines_Audit_Origine.md`.

---

## 1. Le diagnostic, et ce qu'il a corrigé de mon propre récit

Le constat de P10b-2 disait : *la chaîne de gouvernance des protocoles est rompue*. C'était vrai,
et incomplet. La lecture de la base du 2026-08-20 a montré **deux défauts de natures différentes**,
et le second était invisible.

### 1.1 — La chaîne des protocoles crie, et elle a raison

34 entrées, ids 1 → 34 contigus, première entrée sans prédécesseur. Rupture `CONTENU` sur l'entrée
#1. Cause : **16 entrées sur 34 portent `acteur_id = NULL`** alors que leur `acteur_nom` est intact.

Rien n'a été falsifié : c'est le moteur qui a modifié la charge hachée, en exécutant le
`ON DELETE SET NULL` que la migration lui avait demandé, quand les comptes temporaires du G2 ont été
supprimés. *Le journal signale une falsification que le système a commise lui-même, par conception.*

### 1.2 — La chaîne du socle, elle, mentait

`referentiel_journal` répondait **`intacte: true`** avec **3 entrées, ids 98 → 100**, compteur
d'auto-incrément à 101 et une première entrée sans prédécesseur. **97 entrées avaient disparu** —
toute la gouvernance de P6.3 à P6.8 — sans que rien ne le dise. `signature_journal` : 0 ligne,
compteur à 6, « intacte » aussi.

La cause est dans le code de vérification : il partait de `$attendue = null` et acceptait donc
**n'importe quelle première entrée**.

> **Une chaîne tronquée par la tête était indétectable.** Ce défaut est plus grave que celui de
> §1.1, parce qu'il est **muet** : celle qui hurle était la seule des deux à dire quelque chose de
> vrai.

### 1.3 — Un troisième défaut, trouvé en écrivant le vecteur du second

La première conception ne suffisait pas. Une chaîne **déclarée** à l'installation, puis **vidée et
réalimentée**, se serait revérifiée « intacte » : les nouvelles entrées repartent d'une empreinte
précédente nulle et la déclaration existe toujours. Or c'est **exactement l'accident survenu** —
les entrées 1 à 97 ont été supprimées, puis 98 à 100 écrites.

D'où l'**ancrage de tête** (§3.2), ajouté en cours d'implémentation.

---

## 2. Ce que cet incrément ne fait pas

**Il ne répare rien.** Aucune empreinte n'est recalculée, aucune entrée n'est corrigée, complétée ou
supprimée — y compris les deux entrées de `referentiel_journal` qui portent « Système » pour des
acteurs humains, vestiges du défaut `$user->name` corrigé en P10b-1. *Une archive corrigée n'est
plus une archive.*

Il ne restaure pas non plus les 97 entrées perdues : il n'en existe aucune sauvegarde. Le dire est
la seule chose honnête à en faire.

---

## 3. Décisions

### 3.1 — D1 : un identifiant de journal est un identifiant, pas une relation vivante

Les clés étrangères sont retirées de `referentiel_journal.acteur_id`, `protocole_journal.acteur_id`,
`signature_journal.acteur_id` **et** `signature_journal.medecin_id`. Les colonnes restent des
entiers nullables.

Le dernier cas est le plus lourd de conséquence et n'avait jamais été nommé : **supprimer un
professionnel cassait la chaîne qui prouve qu'une ordonnance signée n'a pas été altérée.** Or un
praticien qui quitte le système est un événement ordinaire.

`protocole_applications` (P10b-2) était déjà juste — colonne entière simple, aucune clé étrangère.
Le précédent existait **dans le projet** ; il n'avait pas été appliqué en arrière.

**Ce que D1 ne fait pas** : réparer §1.1. Les 16 entrées restent à `NULL`. Ce qu'il fait : empêcher
que la prochaine suppression de compte — un acte ordinaire, et un droit — ne casse une chaîne de
plus.

### 3.2 — D2 : une chaîne déclare son origine, et sa tête est ancrée

Chaque journal porte une colonne `chaine` ; l'ouverture d'une chaîne est **déclarée** dans la table
`audit_chaines`, et l'empreinte de sa **première entrée** y est **ancrée**, une seule fois.

La vérification ne parcourt que la chaîne courante et rend trois faits distincts :
`origine_declaree`, `origine_conforme` (la tête est bien celle qui a été ancrée) et le verdict
`intacte` / `rupture`.

**Pourquoi une table et non un maillon du journal lui-même** — l'idée naturelle était d'écrire
l'ouverture comme une entrée ordinaire, protégée par le hachage. Elle ne tient pour aucun des quatre
journaux : `protocole_journal` exige un `protocole_code` et un `protocole_id` qu'une ouverture n'a
pas, et `protocole_applications` est un journal d'**évaluations cliniques** — y insérer une ligne
qui n'est pas une évaluation mettrait deux natures de vérité dans la même table, ce que ce projet
refuse depuis P6.6a.

**Ce que la table protège, et ce qu'elle ne protège pas.** Elle n'est pas chaînée. **Supprimer une
déclaration est détecté** — la chaîne repasse au rouge : la disparition joue dans le sens sûr. En
revanche, **forger** une déclaration reste possible à qui tient la base. C'est une limite écrite :
une déclaration est nominative et motivée, c'est tout ce qu'un journal peut opposer à celui qui
possède le serveur.

**L'ancrage n'est pas un miroir.** Il est écrit une fois, à la première entrée de la chaîne. Un
compteur entretenu à chaque écriture aurait été une seconde vérité à maintenir, donc à faire
diverger.

### 3.3 — D3 : recommencement déclaré, ancien scellé, rien de réécrit

La commande `masante:audit:ouvrir-chaine {journal} --motif=… --acteur=…` scelle la chaîne courante
et en ouvre une neuve. La déclaration de la chaîne neuve porte le motif, l'opérateur, le volume
scellé, l'empreinte de la dernière entrée **et le verdict de l'ancienne chaîne au moment du
scellement**.

> C'est ce dernier point qui rend le geste honnête : *on ne scelle pas en silence une chaîne
> cassée, on inscrit dans le marbre neuf qu'elle l'était.*

Les chaînes closes restent **recalculées** à chaque vérification (`verdict_actuel` à côté de
`verdict_au_scellement`) : sceller ne met pas l'ancien à l'abri du contrôle.

Le motif n'a **aucune valeur par défaut** : un scellement sans raison écrite serait un effacement
d'historique déguisé en maintenance (précédent de la commission sans seed, P5.5a). Sceller une
chaîne vide est refusé — ouvrir une chaîne qui n'a jamais commencé n'a pas de sens.

C'est une **commande**, pas un endpoint : *un journal d'audit dont on tourne la page depuis un
navigateur n'est plus un journal d'audit.*

### 3.4 — D4 : aucune empreinte n'est jamais recalculée

Tenu par un vecteur : le scellement compare les empreintes octet à octet avant et après.

### 3.5 — D5 est tombée : le constat C5 du plan était faux

Le plan G1 annonçait que `JournalSignature` portait une copie de `nomLisible()` à faire converger.
**Vérification faite, c'est inexact** : la méthode y délègue déjà à `User::nomLisible()` et ne fait
qu'y traiter le `null`. Rien à corriger. *Le constat est retiré plutôt que transformé en travail.*

### 3.6 — L'ordre des verdicts est délibéré

Une rupture `CONTENU` ou `CHAINAGE` l'emporte sur `ORIGINE` dans le champ `rupture` : elle désigne
une entrée **par son identifiant**, c'est le fait le plus précis qu'un humain doit lire en premier
dans un litige. L'absence d'origine reste rendue à part, donc aucun des deux faits ne masque
l'autre. Même raisonnement que l'ordre des cinq contrôles du §5.4 en P6.5b.

---

## 4. Une divergence test/production refusée

Les tests tournent sur SQLite avec les clés étrangères activées ; MySQL sait retirer une contrainte,
SQLite non — Laravel lève sur `dropForeign`. Ne le faire qu'en MySQL aurait rendu la garantie
« supprimer un compte ne casse pas la chaîne » **vraie en production et fausse en test**, exactement
la divergence relevée en P6.8c (collation) et refusée en P6.8e (REGEXP).

La migration **reconstruit donc la table** sous SQLite : capture du schéma, renommage, recréation
sans la clause, copie, suppression, recréation des index. Si aucune clause n'est retirée, elle
**échoue bruyamment** plutôt que de laisser croire que la contrainte a sauté.

---

## 5. Preuves

**G3** — 20 vecteurs dédiés (`ChaineAuditTest`), écrits dans les deux sens. Le vecteur central est
*vider puis réalimenter une chaîne déclarée* : sans ancrage, il passerait au vert en ayant perdu
toute l'histoire.

**Mutation** — 6 gardes neutralisées, chacune tuant ses vecteurs, arbre restauré et vérifié contre
la copie pré-mutation, vert avant et après (les six règles du harnais).

**Non couvert par la mutation, et c'est dit** : le retrait des clés étrangères vit dans une
migration, qu'on ne peut pas muter à chaud. Il est tenu par trois vecteurs (`acteur_id` protocoles,
`acteur_id` référentiels, `medecin_id` signatures) qui échoueraient si la contrainte était encore
là.

**G2 live MySQL (2026-08-21)** — le seul incrément du projet dont le succès se mesure au fait
qu'**un voyant passe au rouge** :

| | avant | après |
|---|---|---|
| `referentiel_journal` (3 entrées, 97 disparues) | **`intacte: true`** | `intacte: false`, **`ORIGINE`** |
| `protocole_journal` (34 entrées) | `intacte: false`, `CONTENU` #1 | **identique**, plus `origine_declaree: false` |
| clés étrangères `acteur_id` / `medecin_id` | 4 | **0** |
| empreintes des 34 entrées | — | **inchangées, octet pour octet** |

Vecteurs tenus : suppression d'un compte → `acteur_id` **conservé** (94), `acteur_nom` intact,
**verdict rigoureusement identique** · trois refus, chacun **par son motif** (motif vide, journal
hors liste blanche, chaîne vide) · scellement → chaîne #2, **35 entrées scellées**, verdict
`CONTENU` inscrit dans la déclaration, empreintes inchangées · entrée suivante en `chaine = 2` avec
`empreinte_precedente = NULL`.

### Un défaut trouvé par le G2 live, invisible en test

`entrees` comptait les **tours de boucle**, laquelle s'arrête à la première rupture : une chaîne de
34 entrées rompue à la première s'annonçait « 1 entrée », et **le scellement inscrivait ce chiffre
dans le marbre**. Les chaînes de test étant courtes, aucun vecteur ne pouvait le voir — il a fallu
34 entrées réelles. Corrigé (le volume est compté sur la chaîne), et un vecteur ajouté.

### Un contrôle d'exhaustivité, fait en SQL plutôt que supposé

Recherche des tables portant `empreinte_precedente` : **exactement quatre**, celles de cet ADR.
`nis_journal` porte lui aussi un `acteur_id` en clé étrangère mais **n'est pas une chaîne de
hachage** (aucune colonne d'empreinte) : sa contrainte est sans conséquence, et il reste hors
périmètre à bon droit.

### Ce que le G2 a rendu visible et qu'il faut dire

`signature_journal` était **vide** au moment de la migration : son origine a donc été déclarée, et
il s'affiche « intacte ». Or son compteur d'auto-incrément est à 6 — cinq entrées ont existé puis
ont été supprimées. **La déclaration d'installation ne prouve donc pas qu'un journal n'a jamais rien
porté**, elle acte qu'il était vide ce jour-là. Distinguer les deux exigerait de lire le compteur
d'auto-incrément, que MySQL expose et SQLite non : la garantie serait plus forte en production qu'en
test. Le mécanisme **protège à partir du jour où il est installé et ne témoigne pas du passé** —
c'est écrit dans le motif d'ouverture lui-même, que tout auditeur lira.

---

## 6. Limites

1. **La rupture des protocoles n'est pas réparée** et ne le sera jamais. Elle est scellable, datée
   et motivée.
2. **97 entrées de gouvernance sont perdues**, sans sauvegarde.
3. **Forger une déclaration d'origine reste possible** à qui tient la base. La supprimer, non : cela
   fait passer la chaîne au rouge.
4. **La chaîne `audit_entries` du paiement** (Java, P5.1) n'est pas examinée : autre service, autre
   dépôt. Elle mérite le même examen.
5. Aucune signature cryptographique du journal au-delà du hachage chaîné (HSM — ADR-032).
