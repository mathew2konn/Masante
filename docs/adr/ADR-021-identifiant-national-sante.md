# ADR-021 — Identifiant National de Santé (NIS) : porteur, algorithme, exposition

**Statut** : Accepté (propriétaire, 2026-08-11 — G1 du module P6 / CDC_09)
**Contexte** : incrément P6.1. Complète et **corrige** ADR-001.
**Documents liés** : CDC_09 §3, CDC_04 §5.2, CDC_10 §5, ADR-001, ADR-003.

---

## 1. Contexte

Le CDC_09 §3 impose un identifiant de santé unique par citoyen, « permanent, non réutilisable,
sécurisé, facilement vérifiable », accompagnant le patient toute sa vie. ADR-001 en avait fixé le
principe (`CIS` + année + compteur + checksum mod-97) sans l'implémenter.

L'audit G0 du 2026-08-10 a établi trois faits qui conditionnent la décision :

1. **L'entité « patient » est `membres_famille`, pas `users`.** `AuthController::register` ne crée
   qu'un compte ; aucun dossier patient n'est créé automatiquement.
2. **`matricule_ivs` n'est pas un NIS.** Format `IVS-AAAA-XX-NNNNN`, `$hidden`, jamais exposé,
   consommé par `QrTokenService`. Il est conçu pour **ne pas** circuler ; le NIS est conçu pour
   circuler (CDC_09 §3.5).
3. **L'exemple d'ADR-001 est faux.** `CIS241200012547` ne satisfait aucune convention mod-97
   standard — la clé n'avait jamais été calculée (voir §6).

---

## 2. Décision — porteur du NIS

Le NIS est porté par **`membres_famille`** (le dossier patient), avec **création automatique du
dossier du titulaire du compte**.

`users` ne porte pas de NIS : un compte n'est pas un patient. Si le titulaire portait un NIS *et*
s'ajoutait comme membre, il obtiendrait deux identités de santé — exactement la fragmentation que
le MPI doit combattre.

### 2.1 Moment de la création — variante (c)

L'auto-création **ne peut pas** avoir lieu à l'inscription : `membres_famille` exige
`date_naissance` et `sexe` (NOT NULL), que `RegisterRequest` ne collecte pas.

Trois issues avaient été étudiées :

| Variante | Écartée parce que |
|---|---|
| (a) Collecter date de naissance + sexe à l'inscription | Modifie `RegisterRequest`, l'écran mobile d'inscription et le contrat d'API — **P1 est validé G5** ; alourdit le tunnel en zone à faible connectivité |
| (b) Rendre `date_naissance`/`sexe` nullable | Dégrade une contrainte d'intégrité d'un module validé ; casse les calculs d'âge (carte vitale, triage, carnet) |
| **(c) Création différée à la complétion du profil** ✅ | Retenue |

**Mécanique retenue** : à la première ouverture du Carnet, si le titulaire n'a pas de dossier, un
écran de complétion pré-rempli (nom et prénom déjà connus) collecte date de naissance et sexe →
création du dossier `est_titulaire = true` → attribution du NIS. Le Carnet reste inaccessible tant
que l'étape n'est pas faite.

**Conséquence actée** : le dossier titulaire est **hors quota** des **15** membres (F2.2, révisé de
5 à 15 par `modification.txt` — la valeur exacte est `StoreMembreRequest::MAX_MEMBRES`). Le
titulaire n'est pas un « membre ajouté ». Sans cette exclusion, activer le NIS retirerait
silencieusement un emplacement à chaque compte existant.

**Surface livrée**

| Élément | Rôle |
|---|---|
| `GET /api/v1/membres/titulaire` | Le **backend** dit si le dossier existe ; le mobile ne le déduit jamais de la liste des membres |
| `POST /api/v1/membres/titulaire` | Crée le dossier et attribue le NIS dans la même transaction ; **409** si déjà présent |
| `DossierTitulaireController` | Contrôleur **dédié** : `MembreController` (P2, validé G5) n'est pas touché |
| `StoreDossierTitulaireRequest` | N'accepte que `date_naissance`, `sexe`, `groupe_sanguin` |
| `/auth/me` → `a_dossier_titulaire` | Même vérité, exposée au profil |
| `app/(app)/profil-titulaire.tsx` | Écran de complétion (mobile) |
| Carnet | Porte d'entrée : sans dossier, ni liste familiale ni bouton d'ajout |

**`nom` et `prenom` ne sont pas acceptés du client** : le serveur les reprend du compte. Les laisser
au client permettrait de créer un dossier de santé sous une identité différente de celle du compte
— précisément la fragmentation que le MPI (CDC_09 §2) doit combattre.

