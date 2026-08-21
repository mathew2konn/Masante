# Plan G1 — Chaînes d'audit : origine déclarée et identifiants non référentiels

- **Statut** : **proposé, en attente de validation écrite du propriétaire.** Aucune ligne de code
  avant validation (CDC_01 §2.4).
- **Nature** : incrément transverse de correction. Il touche trois modules **validés G5**
  (P6.3 socle référentiel, P6.5b PKI, P10b-1 protocoles) — donc **corrections chirurgicales
  uniquement**, aucune réécriture.
- **Corpus** : CDC_10 (sécurité, journalisation) ; CDC_09 §10 (gouvernance) ; CDC_08 §10 ;
  CDC_04 §125 ; loi 2013-450 (droit à l'effacement d'un compte).
- **Origine** : constat de P10b-2 (« la restauration du G2 de b-1 a rompu la chaîne de
  gouvernance »), laissé à la décision du propriétaire. Diagnostiqué en base le 2026-08-20.

---

## 1. G0 — ce qui a été mesuré, pas supposé

Les chiffres ci-dessous viennent de la base de développement `ivoirsante`, lus le 2026-08-20.

### C1 — Trois chaînes portent le même défaut, l'une deux fois

| Journal | Colonne dans l'empreinte | Action référentielle |
|---|---|---|
| `referentiel_journal` (P6.3) | `acteur_id` | `nullOnDelete` |
| `protocole_journal` (P10b-1) | `acteur_id` | `nullOnDelete` |
| `signature_journal` (P6.5b) | `acteur_id` **et** `medecin_id` | `nullOnDelete` **les deux** |

Le dernier est le plus lourd de conséquence : **supprimer un professionnel casserait la chaîne qui
prouve que les ordonnances signées n'ont pas été altérées.** Or un praticien qui quitte le système
est un événement ordinaire.

`protocole_applications` (P10b-2) est le seul journal fait juste — colonne entière simple, aucune
clé étrangère. Le précédent existe donc **dans le projet**, il n'a simplement pas été appliqué en
arrière.

### C2 — La chaîne des protocoles crie, et elle a raison

34 entrées, ids **1 → 34 contigus**, première entrée sans prédécesseur : la chaîne est
structurellement saine. Rupture de type `CONTENU` sur l'entrée **#1**. Cause : **16 entrées sur 34**
portent `acteur_id = NULL` alors que leur `acteur_nom` est intact (« Awa Relectrice »,
« Bakary Publieur »).

**Rien n'a été falsifié.** C'est le moteur qui a modifié la charge hachée, en exécutant l'action
référentielle que la migration lui a demandée. *Le journal signale une falsification que le système
a commise lui-même, par conception.*

### C3 — La chaîne du socle, elle, ment — et c'est le vrai défaut

`referentiel_journal` répond **`intacte: true`**. Elle contient **3 entrées, ids 98 → 100**,
`AUTO_INCREMENT` à **101**, et la première a `empreinte_precedente = NULL`.

**97 entrées ont disparu** — toute la gouvernance de P6.3 à P6.8 — et la vérification ne le voit
pas. `verifierChaine()` part de `$attendue = null` et accepte donc **n'importe quelle** première
entrée : elle ne sait pas distinguer une chaîne neuve d'une chaîne dont on a effacé l'histoire.

Même forme pour `signature_journal` : **0 ligne**, `AUTO_INCREMENT` à **6** — cinq entrées écrites
au G2 de P6.5b, supprimées, et une chaîne vide se vérifie « intacte » sans rien dire.

> **Une chaîne tronquée par la tête est indétectable.** C'est un défaut plus grave que celui de C2,
> parce qu'il est **muet** : celle qui hurle est la seule des deux qui dise quelque chose de vrai.

### C4 — Deux entrées portent la trace archéologique d'un défaut déjà corrigé

`referentiel_journal` #99 et #100 portent `acteur_nom = 'Système'` pour les comptes 4 et 6, qui ont
de vrais noms (`nomLisible()` répond « IVOIRSANTÉ Admin », « Plateforme Contrôle »). Elles ont été
écrites avant le correctif `User::nomLisible()` de P10b-1. **Elles ne seront pas réécrites** — ce
sont des archives, et une archive corrigée n'est plus une archive.

### C5 — ~~`JournalSignature` porte sa propre copie de `nomLisible()`~~ — **CONSTAT FAUX, RETIRÉ**

