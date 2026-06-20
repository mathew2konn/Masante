/**
 * api/qr.ts — partage sécurisé du dossier d'un membre (backend 2A.3).
 *
 * Réutilise le CLIENT AXIOS UNIQUE (token Bearer injecté). Deux opérations côté patient :
 *  - générer un QR dynamique (usage unique, 10 min) pour l'un de SES membres ;
 *  - consulter le journal d'accès au dossier (droit d'accès patient, §10.3 Sécurité).
 * Le scan (consommation) est une action d'agent prévue au Module 3.
 */
import { api } from '../config/api';
import type { AccesDossier, QrGenere } from '../types/qr';

/** Génère un QR dynamique pour un membre (201). */
export async function genererQr(membreId: number): Promise<QrGenere> {
  const { data } = await api.post<QrGenere>(`/v1/membres/${membreId}/qr`);
  return data;
}

/** Journal d'accès au dossier d'un membre (le plus récent d'abord). */
export async function listerAcces(membreId: number): Promise<AccesDossier[]> {
  const { data } = await api.get<{ acces: AccesDossier[] }>(`/v1/membres/${membreId}/acces`);
  return data.acces;
}
