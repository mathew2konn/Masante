# Dette technique — services/payment

Registre des dettes assumées (décidées explicitement, à traiter plus tard). Chaque entrée : quoi,
pourquoi c'est acceptable maintenant, condition de levée.

## P5.3b-3 — Cashback / Bonus

- **Crédit du cashback gaté OFF** (`masante.payment.wallet.cashback.credit-enabled=false`).
  Le moteur (campagnes, calcul, plafonds, budget, clawback) est livré et testé, mais le **crédit**
  automatique est désactivé. *Pourquoi* : fermer la boucle d'abus (payer→cashback→annuler) exige un
  **chemin de remboursement d'op wallet** qui appelle le clawback dans la même transaction — ce chemin
  relève des **reversements (§11)** et n'existe pas encore. *Levée* : à l'implémentation de §11, activer
  le flag ET brancher `ServiceRecompense.annulerCashback(remboursementId, …)` dans la transaction de
  remboursement. Sans ce branchement, ne PAS activer le crédit.

- **#9 — pas de contrainte `CHECK` sur `wallet_operations.type`.** Les valeurs sont bornées par l'enum
  `TypeOperationWallet` côté application, seule voie d'écriture. *Levée* : ajouter un `CHECK (type IN (…))`
  si une écriture hors application devient possible.

- **#6 — verrou pessimiste inconditionnel sur la campagne.** Point de sérialisation global pour une
  campagne populaire. *Pourquoi acceptable* : correct avant tout ; l'optimisation « ne verrouiller que si
  budget/plafonds posés » est prématurée sans débit mesuré. *Note corrigée* : le gate ne peut PAS se faire
  sur `budget_total` seul — `plafond_par_wallet_par_jour` exige la même sérialisation même à budget
  illimité. Skippable uniquement si **aucun** budget/plafond n'est posé.

- **Plafond journalier & fuseau.** La fenêtre du jour est keyée sur la **date UTC de l'op source**.
  La Côte d'Ivoire étant à **UTC+0**, cela coïncide avec le jour local — **coïncidence heureuse, pas une
  règle générale** : un déploiement multi-pays devra choisir le fuseau de référence explicitement.

- **Index unique partiel** `UNIQUE(type_operation_source) WHERE actif` : interdit deux campagnes actives
  sur le même type → **impossible de préparer la campagne suivante pendant que l'actuelle tourne**
  (procédure de bascule : désactiver puis créer, avec un court trou sans campagne). Assumé.

## P5.4a — Cartes bancaires (§5)

- **Mandats récurrents (§5.4) — LEVÉ (P5.4b, 2026-08-09, V14).** Le débit MIT s'appuie sur les cartes
  enrôlées + NTID (ADR-018). Voir la section P5.4b ci-dessous pour les dettes résiduelles (simulation, préavis).

- **Tout est SIMULÉ (FT5).** Deux adaptateurs `sim_tokenise` / `sim_redirige` déterministes ; secret HMAC
  de webhook = constante de **dév** (`dev-hmac-<psp>`). *Levée* : par PSP réel = nouvel adaptateur +
  secret réel (config/HSM) + `parserEvenement` normalisant le **vrai** format de webhook. Aucune de ces
  branches n'a été testée contre un PSP réel → **ne pas** la déclarer « prête à activer ».

- **Expiration d'autorisation = job dormant.** La capture étant **synchrone** (auto-capture après
  autorisation), aucune transaction ne stationne en `AUTORISEE` ; le job `expirerAutorisationsEchues` ne
  trouve donc jamais rien. *Pourquoi acceptable* : structurellement correct, prêt pour la **capture
  différée** future. *Levée* : à l'introduction de la capture différée (autoriser maintenant / capturer
  plus tard), poser `autorisation_expire_le` à l'autorisation.

- **Concurrence & dédup prouvées en G2, pas en G3.** Le verrou pessimiste (2×finalize → 1 capture), la
  déduplication webhook `UNIQUE(psp, evenement_id)` et l'idempotence Redis+PG **ne peuvent pas** être
  prouvés par les tests unitaires (le build tourne sans base). Ils le sont par les vecteurs G2 live
  (Partie I du guide). *Levée* : introduire des tests d'intégration Testcontainers (dépendance nouvelle →
  accord propriétaire requis, §2.6).

