<?php

namespace App\Support;

/**
 * Types de notification en application (carnet familial partagé, incrément D1).
 *
 * Miroir PHP de `TypeNotification` dans `@masante/shared` : toute valeur ajoutée ici doit l'être
 * là-bas. Le mobile ne s'en sert que pour choisir une icône et un écran de destination — il ne
 * déduit jamais un type, il l'affiche.
 *
 * RAPPEL DE LA RÈGLE POSÉE AU G1 : le titre et le corps produits ici ne contiennent AUCUN contenu
 * médical. « Aya a proposé un ajout au carnet de Koffi Eli », jamais « fièvre 39 ». Ces textes
 * atterrissent sur des écrans verrouillés et transitent par un service tiers.
 */
enum TypeNotification: string
{
    case CONTRIBUTION_DEPOSEE = 'CONTRIBUTION_DEPOSEE';
    case CONTRIBUTION_VALIDEE = 'CONTRIBUTION_VALIDEE';
    case CONTRIBUTION_REJETEE = 'CONTRIBUTION_REJETEE';
    case DELEGATION_RECUE = 'DELEGATION_RECUE';
    case RESPONSABLE_DESIGNE = 'RESPONSABLE_DESIGNE';
    case DOSSIER_CONSULTE = 'DOSSIER_CONSULTE';
    case CARNET_ENRICHI = 'CARNET_ENRICHI';
    /**
     * P6.8b — une échéance du calendrier vaccinal national est atteinte, ou dépassée.
     *
     * LA RÈGLE INVIOLABLE MORD ICI. Le corps dit qu'une vaccination est due ; il ne dit JAMAIS
     * laquelle. Le nom d'un vaccin est une information de santé — il révèle une pathologie visée,
     * parfois un âge, parfois une situation — et cette phrase s'affiche sur un écran verrouillé
     * avant de transiter par un tiers. Le détail se lit dans l'application, après authentification.
     */
    case ECHEANCE_VACCINALE = 'ECHEANCE_VACCINALE';

    /**
     * Lot 9 (post-facturation) — une facture patient est émise ou relancée (§2.7 : ni acte, ni
     * service, ni spécialité, ni établissement — montant et libellé générique seulement).
     */
    case FACTURE_PATIENT_EMISE = 'FACTURE_PATIENT_EMISE';
    case FACTURE_PATIENT_RELANCE = 'FACTURE_PATIENT_RELANCE';

    /**
     * Lot 9 — alertes INTERNES au back-office MaSanté, jamais envoyées à un patient. Contrairement
     * aux deux ci-dessus, elles PEUVENT nommer l'établissement : ce n'est pas une notification
     * patient, la restriction du §2.7 ne s'y applique pas.
     */
    case STRUCTURE_SUSPENDUE_IMPAYE = 'STRUCTURE_SUSPENDUE_IMPAYE';
    case STRUCTURE_REACTIVEE = 'STRUCTURE_REACTIVEE';

    /**
     * P10c-3-i (F19) — un modèle IA candidat attend une revue de gouvernance (CDC_05 §8/§9).
     *
     * Interne au back-office, jamais envoyée à un patient : le corps NOMME un numéro de version,
     * jamais une métrique (les métriques se lisent à l'écran, après authentification et
     * habilitation `ia_triage.valider` — même prudence que §2.7 pour la facturation, transposée à
     * une donnée de gouvernance IA plutôt qu'à un contenu clinique).
     */
    case MODELE_IA_CANDIDAT = 'MODELE_IA_CANDIDAT';

    /** P10c-3-ii lot B — une dérive constatée sur le modèle EN SERVICE. Prévient, ne décide pas. */
    case DERIVE_MODELE_IA = 'DERIVE_MODELE_IA';

    /**
     * B1-d (D15) — le rendez-vous est clos (`honore`). Même garde-fou de contenu que
     * `FACTURE_PATIENT_EMISE`/`_RELANCE` (§2.7) : la facture existait déjà avant la clôture
     * (B1-c a rendu le paiement préalable au check-in), donc cette notification n'annonce PAS une
     * facture nouvelle — elle confirme la fin de la consultation et rappelle le montant déjà réglé.
     */
    case RENDEZ_VOUS_TERMINE = 'RENDEZ_VOUS_TERMINE';

    /**
     * B3-d — une commande de médicaments a changé d'état. Même garde-fou que
     * `ECHEANCE_VACCINALE` : le corps dit qu'une commande a changé d'état, jamais ce qu'elle
     * contient — un nom de médicament désigne une pathologie, et cette phrase s'affiche sur un
     * écran verrouillé avant de transiter par un tiers. Le détail se lit dans l'application.
     */
    case COMMANDE_ACCEPTEE = 'COMMANDE_ACCEPTEE';
    case COMMANDE_REFUSEE = 'COMMANDE_REFUSEE';
    case COMMANDE_PRETE = 'COMMANDE_PRETE';

    /**
     * B5-c (L14) — un résultat d'analyse a été publié dans le carnet par le circuit du
     * laboratoire. Le corps NOMME le laboratoire, JAMAIS l'intitulé de l'analyse ni une valeur :
     * un push s'affiche sur un écran verrouillé, et le nom d'une analyse désigne une pathologie
     * (patron P6.8b, « une vaccination est due, jamais laquelle »).
     */
    case RESULTAT_ANALYSE_PUBLIE = 'RESULTAT_ANALYSE_PUBLIE';

    /**
     * Le titre court affiché en tête de la notification (et sur la bannière push).
     */
    public function titre(): string
    {
        return match ($this) {
            self::CONTRIBUTION_DEPOSEE => 'Un ajout attend votre validation',
            self::CONTRIBUTION_VALIDEE => 'Ajout validé',
            self::CONTRIBUTION_REJETEE => 'Ajout refusé',
            self::DELEGATION_RECUE => 'Un carnet vous a été partagé',
            self::RESPONSABLE_DESIGNE => 'Vous êtes responsable de famille',
            self::DOSSIER_CONSULTE => 'Dossier consulté',
            self::CARNET_ENRICHI => 'Nouvel élément au carnet',
            self::ECHEANCE_VACCINALE => 'Vaccination à prévoir',
            self::FACTURE_PATIENT_EMISE => 'Nouvelle facture',
            self::FACTURE_PATIENT_RELANCE => 'Facture en attente de règlement',
            self::STRUCTURE_SUSPENDUE_IMPAYE => 'Structure suspendue pour impayé',
            self::STRUCTURE_REACTIVEE => 'Structure réactivée',
            self::MODELE_IA_CANDIDAT => 'Modèle IA candidat à revoir',
            self::DERIVE_MODELE_IA => 'Dérive constatée sur le modèle IA',
            self::RENDEZ_VOUS_TERMINE => 'Rendez-vous terminé',
            self::COMMANDE_ACCEPTEE => 'Commande acceptée',
            self::COMMANDE_REFUSEE => 'Commande refusée',
            self::COMMANDE_PRETE => 'Commande prête',
            self::RESULTAT_ANALYSE_PUBLIE => 'Résultat d\'analyse disponible',
        };
    }
}