### 2.2 Unicité du dossier titulaire

Garantie **déclarativement**, pas par l'applicatif — un seul dossier `est_titulaire` par compte :

- **MySQL** (production) : colonne générée `titulaire_du_compte` valant `user_id` si titulaire,
  `NULL` sinon, plus un index `UNIQUE` (qui tolère N valeurs `NULL`) ;
- **SQLite** (tests) : index unique partiel `WHERE est_titulaire = 1`.

Même invariant, deux dialectes. Sans cette distinction, le DDL MySQL casserait la suite de tests.

---

## 3. Décision — algorithme

**MASANTE-NIS-MOD97**, famille ISO 7064 MOD 97-10 (celle de l'IBAN).

```
Format   : PPP + AA + CCCCCCCC + KK          (15 caractères)
           PPP        préfixe pays alphabétique (CIS, SNS, BJS…)
           AA         année sur 2 chiffres
           CCCCCCCC   compteur national sur 8 chiffres
           KK         clé de contrôle

Calcul   : 1. lettres du préfixe → nombres (A=10 … Z=35)
           2. concaténer préfixe converti + AA + CCCCCCCC
           3. clé = 98 − (entier mod 97), sur 2 chiffres

Domaine de la clé : 02..98  (00, 01 et 99 sont impossibles)
```

### 3.1 Pourquoi le préfixe entre dans le calcul

Le CDC_09 §1.2 principe 5 exige qu'ajouter un pays n'implique **aucune modification de code**. En
intégrant le préfixe, un même couple année + compteur produit une clé différente par pays :

```
CIS + 24 + 12000125 → CIS241200012535
SNS + 24 + 12000125 → SNS241200012504
```

Un NIS ivoirien saisi dans un contexte sénégalais est rejeté par le checksum, sans table de
correspondance ni règle supplémentaire.

### 3.2 Propriétés vérifiées (et non postulées)

Mesurées sur 20 000 NIS générés, rejouées en test unitaire :

| Classe d'erreur | Détection |
|---|---|
| Un chiffre modifié | **100 %** (450 000 cas) |
| Inversion de deux chiffres voisins | **100 %** (50 127 cas) |
| Erreur portant sur la clé | **100 %** (90 000 cas) |

Cela couvre exactement ce que le CDC_09 §3.4 demande : « erreurs de saisie, inversions de chiffres,
faux identifiants ».

### 3.3 Ordre de verrou de la séquence — corrigé après un deadlock réel

Le premier jet reprenait le motif du service paiement (P5.5a) : `INSERT … ON CONFLICT` puis
`SELECT … FOR UPDATE`. Ce motif est correct **sur PostgreSQL**. Sur MySQL, le tir parallèle G2 a
produit un **deadlock InnoDB (erreur 1213)** systématique :

```
INSERT IGNORE  → pose un verrou PARTAGÉ (S) sur la ligne existante (contrôle de doublon)
SELECT … FOR UPDATE → réclame un verrou EXCLUSIF (X) sur cette même ligne
```

Deux transactions détenant chacune le S et demandant chacune le X se bloquent mutuellement :
montée en verrou croisée.

**Parade retenue — prendre le verrou exclusif dès le premier accès :**

```
UPDATE nis_compteurs SET dernier = dernier + 1 WHERE pays_code = ? AND annee = ?
  → verrou X immédiat, aucun verrou partagé préalable, donc aucune montée en verrou
si 0 ligne affectée (première attribution de l'année) :
  INSERT … dernier = 1        (course perdue → UniqueConstraintViolationException → on incrémente)
SELECT dernier … FOR UPDATE   → relit NOTRE valeur ; la ligne nous appartient déjà
```

Défense en profondeur : `DB::transaction(…, 3)` rejoue la transaction sur deadlock résiduel.

**Preuve G2** — deux volets :

1. *Déterministe* : deux connexions, la seconde **bloquée 3 s** (timeout) tant que la première
   détient le verrou, puis passante en **2 ms** après libération → sérialisation confirmée.
2. *Parallèle réel* : 8 processus simultanés → **8 NIS, 8 distincts, compteurs consécutifs
   14→21, 0 deadlock, 0 erreur**. Intégrité globale : compteur = journal = NIS distincts = 21,
   aucun doublon, aucun compteur consommé sans NIS.

> **Leçon transverse** : un motif de verrouillage validé sur un moteur ne se transpose pas tel
> quel. Le vecteur de concurrence n'est pas une formalité — c'est lui, et lui seul, qui a
> révélé le défaut. Aucun test unitaire ne pouvait le voir (la suite tourne sans base réelle).

### 3.4 Pas de dépendance nouvelle

Le nombre formé dépasse `PHP_INT_MAX` et `Number.MAX_SAFE_INTEGER`. Le modulo est appliqué
**chiffre par chiffre** — mathématiquement équivalent, sans `bcmath`, `gmp` ni `BigInt`
(discipline §2.6 : aucune dépendance sans accord écrit).

---

## 4. Décision — frontière et exposition

### 4.1 Le calcul côté client est autorisé, et pourquoi ce n'est pas une entorse

Le CDC_09 §3.4 **impose** la double validation : « côté client (feedback immédiat) **et** côté
serveur (autorité) ». Un checksum est une règle de **format**, jamais une règle médicale, tarifaire
ou d'éligibilité (CDC_01 §0.1).

Réponse au test de conformité de fin de module — *« quelles règles métier ce module calcule-t-il
côté front ? »* → **aucune**.

### 4.2 Anti-énumération

`GET /api/v1/nis/{nis}/verifier` **ne consulte jamais la base**. Il valide le format et la clé,
jamais l'existence. Un endpoint public qui confirmerait l'existence d'un NIS serait un oracle
permettant de balayer la population nationale (CDC_10 §5). Limiteur resserré : 30 requêtes/min/IP.

### 4.3 NIS exposé, matricule caché

`nis` **n'est pas** dans `$hidden` : il est destiné aux consultations, ordonnances, assurances,
CNAM et urgences (CDC_09 §3.5). `matricule_ivs` reste caché. Les deux coexistent — aucun n'est
renommé ni réutilisé.

---

## 5. Décision — garde anti-divergence TS ↔ PHP

Deux implémentations du même algorithme = risque de dérive silencieuse : le mobile accepterait un
NIS que le serveur refuse, ou l'inverse.

**Un fichier de vecteurs unique**, `packages/shared/src/nis/vecteurs.json` (généré, jamais édité à
la main), est consommé par **les deux** suites de tests :

- TypeScript : `@masante/shared` ;
- PHP : `tests/Unit/NisVecteursPartagesTest.php`.

Toute divergence casse la CI. C'est le seul mécanisme qui rende la règle « source unique »
(CDC_02 §2.2) vérifiable par la machine plutôt que par la discipline.

---

## 6. Correction apportée à ADR-001

ADR-001 donne l'exemple `CIS241200012547`. Aucune convention mod-97 standard ne produit `47` :

| Convention testée | Clé obtenue |
|---|---|
| 10 chiffres, `98 − (n mod 97)` | 33 |
| 10 chiffres, `n mod 97` | 65 |
| **Préfixe converti + 10 chiffres, `98 − (n mod 97)`** — retenue | **35** |
| IBAN strict (base + `00`) | 06 |

Les deux derniers caractères de l'exemple d'ADR-001 n'avaient jamais été calculés : c'était une
illustration, pas un vecteur.

**Exemple de référence corrigé** : `CIS241200012535`.

L'ancienne valeur est conservée dans `vecteurs.json` **comme cas invalide**, avec son motif — de
sorte qu'une régression qui la ferait accepter soit immédiatement détectée.

---

## 7. Conséquences

**Positives**
- Identifiant vérifiable hors ligne, sans appel réseau (CDC_09 §12).
- Multi-pays sans modification de code.
- Non-réutilisabilité prouvable : `nis_journal` conserve le NIS même après suppression du dossier
  (`membre_id` en `nullOnDelete`, jamais `cascade`).
- Unicité sous concurrence garantie par verrou pessimiste sur `nis_compteurs`.
- Aucune dépendance nouvelle, aucune modification de module validé G5.

**Négatives / limites assumées**
- Le titulaire obtient son NIS à la complétion du profil, pas à l'inscription — quelques secondes
  plus tard dans le parcours.
- Deux implémentations de l'algorithme à maintenir (mitigé par la garde §5).
- Le compteur est une **séquence nationale unique** : point de sérialisation. Acceptable au
  volume visé (< 100 M/an) ; un sharding par région serait la parade si nécessaire.
- La séquence est bornée à 99 999 999 dossiers par an et par pays ; le dépassement lève une
  exception explicite plutôt que de produire un identifiant invalide.

---

## 8. Alternatives écartées

| Alternative | Motif du rejet |
|---|---|
| Réutiliser `matricule_ivs` comme NIS | Conçu pour ne pas circuler, format non conforme, déjà consommé par le QR |
| UUID v7 comme NIS | Non saisissable, non dictable au téléphone, aucune vérifiabilité humaine |
| Luhn (mod 10) | Ne détecte pas toutes les transpositions ; une seule décimale de contrôle |
| NIS attribué par un service national externe | Aucun service de ce type n'existe ; à rebrancher le jour venu via le journal |