- **`StatutCarte` / `ActionClient` backend-only.** À **promouvoir dans `@masante/shared`** le jour où un
  écran web/mobile carte les consomme (aujourd'hui aucun consommateur → coût de rattrapage faible). Même
  logique qu'ADR-014.

## P5.5a — Reversements (§11)

- **DT-REV-01 — Backfill `factures.soldee_a` approximatif.** La colonne `soldee_a` (assiette temporelle
  immuable) est reprise pour l'historique depuis `updated_at` (`UPDATE … WHERE statut='PAYEE'`). Or
  `updated_at` a pu être remué après le solde → la date de solde des factures **déjà PAYEE avant V10**
  est **approximative**. *Pourquoi acceptable* : les factures futures sont horodatées exactement par le
  trigger ; l'écart ne concerne que l'historique pré-V10. *Levée* : si un reversement rétroactif sur des
  factures pré-V10 est requis, reconstituer `soldee_a` depuis le journal d'audit (`audit_entries`,
  évènement `InvoicePaymentApplied`) plutôt que `updated_at`.

- **DT-REV-02 — Backfill `carte_remboursements.etablissement_ref` par jointure.** L'établissement est
  désormais figé à la création ; l'historique est reconstitué via `carte_transactions → payments`. Correct
  au moment du backfill, mais dépend de l'état courant de ces tables. *Levée* : aucune action requise tant
  que le rattachement historique reste stable ; les remboursements futurs figent la valeur à la source.

- **Date d'imputation d'un remboursement = `cree_le`.** Valable car, dans le flux SIMULÉ synchrone actuel,
  un remboursement **naît `REUSSI`** (ServiceCarte). *Levée* : si un cycle asynchrone `DEMANDE→REUSSI` est
  introduit, ajouter un stamp immuable `rembourse_a` (posé au passage REUSSI, write-path unique → Java, pas
  de trigger) et l'utiliser comme date d'imputation à la place de `cree_le`.

- **Décaissement réel absent (SIMULÉ, FT5).** P5.5a calcule et fige le relevé (sommes dues, commission,
  net, report) ; il **ne décaisse rien**. L'exécution en partie double, l'adaptateur de décaissement OCP,
  la destination de paiement chiffrée et le quatre-yeux à l'approbation = **P5.5b (V11)**. Classé
  « conçu », pas « prêt à activer ».

- **`ReversementStatut` / `TypeLigneReversement` backend-only.** À **promouvoir dans `@masante/shared`**
  quand l'écran d'administration web les consommera (aucun consommateur aujourd'hui). Même logique
  qu'ADR-014/015.

- **Immuabilité I5 par Java + REVOKE, sans effet sous le rôle propriétaire.** Les REVOKE/GRANT de la
  section 7 de V10 ne mordent qu'avec un rôle applicatif à moindre privilège ; en dev (rôle `payment` =
  propriétaire de la base), l'immuabilité repose sur l'absence de mutateur Java. *Levée* : activer un rôle
  applicatif non-propriétaire en prod et décommenter les REVOKE/GRANT.

- **Concurrence prouvée en G2, pas en G3.** L'ordre de verrou (compteur `FOR UPDATE` avant lecture
  d'assiette), l'unicité de période active et l'unicité de successeur vivant se prouvent en **live**
  (script `V10_verification_invariants.sql` + vecteurs Postman), pas par les tests unitaires purs (build
  sans base). *Levée* : tests d'intégration Testcontainers (dépendance nouvelle → accord propriétaire).

## P5.5b-1 — Reversements : destinations chiffrées, quatre-yeux, constatation

- **Chiffrement/poivre/secret principal « prêts à activer », clés de DÉV en mémoire.** `MASANTE_PAYMENT_DEST_KEY`
  (AES-256), `MASANTE_PAYMENT_DEST_PEPPER` (HMAC empreinte), `MASANTE_PAYMENT_PRINCIPAL_SECRET` (principal
  signé). En profil **durci** (`masante.payment.securite.exiger-cles=true`, prod), leur absence **empêche
  le démarrage**. En dév, matériel éphémère généré + `log.warn`. *Levée* : adosser à un KMS/HSM et activer
  le profil durci en prod. `cle_version`/`empreinte_version` posées → rotation possible sans migration.

- **Principal signé HMAC (pas encore JWT Keycloak).** L'identité + les rôles des actes sensibles viennent
  d'un principal **signé et vérifié en service** (anti « rôle déclaré par le client », P5.3b-1), lié à la
  requête (méthode+chemin) et anti-rejeu (nonce à usage unique Redis). *Levée* : remplacer par un JWT
  Keycloak RS256 quand l'IAM national est branché. **Les endpoints X-Acteur-Id existants** (bonus, cashback,
  run/cancel reversement) restent sur l'en-tête posé par la passerelle : dette d'unification vers le principal
  signé, non refactorée maintenant (périmètre maîtrisé).

- **REVOKE/GRANT sans effet sous le rôle propriétaire.** L'append-only des tables `reversement_destination`
  / `reversement_ecriture` / `reversement_grand_livre_ligne` repose en dév sur l'absence de mutateur Java ;
  les REVOKE (V11 §5) ne mordent qu'en prod sous un rôle applicatif ≠ propriétaire. *Levée* : rôle applicatif
  à moindre privilège + décommenter les REVOKE ; **à vérifier réellement en G2**.

