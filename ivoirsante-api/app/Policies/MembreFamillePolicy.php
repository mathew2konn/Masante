<?php

namespace App\Policies;

use App\Models\Delegation;
use App\Models\MembreFamille;
use App\Models\User;

/**
 * Cloisonnement des données médicales (§4.3 Sécurité) — défense contre l'IDOR
 * (OWASP A01, Broken Access Control). Le middleware `auth:sanctum` prouve QUI agit ;
 * cette Policy vérifie l'APPARTENANCE : un utilisateur n'accède qu'à SES propres membres.
 *
 * Exception ciblée : la génération de QR est aussi ouverte à un DÉLÉGUÉ actif (voie 3,
 * Note_Continuite chap. 4) — jamais la lecture/écriture du dossier, qui restent réservées
 * au propriétaire.
 */
class MembreFamillePolicy
{
    public function view(User $user, MembreFamille $membre): bool
    {
        return $membre->user_id === $user->id;
    }

    public function update(User $user, MembreFamille $membre): bool
    {
        return $membre->user_id === $user->id;
    }

    public function delete(User $user, MembreFamille $membre): bool
    {
        return $membre->user_id === $user->id;
    }

    /** Générer un QR de partage : le propriétaire OU un délégué actif sur ce membre (voie 3). */
    public function generateQr(User $user, MembreFamille $membre): bool
    {
        return $membre->user_id === $user->id
            || Delegation::actifPour($user->id, $membre->id);
    }

    /** Consulter l'historique d'accès au dossier (droit d'accès patient, §10.3). */
    public function viewAcces(User $user, MembreFamille $membre): bool
    {
        return $membre->user_id === $user->id;
    }
}
