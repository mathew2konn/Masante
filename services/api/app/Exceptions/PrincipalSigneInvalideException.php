<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Un principal signé entrant (canal interne, lot 6) n'a pas passé la vérification — en-têtes
 * absents, signature fausse, expiré, method/path non liés, ou nonce rejoué.
 *
 * Le message porte le motif PRÉCIS pour le journal, jamais pour la réponse HTTP : l'appelant ne
 * reçoit qu'un 401 vide (Phase 2 du lot 6 — un attaquant ne doit rien apprendre de la raison
 * exacte du refus).
 */
class PrincipalSigneInvalideException extends RuntimeException
{
}