- **Équilibre du grand livre garanti en Java, pas par trigger.** `ReglesEcritureReversement` garantit
  Σdébit=Σcrédit par construction (contributions signées) ; le **balayage global** (`V11_verification`) et la
  **balance de vérification** (Σ A_REVERSER = Σ net approuvé) le prouvent en G2. Pas de contrainte SGBD
  inter-lignes (choix hybride ciblé). *Levée* : ajouter un contrôle d'intégrité périodique (S11.x) sur le
  grand livre reversement.

- **Une seule destination active par établissement.** Pas de split banque + Mobile Money simultané. Décision
  assumée (ADR-016). *Levée* : autoriser une active par (établissement, type) + choix à l'approbation.

- **Décaissement toujours absent (→ P5.5b-2).** La constatation reconnaît la dette ; le versement effectif
  (adaptateur OCP `PasserelleReversement` simulé, écriture de DÉCAISSEMENT, machine `EN_COURS/EXECUTE/ECHOUE`,
  idempotence anti-double-versement, frais `FRAIS_PASSERELLE`, contrôle « destination révoquée depuis le
  figeage ») est P5.5b-2. `FRAIS_PASSERELLE` est un compte **réservé**, non alimenté en b-1.

## P5.5b-2 — Reversements : décaissement (SIMULÉ)

- **Tout le versement est SIMULÉ (FT5).** Un seul adaptateur `AdaptateurReversementSimule` (déterministe :
  compte destination se terminant par `99` → ÉCHOUE, sinon EXÉCUTÉ) couvre `MOBILE_MONEY` et
  `VIREMENT_BANCAIRE`. Il ne verse RIEN. *Levée* : par opérateur réel = nouvel adaptateur (déclaré via
  `supporte(type)`, choisi en priorité par `RegistrePasserellesReversement` — OCP, aucun `if type==`) +
  secret/API réels + normalisation du vrai format d'accusé. **Aucune** de ces branches n'a été testée contre
  un opérateur réel → **ne pas** déclarer « prête à activer ».

