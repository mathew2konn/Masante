<?php

namespace App\Services;

use App\Models\PasswordResetGrant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PasswordResetService — récupération de mot de passe durcie (modification.txt « Mot de passe oublié »
 * + note Securite_IVOIRSANTE_2, chap. 4 : défense en profondeur contre la menace « téléphone en main »).
 *
 * L'OTP par SMS ne protège pas d'un attaquant qui DÉTIENT l'appareil (il reçoit lui-même le code).
 * On exige donc, en plus de l'OTP, une PREUVE que l'attaquant occasionnel ne connaît pas, graduée
 * selon le palier du compte. Puis on délivre un jeton intermédiaire à usage unique échangé au `reset`.
 */
class PasswordResetService
{
    private const GRACE_MINUTES = 10;

    /**
     * Vérifie la preuve durcie exigée en plus de l'OTP (étape verify-otp).
     * Lève une erreur HTTP 422 générique en cas d'échec (aucune fuite sur le champ fautif).
     *
     * @param array<string, mixed> $donnees Charge utile validée (peut porter `date_naissance`).
     */
    public function verifierPreuveDurcie(User $user, array $donnees): void
    {
        // Palier « vérifié » (CMU/CNI) → fragment d'identité. BRANCHE DORMANTE : aucun flux de
        // vérification ne pose encore `compte_verifie_at` ni ne stocke le n° CMU/CNI sur `users`.
        // Elle s'activera avec le module d'identité confirmée (le n° sera comparé sur ses 4 derniers).
        if ($user->compteEstVerifie()) {
            abort(422, 'Vérification indisponible pour ce compte. Contactez le support.');
        }

        // Palier « base » → date de naissance exacte du titulaire, si elle est renseignée.
        if ($user->date_naissance !== null) {
            $fournie = $donnees['date_naissance'] ?? null;
            abort_if($fournie === null, 422, 'La date de naissance est requise pour vérifier votre identité.');
            abort_if(
                ! $user->date_naissance->isSameDay(Carbon::parse($fournie)),
                422,
                'Les informations de vérification ne correspondent pas.',
            );
            return;
        }

        // Aucune donnée de preuve enregistrée (profil incomplet) : l'OTP a fait foi.
        // LIMITATION DOCUMENTÉE (RAPPORT) : la menace « téléphone en main » n'est pleinement
        // couverte qu'une fois la date de naissance renseignée ; l'app incite à compléter le profil.
    }

    /**
     * Délivre un jeton de réinitialisation à usage unique (~10 min). Renvoie le jeton EN CLAIR
     * (à transmettre au client) ; seule son empreinte SHA-256 est stockée.
     */
    public function delivrerJeton(User $user): string
    {
        // Un seul jeton actif : on neutralise les précédents non consommés.
        PasswordResetGrant::where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $token = Str::random(64);

        PasswordResetGrant::create([
            'user_id' => $user->id,
            'telephone' => $user->telephone,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::GRACE_MINUTES),
        ]);

        return $token;
    }

    /**
     * Consomme un jeton de réinitialisation valide et renvoie l'utilisateur ciblé.
     * Lève une erreur HTTP 422 si le jeton est inconnu, expiré ou déjà utilisé.
     */
    public function consommerJeton(string $token): User
    {
        $grant = PasswordResetGrant::where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')
            ->latest()
            ->first();

        abort_if($grant === null, 422, 'Jeton de réinitialisation invalide ou déjà utilisé.');
        abort_if($grant->expires_at->isPast(), 422, 'Jeton de réinitialisation expiré. Reprenez la procédure.');

        $grant->update(['used_at' => now()]);

        return $grant->user;
    }
}
