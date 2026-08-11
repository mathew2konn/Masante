/**
 * api/delegations.ts — délégation d'accès (voie 3, backend B3), élargie par le carnet familial
 * partagé (incrément A). Client axios unique (token Bearer).
 *
 * Une délégation créée aujourd'hui porte la LECTURE du carnet ; la génération de QR reste
 * accessible via api/qr.ts. Ce que le délégué a le droit de voir est décidé par le serveur
 * (MembreFamillePolicy) — ce module ne fait qu'appeler.
 */
import { api } from '../config/api';
import type {
  CarnetPartage,
  Delegation,
  DelegationsListe,
  ResultatPartageEnMasse,
} from '../types/delegation';

/** Délégations accordées (comme titulaire) et reçues (comme délégué). */
export async function listerDelegations(): Promise<DelegationsListe> {
  const { data } = await api.get<DelegationsListe>('/v1/delegations');
  return data;
}

/** Invite un délégué (par téléphone) sur un membre du titulaire. */
export async function inviterDelegue(membreId: number, telephone: string): Promise<Delegation> {
  const { data } = await api.post<{ delegation: Delegation }>(`/v1/membres/${membreId}/delegations`, { telephone });
  return data.delegation;
}

/** Le délégué accepte une invitation. */
export async function accepterDelegation(id: number): Promise<Delegation> {
  const { data } = await api.post<{ delegation: Delegation }>(`/v1/delegations/${id}/accepter`);
  return data.delegation;
}

/** Révoque (titulaire) ou refuse (délégué) une délégation. */
export async function revoquerDelegation(id: number): Promise<void> {
  await api.delete(`/v1/delegations/${id}`);
}

/**
 * Partage EN UNE FOIS plusieurs carnets avec un proche (incrément A).
 * `membreIds` omis = tous les carnets du compte. Rejouable : les carnets déjà partagés sont
 * comptés dans `deja_partages`, jamais rejetés.
 */
export async function partagerEnMasse(
  telephone: string,
  membreIds?: number[],
): Promise<ResultatPartageEnMasse> {
  const { data } = await api.post<ResultatPartageEnMasse>('/v1/delegations/en-masse', {
    telephone,
    ...(membreIds ? { membre_ids: membreIds } : {}),
  });
  return data;
}

/**
 * Les carnets qu'on m'a partagés, avec leur origine.
 *
 * Endpoint SÉPARÉ de `GET /membres` : « mes membres » garde exactement le sens qu'il avait
 * en P2 (le cache hors-ligne s'appuie dessus). C'est l'écran qui compose les deux listes.
 */
export async function listerCarnetsPartages(): Promise<CarnetPartage[]> {
  const { data } = await api.get<{ partages: CarnetPartage[] }>('/v1/membres/partages');
  return data.partages;
}
