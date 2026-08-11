/**
 * api/titulaire.ts — dossier de santé du titulaire du compte (P6.1, CDC_09 §3, ADR-021 §2.1).
 *
 * Réutilise le CLIENT AXIOS UNIQUE (src/config/api.ts) : le token Bearer est injecté par
 * l'intercepteur, comme pour tous les autres appels.
 *
 * FRONTIÈRE (CDC_01 §0.1) : l'existence du dossier est une réponse du BACKEND
 * (`GET /membres/titulaire`), jamais une déduction locale à partir de la liste des membres.
 * Le mobile affiche et transmet, il ne décide pas.
 */
import { api } from '../config/api';
import type { DossierTitulairePayload, EtatDossierTitulaire, Membre } from '../types/membre';

/**
 * Le compte a-t-il déjà son dossier de santé ?
 *
 * Volontairement NON mis en cache hors ligne : c'est une porte d'entrée: un cache
 * périmé afficherait le formulaire à quelqu'un qui a déjà son dossier, ou l'inverse.
 */
export async function etatDossierTitulaire(): Promise<EtatDossierTitulaire> {
  const { data } = await api.get<EtatDossierTitulaire>('/v1/membres/titulaire');
  return data;
}

/**
 * Crée le dossier de santé du titulaire et déclenche l'attribution du NIS côté serveur.
 * 409 si le dossier existe déjà (le serveur refuse le doublon, l'app ne le devine pas).
 */
export async function creerDossierTitulaire(payload: DossierTitulairePayload): Promise<Membre> {
  const { data } = await api.post<{ membre: Membre }>('/v1/membres/titulaire', payload);
  return data.membre;
}
