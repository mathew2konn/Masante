# ADR-018 — Mandats récurrents + canal de notification (P5.4b/P5.4c, CDC_06 §5.4) : MIT sur vault, échéancier backend, anti double-prélèvement, préavis via Outbox

- **Statut** : **Accepté** (P5.4b mandats + P5.4c canal de notification prouvés G2/G3 ; G4 propriétaire en attente). PAIEMENT + LIVRAISON **SIMULÉS** (FT5).
- **Date** : 2026-08-09
- **Corpus** : CDC_06 §5.4 (mandats tokenisés, échéancier, notifications avant prélèvement, annulation à tout moment), §5.2 (tokenisation, jamais de PAN), §9.6 (idempotence). CDC_01/02 §0.1 (frontière : calcul backend). CDC_10 (sécurité).
- **Lié à** : [[ADR-013]] (microservice paiement), [[ADR-015]] (cartes §5, vault + NTID), P5.4a.

## Contexte

Le §5.4 exige des paiements récurrents (abonnements, suivi, télémédecine, assurances). Le vault carte de
P5.4a conservait déjà `token`, `network_transaction_id` (NTID) et `psp_customer_id` — le nécessaire à un
débit **MIT** (Merchant-Initiated, porteur absent, sans 3DS). Il restait à bâtir le mandat, l'échéancier,
les préavis, l'annulation et l'exécution MIT. Sans PSP réel (FT5), le débit est **simulé** ; le reste
(échéancier, idempotence, cycle de vie, audit, traçabilité) est réel et prouvable.

## Décision

### 1. Débit MIT via extension OCP de la passerelle carte (zéro `if psp==`)

`PasserelleCarte` gagne `debiterRecurrent(token, ntid, montant, refMandat)` → `ResultatDebitRecurrent`
**scellé** : comme le 3DS (§1.2), **le résultat est décidé par la passerelle, jamais par l'appelant**.
Les deux adaptateurs simulés l'implémentent **déterministe** (montant en `…99` → refus). Le débit réutilise
la machine carte partagée (chemin frictionless : CREEE→AUTHENTIFIEE→AUTORISEE→CAPTUREE) **sans la modifier**,
et crée un vrai `Paiement` + `CarteTransaction` (traçabilité, réconciliation via l'existant).

### 2. Échéancier = backend (frontière §0.1)

`Periodicite.prochaine(date)` (pur) calcule la prochaine échéance ; le front ne calcule rien. Le mandat porte
`prochaine_echeance` et `sequence_courante` ; chaque exécution crée l'échéance suivante puis expire le mandat
si la prochaine dépasse `date_fin`. Montant fixe = donnée du mandat.

### 3. Anti double-prélèvement en profondeur

Quatre garde-fous cumulés : verrou **Redis** par échéance (`Idempotency-Key`), **verrou pessimiste** sur
l'échéance (+ sur le mandat), **clé d'idempotence déterministe** du paiement (`mandat:<id>:<seq>` → unicité
PG de `payments.idempotency_key`), et **`UNIQUE(mandat_id, numero_sequence)`** qui rend la création de
l'échéance suivante idempotente sous concurrence. Une échéance déjà `EXECUTEE/ECHOUEE` est un no-op.

### 4. Machine à états mandat + annulation à tout moment

`ACTIF ⇄ SUSPENDU`, `ACTIF|SUSPENDU → ANNULE` (§5.4 : **annulable à tout moment**) `→ EXPIRE` (date de fin).
Machine pure `MachineEtatsMandat`. À l'exécution : mandat `SUSPENDU` → échéance laissée `PLANIFIEE` (reprise
possible) ; `ANNULE/EXPIRE` → échéance `SAUTEE` (plus jamais prélevée). Un **refus** ne tue pas l'abonnement :
l'échéance passe `ECHOUEE` et la planification avance (relance/dunning = dette).

### 5. Notifications avant prélèvement = préavis + canal Outbox (livraison SIMULÉE) — P5.4c

Le §5.4 demande des notifications avant prélèvement. On **pose un préavis** (échéance `PLANIFIEE→PREAVIS`
dans la fenêtre `preavis_jours` = donnée) **et** on émet une notification via un **Outbox** (CDC_03 §8 :
table `notifications_outbox` écrite dans la MÊME transaction que le préavis — jamais publiée avant le commit).
Un **relais** (`ServiceNotifications.envoyerEnAttente`, job + endpoint) livre les lignes `EN_ATTENTE` via un
**port OCP** `EnvoiNotification` (un canal réel = une implémentation, jamais un `if canal==`). Aujourd'hui seul
un **adaptateur SIMULÉ** existe (FT5, déterministe : destinataire en `FAIL` → échec) → `EN_ATTENTE→ENVOYEE|ECHOUEE`.
Relais **idempotent** (verrou pessimiste + garde d'état). Décision propriétaire : **Outbox + adaptateur simulé
(les deux)**, **livraison simulée**. La **livraison réelle** (SMS/push/email via un vrai fournisseur) reste
**différée** (dette, secret/dépendance à approuver). Honnête : « boucle conçue et prouvée, livraison non réelle ».

### 6. Correctif de vault multi-carte (token unique)

En construisant le multi-utilisateur des mandats, une limitation de P5.4a est apparue : l'enrôlement stockait
la **référence client** (réf. de session à usage unique) comme `token`, or `UNIQUE(psp, token)` rend alors le
vault **mono-carte pour tout le système** (2ᵉ enrôlement → conflit). Correctif chirurgical : l'enrôlement génère
un **token de vault propre et unique** par carte (en prod, celui renvoyé par le PSP). Aucune donnée sensible ;
aucun test existant ne dépendait de `token == referenceClient`.

### 7. Frontière PCI intacte

Aucune donnée de carte dans le mandat : seulement la **référence** d'une carte déjà enrôlée (`carte_id`).
Le débit MIT s'appuie sur le token + NTID du vault.

## Conséquences

- Migration **V14** (`mandats`, `mandat_echeances`). Enums `MandatStatut`/`Periodicite`/`StatutEcheance`
  **backend-only** (→ `@masante/shared` quand un écran les consommera). `ObjetPaiement` gagne `ABONNEMENT`.
- Jobs `@Scheduled` quotidiens (préavis / exécution / expiration) + endpoints manuels (`/executer-echeances`,
  `/poser-preavis`) pour l'exploitation et la preuve G2.
- **Dettes** (`services/payment/DETTE_TECHNIQUE.md`) : SIMULÉ (FT5, pas « prêt à activer » contre un PSP réel) ;
  livraison des préavis différée ; relance/dunning après refus absente ; endpoints d'exploitation non gardés
  par rôle (à protéger `ADMIN`) ; concurrence prouvée en G2 (pas de Testcontainers).
- La dette **« mandats récurrents §5.4 »** de P5.4a (ADR-015) est **levée** (le vault était le socle).
