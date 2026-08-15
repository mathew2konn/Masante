/**
 * sos.ts — Déclenchement d'une alerte SOS (CdC FN1).
 *
 * PRINCIPE : c'est le TÉLÉPHONE qui alerte, pas notre serveur. L'appel au SAMU 185 et le SMS au
 * contact d'urgence passent par les liens natifs `tel:` et `sms:`, donc **sans données mobiles** —
 * exactement le cas que FN1 veut couvrir (« fonctionne offline via SMS si pas de data »). Le projet
 * n'a d'ailleurs aucune passerelle SMS.
 *
 * Les données du message viennent du CACHE de la carte vitale (5.1), jamais de l'API : demander au
 * serveur qui alerter supposerait un réseau que l'on n'a précisément pas.
 *
 * La journalisation côté serveur arrive APRÈS, en best-effort, et n'est jamais bloquante.
 */
import { Linking, Platform } from 'react-native';
import { CODE_SAMU, numeroUrgence } from './numerosUrgence';
import { SAMU_NUMERO_REPLI } from '../config/constants';
import { journaliserSos, type CanalSos } from '../api/sos';
import type { PositionUrgence } from '../utils/geoloc';
import type { ContactUrgenceVital, FicheVitale } from '../types/urgence';

/** Contact principal du membre, sinon le premier renseigné, sinon rien. */
export function contactAAlerter(fiche: FicheVitale | null): ContactUrgenceVital | null {
  if (!fiche || fiche.contacts_urgence.length === 0) return null;
  return fiche.contacts_urgence.find((c) => c.principal) ?? fiche.contacts_urgence[0];
}

/**
 * Corps du SMS d'urgence. Court et dense : il doit tenir dans un SMS et se lire d'un coup d'œil.
 * Le lien de carte permet au destinataire d'ouvrir la position directement.
 */
export function messageUrgence(fiche: FicheVitale | null, position: PositionUrgence | null): string {
  const lignes: string[] = ['URGENCE MaSante'];

  if (fiche) {
    const identite = [
      `${fiche.prenom} ${fiche.nom}`,
      fiche.age !== null ? `${fiche.age} ans` : null,
      fiche.groupe_sanguin,
    ]
      .filter(Boolean)
      .join(', ');
    lignes.push(identite);

    if (fiche.allergies.length > 0) lignes.push(`Allergies: ${fiche.allergies.join(', ')}`);

    if (fiche.maladies_chroniques.length > 0) {
      const chroniques = fiche.maladies_chroniques
        .map((m) => (m.traitement ? `${m.libelle} (${m.traitement})` : m.libelle))
        .join(', ');
      lignes.push(`Chroniques: ${chroniques}`);
    }
  }

  if (position) {
    lignes.push(`Position: ${position.lat.toFixed(5)}, ${position.lng.toFixed(5)}`);
    lignes.push(`https://www.openstreetmap.org/?mlat=${position.lat}&mlon=${position.lng}#map=18/${position.lat}/${position.lng}`);
  } else {
    lignes.push('Position indisponible');
  }

  return lignes.join('\n');
}

/**
 * Ouvre le composeur téléphonique sur le SAMU.
 *
 * P6.8e — le numéro vient du référentiel national (`numerosUrgence.ts`), plus d'une constante.
 * `?? SAMU_NUMERO_REPLI` n'est pas une seconde chaîne de repli : `numeroUrgence` garantit déjà une
 * valeur pour le SAMU. C'est la garde qui rend impossible d'ouvrir `tel:null` si ce module changeait
 * un jour de contrat — *sur un écran d'urgence, l'invariant vaut mieux que la confiance*.
 */
export async function appelerSamu(): Promise<void> {
  const numero = (await numeroUrgence(CODE_SAMU)) ?? SAMU_NUMERO_REPLI;

  await Linking.openURL(`tel:${numero}`);
}

/**
 * Ouvre l'application SMS avec le destinataire et le message pré-remplis. L'utilisateur garde la
 * main sur l'envoi : aucune application ne peut envoyer un SMS à son insu, et c'est heureux.
 *
 * Le séparateur du corps diffère selon la plate-forme (`&body=` sur iOS, `?body=` sur Android).
 */
export async function envoyerSmsUrgence(telephone: string, message: string): Promise<void> {
  const separateur = Platform.OS === 'ios' ? '&' : '?';
  await Linking.openURL(`sms:${telephone}${separateur}body=${encodeURIComponent(message)}`);
}

/**
 * Trace l'alerte côté serveur. Appelé après le déclenchement, ignoré s'il échoue (hors ligne).
 */
export async function tracerAlerte(
  canal: CanalSos,
  fiche: FicheVitale | null,
  position: PositionUrgence | null,
  contact: ContactUrgenceVital | null,
): Promise<void> {
  await journaliserSos({
    canal,
    membre_id: fiche?.membre_id,
    latitude: position?.lat,
    longitude: position?.lng,
    // Le serveur attend un entier de mètres ; le capteur donne un flottant.
    precision_metres: position?.precision != null ? Math.round(position.precision) : undefined,
    contact_prevenu_nom: contact?.nom,
    contact_prevenu_tel: contact?.telephone,
  });
}
