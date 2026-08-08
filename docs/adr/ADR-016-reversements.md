# ADR-016 — Reversements aux établissements (P5.5a) : assiette immuable, report chaîné, invariance hybride, calcul backend

- **Statut** : **Accepté** (P5.5a **VALIDÉ G5** ; P5.5b-1 livré : destinations chiffrées + quatre-yeux + constatation ; P5.5b-2 livré : décaissement SIMULÉ + écriture DÉCAISSEMENT + machine EN_COURS/EXECUTE/ECHOUE). Décaissement **SIMULÉ** (FT5) — aucun virement réel ; rapprochement 2 sources = S11.x/P5.5c.
- **Date** : 2026-08-07 (P5.5a) · 2026-08-07 (P5.5b-1) · 2026-08-08 (P5.5b-2)
- **Corpus** : CDC_06 §11 (Settlement : sommes dues par établissement, périodicité configurable, retenue de commission, relevés, exécution et traçabilité ; rapprochement quotidien opérateurs ↔ MASANTÉ ↔ factures ↔ reversements). §7 (facturation), §6.3 (journal immuable), §9.7 (audit chaîné), §12 (batch). CDC_01/02 §0.1 (frontière : calcul backend seul). CDC_10 (sécurité > tout).
- **Lié à** : [[ADR-013]] (microservice paiement), [[ADR-014]] (contrôle interne vs rapprochement 2 sources : S11.x), P5.2a (factures), P5.4a (remboursements carte).

## Contexte

Le §11 demande de calculer et verser à chaque établissement ce qui lui revient. Sans passerelle réelle (FT5), le **décaissement** ne peut être que simulé ; en revanche le **calcul, le relevé, la numérotation, l'idempotence, le report et l'audit** sont réels et prouvables. P5.5 est donc découpé : **P5.5a** (calcul + relevé immuable, ce document), **P5.5b** (exécution + grand livre + destination), **P5.5c** (bras de rapprochement « factures ↔ reversements » de S11.x — cf. ADR-014).

Trois pièges ont été identifiés à la revue et tranchés ci-dessous.

## Décision

### 1. Assiette temporelle = `factures.soldee_a` immuable (jamais `updated_at`)

`updated_at` est remué par toute écriture ultérieure (4 services écrivent `factures`) : une facture soldée en janvier puis retouchée en mars migrerait de fenêtre → perte ou double paiement. On introduit **`soldee_a`**, posée **une seule fois** au passage à `PAYEE`, **immuable**. C'est la **seule** assiette temporelle admise. L'assiette d'un relevé = factures `PAYEE`, `montantRegle > 0`, **`soldee_a < fin`** (borne haute **seule** — pas de borne basse) et **non déjà imputées** (I1). L'absence de borne basse rend le **rattrapage automatique** : une pièce arrivée tardivement est reprise au relevé suivant. Un remboursement s'impute par sa date **`cree_le`** (immuable ; il naît `REUSSI`), l'établissement étant **dénormalisé** sur `carte_remboursements` (fin du rattachement à 3 sauts sur données mutables).

### 2. Report de solde négatif **chaîné** — et annulation qui respecte la chaîne

Si remboursements + report dépassent l'encaissement net, on ne décaisse rien : `net = 0` et la dette bascule en `solde_reporte ≤ 0`, reprise au relevé suivant comme `report_anterieur`. Cette dépendance **inter-relevés** est le point sensible : elle est matérialisée par **`releve_precedent_id`** (chaînage explicite — fin de la « requête par date » ambiguë : `periode_fin` vs `calcule_a` divergent dès le premier rattrapage). Deux garde-fous : **≤1 successeur vivant** par relevé (`uq_releve_successeur_vivant`) et **règle d'annulation** — un relevé n'est annulable que s'il n'a **aucun successeur actif** (seul le dernier maillon vivant). Recalculer janvier impose donc d'annuler d'abord février. Le report antérieur d'un nouveau relevé est le `solde_reporte` de la **queue** de la chaîne vivante, jamais une agrégation par date.