> **Corrigé le 2026-08-20, à l'implémentation.** Vérification faite dans le code : la méthode privée
> de `JournalSignature` **délègue déjà** à `User::nomLisible()` et ne fait qu'y traiter le `null`.
> Ce n'est pas une seconde implémentation. Le constat est retiré plutôt que transformé en travail,
> et la décision D5 qui en découlait tombe avec lui.

### C6 — Aucun autre consommateur ne dépend des clés étrangères visées

Les journaux ne sont jamais joints à `users` par une relation exploitée : l'affichage se fait sur
`acteur_nom`, la vérification sur la charge hachée. Retirer la contrainte ne prive donc aucun écran
d'information. *(À reconfirmer exhaustivement au G0 d'implémentation.)*

---

## 2. Décisions proposées

### D1 — Un identifiant de journal est un identifiant, pas une relation vivante

Retirer les clés étrangères `acteur_id` (et `medecin_id` pour la PKI) des trois journaux chaînés ;
les colonnes restent des entiers nullables, avec leur index. **Les valeurs déjà écrites ne sont pas
touchées.**

**Ce que cela ne fait pas** : réparer le passé. Les 16 entrées déjà à `NULL` le restent, et la
rupture C2 subsiste. **Ce que cela fait** : empêcher que la prochaine suppression de compte — un
acte ordinaire et un droit — ne casse une chaîne de plus.

### D2 — Une chaîne déclare son origine

Une chaîne doit pouvoir dire « je commence ici, et voici pourquoi ». Sans cela, C3 reste invisible.

Mécanisme proposé : une colonne `chaine` (entier, défaut `1`) sur chaque journal chaîné, et une
entrée d'ouverture portant l'action `CHAINE_OUVERTE`. La vérification :

- ne parcourt que la **chaîne courante** (le plus grand numéro) ;
- exige que sa première entrée soit une `CHAINE_OUVERTE` — sinon rupture de type **`ORIGINE`** :
  « la chaîne ne déclare pas son origine ; des entrées ont pu être supprimées en tête » ;
- **rend compte des chaînes scellées** : leur numéro, leur volume, et leur verdict au moment du
  scellement.

Rien n'est masqué : la rupture C2 continue d'être **nommée**, elle cesse seulement d'être la seule
chose que le système sait dire.

> **Deux écarts à l'implémentation, dits plutôt que déguisés** (voir ADR-042 §3.2) :
> 1. L'ouverture **n'est pas** une entrée du journal mais une ligne d'une table dédiée
>    `audit_chaines`. Le mécanisme prévu ici ne tient pour aucun des quatre journaux :
>    `protocole_journal` exige un `protocole_code` et un `protocole_id` qu'une ouverture n'a pas, et
>    `protocole_applications` est un journal d'évaluations cliniques — y insérer une ligne qui n'est
>    pas une évaluation mettrait deux natures de vérité dans la même table.
> 2. **La déclaration ne suffisait pas** : une chaîne déclarée puis **vidée et réalimentée** se
>    revérifiait « intacte », c'est-à-dire l'accident exact qu'on corrige. D'où l'**ancrage de
>    tête** — l'empreinte de la première entrée, inscrite une seule fois. Trouvé en écrivant le
>    vecteur, pas en relisant le plan.

### D3 — Recommencement déclaré, ancien scellé, rien de réécrit

Pour chaque journal, une commande d'exploitation écrit **une** entrée `CHAINE_OUVERTE` dans une
chaîne neuve, dont les détails portent :

- le **motif**, obligatoire (aucune valeur par défaut → échec bruyant ; précédent de la commission
  sans seed, P5.5a) ;
- le **nom de l'opérateur** ;
- le **volume** de la chaîne scellée et **l'empreinte de sa dernière entrée** ;
- **le verdict de l'ancienne chaîne au moment du scellement** — `rompue à #1, type CONTENU` pour les
  protocoles. *C'est le point qui rend le geste honnête : on ne scelle pas en silence une chaîne
  cassée, on inscrit dans le marbre neuf qu'elle l'était.*

