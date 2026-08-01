<?php

namespace App\Services;

use App\Models\CodeOtp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * OtpService — génération et vérification des codes OTP par SMS (doc Identification §4).
 *
 * Règles de sécurité (§4.2) : code 6 chiffres aléatoire, valable 5 min, HACHÉ en base,
 * usage unique, max 5 tentatives, limitation d'envoi par numéro et par heure.
 *
 * En développement, AUCUNE passerelle SMS n'est branchée : le code est journalisé et
 * renvoyé par l'API (cf. AuthController) pour permettre les tests Postman. En production,
 * il serait remis à une passerelle Orange/MTN/Moov et jamais exposé.
 */
class OtpService
{
    private const LONGUEUR = 6;
    private const DUREE_MINUTES = 5;
    public const MAX_TENTATIVES = 5;
    private const MAX_ENVOIS_PAR_HEURE = 5;

    /**
     * Génère un nouveau code pour un téléphone et une finalité donnés, invalide les codes
     * actifs précédents, et renvoie le code EN CLAIR (à transmettre par SMS / exposer en dev).
     */
    public function generer(string $telephone, string $but, ?int $userId = null): string
    {
        // Un seul code valable à la fois : on neutralise les précédents non utilisés.
        CodeOtp::where('telephone', $telephone)
            ->where('but', $but)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), self::LONGUEUR, '0', STR_PAD_LEFT);

        CodeOtp::create([
            'user_id' => $userId,
            'telephone' => $telephone,
            'code_hash' => Hash::make($code),
            'but' => $but,
            'expires_at' => now()->addMinutes(self::DUREE_MINUTES),
        ]);

        // Simulation d'envoi en dev (trace serveur, jamais le code en prod).
        Log::info('OTP simulé (dev)', ['telephone' => $telephone, 'but' => $but, 'code' => $code]);

        return $code;
    }

    /** Le numéro a-t-il dépassé le quota d'envois de l'heure ? (anti-abus / coût SMS). */
    public function tropDEnvois(string $telephone): bool
    {
        return RateLimiter::tooManyAttempts($this->cleEnvoi($telephone), self::MAX_ENVOIS_PAR_HEURE);
    }

    /** Comptabilise un envoi (fenêtre glissante d'une heure). */
    public function enregistrerEnvoi(string $telephone): void
    {
        RateLimiter::hit($this->cleEnvoi($telephone), 3600);
    }

    /**
     * Vérifie un code saisi. Lève une erreur HTTP explicite (rendue en JSON pour /api/*)
     * en cas d'échec ; renvoie le CodeOtp consommé en cas de succès.
     */
    public function verifier(string $telephone, string $but, string $code): CodeOtp
    {
        $otp = CodeOtp::where('telephone', $telephone)
            ->where('but', $but)
            ->whereNull('used_at')
            ->latest()
            ->first();

        abort_if($otp === null, 422, 'Aucun code valide pour ce numéro. Demandez un nouveau code.');
        abort_if($otp->expires_at->isPast(), 422, 'Code expiré. Demandez un nouveau code.');
        abort_if($otp->tentatives >= self::MAX_TENTATIVES, 429, 'Trop de tentatives. Demandez un nouveau code.');

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('tentatives');
            abort(422, 'Code incorrect.');
        }

        $otp->update(['used_at' => now()]);

        return $otp;
    }

    private function cleEnvoi(string $telephone): string
    {
        return 'otp-send:'.$telephone;
    }
}
