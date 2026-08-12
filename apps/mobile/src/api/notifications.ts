/**
 * api/notifications.ts — notifications en application (incrément D1).
 *
 * Délibérément NON mis en cache hors ligne : une notification périmée est pire qu'absente. Le
 * bandeau hors-ligne global (P2) explique déjà pourquoi la liste ne se remplit pas.
 */
import { api } from '../config/api';
import type { Notification } from '../types/notification';

export async function listerNotifications(): Promise<{
  notifications: Notification[];
  nonLues: number;
}> {
  const { data } = await api.get<{ notifications: Notification[]; non_lues: number }>(
    '/v1/notifications',
  );
  return { notifications: data.notifications, nonLues: data.non_lues };
}

/**
 * Le strict nécessaire à la pastille — appelé au focus de l'Accueil et du Carnet.
 *
 * Renvoie 0 en cas d'échec plutôt que de propager : une pastille est un confort, elle ne doit
 * jamais faire échouer l'écran qui la porte (hors ligne, session expirée, serveur muet).
 */
export async function compterNonLues(): Promise<number> {
  try {
    const { data } = await api.get<{ non_lues: number }>('/v1/notifications/non-lues');
    return data.non_lues;
  } catch {
    return 0;
  }
}

export async function marquerLue(id: string): Promise<number> {
  const { data } = await api.post<{ non_lues: number }>(`/v1/notifications/${id}/lu`);
  return data.non_lues;
}

export async function toutMarquerLu(): Promise<void> {
  await api.post('/v1/notifications/tout-lu');
}

// ── Jeton de push ─────────────────────────────────────────────────────────

/** Enregistre le téléphone. Silencieux en cas d'échec : le push est un bonus, pas un prérequis. */
export async function enregistrerJetonPush(jeton: string, plateforme?: string): Promise<void> {
  try {
    await api.post('/v1/appareils-push', { jeton, plateforme });
  } catch {
    // Volontairement avalé : sans jeton, l'utilisateur garde toutes les notifications en
    // application. Le priver de l'écran parce qu'Expo n'a pas répondu serait absurde.
  }
}

/** À la déconnexion : ce téléphone ne doit plus recevoir les notifications de ce compte. */
export async function retirerJetonPush(jeton: string): Promise<void> {
  try {
    await api.delete('/v1/appareils-push', { data: { jeton } });
  } catch {
    // Idem : ne jamais bloquer une déconnexion.
  }
}