### 3. Invariance **hybride ciblée** — métier en Java, garde-fous déclaratifs en base

V1→V9 ne portent **aucune** logique en base (tout en Java, tests unitaires purs). V10 respecte ce style : le **métier** (équation, report, commission, machine à états, hash) vit en Java (`ReglesReversement` pur, `ServiceReversement`), la base ne porte que des **garde-fous déclaratifs** (CHECK, UNIQUE, FK, index partiels). **Un seul trigger plpgsql** : `factures.soldee_a` — justifié parce que **4 services** écrivent `factures` (un stamp centralisé évite 4 bugs). L'immuabilité des relevés/lignes (I5) = **absence de mutateur Java + REVOKE/GRANT déclaratifs** (moindre privilège, « prêt à activer » en prod sous rôle non-propriétaire), pas de trigger, pas de double autorité.

### 4. Ordre de verrou explicite

`calculerReleve` : `INSERT compteur ON CONFLICT DO NOTHING → SELECT … FOR UPDATE → lecture de l'assiette → calcul → écriture`. Le verrou pris **avant** l'assiette sérialise calcul + numérotation + chaînage de report par établissement et évite qu'une exécution concurrente ne rejette l'autre par violation brute de `uq_ligne_*` (I1).

### 5. Commission = donnée, **jamais** de taux d'amorçage

