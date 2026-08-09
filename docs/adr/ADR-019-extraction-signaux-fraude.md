# ADR-019 — Extraction réelle des signaux de fraude (CDC_05, incrément A) : endpoint read-only possédé par le paiement, consommé en HTTP par la fraude sous principal signé

- **Statut** : **Accepté** (prouvé G2 live côté paiement + G3 des deux côtés ; G4 propriétaire en attente).
- **Date** : 2026-08-09
- **Corpus** : CDC_05 §1.6/§1.7 (hybride, dégradation gracieuse), §7.2 (jamais d'entraînement sur prod), §9.1 (détection seule, revue humaine). CDC_10 (sécurité : identité vérifiée, jamais déclarée). CDC_01/02 §0.1 (frontière : agrégats = calcul backend).
- **Lié à** : [[ADR-013]] (microservice paiement isolé), [[ADR-014]] (ne pas coupler aveuglément au schéma d'un autre service), [[ADR-017]] (fraud-detection-service, détection seule), P5.5b-1 (principal signé).

## Contexte

Le `fraud-detection-service` (ADR-017) recevait ses signaux **dans le corps de la requête** (`POST /score`,
`POST /scan`) : contrat = forme des signaux internes. Le branchement lisant réellement
factures/wallet/cartes de la base paiement était une **dette assumée**. La lever proprement pose une
tension : la fraude a besoin d'agrégats calculés sur le schéma paiement, mais **coupler la fraude
aveuglément au schéma d'un autre service est précisément ce qu'ADR-014 proscrit** (une migration paiement
casserait la fraude en silence).

## Décision (validée par le propriétaire au G1, 2026-08-09)

### 1. Le paiement expose, la fraude consomme (le couplage reste chez le propriétaire du schéma)

Le **service paiement** — propriétaire de son schéma — expose un **endpoint de projection read-only**
`GET /api/v1/fraud-signals/{reference}` (+ `POST /api/v1/fraud-signals/lot`) qui calcule les agrégats en
SQL sur *ses* tables et renvoie le vecteur de signaux. La fraude le **consomme en HTTP** ; elle ne lit
**jamais** la base paiement. Rejeté : adaptateur SQL direct côté Python (couple la fraude au schéma
d'autrui + dépendance `psycopg`) ; événements/outbox (surdimensionné pour l'incrément).

### 2. Frontière : projection de DONNÉES, aucune décision de fraude

Les agrégats (`nb_factures_etablissement_30j`, `montant_cumule_wallet_24h`, `montant_acte_reference`,
`delai_facture_paiement_minutes`…) sont **des données** dérivées en lecture seule. Le controller/service
paiement ne porte **aucune règle de fraude** ; tout le jugement (règles + XGBoost + SHAP) reste dans le
microservice fraude. Test de frontière « quelles règles métier ce module calcule-t-il ? » → **aucune**
(côté paiement : projection ; côté fraude : le scoring existait déjà, inchangé). Read-only strict : zéro
écriture ; cut-off `asOf` optionnel pour la reproductibilité (fenêtres 30 j / 7 j / 24 h / 1 h bornées à T).

### 3. Canal authentifié : principal signé (P5.5b-1) + rôle ADMIN_FINANCE

L'endpoint expose des agrégats financiers patient/établissement → **sensible**. Garde par **principal
signé** vérifié en service (`X-Principal` + `X-Principal-Sig`, lié method+path, fraîcheur ±5 min, anti-rejeu
nonce Redis) et rôle **ADMIN_FINANCE** — jamais un rôle déclaré en clair (CDC_10). La fraude **reproduit la
signature en stdlib** (`hmac`/`hashlib`/`base64` — aucune dépendance nouvelle) ; le secret HMAC partagé
vient de l'**environnement** (`FRAUD_PRINCIPAL_SECRET`), jamais du code (CDC_00 §4). « Prêt à activer » :
en prod, principal = identité de l'appelant propagée ou jeton de compte de service Keycloak (RS256).

### 4. Additif pur, modèle intact

Les endpoints `POST /score` et `POST /scan` (signaux fournis) restent **inchangés** (mode « signaux
fournis » / tests offline). Deux nouvelles routes `POST /score-ref` et `POST /scan-refs` extraient les
signaux réels puis appellent le **scoring existant**. Le contrat `SignalFacturation` est **réutilisé** comme
cible de normalisation → vecteur de features, entraînement, modèle **inchangés** (train/serve intact).

### 5. Normalisation = frontière anti-corruption explicite

Le paiement répond en `camelCase` (Jackson idiomatique) ; l'**adaptateur Python** (`vers_signal`) traduit
vers le contrat interne `snake_case`, en un seul endroit testable. Toute divergence de schéma se voit d'un
coup d'œil dans cette fonction — c'est l'anti-corruption d'ADR-014 rendue concrète.

### 6. Dégradation HONNÊTE : on n'invente pas ce qu'on ne peut pas lire

Source injoignable / 5xx / corps illisible → **502** (`SourceIndisponible`) ; référence inconnue → **404**
(`PieceIntrouvable`). **Jamais un score fabriqué** sur données absentes. C'est le pendant, pour la donnée,
de « modèle absent → règles seules » (§1.7) : deux dégradations distinctes, toutes deux honnêtes.

## Conséquences

- **Aucune migration** : endpoint 100 % lecture seule (zéro changement de schéma). **Aucune dépendance
  nouvelle** (paiement : HMAC/JDK déjà là ; fraude : `httpx` déjà présent, promu test→runtime ; signature
  en stdlib).
- Côté paiement : `RequetesSignauxFraude` (SQL natif), `ServiceSignauxFraude` (`@Transactional(readOnly)`),
  `SignauxFactureReponse`/`SignauxLotRequete`, `FraudSignalsController`. Test unitaire pur
  (`ServiceSignauxFraudeTest`) ; agrégats prouvés **G2 live** (vecteurs à valeurs connues).
- Côté fraude : sous-module `app/extraction/` (`SignatairePrincipal`, port `SourceSignaux`,
  `AdaptateurSignauxPaiement`), routes `/score-ref` & `/scan-refs`, tests `test_extraction.py`
  (httpx `MockTransport`).
- **Dette levée** : « extraction depuis la base payment réelle » (ADR-017). **Différé** : le
  **routage/notification** d'alerte vers `ADMIN_FINANCE` + l'**écran admin Next** = incrément B (le paiement
  orchestrera extraction → `/scan` → persistance → Outbox P5.4c ; la fraude reste un scoreur passif). En
  prod, remplacer le secret partagé par un jeton de compte de service (Keycloak) ; envisager l'audit
  d'accès à l'endpoint (données financières).
