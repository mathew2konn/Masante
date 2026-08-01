import axios from 'axios';

/**
 * Extrait un message lisible d'une erreur d'appel API (axios). Gère les réponses JSON
 * de Laravel : `message` global et `errors` de validation (422). Repli générique sinon.
 */
export function messageErreur(e: unknown): string {
  if (axios.isAxiosError(e)) {
    const data = e.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined;
    if (data?.errors) {
      const premier = Object.values(data.errors)[0];
      if (premier && premier.length > 0) return premier[0];
    }
    if (data?.message) return data.message;
    if (!e.response) return 'Connexion au serveur impossible. Vérifiez votre réseau.';
  }
  return 'Une erreur est survenue. Réessayez.';
}
