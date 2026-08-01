import 'server-only';
import { authedFetch } from './api';
import type { RdvDetail, StaffRdv, StatutRdv } from './rdv-types';

/**
 * Lectures serveur de la file staff des RDV (portail Next). Proxy pur : la garde de permission
 * (`rdv.validate`) et le périmètre sont appliqués par l'API. `null`/403 remontés proprement.
 */

export type FileRdv = { rdvs: StaffRdv[]; interdit: boolean };

export async function getFileRdv(statut: StatutRdv): Promise<FileRdv> {
  try {
    const res = await authedFetch(`/v1/portail/rendez-vous?statut=${statut}`);
    if (res.status === 403) return { rdvs: [], interdit: true };
    if (!res.ok) return { rdvs: [], interdit: false };
    const data = (await res.json()) as { data?: StaffRdv[] };
    return { rdvs: data.data ?? [], interdit: false };
  } catch {
    return { rdvs: [], interdit: false };
  }
}

export async function getRdvDetail(id: number): Promise<RdvDetail | null> {
  try {
    const res = await authedFetch(`/v1/portail/rendez-vous/${id}`);
    if (!res.ok) return null;
    return (await res.json()) as RdvDetail;
  } catch {
    return null;
  }
}
