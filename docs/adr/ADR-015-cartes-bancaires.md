# ADR-015 — Cartes bancaires (P5.4a) : double modalité de passerelle, frontière PCI, webhook source de vérité, réconciliation à deux sources

- **Statut** : **Accepté** (livré, prouvé G2/G3 le 2026-08-07). Paiement **SIMULÉ** (FT5) — aucune passerelle réelle branchée.
- **Date** : 2026-08-07
- **Corpus** : CDC_06 §5 (cartes bancaires : 3DS2, tokenisation, capture, remboursement, vault §5.4), §7.3 (webhooks signés), §6.3 (rapprochement quotidien), §9 (idempotence, sécurité). CDC_10 (sécurité > tout).
- **Lié à** : [[ADR-013]] (microservice paiement isolé), [[ADR-014]] (contrôle interne vs rapprochement 2 sources), P5.1 (passerelle OCP + machine à états + idempotence + audit).

## Contexte

Le CDC_06 §5 demande le paiement par carte. Une carte n'est pas un canal Mobile Money de plus : elle porte une **contrainte réglementaire dure (PCI-DSS)** — le numéro (PAN) et le cryptogramme (CVV) ne doivent **jamais** transiter ni être stockés par nos services — et un **protocole d'authentification** (3DS2) dont le résultat fait autorité côté banque, pas côté client. Deux familles de PSP coexistent sur le marché ivoirien/africain : les **tokenisés** (type Stripe/Adyen : SDK client → token, défi 3DS in-app) et les **redirigés** (type CinetPay/PayDunya/Paystack/Flutterwave : page hébergée, retour par webhook).

## Décision

### 1. Frontière PCI inviolable — jamais de PAN/CVV côté service

Aucune colonne, aucun DTO, aucun log ne contient de PAN ni de CVV. Le service ne manipule que des **tokens** et des **métadonnées non sensibles** fournies **par** la passerelle (marque, 4 derniers chiffres, expiration, NTID). Défense en profondeur : un **filtre d'entrée** (`FiltreAntiPan`) inspecte le corps de toute écriture carte et **rejette en 422** toute suite de 13-19 chiffres satisfaisant la clé de Luhn — **sans jamais journaliser le corps** (interdit #7). Faux positif assumé (aucune donnée métier légitime n'est un entier Luhn de 13-19 chiffres). Prouvé G2 : PAN posté → 422, `grep` des logs → 0 occurrence.

### 2. Double modalité via une passerelle OCP — zéro `if psp ==`

Un contrat unique `PasserelleCarte` (`initier / recupererStatut / capturer / rembourser / verifierSignature / parserEvenement`) et un registre par PSP (`RegistrePasserellesCarte`). Ajouter un PSP = ajouter un bean, jamais un `if`. Deux adaptateurs **simulés déterministes** : `sim_tokenise` (frictionless / défi 3DS) et `sim_redirige` (redirection + webhook). L'issue d'initiation est une **interface scellée** (`Frictionless / DefiRequis / RedirectionRequise / Refusee`) — le domaine ne connaît que ces quatre cas.

### 3. Le résultat 3DS/autorisation n'est JAMAIS déclaré par le client (§1.2)

La vérité vient soit de `recupererStatut` (pull autoritatif), soit d'un **webhook signé** (push). `finaliser` et le webhook partagent **le même code** d'application (`appliquerIssueAutoritative`) sous **verrou pessimiste** (`SELECT … FOR UPDATE`) : deux finalisations concurrentes, ou finalisation ⇄ webhook, ne produisent **qu'une seule capture**. Prouvé G2 (2× finalize parallèles → `montant_capture` unique, 1 transition SUCCESS).

### 4. Webhook = source de vérité, avec un ordre de contrôles strict (§7.3)

`POST /api/v1/card-webhooks/{psp}` reçoit le **corps brut** (octets exacts). Ordre : **(1)** vérification **HMAC-SHA256** sur les octets bruts **avant toute désérialisation** (comparaison en temps constant) → **(2)** parsing normalisé → **(3)** **fraîcheur** (horodatage **dans le corps signé**, donc lié par le HMAC ; fenêtre ±5 min) → **(4)** **anti-rejeu Redis** (nonce posé **après** commit → un rollback ne brûle pas un événement légitime) → **(5)** application sous verrou + **déduplication base** `UNIQUE(psp, evenement_id)` (autorité ultime ; doublon concurrent → 200 idempotent). Tout échec des contrôles 1-3 → **401 GÉNÉRIQUE** (anti-fuite : on ne révèle jamais lequel a échoué). Ce chemin est **exclu** du `FiltreAntiPan` (un corps signé ne se met pas en cache et ne se scanne pas ; sa vérité est la signature). Prouvé G2 : signé→SUCCESS, rejeu→200, altéré→401, périmé→401.

