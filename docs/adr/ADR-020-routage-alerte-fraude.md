# ADR-020 — Routage des alertes de fraude IA vers le contrôleur plateforme (approfondissement fraude, incrément B1) : le paiement orchestre, la fraude reste passive, notification via Outbox

- **Statut** : **Accepté** (B1 backend prouvé G2/G3 ; G4 propriétaire en attente). LIVRAISON de notification **SIMULÉE** (FT5). Écran admin Next = B2 (à suivre).
- **Date** : 2026-08-09
- **Corpus** : CDC_05 §6.9 (détection de comportements anormaux), §9.1 (détection seule, revue humaine). CDC_03 §8 (Outbox). CDC_10 (identité vérifiée). CDC_01/02 §0.1 (frontière : le score/agrégats = backend).
- **Lié à** : [[ADR-017]] (fraud-detection-service passif, destinataire figé), [[ADR-019]] (extraction des signaux), [[ADR-018]] (canal de notification Outbox P5.4c).

## Contexte

Le fraud-detection-service **répond** au demandeur ; il n'**envoie** l'alerte à personne (ADR-017 : « pas
d'écran ni routage »). Il fallait router les détections vers un humain — le **contrôleur anti-fraude /
conformité plateforme** (`ADMIN_FINANCE`), **indépendant**, jamais le directeur de la structure signalée
(le prévenir = prévenir le fraudeur, ADR-017 §7). Décision propriétaire (G1) : **le paiement orchestre**,
le microservice fraude **reste un scoreur passif** ; livraison = **notification (Outbox) + écran Next**,
séquencé en **B1 (backend, ce document)** puis **B2 (écran)**.

## Décision (B1)

### 1. Le paiement orchestre (1er appel SORTANT du paiement)

`ServiceRoutageFraude` : sélectionne les factures d'une fenêtre `[T − fenêtre, T]` (fenêtre = donnée,
défaut 1 j) → **extrait leurs signaux** (réutilise `ServiceSignauxFraude` d'ADR-019) → **demande un score**
au fraud-detection-service via `ClientFraudeDetection` (Spring **RestClient**, aucune dépendance nouvelle ;
1er appel sortant du paiement). Le microservice fraude reste **passif** : il note, il n'envoie rien.
L'appel HTTP est **hors transaction** ; la persistance suit.

### 2. Alerte IA = table distincte (séparation honnête)

`ia_fraude_alertes` (V16) est **distincte** de `fraud_alertes` (P5.3b-2, garde temps-réel wallet, palier
GEL/CHALLENGE) : ici, alerte **analytique sur une FACTURE** (niveau SUSPECT/TRES_SUSPECT + snapshots
JSONB règles/facteurs SHAP/signaux). Sémantiques différentes → deux tables (cf. ADR-014). Seules les
factures **≥ SUSPECT** deviennent des alertes ; **NORMAL n'est jamais persisté**.

### 3. Notification via Outbox (P5.4c), dans la transaction de l'alerte

À la **création** d'une alerte, on émet une notification `FRAUDE_SUSPECTEE` via `ServiceNotifications.emettre`
(**Outbox** : écrite dans la MÊME transaction que l'alerte, jamais publiée avant commit) vers le
destinataire `ADMIN_FINANCE` (réf **configurable**, défaut `CTRL-FRAUDE-PLATEFORME`). Le relais existant
livre ensuite (adaptateur **SIMULÉ**, FT5). Le type d'agrégat est `ia_fraude_alerte`.

### 4. Idempotence + anti-spam

`UNIQUE(facture_ref, date_rapport)` : rejouer un scan **met à jour** le verdict de l'alerte **sans
dupliquer** et **sans ré-émettre** de notification (le flag `notifiee` marque l'émission unique à la
création). Un `@Scheduled` quotidien (veille) + un endpoint manuel `POST /fraud-alertes/scan` déclenchent.

### 5. DÉTECTION SEULE + garde + dégradation honnête

Router vers un humain ≠ agir : **aucun gel, aucune correction** (ADR-017 ; marquer « revue » n'est qu'une
trace). Endpoints **sensibles** (données financières + décision de conformité) gardés par **principal
signé** (P5.5b-1) + rôle **ADMIN_FINANCE**. Si le fraud-detection-service est injoignable →
`FraudeInjoignableException` (**502**) : le run échoue proprement, **aucune alerte inventée**, aucun état
partiel (rien n'est persisté avant d'avoir le score) ; le cœur paiement n'est pas affecté (le scoring est
hors du chemin transactionnel de paiement).

### 6. Enums backend-only (promus en B2)

`NiveauFraudeIa`, `StatutAlerteFraudeIa` restent **backend-only** en B1 ; ils seront promus dans
`@masante/shared` quand l'écran admin Next (B2) les consommera (même logique qu'ADR-014/015/016).

## Conséquences

- Migration **V16** (`ia_fraude_alertes`). `TypeNotification` gagne `FRAUDE_SUSPECTEE`. Aucune dépendance
  nouvelle (RestClient = Spring Web déjà présent).
- Endpoints : `POST /api/v1/fraud-alertes/scan`, `GET /api/v1/fraud-alertes`, `GET /{id}`,
  `POST /{id}/revue` (tous gardés). `@Scheduled` quotidien.
- **Dettes** : livraison de notification SIMULÉE (FT5) ; le patient n'est pas repris dans l'alerte (le
  vecteur de signaux ne porte pas d'identité patient — confidentialité ; récupérable à la revue) ; scan
  synchrone (pas de file) ; **écran admin Next = B2** ; en prod, remplacer le secret partagé par un compte
  de service (Keycloak) et envisager l'audit d'accès.
