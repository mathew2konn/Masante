import { QueryClient } from '@tanstack/react-query';

/**
 * Client TanStack Query unique de l'app (état serveur — CDC_01 §3).
 * TTL alignés sur le backend (CDC_00 §5) : à appliquer par requête via `staleTime`.
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 60_000,
      gcTime: 5 * 60_000,
      retry: 2,
      refetchOnWindowFocus: false,
    },
  },
});

/** TTL de cache imposés (ms) — médecins 15 min · hôpitaux 24 h · pharmacies 12 h · géo 30 j. */
export const TTL = {
  medecins: 15 * 60_000,
  hopitaux: 24 * 60 * 60_000,
  pharmacies: 12 * 60 * 60_000,
  geographie: 30 * 24 * 60 * 60_000,
} as const;