Commande d'artisan, **pas d'endpoint HTTP** : c'est une étape de déploiement, comme les mises en
vigueur de L1+L2 et de b-1. Refusée si la chaîne courante est vide (ouvrir une chaîne qui n'a jamais
commencé n'a pas de sens).

### D4 — Aucune empreinte n'est jamais recalculée

Écrit comme invariant, et **tenu par un vecteur** : la suite échoue si un chemin de code recalcule
l'empreinte d'une entrée existante. Recalculer serait réécrire l'histoire — exactement ce que la
chaîne existe pour rendre impossible.

### D5 — ~~`nomLisible()` redevient la source unique~~ — **SANS OBJET** (voir C5)

---

## 3. Ce qui reste hors périmètre, et pourquoi

1. **Les entrées déjà écrites** — ni corrigées, ni complétées, ni supprimées.
2. **La chaîne `audit_entries` du paiement** (Java, P5.1) : autre service, autre langage, autre
   cycle. Elle mérite le même examen ; le faire ici mélangerait deux dépôts.
3. **Une signature cryptographique du journal** (au-delà du hachage chaîné) : c'est un autre sujet
   (HSM, ADR-032), et ce plan ne prétend pas y toucher.
4. **La restauration de l'historique perdu** : il n'existe aucune sauvegarde de
   `referentiel_journal` antérieure à sa troncature. *Le dire est la seule chose honnête à en faire.*

---

## 4. Surface technique prévue

**Migration** (une seule, sans perte) : suppression de 4 contraintes de clé étrangère, ajout de la
colonne `chaine` (défaut 1, indexée avec `id`) sur les trois journaux. Aucune donnée modifiée.

**Code** : `JournalProtocole`, `JournalReferentiel`, `JournalSignature` — écriture dans la chaîne
courante, vérification par chaîne, nouveau type de rupture `ORIGINE`. Une commande
`masante:audit:ouvrir-chaine {journal} --motif=... --acteur=...`.

**Contrat de lecture** : `GET /protocoles/journal/integrite` gagne `chaine_courante`,
`origine_declaree` et `chaines_scellees`. Les clés existantes (`intacte`, `entrees`, `rupture`) ne
changent ni de nom ni de sens.

---

## 5. Vecteurs prévus

**G3** (une garantie, un vecteur) :

1. une chaîne sans entrée d'ouverture → rupture `ORIGINE` ;
2. une chaîne ouverte puis alimentée → `intacte`, `origine_declaree: true` ;
3. **suppression de la première entrée d'une chaîne ouverte → rupture `ORIGINE`**, pas un silence
   (le vecteur qui n'existait pas et qui aurait vu C3) ;
4. suppression d'une entrée du milieu → rupture `CHAINAGE` (comportement conservé) ;
5. modification d'une entrée → rupture `CONTENU` (comportement conservé) ;
6. **suppression d'un compte acteur → la chaîne reste intacte** (le vecteur qui n'existait pas et
   qui aurait vu C1) ;
7. **suppression d'un médecin → la chaîne des signatures reste intacte** ;
8. scellement : l'ancienne chaîne reste lisible, son verdict est inscrit dans l'entrée d'ouverture,
   les empreintes anciennes sont **inchangées** (comparaison octet à octet avant/après) ;
9. scellement sans motif → refus **par son motif** ;
10. scellement d'une chaîne vide → refus ;
11. `nomLisible()` : un acteur réel n'écrit jamais « Système ».

**G2 live MySQL** : état avant (34 / 3 / 0 entrées, rupture CONTENU #1, deux « intactes »
mensongères) → migration → **les mêmes verdicts, plus l'aveu** : `referentiel_journal` et
`signature_journal` passent d'« intacte » à `ORIGINE`. *C'est le seul incrément du projet dont le
succès se mesure au fait que deux voyants passent au rouge.* Puis scellement des trois, vérification
que rien n'a bougé en base hors la colonne `chaine`, et suppression d'un compte sans casse.

**Mutations** : neutraliser le contrôle d'origine, le refus sans motif, le refus de chaîne vide, et
la sélection de la chaîne courante — chacune doit tuer ses vecteurs. Harnais des six règles (vert
avant mutation, application assertée, site asserté, ancre sur une seule ligne, ancre non préfixe du
remplacement, restauration vérifiée contre la copie pré-mutation).

---

## 6. Limites qui seront annoncées

1. **La rupture C2 n'est pas réparée** et ne le sera jamais. Elle sera **scellée et datée**.
2. **97 entrées de gouvernance sont perdues** sans sauvegarde.
3. Le scellement est un acte d'exploitation : rien n'empêche techniquement de l'abuser. Il est
   journalisé, motivé et nominatif — c'est tout ce qu'une chaîne peut offrir contre celui qui tient
   la base.
4. La chaîne du paiement (Java) n'est pas examinée ici.
