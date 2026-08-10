# ADR-020 — Routage des alertes de fraude IA vers le contrôleur plateforme (approfondissement fraude, incrément B) : le paiement orchestre, la fraude reste passive, notification via Outbox + écran admin Next

- **Statut** : **Accepté** — **B1** (backend) VALIDÉ G5 (2026-08-09) ; **B2** (écran admin Next) VALIDÉ G5 (2026-08-10). LIVRAISON de notification **SIMULÉE** (FT5).
- **Date** : 2026-08-09 (B1) · 2026-08-10 (B2)
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
  synchrone (pas de file) ; en prod, remplacer le secret partagé par un compte de service (Keycloak) et
  envisager l'audit d'accès.

## Décision (B2) — Écran admin Next (portail professionnel)

### 1. Canal DISTINCT du proxy Laravel : Next mint un principal signé vers le paiement

Les alertes vivent dans le **microservice paiement** (port 8080), gardées par **principal signé +
`ADMIN_FINANCE`** — pas dans Laravel/Sanctum. Le portail Next ne pouvait donc pas les atteindre par son
`authedFetch` (Bearer → Laravel). Nouvelle couche serveur `lib/paiement.ts` : Next agit en **passerelle
authentifiée** — il vérifie la session/rôle Laravel PUIS **mint** un principal signé (Node `crypto`,
HMAC-SHA256, **aucune dépendance**) reproduisant à l'octet près le vérifieur Java (`ServicePrincipal`) et
`signer.py`. `PAYMENT_URL` + `MASANTE_PAYMENT_PRINCIPAL_SECRET` (server-only, `.env` gitignoré ;
`.env.example` documente les clés sans valeur). Le `path` signé = pathname sans query (le paiement lie sur
`getRequestURI()`). Interop prouvée live (Node → paiement : 200 sur liste/scan/détail/revue, 403 rôle
insuffisant, 401 signature altérée).

### 2. RBAC : accès = `super_admin` OU `ministere` (décision propriétaire G1)

L'enum `Role` (`@masante/shared`, 11 rôles CDC_10) **ne contient pas** `admin_finance` ; `/v1/auth/me`
ne le renvoie jamais. Choix propriétaire : les **contrôleurs plateforme indépendants** habilités sont
`super_admin` et `ministere` (jamais `admin_etablissement`, établissement-scopé — ADR-017). `lib/fraude.ts`
n'accepte de **minter** un principal `ADMIN_FINANCE` qu'après avoir vérifié ce rôle sur la session ;
**défense en profondeur** — la garde faisant autorité reste le paiement (principal signé). Seeder dév
`ControleurPlateformeSeeder` (compte `super_admin`) pour la démo (jamais en prod).

### 3. Frontière : Next affiche + proxie, zéro métier ; détection seule

Server Components pour la lecture (SSR via `lib/fraude.ts`), Route Handlers `/api/portail/fraude-alertes/*`
pour scan/revue, Client Components pour les actions. `niveau/score/règles/facteurs SHAP/signaux` viennent
du paiement — Next ne déduit rien (frontière CDC_02). L'écran détail affiche l'**explication obligatoire**
(règles déterministes + facteurs SHAP + signaux — CDC_00 §4 : jamais de sortie IA sans explication). **Aucun
bouton de gel/action** : la seule action est « marquer revue » (trace humaine — ADR-017). Enums
`NiveauFraudeIa`/`StatutAlerteFraudeIa` **promus dans `@masante/shared`** (source unique) — dette B1 levée.

### Conséquences (B2)

- Web-only + 1 seeder dév Laravel. **Aucune migration**, **aucune dépendance** (Node `crypto` stdlib).
  Fichiers : `packages/shared/src/enums` (2 enums), `apps/web/src/lib/{paiement,fraude,fraude-types}.ts`,
  routes `(portail)/fraude-alertes` (liste + `[id]`) + `api/portail/fraude-alertes/{scan,[id]/revue}`,
  carte d'accueil conditionnée au rôle, `.env.example`.
- **G3** typecheck (shared + web) + lint + build de prod verts. **G2** : interop crypto Node↔Java (4
  endpoints + rejets 403/401), runtime non authentifié (307 `/login`, 403 garde), boucle **authentifiée
  réelle** (login super_admin → liste SSR → scan → détail SSR avec explication → revue). **G4** propriétaire OK.
- **Dettes B2** : pas de harnais axe-core local (a11y vérifiée au G4 navigateur) ; secret symétrique partagé
  (prod → Keycloak RS256 « prêt à activer », déjà au dossier `ServicePrincipal`) ; pas de pagination/
  recherche sur la liste ; le patient reste absent de l'alerte (cf. dette B1).