Taux historisé (`reversement_commission_config`, bps entiers, `etablissement_ref` NULL = défaut plateforme), append-only, non-chevauchant (index unique partiel « un seul taux ouvert par périmètre » + clôture-puis-ouverture en Java). **Aucun seed** : `commission_config_id` étant `NOT NULL`, l'absence de config fait **échouer le calcul bruyamment** (panne sûre) — jamais un 0 % silencieux qui surpaierait l'établissement. Commission calculée **par ligne** (division entière = arrondi plancher, en faveur de l'établissement), totaux d'en-tête = **somme des lignes** → I3 (`Σ lignes = en-tête`) exact. Le changement de taux, action la plus sensible du lot, est réservé à **`ADMIN_FINANCE`** (en-tête `X-Acteur-Role` posé par la passerelle) et **audité nominativement** ; le quatre-yeux est différé en P5.5b (rien n'étant décaissé en P5.5a).

### 6. Audit : empreinte de ligne + inaltérabilité de série par la chaîne globale

Le relevé porte `hash_integrite` (empreinte de ligne, calculée en Java, pour vérification/QR). L'inaltérabilité de **série** (disparition d'un relevé entier) est couverte par le **journal global chaîné `audit_entries`** (§9.7 : `previous_hash → hash`, séquence monotone, ancre GENESIS) où chaque acte (`SettlementCalculated/Approved/Cancelled`, `SettlementCommissionRateSet`) est consigné. Les deux ensemble satisfont l'exigence d'inaltérabilité (loi 2013-450) sans dupliquer de chaîne par établissement.

### 7. Décaissement, grand livre, destination, quatre-yeux — P5.5b (V11)

Découpé en deux sous-incréments après revue :

**P5.5b-1 (livré, V11)** — registre de destinations **chiffré** (AES-256-GCM : nonce stocké, **AAD** liant
`etablissement_ref`+`id`, `cle_version`), **empreinte HMAC poivrée** versionnée (comparaison sans
déchiffrement, non brute-forçable — un SHA-256 nu sur un espace MSISDN ~10⁸ serait un oracle) ; **quatre-yeux**
(approbateur ≠ calculateur [CHECK] **et** ≠ créateur de la destination [Java] ; **anti-substitution** : la
destination active doit être identique à celle figée au calcul, `destination_empreinte_calcul`) ; **écriture
de CONSTATATION** en partie double (grand livre **en-tête + lignes**, Σdébit=Σcrédit **par contributions
signées** — aucun `abs()` ; `ck_rev_equation` garantit l'équilibre côté SGBD). **Contre-passation** =
écriture `EXTOURNE` inverse (append-only, jamais d'UPDATE) ; état **`REJETE`** distinct d'`ANNULE`.
**Autorisation** : les actes sensibles exigent un **principal signé vérifié en service** (`X-Principal` +
HMAC lié à méthode+chemin, anti-rejeu par nonce à usage unique) — corrige la faille « rôle déclaré par le
client » (P5.3b-1) : plus d'`X-Acteur-Role`. Convention de signe **relâchée** (V11 supprime `ck_rev_signes`) :
`report`/`solde` libres de signe → le **report positif** (dû sous seuil de versement) devient représentable
pour b-2 ; l'équilibre reste garanti par `ck_rev_equation`.

**P5.5b-2 (livré, V12)** — versement effectif d'un relevé approuvé, **SIMULÉ** (FT5, aucun virement réel).
Adaptateur OCP `PasserelleReversement` (dispatch par `TypeDestination` via `RegistrePasserellesReversement`,
**zéro `if type==`** ; le résultat est décidé par la passerelle, **jamais par l'appelant** — miroir du 3DS
P5.4a). Machine `APPROUVE|ECHOUE → EN_COURS → EXECUTE|ECHOUE` sur le relevé ; **écriture de DÉCAISSEMENT**
en partie double postée **uniquement au succès** (contributions signées : `A_REVERSER` +net au débit,
`FRAIS_PASSERELLE` +frais au débit, `TRESORERIE` −(net+frais) au crédit ; Σ=0). **La plateforme porte les
frais** (l'établissement reçoit le `net` intégral) ; `frais` = **donnée** rapportée par la passerelle,
jamais codée (config simulée `frais-simules-bps`, défaut 0). **Anti-double-versement en profondeur** :
(1) verrou d'idempotence Redis + `Idempotency-Key`, (2) verrou pessimiste sur la ligne relevé + garde
d'état, (3) unicités SGBD (`uq_ecr_decaissement_par_releve`, `uq_decaissement_reussi_par_releve`,
`uq_decaissement_en_cours_par_releve`, `uq_decaissement_idempotency`). **Contrôle « destination révoquée
depuis le figeage »** : la destination active doit être identique (id + empreinte) à celle figée à
l'approbation, sinon refus → re-approbation. Le **déchiffrement** de la destination n'existe que dans ce
chemin de versement (promesse b-1 tenue). **Séparation des tâches** : le décaisseur ≠ l'approbateur
(six-yeux avec le calculateur). `ECHOUE` rejouable (nouvelle clé ; rien n'est parti) ou annulable (extourne
de la constatation) ; `EXECUTE` terminal. **Registre local `reversement_decaissement`** = bras LOCAL du
futur rapprochement 2 sources (S11.x) : la réf destination en clair n'y figure jamais, seule la réf
passerelle. Autorisation : principal signé `ADMIN_FINANCE` (comme les autres actes sensibles).

Le vrai rapprochement « factures ↔ reversements » = **P5.5c** (extension de `TypeControle`/`TypeEcart`, cf.
ADR-014) ; le bras externe « opérateurs ↔ MASANTÉ » reste différé (aucun relevé opérateur réel).

**Décisions assumées** : une **seule destination active** par établissement (pas de split banque+MoMo) ;
`etablissement_ref` **référence molle** (établissements hors base paiement, ADR-013) → réconciliation des
orphelins en G2 ; clés/secret « prêts à activer » (KMS), prod refuse de démarrer sans eux.

## Conséquences

- **+** §11 livré côté **calcul/relevé/report/traçabilité**, avec frontière respectée (tout le jugement dans `ReglesReversement`, prouvé G3 pur) et double paiement structurellement impossible (I1), rattrapage automatique.
- **+** Cohérence d'architecture préservée (Java-first + garde-fous déclaratifs), testabilité G3 intacte.
- **−** Décaissement, grand livre, destination, quatre-yeux, rapprochement 2 sources restent à faire (P5.5b/c). Assumé et tracé (`DETTE_TECHNIQUE.md`, DT-REV-01/02).
- **Dette / promotion** : enums `ReversementStatut`/`TypeLigneReversement` backend-only, promus dans `@masante/shared` quand un client web les consommera.
