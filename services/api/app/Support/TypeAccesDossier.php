<?php

namespace App\Support;

/**
 * Voies d'accès à un dossier — miroir PHP de `TypeAccesDossier` dans `@masante/shared`
 * (carnet familial partagé, incrément D2).
 *
 * Les valeurs sont celles de l'ENUM `acces_dossier.type_acces`, posé au Module 2 puis étendu au
 * Module 5. Elles ne changent pas : elles vivent aussi dans la permission `urgence.bris_de_glace`
 * et dans des modules validés G5. Seul l'AFFICHAGE change.
 *
 * POURQUOI CETTE CLASSE EXISTE. Le G0 de D2 a trouvé que les libellés vivaient en dur côté mobile
 * et avaient divergé de la base : `referent`, `delegation` et `bris_de_glace` n'y figuraient pas,
 * et un parent lisait « bris_de_glace » — valeur brute, tiret bas compris — dans son journal
 * d'accès. Le libellé est donc décidé ici, une fois, et servi au client.
 *
 * CE N'EST PAS DE LA LOGIQUE MÉTIER : c'est du vocabulaire. Aucun droit, aucune durée, aucune
 * décision n'en dépend — ces règles-là vivent dans les services (frontière CDC_01 §0.1).
 */
enum TypeAccesDossier: string
{
    /** Un agent a scanné le QR présenté par le patient (voie consentie, 30 min). */
    case QR_SCAN = 'qr_scan';

    /** Le médecin désigné référent du membre a ouvert le dossier. */
    case REFERENT = 'referent';

    /** Un proche à qui le carnet est partagé l'a consulté depuis son application (incrément A). */
    case DELEGATION = 'delegation';

    /** Urgence vitale : ouverture SANS consentement, périmètre vital, 15 min, motif obligatoire. */
    case BRIS_DE_GLACE = 'bris_de_glace';

    /** Accès exceptionnel d'un administrateur de la plateforme. */
    case ADMIN = 'admin';

    /**
     * B1-c — accès ouvert par LE MÉDECIN de ce rendez-vous précis, 30 minutes (D8, CDC_11 §9).
     *
     * Miroir de REFERENT (même mécanisme d'ouverture, même fenêtre de 30 minutes) mais jamais
     * permanent : la voie référent désigne un médecin une fois pour toutes ; celle-ci ne vaut que
     * pour LE rendez-vous qui l'a rendue possible (`rendez_vous_id`, posé sur la ligne — la seule
     * voie qui en porte un), et seulement une fois le patient enregistré à l'accueil.
     */
    case RDV_PARTAGE = 'rdv_partage';

    /**
     * Ce que lit le CITOYEN (décision propriétaire, 2026-08-12).
     *
     * « Bris de glace » est un terme de métier. « Accès d'urgence vitale » dit en trois mots
     * pourquoi personne n'a demandé son accord au patient — c'est tout ce qu'une famille doit
     * comprendre. Le portail professionnel, lui, garde le terme technique.
     */
    public function libelle(): string
    {
        return match ($this) {
            self::QR_SCAN       => 'Consultation après scan de votre QR',
            self::REFERENT      => 'Consultation par votre médecin référent',
            self::DELEGATION    => 'Consultation par un proche',
            self::BRIS_DE_GLACE => "Accès d'urgence vitale",
            self::ADMIN         => 'Accès administrateur MaSanté',
            self::RDV_PARTAGE   => 'Consultation pour votre rendez-vous',
        };
    }

    /**
     * Cette voie est-elle un passage en établissement ?
     *
     * `delegation` est la seule qui ne l'est pas : c'est un proche qui lit le carnet depuis son
     * téléphone, et il y en a une ligne PAR SECTION consultée. Les mêler aux visites noierait le
     * passage à l'hôpital sous les lectures familiales — et surtout, présenterait la lecture d'un
     * proche comme un acte de soin.
     */
    public function estVisite(): bool
    {
        return $this !== self::DELEGATION;
    }

    /** Les voies retenues par la fiche de parcours, en valeurs brutes (pour une clause `whereIn`). */
    public static function voiesDeVisite(): array
    {
        return array_map(
            static fn (self $type) => $type->value,
            array_filter(self::cases(), static fn (self $type) => $type->estVisite()),
        );
    }

    /** Libellé d'une valeur brute venue de la base — repli sur la valeur si elle est inconnue. */
    public static function libelleDe(?string $valeur): string
    {
        return self::tryFrom((string) $valeur)?->libelle() ?? (string) $valeur;
    }
}
