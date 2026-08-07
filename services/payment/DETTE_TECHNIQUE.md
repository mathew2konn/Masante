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

- **Mandats récurrents (§5.4) non implémentés.** Le vault conserve `network_transaction_id` (NTID) et
  `psp_customer_id` — tout le nécessaire aux paiements initiés marchand (MIT) récurrents — mais **aucun
  débit récurrent** n'existe. *Pourquoi acceptable* : le vault est le socle ; le moteur d'abonnement/mandat
  est un incrément distinct. *Levée* : §5.4, s'appuyer sur les cartes enrôlées + NTID pour rejouer un débit
  MIT. Classé « conçu », pas « prêt à activer ».

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
