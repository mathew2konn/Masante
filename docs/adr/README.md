# Architecture Decision Records — MASANTÉ

Une décision = une entrée (Rule-003). Statuts : **Accepté** (validé par le propriétaire), **Proposé**, **Perspective**.

| N° | Décision | Statut | Résumé |
|----|----------|--------|--------|
| ADR-000 | Identité de marque & palette | **Accepté** | Nom **MaSanté**, palette **Bleu Santé** + secondaire Orange. Symbole **éléphant conservé, recoloré en bleu** (logo fourni). Animation « sphères rebondissantes » au Design System P0. |
| ADR-001 | Format NIS | **Accepté** | `CIS` + année(2) + compteur(8) + checksum mod-97 (ex. `CIS241200012547`), vérifié client + serveur. `pays_code` par référentiel. |
| ADR-002 | Keycloak vs Sanctum | **Accepté** | MVP = **téléphone+OTP+Sanctum** conservés (existant validé). Keycloak/OAuth2/OIDC/MFA « prêt à activer » (module P1). |
| ADR-003 | Monorepo & moteur SQL | **Accepté** | **Monorepo pnpm** réorganisé (`apps/mobile`, `apps/web`, `packages/shared`, `services/api`) + `@masante/shared`. **MySQL conservé** en MVP ; PostgreSQL « prêt à activer » (CDC_04). |
| ADR-004 | Périmètre CQRS / Event Sourcing | Perspective | CQRS read-model sur DMEN/urgences/stats ; ES sur historique médical + audit. Non P0. |
| ADR-005 | Modèle LLM privé (CDC_07) | Perspective | Abstraction `LLMProvider`, Llama 3 cible. Hors périmètre démo. |
| ADR-006 | Clé primaire UUID v7 vs BIGSERIAL | Proposé | UUID v7 pour nouvelles tables nationales ; existant BIGINT conservé (pas de migration destructive). |
| ADR-007 | Auth téléphone+OTP, verrou, récupération | **Accepté** | Flux OTP + `VerrouContext`/PIN + `password_reset_grants` existants conservés et formalisés. |
| ADR-008 | Voies d'accès dossier (délégation + bris de glace) | **Accepté** | Existant (`Delegation`, `BrisDeGlaceService`, `AccesDossier`) conservé, aligné CDC_10 (lecture 15 min, audit, revue admin). |
| ADR-009 | Retrofit mobile vs Expo Go (MMKV) | **Accepté** | Retrofit complet du stack CDC_01 **sauf MMKV** (casse Expo Go). Cache via expo-sqlite/SecureStore ; MMKV « prêt à activer » en development build. |
| ADR-010 | Tailwind v3 (mobile+web) | **Accepté** | Pin **Tailwind 3.4** (NativeWind 4 exige v3 ; Tailwind v4 = config CSS incompatible). Preset partagé `@masante/shared/tailwind-preset`. |
| ADR-011 | Rôles & portails = Web ; mobile = patient | **Accepté** | **Mobile = app citoyenne patient uniquement** (pas de nav par rôle pro). **Tous les rôles pros + portails** (médecin, infirmier, secrétaire, pharmacien, labo, radio, admin établissement, super-admin, ministère, assurance) = **Web Next.js** (CDC_02 : Next.js 15 + Tailwind + **Shadcn UI** + TanStack + Zustand + RHF/Zod). Le **Portail Blade existant est à migrer vers Next.js**. Backend commun (API) sert les deux. |

> Détail complet et alternatives : voir le Knowledge Book et l'historique de conversation de la bascule v2.0 (2026-07-31).
