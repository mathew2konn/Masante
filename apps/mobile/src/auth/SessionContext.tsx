import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import type { Role } from '@masante/shared';
import { clearToken, getStoredToken, saveToken } from '../config/api';
import * as authApi from '../api/auth';
import type { Utilisateur } from '../types/auth';

/**
 * SessionContext — source unique de l'état d'authentification côté mobile.
 *
 * Le token Bearer est la vérité : stocké de façon sécurisée (expo-secure-store, §3.5
 * Sécurité), il conditionne l'accès au groupe de routes protégées (app/(app)). Au
 * démarrage, on tente de restaurer la session depuis le stockage sécurisé.
 */
type SessionValue = {
  token: string | null;
  user: Utilisateur | null;
  roles: Role[]; // rôles RBAC fournis par le backend (jamais déduits côté front — P1)
  hasRole: (role: Role) => boolean;
  isLoading: boolean; // chargement initial (restauration du token)
  signIn: (token: string, user: Utilisateur) => Promise<void>;
  signOut: () => Promise<void>;
};

const SessionContext = createContext<SessionValue | null>(null);

export function useSession(): SessionValue {
  const value = useContext(SessionContext);
  if (value === null) {
    throw new Error('useSession doit être utilisé dans un <SessionProvider />.');
  }
  return value;
}

export function SessionProvider({ children }: { children: React.ReactNode }) {
  const [token, setToken] = useState<string | null>(null);
  const [user, setUser] = useState<Utilisateur | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  // Restauration de session au démarrage : token stocké -> on récupère le profil.
  useEffect(() => {
    let actif = true;
    (async () => {
      try {
        const stored = await getStoredToken();
        if (stored && actif) {
          setToken(stored);
          try {
            const profil = await authApi.me();
            if (actif) setUser(profil);
          } catch {
            // Token invalide/expiré : on nettoie pour repartir sur l'écran de connexion.
            await clearToken();
            if (actif) setToken(null);
          }
        }
      } finally {
        if (actif) setIsLoading(false);
      }
    })();
    return () => {
      actif = false;
    };
  }, []);

  const signIn = useCallback(async (nouveauToken: string, profil: Utilisateur) => {
    await saveToken(nouveauToken);
    setToken(nouveauToken);
    setUser(profil);
  }, []);

  const signOut = useCallback(async () => {
    try {
      await authApi.logout(); // révocation serveur (best-effort)
    } catch {
      // Même hors-ligne, on déconnecte localement.
    }
    await clearToken();
    setToken(null);
    setUser(null);
  }, []);

  // Les rôles suivent le profil : autorité backend, jamais recalculés ici (frontière CDC_01 §0.1).
  const roles = useMemo<Role[]>(() => user?.roles ?? [], [user]);
  const hasRole = useCallback((role: Role) => roles.includes(role), [roles]);

  const value = useMemo<SessionValue>(
    () => ({ token, user, roles, hasRole, isLoading, signIn, signOut }),
    [token, user, roles, hasRole, isLoading, signIn, signOut],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}
