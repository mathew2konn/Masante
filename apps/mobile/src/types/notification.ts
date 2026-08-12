/**
 * types/notification.ts — notifications en application (incrément D1).
 *
 * Le TYPE vient de `@masante/shared` : c'est la source unique, miroir de l'énumération PHP
 * `App\Support\TypeNotification`. Le mobile ne le déduit jamais — il s'en sert uniquement pour
 * choisir une icône et un écran de destination.
 *
 * Rien de ce qui arrive ici n'est un contenu médical : le backend compose des phrases qui nomment
 * la personne et l'acte, jamais le fait clinique (règle posée au G1).
 */
import { TypeNotification } from '@masante/shared';

export type Notification = {
  id: string;
  type: TypeNotification;
  lue: boolean;
  creee_a: string;
  donnees: {
    titre: string;
    corps: string;
    membre_id?: number;
    contribution_id?: number;
    delegation_id?: number;
    responsable_id?: number;
    /** Vrai pour un bris de glace : accès ouvert SANS le consentement du titulaire. */
    urgent?: boolean;
    /** Le carnet partagé serait celui du délégué → l'écran de revendication l'attend. */
    revendicable?: boolean;
    [cle: string]: unknown;
  };
};

/** Les seules destinations qu'une notification peut ouvrir — union fermée, pas un `string`. */
export type RouteNotification =
  | '/(app)/contributions'
  | '/(app)/revendiquer-carnet'
  | '/(app)/partages'
  | '/(app)/carnet';

/**
 * L'écran vers lequel ouvrir à l'appui.
 *
 * C'est de la NAVIGATION, pas du métier : aucune règle n'est calculée ici, on choisit une route
 * à partir d'un type que le serveur a décidé.
 */
export function routeDe(notification: Notification): RouteNotification {
  switch (notification.type) {
    case TypeNotification.CONTRIBUTION_DEPOSEE:
      return '/(app)/contributions';
    case TypeNotification.DELEGATION_RECUE:
      return notification.donnees.revendicable
        ? '/(app)/revendiquer-carnet'
        : '/(app)/partages';
    case TypeNotification.RESPONSABLE_DESIGNE:
      return '/(app)/contributions';
    case TypeNotification.CONTRIBUTION_VALIDEE:
    case TypeNotification.CONTRIBUTION_REJETEE:
    case TypeNotification.DOSSIER_CONSULTE:
    default:
      return '/(app)/carnet';
  }
}

/** Icône Ionicons associée au type — présentation pure. */
export function iconeDe(type: TypeNotification): string {
  switch (type) {
    case TypeNotification.CONTRIBUTION_DEPOSEE:
      return 'document-text-outline';
    case TypeNotification.CONTRIBUTION_VALIDEE:
      return 'checkmark-circle-outline';
    case TypeNotification.CONTRIBUTION_REJETEE:
      return 'close-circle-outline';
    case TypeNotification.DELEGATION_RECUE:
      return 'people-outline';
    case TypeNotification.RESPONSABLE_DESIGNE:
      return 'shield-checkmark-outline';
    case TypeNotification.DOSSIER_CONSULTE:
      return 'eye-outline';
    default:
      return 'notifications-outline';
  }
}
