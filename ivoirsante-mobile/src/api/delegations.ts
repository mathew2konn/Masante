/**
 * api/delegations.ts — délégation d'accès (voie 3, backend B3). Client axios unique (token Bearer).
 * Le droit délégué se limite à la génération de QR ; la génération elle-même passe par api/qr.ts.
 */
import { api } from '../config/api';
import type { Delegation, DelegationsListe } from '../types/delegation';

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
