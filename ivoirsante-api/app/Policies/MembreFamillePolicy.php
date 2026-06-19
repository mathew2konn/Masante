<?php

namespace App\Policies;

use App\Models\MembreFamille;
use App\Models\User;

/**
 * Cloisonnement des données médicales (§4.3 Sécurité) — défense contre l'IDOR
 * (OWASP A01, Broken Access Control). Le middleware `auth:sanctum` prouve QUI agit ;
 * cette Policy vérifie l'APPARTENANCE : un utilisateur n'accède qu'à SES propres membres.
 *
 * L'accès par tiers (soignant via QR, médecin référent, admin) relève d'autres voies
 * tracées (§4.4) et sera implémenté aux étapes/modules suivants.
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

    /** Générer un QR de partage : seul le propriétaire du membre le peut (§5, §4.4). */
    public function generateQr(User $user, MembreFamille $membre): bool
    {
        return $membre->user_id === $user->id;
    }

    /** Consulter l'historique d'accès au dossier (droit d'accès patient, §10.3). */
    public function viewAcces(User $user, MembreFamille $membre): bool
    {
        return $membre->user_id === $user->id;
    }
}
