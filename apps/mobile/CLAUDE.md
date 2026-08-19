@AGENTS.md

# CLAUDE.md — apps/mobile (MaSanté)

App citoyenne Expo SDK 54. Voir le `CLAUDE.md` racine pour l'architecture globale, la règle de frontière et les gates. Rappels spécifiques mobile :

## Stack verrouillée (versions installées)
Expo `~54.0.37` · React Native `0.81.5` · React `19.1.0` · Expo Router `~6.0.24` · TypeScript `~5.9.2` strict · **NativeWind `^4.2.6` + Tailwind `^3.4.19`** (pas v4) · TanStack Query `^5.101.4` · Zustand `^5.0.14` · React Hook Form `^7.83.0` · Zod `^4.4.3` · FlashList `2.0.2` · reanimated `~4.1.1` · expo-sqlite `~16.0.10` · axios `^1.18.0`. Auth/verrou existants : SecureStore, expo-local-authentication (PIN/bio).

## Règles
- **Aucune règle médicale/tarifaire ici.** Le questionnaire de triage, les seuils, les états RDV viennent du backend.
- Style : **NativeWind (className)** + tokens de `@masante/shared` (`tokens.*`) — jamais de couleur/taille en dur.
- État serveur : **TanStack Query** (un client unique `src/services/queryClient.ts`). État global : **Zustand** (`src/store/`). Auth/verrou : Context existants (`src/auth/`).
- i18n : `useT()` (`src/i18n/useT.ts`) lit `@masante/shared/i18n`.
- Client HTTP unique (`src/config/api.ts`) — aucun `fetch`/`axios` direct dans un composant.
- **MMKV interdit** (casse Expo Go) → cache via expo-sqlite/SecureStore ; MMKV « prêt à activer » en dev build.
- Monorepo : `metro.config.js` surveille la racine ; `node-linker=hoisted`.

## Commandes
`pnpm --filter @masante/mobile start` (ou `npx expo start --tunnel`) · `tsc --noEmit` · `npx expo-doctor` (doit rester 18/18) · `npx expo install <pkg>` (jamais npm).

## Gate de test (G4)
Expo Go SDK 54 sur le téléphone du propriétaire via tunnel **Ngrok**. Reporter l'URL Ngrok dans `app.config.ts` (`extra.apiUrl` ← `EXPO_PUBLIC_API_URL`).

## Existant à ne pas réécrire (P0)
`app/(auth)`, `app/(app)`, `src/theme/theme.ts` (étendre, pas réécrire), `src/auth/*`, écrans carnet/triage/urgence/carte. Le logo = `assets/images/logo.png` (source unique) — à remplacer par l'éléphant bleu.
