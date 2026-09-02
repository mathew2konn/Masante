<?php

namespace App\Support;

use App\Models\RendezVous;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;

/**
 * B1-c — autorisation du canal `rdv.{id}.presence` (D9), extraite de `routes/channels.php` pour
 * rester TESTABLE sans dépendre du mécanisme HTTP d'authentification d'un broadcaster réel (les
 * pilotes `null`/`log`, seuls utilisables en test — {@see NullBroadcaster} —
 * n'implémentent PAS `auth()`, un test passant par la route HTTP ne prouverait donc rien).
 *
 * « LE SEUL TITULAIRE/PATIENT CONCERNÉ » (D9), PAS LES DÉLÉGUÉS EN LECTURE (P7-A) : décision,
 * pas oubli — la présence en direct d'un soignant au chevet d'un proche est plus sensible qu'une
 * simple lecture de dossier.
 */
class AutorisationCanalPresenceRdv
{
    public static function verifier(User $user, int $rdvId): bool
    {
        $rdv = RendezVous::find($rdvId);

        return $rdv !== null && $rdv->membre?->user_id === $user->id;
    }
}
