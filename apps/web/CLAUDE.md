# CLAUDE.md — apps/web (MaSanté)

Portail web Next.js 15 (App Router). Voir le `CLAUDE.md` racine pour l'architecture globale, la règle de frontière et les gates. Rappels spécifiques web :

## Stack verrouillée
Next.js `^15.1.6` (App Router) · React `19.1.0` · TypeScript strict · **Tailwind `^3.4.17` + preset partagé `@masante/shared/tailwind-preset`** · Shadcn UI (à ajouter au besoin) · TanStack Query `^5` · Zustand `^5` · React Hook Form `^7` + Zod `^4` · lucide-react.

## Règles (CDC_02)
- **Route Handlers / Server Actions / middleware = proxy et session uniquement. Aucun métier, aucun calcul de tarif/plafond/reste à charge.**
- **Aucun accès base depuis un Server Component** (Rule-001).
- Tokens Tailwind = **preset partagé** (mêmes valeurs que le mobile) ; aucune couleur/taille en dur.
- Types d'API **dérivés d'OpenAPI**, jamais écrits à la main. Schémas Zod = `@masante/shared`.
- Tokens/session en **cookies HTTPOnly+Secure+SameSite** (jamais `localStorage`).
- Chaque route : `layout.tsx`, `page.tsx`, `loading.tsx`, `error.tsx`, `not-found.tsx` ; stratégie de rendu (SSG/ISR/SSR/CSR) décidée dans le plan ; pages privées non indexables.
- **axe-core blocant en CI** (G3) ; WCAG 2.2 AA.

## Commandes
`pnpm --filter @masante/web dev` · `pnpm --filter @masante/web build` · `tsc --noEmit` · `next lint`.

## Gate de test (G4)
Chrome + Firefox, **desktop et mobile**, réseau bridé (3G lent), scénario hors ligne PWA.

## État
P0 : squelette (layout, page démo consommant `@masante/shared`, providers TanStack, Tailwind preset). Mobile prioritaire (CDC_02 §18).