- **Cycle SYNCHRONE ; l'ambigu réel « le virement est-il parti ? » non couvert.** Le versement pose
  `EN_COURS` puis applique l'issue terminale de la passerelle dans la même transaction (sim synchrone).
  `PasserelleReversement.statut(refPasserelle)` existe (vérité passerelle) mais **aucun endpoint de reprise
  d'un `EN_COURS` bloqué** n'est branché (dormant, comme le job d'expiration carte de P5.4a). *Levée* : à
  l'introduction d'un versement asynchrone (accusé différé/webhook opérateur), committer `EN_COURS` puis
  résoudre via `statut()`/webhook ; ajouter la reprise et un TTL de réconciliation.

- **Rapprochement 2 sources = S11.x / P5.5c, PAS livré ici.** Le registre `reversement_decaissement` est le
  **bras LOCAL** (une source) ; la confrontation registre local ⇄ vérité opérateur (2 sources, comme
  `carte_reconciliation` de P5.4a) reste à faire. Classé « bras local conçu », pas « rapprochement prêt ».

- **Frais passerelle = donnée simulée à 0 par défaut.** `masante.payment.reversement.frais-simules-bps`
  (défaut `0`) → écriture de DÉCAISSEMENT à 2 jambes ; une valeur >0 exerce la 3ᵉ jambe `FRAIS_PASSERELLE`
  (charge plateforme). La **plateforme porte les frais** (l'établissement reçoit le net intégral) — décision
  ADR-016 §7. *Levée* : brancher le frais réel rapporté par l'opérateur.

- **`DecaissementStatut` backend-only.** À promouvoir dans `@masante/shared` le jour où un écran le consomme
  (aucun consommateur aujourd'hui). Même logique qu'ADR-014/015.

- **Concurrence & idempotence prouvées en G2, pas en G3.** Le verrou pessimiste (2×disburse → 1 EXECUTE, 1
  écriture), les index partiels (`uq_decaissement_reussi/en_cours`, `uq_ecr_decaissement_par_releve`,
  `uq_decaissement_idempotency`) et le verrou Redis se prouvent en **live** (`V12_verification_invariants.sql`
  + vecteurs, Partie L du guide), pas par les tests unitaires purs (build sans base). *Levée* : tests
  d'intégration Testcontainers (dépendance nouvelle → accord propriétaire, §2.6).

## P5.5c — Rapprochement 2 sources « factures ↔ reversements »

- **Bras EXTERNE « opérateurs ↔ MASANTÉ » toujours différé (FT5).** P5.5c livre la confrontation des deux
  sources INTERNES (facturation ⇄ reversements). La confrontation à une **vérité opérateur** (relevé de
  settlement Wave/Orange/MTN) reste hors périmètre : aucun relevé réel n'existe (passerelle SIMULÉE). Le
  format pivot est figé dans ADR-014 §2. *Levée* : accès sandbox opérateur → adaptateur d'import normalisant
  le relevé vers le pivot, puis nouveaux `TypeEcartRapprochement` (MANQUANT_COTE_OPERATEUR, DECALAGE_DATE).
  Classé « conçu », **pas « prêt à activer »** : aucune branche opérateur testée.

- **Bras « décaissement local ⇄ vérité passerelle » différé.** Le registre `reversement_decaissement`
  (P5.5b-2) est le bras LOCAL ; sa confrontation à la passerelle (comme `carte_reconciliation`) n'est pas
  faite (versement SIMULÉ). *Levée* : au versement réel, réconcilier le registre ⇄ accusés opérateur.

- **`DOUBLON` non re-vérifié — garanti par I1.** Une facture/remboursement imputé deux fois sur des relevés
  actifs est **structurellement impossible** (index partiels `uq_ligne_facture_imputee_une_fois` /
  `uq_ligne_remboursement_impute_une_fois`). On n'ajoute pas un contrôle qui serait toujours vert (même
  logique que « un run vert sur du vide ne prouve rien »). *Levée* : aucune tant que I1 tient.

- **`TypeEcartRapprochement` backend-only.** À promouvoir dans `@masante/shared` quand un écran
  d'administration le consommera (aucun consommateur aujourd'hui). Même logique qu'ADR-014/015.

- **Détection prouvée en G3 (règles pures) ; balayage 2 sources & idempotence en G2.** `ReglesRapprochement`
  est testé unitairement (chaque écart + sain). Le balayage SQL des deux sources, l'idempotence
  (`UNIQUE(date_rapport)`) et le seedeur d'anomalies se prouvent **live** (Partie M du guide), pas au build
  (sans base). *Levée* : tests d'intégration Testcontainers (dépendance nouvelle → accord propriétaire).

## P5.4b — Mandats de paiement récurrents (§5.4)

- **Débit MIT SIMULÉ (FT5).** `debiterRecurrent` sur les deux adaptateurs simulés (montant en `…99` → refus) ;
  ne verse RIEN. *Levée* : par PSP réel = implémenter `debiterRecurrent` avec l'API MIT réelle (token + NTID)
  + secret/creds réels. **Aucune** branche testée contre un PSP réel → **pas « prête à activer »** (ADR-018).

- **Notifications avant prélèvement : canal Outbox livré (P5.4c), LIVRAISON SIMULÉE.** Le préavis pose
  une ligne `notifications_outbox` (Outbox, committée avec le préavis) ; un relais la livre via le port OCP
  `EnvoiNotification`. **Seul un adaptateur SIMULÉ existe** (déterministe, destinataire `…FAIL` → échec) :
  aucun SMS/push/email réel n'est envoyé. *Levée* : adaptateur réel (fournisseur SMS/push + secret/API →
  accord §2.6), jamais testé ici → **pas « prêt à activer »**. La **relance/dunning** après échec reste due.

- **Pas de relance / dunning après refus.** Une échéance `ECHOUEE` n'est pas rejouée ; la planification avance
  simplement à la période suivante (l'abonnement continue). *Levée* : politique de relance (retenter J+n,
  suspendre après N échecs, notifier).

- **Endpoints d'exploitation non gardés par rôle.** `/mandats/executer-echeances` et `/mandats/poser-preavis`
  déclenchent les jobs manuellement (preuve G2, exploitation) sans garde d'autorisation. *Levée* : réserver à
  `ADMIN`/tâche interne (principal signé ou garde de rôle), comme les actes sensibles reversement.

- **`MandatStatut` / `Periodicite` / `StatutEcheance` backend-only.** À promouvoir dans `@masante/shared` quand
  un écran (mobile « mes abonnements » / admin) les consommera. Même logique qu'ADR-014/015/016/017.

- **Concurrence & idempotence prouvées en G2, pas en G3.** Les 4 garde-fous anti double-prélèvement (verrou
  Redis, verrou pessimiste, clé `mandat:<id>:<seq>`, `UNIQUE(mandat_id, numero_sequence)`) se prouvent **live**
  (Partie N du guide), pas au build (tests unitaires purs sans base). *Levée* : Testcontainers (dép nouvelle →
  accord propriétaire, §2.6).

- **Cycle synchrone.** Le débit MIT pose l'issue terminale dans la même transaction (sim synchrone). Un accusé
  différé/asynchrone (webhook opérateur) n'est pas géré. *Levée* : à l'introduction d'un MIT asynchrone,
  committer `EN_COURS` puis résoudre via statut/webhook (comme prévu pour la carte P5.4a).
