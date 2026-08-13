<?php

namespace App\Services\Referentiel;

use RuntimeException;

/**
 * Refus métier du socle référentiel, porteur de son code HTTP.
 *
 * La distinction 409 / 422 n'est pas cosmétique :
 *  - **409 Conflit** — l'état ne permet pas l'action (proposition déjà en cours, contenu modifié
 *    depuis la proposition, auteur qui tente de se valider lui-même). Réessayer à l'identique
 *    échouerait encore ; il faut d'abord que quelque chose change.
 *  - **422 Contenu invalide** — la donnée elle-même est refusée (contrôles qualité §10).
 *
 * C'est le même partage qu'en P7-C, où un refus de décision est un 409 (« décision impossible »)
 * et non un 403.
 */
class ReferentielException extends RuntimeException
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
            'Le contenu du référentiel ne satisfait pas les contrôles qualité : publication refusée.',
            422,
            $erreurs,
        );
    }
}
