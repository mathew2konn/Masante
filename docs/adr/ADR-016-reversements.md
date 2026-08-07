# ADR-016 — Reversements aux établissements (P5.5a) : assiette immuable, report chaîné, invariance hybride, calcul backend

- **Statut** : **Accepté** (P5.5a livré, prouvé G3 ; G2 live à confirmer). Décaissement **SIMULÉ / différé** (P5.5b) — aucun virement réel.
- **Date** : 2026-08-07
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

### 7. Hors périmètre P5.5a (→ P5.5b / V11), classé honnêtement

Grand livre en partie double (écritures **au décaissement**, plan de comptes incluant la trésorerie), destination de paiement chiffrée + empreinte figées à l'approbation, quatre-yeux, adaptateur de décaissement OCP simulé. Non posés en V10 : **on ne pose pas un grand livre incomplet, on ne le pose pas du tout**. Le vrai rapprochement « factures ↔ reversements » = **P5.5c** (extension de `TypeControle`/`TypeEcart`, cf. ADR-014) ; le bras externe « opérateurs ↔ MASANTÉ » reste différé (aucun relevé opérateur réel).

## Conséquences

- **+** §11 livré côté **calcul/relevé/report/traçabilité**, avec frontière respectée (tout le jugement dans `ReglesReversement`, prouvé G3 pur) et double paiement structurellement impossible (I1), rattrapage automatique.
- **+** Cohérence d'architecture préservée (Java-first + garde-fous déclaratifs), testabilité G3 intacte.
- **−** Décaissement, grand livre, destination, quatre-yeux, rapprochement 2 sources restent à faire (P5.5b/c). Assumé et tracé (`DETTE_TECHNIQUE.md`, DT-REV-01/02).
- **Dette / promotion** : enums `ReversementStatut`/`TypeLigneReversement` backend-only, promus dans `@masante/shared` quand un client web les consommera.
