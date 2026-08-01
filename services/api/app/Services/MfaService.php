<?php

namespace App\Services;

use App\Models\MfaFacteur;
use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

/**
 * MfaService — enrôlement, confirmation et vérification du second facteur (TOTP, RFC 6238).
 *
 * Frontière métier (CDC_01 §0.1) : la décision « MFA requis à la connexion » vit ICI, jamais
 * dans le front. Le front affiche un statut fourni, saisit un code, mais ne décide de rien.
 *
 * Sécurité (CDC_10 §3.5) :
 *  - secret chiffré au repos (cast du modèle) ;
 *  - anti-rejeu strict : chaque code n'est accepté qu'une fois (tranche horaire mémorisée) ;
 *  - un facteur ne compte que CONFIRMÉ (premier code validé après l'enrôlement).
 */
class MfaService
{
    public function __construct(private readonly Google2FA $google2fa)
    {
    }

    /**
     * Démarre l'enrôlement TOTP : génère un secret, l'enregistre NON confirmé, et renvoie de quoi
     * afficher le QR côté client (URI otpauth://). Le secret n'est exposé qu'ici, une seule fois.
     *
     * @return array{type: string, secret: string, otpauth_uri: string}
     */
    public function enroll(User $user, string $type = 'totp'): array
    {
        abort_unless($type === 'totp', 422, 'Seul le facteur TOTP est disponible pour le moment.');

        $secret = $this->google2fa->generateSecretKey();

        MfaFacteur::updateOrCreate(
            ['user_id' => $user->id, 'type' => 'totp'],
            ['secret' => $secret, 'confirmed_at' => null, 'last_used_at' => null, 'last_timeslice' => null],
        );

        return [
            'type' => 'totp',
            'secret' => $secret,
            'otpauth_uri' => $this->google2fa->getQRCodeUrl(
                (string) config('mfa.issuer'),
                $user->telephone,
                $secret,
            ),
        ];
    }

    /** Confirme l'enrôlement : le premier code valide « active » le facteur. */
    public function confirm(User $user, string $code): void
    {
        $facteur = $user->mfaFacteurs()
            ->where('type', 'totp')
            ->whereNull('confirmed_at')
            ->first();

        abort_if($facteur === null, 422, 'Aucune configuration TOTP en attente. Lancez d\'abord l\'enrôlement.');

        $timeslice = $this->verifierCode($facteur, $code);
        $facteur->update([
            'confirmed_at' => now(),
            'last_used_at' => now(),
            'last_timeslice' => $timeslice,
        ]);
    }

    /** Vérifie un code lors de la connexion (facteur déjà confirmé). Lève 422 si invalide/rejoué. */
    public function verify(User $user, string $code): void
    {
        $facteur = $this->facteurConfirme($user);
        abort_if($facteur === null, 422, 'Aucun second facteur confirmé pour ce compte.');

        $timeslice = $this->verifierCode($facteur, $code);
        $facteur->update(['last_used_at' => now(), 'last_timeslice' => $timeslice]);
    }

    /** Le compte DOIT-il présenter un 2e facteur à cette connexion ? (gate + rôle pro + facteur prêt). */
    public function estRequis(User $user): bool
    {
        return (bool) config('mfa.enforce')
            && $user->hasAnyRole((array) config('mfa.roles_obligatoires'))
            && $this->facteurConfirme($user) !== null;
    }

    /** Le compte est soumis à l'obligation mais n'a pas encore configuré son facteur (front → onboarding). */
    public function doitConfigurer(User $user): bool
    {
        return (bool) config('mfa.enforce')
            && $user->hasAnyRole((array) config('mfa.roles_obligatoires'))
            && $this->facteurConfirme($user) === null;
    }

    public function facteurConfirme(User $user): ?MfaFacteur
    {
        return $user->mfaFacteurs()
            ->where('type', 'totp')
            ->whereNotNull('confirmed_at')
            ->first();
    }

    /**
     * Vérifie un code contre le secret du facteur en refusant tout code déjà consommé (anti-rejeu).
     * Renvoie la tranche horaire validée (à mémoriser) ; lève 422 si le code est faux ou rejoué.
     */
    private function verifierCode(MfaFacteur $facteur, string $code): int
    {
        // Borne basse (jamais null) : refuse toute tranche déjà consommée. Important : avec un
        // oldTimestamp non nul, google2fa renvoie la TRANCHE validée (entier) ; avec null il
        // renverrait `true`, ce qui casserait la mémorisation anti-rejeu. D'où le `?? 0`.
        $timeslice = $this->google2fa->verifyKeyNewer(
            (string) $facteur->secret,
            $code,
            (int) ($facteur->last_timeslice ?? 0),
            (int) config('mfa.fenetre'),
        );

        abort_if($timeslice === false, 422, 'Code de vérification incorrect.');

        return (int) $timeslice;
    }
}
