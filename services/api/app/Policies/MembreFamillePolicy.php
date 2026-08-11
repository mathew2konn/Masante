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
 * Exceptions ciblées, toutes portées par une DÉLÉGATION acceptée et révocable :
 *  - `generateQr` : tout délégué actif (voie 3, Note_Continuite chap. 4) ;
 *  - `view` : délégué actif portant `lecture` ou `lecture_ecriture` (carnet familial partagé).
 *
 * L'ÉCRITURE (`update`, `delete`) et l'HISTORIQUE D'ACCÈS (`viewAcces`) restent strictement
 * réservés au propriétaire. Un délégué peut voir le dossier, jamais le modifier ni savoir qui
 * d'autre l'a consulté.
 */
class MembreFamillePolicy
{
    /**
     * Lecture du dossier — propriétaire, OU délégué actif avec droit de lecture.
     *
     * PORTÉE DE CETTE MÉTHODE : elle gouverne TOUTES les lectures du carnet (antécédents,
     * vaccinations, ordonnances, résultats, documents, mesures, grossesse, photo, carte CMU,
     * fiche vitale, NIS, référent) — chaque contrôleur de section appelle `authorize('view')`.
     * C'est voulu : le partage familial n'aurait aucun sens s'il fallait l'ouvrir section par
     * section. Mais c'est aussi le point le plus sensible du projet : élargir cette méthode
     * élargit l'accès à tout le dossier médical d'une personne.
     *
     * Trois garde-fous encadrent cet élargissement :
     *  1. la délégation doit avoir été ACCEPTÉE par le délégué (consentement explicite) ;
     *  2. elle est révocable à tout moment par l'un ou l'autre, avec effet immédiat ;
     *  3. chaque lecture déléguée est journalisée nominativement dans `acces_dossier`
     *     (middleware JournaliserAccesDelegue) — loi 2013-450, Sécurité §10.
     */
    public function view(User $user, MembreFamille $membre): bool
    {
        return $membre->user_id === $user->id
            || Delegation::lecturePour($user->id, $membre->id);
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
