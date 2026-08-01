<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\VerifyResetOtpRequest;
use App\Models\User;
use App\Services\OtpService;
use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Phase B / B1 — Récupération et changement de mot de passe (modification.txt « Mot de passe oublié »
 * + note Securite_IVOIRSANTE_2, chap. 4 : défense en profondeur, menace « téléphone en main »).
 *
 * Trois étapes publiques (forgot → verify-otp → reset), séparées pour qu'aucune requête ne saisisse
 * à la fois le code et le nouveau mot de passe. Un quatrième endpoint (change) sert l'utilisateur
 * connecté. Réutilise l'infrastructure OTP existante (OtpService, but='recuperation').
 */
class PasswordController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly PasswordResetService $reset,
    ) {
    }

    /**
     * Étape 1 — Demande de réinitialisation. Réponse identique que le numéro existe ou non
     * (anti-énumération) ; l'OTP n'est réellement généré que pour un compte existant.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $telephone = $request->validated()['telephone'];
        $user = User::where('telephone', $telephone)->first();

        $code = null;
        if ($user !== null && ! $this->otp->tropDEnvois($telephone)) {
            $code = $this->otp->generer($telephone, 'recuperation', $user->id);
            $this->otp->enregistrerEnvoi($telephone);
        }

        return response()->json([
            'message' => 'Si ce numéro est enregistré, un code de réinitialisation vient d\'être envoyé.',
            // En dev uniquement, et seulement si un code a été généré (sinon on divulguerait l'inexistence).
            ...($code !== null ? $this->otpDeDev($code) : []),
        ]);
    }

    /**
     * Étape 2 — Vérification de l'OTP + preuve durcie. En cas de succès, délivre un jeton
     * de réinitialisation à usage unique (~10 min).
     */
    public function verifyOtp(VerifyResetOtpRequest $request): JsonResponse
    {
        $data = $request->validated();

        // 1) L'OTP doit être valide (usage unique, non expiré, max 5 tentatives) — géré par OtpService.
        $this->otp->verifier($data['telephone'], 'recuperation', $data['code']);

        $user = User::where('telephone', $data['telephone'])->first();
        abort_if($user === null, 422, 'Aucun code valide pour ce numéro. Demandez un nouveau code.');

        // 2) Preuve durcie (date de naissance au palier base ; branche CMU/CNI dormante).
        $this->reset->verifierPreuveDurcie($user, $data);

        // 3) Jeton intermédiaire prouvant le franchissement des deux barrières.
        return response()->json([
            'message'     => 'Vérification réussie. Définissez votre nouveau mot de passe.',
            'reset_token' => $this->reset->delivrerJeton($user),
            'expire_dans_minutes' => 10,
        ]);
    }

    /**
     * Étape 3 — Nouveau mot de passe via le jeton. Applique la politique MDP, ré-hache, puis
     * RÉVOQUE TOUS les tokens Sanctum du compte (les sessions volées deviennent inutilisables).
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $this->reset->consommerJeton($data['reset_token']);

        $user->forceFill(['password' => $data['password']])->save(); // haché par le cast 'hashed'.
        $user->tokens()->delete();                                    // révocation globale.

        $this->journaliserChangement($user, 'reinitialisation');

        return response()->json([
            'message' => 'Mot de passe réinitialisé. Vous pouvez maintenant vous connecter.',
        ]);
    }

    /**
     * Changement volontaire par l'utilisateur connecté : ancien mot de passe requis, pas d'OTP.
     * Révoque les AUTRES sessions ; conserve la session courante.
     */
    public function change(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Le mot de passe actuel est incorrect.'],
            ]);
        }

        $user->forceFill(['password' => $data['password']])->save();

        // Conserve le token courant, révoque tous les autres.
        $user->tokens()
            ->where('id', '!=', $user->currentAccessToken()->id)
            ->delete();

        $this->journaliserChangement($user, 'changement');

        return response()->json(['message' => 'Mot de passe modifié.']);
    }

    /**
     * Notification + trace d'audit (FT6, loi n°2013-450). Le mailer n'est pas branché en dev et le
     * journal d'audit global n'est pas encore implémenté : on journalise (stub documenté au RAPPORT).
     */
    private function journaliserChangement(User $user, string $type): void
    {
        Log::info('Mot de passe modifié', [
            'user_id' => $user->id,
            'type'    => $type, // reinitialisation | changement
            'at'      => now()->toIso8601String(),
        ]);
        // TODO (module Audit / Notifications) : e-mail « Votre mot de passe a été modifié le… » si e-mail présent.
    }

    /** En dev uniquement : expose le code OTP pour les tests (jamais en production). */
    private function otpDeDev(string $code): array
    {
        return app()->environment('local') ? ['dev_code_otp' => $code] : [];
    }
}
