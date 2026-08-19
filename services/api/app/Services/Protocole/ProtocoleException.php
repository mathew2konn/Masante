<?php

namespace App\Services\Protocole;

use RuntimeException;

/**
 * Refus métier du registre des protocoles, porteur de son code HTTP.
 *
 * Même partage qu'au socle référentiel (P6.3), et il n'est pas cosmétique :
 *  - **403** — l'appelant n'a pas l'habilitation (§10 « accès strictement réservé aux rôles
 *    habilités ») ;
 *  - **409 Conflit** — l'état ne permet pas l'action : brouillon déjà ouvert, validation manquante,
 *    contenu modifié depuis la relecture, rédacteur qui tente de publier son propre texte.
 *    Réessayer à l'identique échouerait encore ; il faut d'abord que quelque chose change ;
 *  - **422** — la donnée elle-même est refusée (contrôles qualité §7.4).
 *
 * Le 409 sur le quatre-yeux suit le précédent de P7-C, où un refus de décision est un 409
 * (« décision impossible ») et non un 403 : la personne a bien le droit de publier, c'est CETTE
 * publication-là qu'elle ne peut pas faire.
 */
class ProtocoleException extends RuntimeException
{
    /** @param array<int, string> $details */
    public function __construct(
        string $message,
        public readonly int $statut = 409,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<int, string> $erreurs */
    public static function qualite(array $erreurs): self
    {
        return new self(
            'Le protocole ne satisfait pas les contrôles techniques du §7.4 : publication refusée.',
            422,
            $erreurs,
        );
    }
}
