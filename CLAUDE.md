# CLAUDE.md — MASANTÉ (monorepo)

Plateforme numérique nationale de santé (Côte d'Ivoire → multi-pays). Mémoire longue = Knowledge Book + ADR ; ce fichier = mémoire courte à jour (CDC_01 §2.1).

## Corpus qui fait autorité
14 cahiers des charges dans `CDC.md/` (CDC_00→CDC_13). **Ordre de résolution des conflits : CDC_10 Sécurité > CDC_08 Protocoles > CDC_09 Données nationales > ADR validé par le propriétaire.** Ne jamais inventer : toute ambiguïté → ADR.

## Architecture (ADR-003 : monorepo réorganisé)
```
apps/mobile/     Expo SDK 54 (React Native, TS strict, NativeWind, Expo Router)
apps/web/        Next.js 15 App Router (TS strict, Tailwind v3 + preset partagé)
packages/shared/ @masante/shared — SOURCE UNIQUE (palette.json→tokens, enums d'état, Zod, i18n)
services/api/    Laravel (PHP 8.3, MySQL) — hors workspace pnpm
CDC.md/          cahiers des charges (lecture seule)
```
- Gestionnaire : **pnpm 9** (via corepack) ; `.npmrc` `node-linker=hoisted` (survie Metro/RN).
- **MySQL conservé** en MVP (PostgreSQL « prêt à activer » — CDC_04). Auth **Sanctum+OTP** (Keycloak « prêt à activer » — CDC_10).

## Règle de frontière (la plus importante — CDC_01 §0.1, CDC_02 §0.1)
**Aucune logique métier dans le front.** Calculs médicaux, scores triage, tarifs, plafonds, reste à charge, éligibilité, transitions d'état : **backend uniquement**. Les états métier (`EN_ATTENTE_VALIDATION`…`TERMINE`) sont **fournis** par le backend, jamais déduits. Route Handlers/Server Actions Next = proxy, jamais du métier. Test de fin de module : « quelles règles métier ce module calcule-t-il ? » → réponse obligatoire **« aucune »**.

## Source unique (anti-divergence — CDC_02 §2.2)
Tokens Design System, schémas Zod, enums d'état, i18n, types dérivés d'OpenAPI = **définis une fois** dans `@masante/shared`, consommés par mobile ET web. Aucune redéfinition locale. Aucune couleur/espacement/taille en dur : uniquement les tokens.

## Méthode (gates blocantes — CDC_01 §2.4)
G0 Audit (lire réellement, ne rien supposer) → G1 Plan validé par écrit → G2 Backend prouvé (OpenAPI + Postman avant tout écran) → G3 Qualité (lint+typecheck+tests+expo-doctor / axe-core verts) → G4 Test réel (mobile Expo Go SDK 54 + Ngrok ; web Chrome/Firefox desktop+mobile, réseau bridé, offline) → G5 « module validé » écrit. **Aucun module suivant avant G5. Corrections chirurgicales uniquement.**

## Discipline dépendances (§2.6)
Mobile : `npx expo install` uniquement (jamais `npm install` natif). **Aucune dépendance sans accord écrit du propriétaire.** Lockfile committé, `expo-doctor` propre avant G3. MMKV interdit tant qu'on teste via Expo Go (module natif) — cache via expo-sqlite/SecureStore.

## Commandes
- `pnpm install` (racine) · `pnpm mobile` (Expo) · `pnpm web` (Next dev) · `pnpm typecheck` (tous)
- Backend : `services/api` — PHP `C:\wamp64\bin\php\php8.3.28\php.exe` (préfixer `XDEBUG_MODE=off`), MySQL WAMP 8.4.7 base `ivoirsante`, `$PHP artisan serve --host=0.0.0.0 --port=8000` (Ngrok).

## État des modules (ordre CDC_01 §17)
- **P0 Socle** : ✅ **VALIDÉ (G5, 2026-08-01)** — monorepo, @masante/shared, retrofit mobile (sans reanimated/MMKV, Expo Go OK), squelette web, sphères + logo bleu arrondi, slogan « Votre Santé Notre Priorité ». Testé Expo Go + navigateur.
- **P1 Identité** : ✅ **VALIDÉ (G5, 2026-08-01)** — RBAC (11 rôles spatie, `patient` auto, `/me` expose `roles`) + MFA TOTP « prêt à activer » (`pragmarx/google2fa`, gate `MFA_ENFORCE`). Mobile : rôles au Carnet, écran double authentification, sphères en fond global. Web pro (Next) : login + défi/enrôlement MFA (QR) + garde par rôle (cookie httpOnly). Testé Expo Go + navigateur. Guide `GUIDE_TEST_G4_P1.md`.
- **P2 Profil + dossier médical (lecture, offline)** : ✅ **VALIDÉ (G5, 2026-08-01)** — cache de lecture **chiffré** (AES-256 aes-js, clé SecureStore) sur **expo-sqlite**, injecté SOUS les écrans validés (aucune réécriture : aucun écran n'utilisait `useQuery` → cache au niveau API, ADR-009). Couvre membres/sections/profil/mesures/grossesse/documents ; bandeau hors-ligne global ; purge à la déconnexion. Testé mode avion.
- **P3 Recherche établissements/médecins + fiches + carte OSM (offline)** : ✅ **VALIDÉ (G5, 2026-08-01)** — annuaire (recherche/fiches/pharmacies/avis) lisible hors ligne via le cache chiffré P2 ; bandeau hors-ligne + dégradation gracieuse de la carte (Leaflet local + tuiles offline = « prêt à activer »). Testé mode avion.
- **P4 Rendez-vous (workflow deux étapes complet)** : ✅ **VALIDÉ (G5, 2026-08-01)** — offline RDV mobile (liste, cache P2) ; workflow staff (confirmer/refuser) extrait dans `RendezVousValidationService` (source unique Blade + API Sanctum) ; écrans portail Next (file d'attente + détail + actions). Garde `rdv.validate` via `can()` (pas le middleware spatie, guard web). Testé navigateur (compte agent).
- **P5 Paiement (Mobile Money, cartes, Wallet, CNAM, assurance — CDC_06)** : EN COURS. **Décision ADR-013 : domaine paiement = microservice Java Spring Boot** (`services/payment/`, Postgres+Redis, build/run Docker), indépendant du cœur Laravel ; existant paiement/reçu RDV conservé intact (incréments additifs).
  - **P5.1 Socle + prise en charge CNAM/assurance** : ✅ **VALIDÉ (G5, 2026-08-02)** — passerelle OCP (`PasserellePaiement`+adaptateur simulé, zéro `if canal==`), machine à états stricte (enum source unique `@masante/shared`), idempotence (`Idempotency-Key` Redis+unicité PG), audit append-only à hachage chaîné, moteur de couverture/ticket modérateur/reste à charge (frontière : calcul backend seul ; vecteurs CDC 20000@70→6000, 250000@80→50000). **Paiement SIMULÉ** (FT5, aucune passerelle réelle). Prouvé G2 live (Swagger+curl) + G3 (tests Gradle) ; G4 propriétaire OK (Swagger). Guide `GUIDE_TEST_G4_P5.md`.
  - **P5.2a Facturation** (facture : calcul TVA/remises/prise en charge → reste à payer ; numérotation unique par établissement/exercice ; PDF+QR ; règlement paiement↔facture EMISE→PAYEE — §7) : ✅ **VALIDÉ (G5, 2026-08-02)** — prouvé G2 (live) + G3 (tests) ; G4 propriétaire OK (Swagger). TVA = donnée (jamais codée). Deps OpenPDF+ZXing (approuvées). Enum `FactureStatut` ajouté à `@masante/shared`. Guide `GUIDE_TEST_Paiement_G4_P5.md` (Partie B).
  - **P5.2b Avoir + versionnage + signature** (correction = nouvelle version immuable + avoir du TTC ; annulation + avoir ; signature RSA-SHA256 « prête à activer » ON par défaut, clé dév en mémoire — §7.1/§7.4/§7.5) : ✅ **VALIDÉ (G5, 2026-08-03)** — prouvé G2 (live) + G3 (tests) ; G4 propriétaire OK (Swagger). Statut `REMPLACEE` ajouté à `@masante/shared`. Aucune dépendance nouvelle (crypto JDK). Guide `GUIDE_TEST_Paiement_G4_P5.md` (Partie C).
  - **P5.3a Wallet + double écriture** (portefeuille ; comptabilité en double écriture = 2 écritures/opération de somme nulle, solde = SUM jamais stocké ; crédit/débit/transfert/gel idempotents+audités ; paiement d'une facture depuis le wallet EMISE→PAYEE — §6) : ✅ **VALIDÉ (G5, 2026-08-03)** — prouvé G2 (live) + G3 (tests) ; G4 propriétaire OK (Swagger). `WalletStatut` ajouté à `@masante/shared`. Frontière : suffisance/état/solde = backend seul. Aucune dépendance nouvelle. Guide `GUIDE_TEST_Paiement_G4_P5.md` (Partie D).
  - **P5.3b Sécurité wallet §6.4 + cashback/bonus + rapprochement** : EN COURS, découpé en 4 sous-incréments.
    - **P5.3b-1 Sécurité transactionnelle** (PIN wallet BCrypt haché + verrou après N échecs ; OTP à usage unique Redis, exigé au-delà d'un seuil ; **limites op/jour/mois = données** ; signature d'opération « prête à activer » — §6.4) : ✅ **VALIDÉ (G5, 2026-08-04)** — prouvé G2 (live) + G3 (tests) ; G4 propriétaire OK (Swagger). Frontière : PIN/OTP/limites vérifiés backend seul ; consommations jour/mois dérivées du grand livre (aucun compteur stocké). Dép. `spring-security-crypto` (approuvée). Biométrie = côté device « prête à activer ». Guide `GUIDE_TEST_Paiement_G4_P5.md` (Partie E).
    - **P5.3b-2 Détection de fraude + gel sur suspicion** (règles déterministes — pas d'IA qui décide seule ; 3 paliers ALERTE/CHALLENGE/GEL ; vélocité/cumul/échecs PIN → score, seuils=données ; gel temporaire à TTL + auto-dégel ; alertes `fraud_alertes` JSONB+snapshot ; 409/401 génériques anti-fuite — §6.4) : ✅ **VALIDÉ (G5, 2026-08-04)** — prouvé G2 (live) + G3 (tests) ; G4 propriétaire OK (Swagger). Évaluation **sous verrou pessimiste** (concurrence) ; gel/alerte/audit en **tx propre après le rollback de l'op** (résout la contradiction REQUIRES_NEW × verrou = deadlock). Détection **IA** + **multi-comptes** = dette renvoyée au `fraud-detection-service` (CDC_05). Aucune dépendance nouvelle. Guide `GUIDE_TEST_Paiement_G4_P5.md` (Partie F).
    - **P5.3b-3 Cashback (campagnes) + Bonus** (cashback par campagnes taux=bps entiers/période/plafonds/budget=données ; calcul+résolution de campagne+décision backend seul ; réversibilité `operation_source_id`+clawback proportionnel Σ≤origine ; anti-siphon plafonds par wallet/par jour ; double écriture somme=0 ; acteur via en-tête `X-Acteur-Id` non usurpable+audité — §6/§11) : ✅ **VALIDÉ (G5, 2026-08-04)** — prouvé G3 (tests Gradle : `ReglesCashbackTest`+`CashbackFlagDefautTest`) + G2 (live) ; G4 propriétaire OK. **CRÉDIT du cashback gaté OFF** (`cashback.credit-enabled=false` → dry-run) tant que le remboursement wallet §11 déclenchant le clawback en même tx n'existe pas (dette `DETTE_TECHNIQUE.md`). Aucune dépendance nouvelle. Guide `GUIDE_TEST_Paiement_G4_P5.md` (Partie G).
    - **P5.3b-4** (rapprochement quotidien automatique) : PROCHAIN.
  - Puis **cartes §5**, **reversements §11**, **fraude CDC_05**.
- Refontes engagées (post-P0, à leur module) : Auth→P1, Carnet→DMEN (P2/P4/P6/P8), Triage→protocoles+IA (P10).
- Existant conservé (« implémenté et prouvé », ne pas réécrire) : auth OTP, carnet familial, carte vitale/CMU, médecin référent, triage, délégation/bris-de-glace/audit, verrou PIN, QR, alertes SOS/épidémiques.

## Interdits absolus (CDC_00 §4) — rappel
Règle médicale en dur · triage présenté comme diagnostic · IA décidant seule · secret dans le code · fichier médical en base · logique métier dans un composant/contrôleur · accès dossier sans lien de prise en charge (hors bris de glace audité) · sortie IA sans explication+confiance+limites. **SAMU 185** (jamais le 15).
