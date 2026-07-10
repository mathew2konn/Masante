/**
 * api/sos.ts — Journalisation des alertes SOS (CdC FN1, Module 5.2).
 *
 * BEST-EFFORT, JAMAIS BLOQUANT. L'alerte réelle (appel SAMU, SMS au contact) part du téléphone.
 * Cet appel n'arrive qu'APRÈS, et son échec est sans conséquence : sans réseau de données — le cas
 * même que FN1 veut couvrir — il n'aboutira pas, et c'est normal.
 */
import { api } from '../config/api';

export type CanalSos = 'appel' | 'sms' | 'appel_sms';

export interface AlerteSosPayload {
  membre_id?: number;
  latitude?: number;
  longitude?: number;
  precision_metres?: number;
  canal: CanalSos;
  contact_prevenu_nom?: string;
  contact_prevenu_tel?: string;
}

/**
 * Enregistre l'alerte côté serveur. N'échoue jamais : renvoie `true` si la trace est passée,
 * `false` sinon. Aucun appelant ne doit conditionner l'alerte à ce résultat.
 */
export async function journaliserSos(payload: AlerteSosPayload): Promise<boolean> {
  try {
    await api.post('/v1/sos', payload);
    return true;
  } catch {
    return false;
  }
}

/**
 * Alerte telle que le serveur l'a enregistrée (transparence, loi n°2013-450 : le patient doit
 * pouvoir consulter ce qui est conservé sur lui — ici, une position GPS).
 */
export interface AlerteSos {
  id: number;
  canal: CanalSos;
  latitude: number | null;
  longitude: number | null;
  precision_metres: number | null;
  contact_prevenu_nom: string | null;
  contact_prevenu_tel: string | null;
  declenchee_le: string;
  membre: { id: number; nom: string; prenom: string } | null;
}

/** Historique des alertes du compte (50 dernières), les plus récentes d'abord. */
export async function listerAlertesSos(): Promise<AlerteSos[]> {
  const { data } = await api.get<{ alertes: AlerteSos[] }>('/v1/sos');
  return data.alertes;
}
