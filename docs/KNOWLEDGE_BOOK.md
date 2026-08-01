# MASANTÉ — Knowledge Book

Mémoire longue du projet (CDC_00 §8) : raisons des choix, pièges, ce qui a été validé.

## 2026-07-31 — Bascule architecture v2.0 & module P0 (socle)

### Contexte
Passage du plan « 5 modules IVOIRSANTÉ » à l'architecture **MASANTÉ** (corpus 14 CDC). 6 livrables §12 produits, ADR tranchés (voir `docs/adr/`).

### P0 construit et prouvé (G3 vert)
- **Monorepo pnpm** (corepack) réorganisé : `apps/mobile`, `apps/web`, `packages/shared`, `services/api`.
- **`@masante/shared`** : `palette.json` = **source unique** des valeurs → tokens TS + **preset Tailwind partagé** (mobile NativeWind + web Tailwind lisent le même fichier). Enums d'état, schémas Zod, i18n fr/en.
- **Retrofit mobile** : NativeWind+Tailwind3, TanStack Query, Zustand, RHF, Zod, FlashList, expo-sqlite, reanimated. Providers branchés. **Sphères bleues rebondissantes** (reanimated) sur le splash.
- **Web** : squelette Next.js 15, page démo consommant `@masante/shared`. Build OK.
- Gouvernance : 3 `CLAUDE.md`, CI (typecheck + gitleaks), squelette OpenAPI, catalogue d'événements.

### Pièges rencontrés (à retenir)
1. **pnpm + Expo/Metro** : Metro ne remonte pas l'arbre → il faut `.npmrc node-linker=hoisted` **et** un `metro.config.js` monorepo (`watchFolders` = défauts Expo **+** racine, sans écraser). Écraser `watchFolders` casse expo-doctor.
2. **`npx expo install` a pris Tailwind v4** → incompatible NativeWind 4 (qui exige **v3**). Pin `tailwindcss@^3.4`. Idem web.
3. **MMKV** casse Expo Go (module natif) → repoussé au development build. Cache via expo-sqlite/SecureStore.
4. **Déplacement de dossiers Windows** : `mv` échoue (« resource busy », watcher VSCode) → `robocopy /MOVE` fichier par fichier fonctionne ; 1 log verrouillé (`laravel.log`) reste, sans gravité.
5. **i18n typé** : un `as const` sur `fr` fige les valeurs en littéraux → `en` invalide. Retirer `as const` (valeurs `string`).
6. Map `exports` d'un paquet bloque `/package.json` (voulu) — ne pas s'en alarmer, les sous-chemins déclarés résolvent.
7. **reanimated 4 / react-native-worklets NE se charge PAS dans Expo Go** (`TurboModule installTurboModule called with 1 arguments…`) : désalignement JS/natif. Tout module l'important au boot fait planter `_layout.tsx` (→ cascade « missing default export », « useSession hors provider »). Solution : **API `Animated` native de RN** (avec `useNativeDriver`) pour les animations tant qu'on est sur Expo Go ; reanimated « prêt à activer » en dev build. reanimated reste peer transitif d'expo-router mais n'est pas chargé au boot.
8. Expo `--tunnel` (ngrok du bundler) échoue souvent (« tunnel took too long ») : utiliser `--lan` (même Wi-Fi) pour Expo Go. Le ngrok du **backend** (API) est séparé.

### Reste pour clôturer P0
G4 (mobile Expo Go+Ngrok, web navigateur desktop+mobile réseau bridé/offline) → G5 (« module validé »). Logo éléphant bleu à déposer dans `apps/mobile/assets/images/logo.png`.