### 5. Machine à états carte **projetée** sur la machine générique — jamais l'inverse (interdit #1)

Un sous-état `StatutCarte` (backend-only, **jamais exposé** au front, interdit #8) piloté par une machine **pure**, puis **projeté** sur le `PaiementStatut` générique via un mapping total. Point clé : `REMBOURSEE_PARTIELLE → SUCCESS` (le remboursement partiel ne bouge pas le statut générique) → la machine partagée `MachineEtatsPaiement` n'est **jamais** modifiée. Le front ne reçoit que `PaiementStatut` + une `ActionClient` (`AUCUNE / DEFI_3DS / REDIRECTION / REFUSEE`).

### 6. Argent : entiers en unité mineure + devise ; type confiné au domaine carte

Un type valeur `Montant(long, Devise)` **local au domaine carte** (le cœur existant utilise `long + String devise`, non touché). L'exposant est porté par la devise ; **le XOF n'a pas de sous-unité** (aucun ×100). Le contrôle « remboursement cumulé ≤ capturé » est **backend** (frontière), 422 sinon — jamais le client. Remboursement **toujours vers la carte d'origine** (interdit #10).

### 7. Réconciliation quotidienne = **vraie** confrontation à deux sources (distincte d'ADR-014)

Contrairement à l'auditeur d'intégrité **interne** de P5.3b-4 (une seule source : notre base — cf. [[ADR-014]]), la réconciliation carte confronte **deux sources indépendantes** : le **registre local** (`carte_transactions`) et la **vérité PSP** (`recupererStatut`). Ici la source PSP est **simulée** (adaptateur déterministe) mais réellement séparée. **Détection seule** (§11), idempotente par `UNIQUE(date_rapport, psp)`. Un écart n'est signalé que pour une **divergence financière réelle** (un côté « argent bougé », l'autre non) — les cas « en vol » et l'abandon expiré unilatéralement sont tolérés (sinon faux positifs). Prouvé G2 : sain→0, anomalie injectée (`CAPTUREE`↔`REFUSE`)→1 écart daté, idempotent.

### 8. Vault « prêt pour §5.4 » (mandats récurrents)

Les cartes enrôlées conservent `network_transaction_id` (NTID) et `psp_customer_id` — nécessaires aux paiements initiés marchand (MIT) récurrents du §5.4 — mais **aucun débit récurrent n'est implémenté** ici. Classé « conçu », pas « prêt à activer ».

### 9. Trois jobs planifiés (horaires = données)

Expiration des défis (~1 min : `EN_ATTENTE_CLIENT` échu → `EXPIREE`), expiration des autorisations (quotidien ; **dormant** tant que la capture est synchrone — assumé), réconciliation quotidienne (la veille). Réutilise l'infra `@Scheduled` de P5.3b-4.

## Conséquences

- **+** §5 livré et prouvé (G3 build vert + 18 tests neufs ; G2 live 11 vecteurs), sans une ligne de PAN, sans modifier la machine générique ni le cœur Laravel.
- **+** Sécurité par construction : PCI en bord d'entrée, webhook signé + anti-rejeu + dédup, verrou anti-double-capture, anti-fuite 401/422 génériques.
- **−** Tout est **simulé** (FT5). Le branchement d'un PSP réel = nouvel adaptateur + secret HMAC réel (config/HSM, « prêt à activer ») + format de webhook réel à normaliser dans `parserEvenement`. Aucune de ces branches n'a été testée contre un vrai PSP — non promue « prête à activer ».
- **−** Mandats récurrents §5.4 (débits MIT) : **non faits** (vault prêt, moteur absent). Tracé en dette.
- **Dette / promotion** : `StatutCarte` et `ActionClient` sont backend-only ; à **promouvoir dans `@masante/shared`** le jour où un écran web/mobile carte les consomme (coût de rattrapage faible aujourd'hui — aucun consommateur). Même logique qu'ADR-014.
