/**
 * api/contributions.ts — brouillons du carnet familial partagé (incrément C).
 *
 * Un délégué PROPOSE, un responsable VALIDE. Qui a le droit de proposer, qui a le droit de
 * décider, et ce que devient une contribution validée : tout est décidé côté serveur
 * (`ContributionCarnetService`). Ce module appelle, il ne juge pas.
 *
 * Délibérément NON mis en cache hors ligne : proposer une contribution suppose de savoir si l'on
 * en a encore le droit, et valider écrit au dossier médical. Ni l'un ni l'autre ne se décide sur
 * des données périmées.
 */
import { api } from '../config/api';
import type { Contribution, Responsable } from '../types/contribution';

/** Dépose une proposition au brouillon sur le carnet d'un proche. */
export async function deposerContribution(
  membreId: number,
  section: string,
  donnees: Record<string, unknown>,
): Promise<Contribution> {
  const { data } = await api.post<{ contribution: Contribution }>(
    `/v1/membres/${membreId}/contributions`,
    { section, donnees },
  );
  return data.contribution;
}

/** Les contributions déposées sur un carnet — brouillons compris, jamais cachés. */
export async function listerContributions(membreId: number): Promise<Contribution[]> {
  const { data } = await api.get<{ contributions: Contribution[] }>(
    `/v1/membres/${membreId}/contributions`,
  );
  return data.contributions;
}

/** La file du responsable : ce qu'on lui demande d'arbitrer, tous carnets confondus. */
export async function contributionsEnAttente(): Promise<Contribution[]> {
  const { data } = await api.get<{ contributions: Contribution[] }>('/v1/contributions');
  return data.contributions;
}

/** Valide : l'entrée est écrite dans le carnet par le serveur. */
export async function validerContribution(id: number): Promise<Contribution> {
  const { data } = await api.post<{ contribution: Contribution }>(`/v1/contributions/${id}/valider`);
  return data.contribution;
}

/** Rejette : rien n'est écrit, mais la contribution reste consultable et explicable. */
export async function rejeterContribution(id: number, motif?: string): Promise<Contribution> {
  const { data } = await api.post<{ contribution: Contribution }>(
    `/v1/contributions/${id}/rejeter`,
    motif ? { motif } : {},
  );
  return data.contribution;
}

// ── Responsables de famille ────────────────────────────────────────────────

export async function listerResponsables(): Promise<{
  designes: Responsable[];
  je_suis_responsable_de: Responsable[];
}> {
  const { data } = await api.get<{
    designes: Responsable[];
    je_suis_responsable_de: Responsable[];
  }>('/v1/responsables');
  return data;
}

/** Désigne un second responsable — celui qui a créé les carnets décide, jamais « les parents ». */
export async function designerResponsable(telephone: string): Promise<Responsable> {
  const { data } = await api.post<{ responsable: Responsable }>('/v1/responsables', { telephone });
  return data.responsable;
}

export async function retirerResponsable(id: number): Promise<void> {
  await api.delete(`/v1/responsables/${id}`);
}
