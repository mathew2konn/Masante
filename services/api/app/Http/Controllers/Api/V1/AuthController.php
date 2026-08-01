<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Module 2 / étape 2A.1 — Authentification par téléphone + OTP + Sanctum.
 *
 * Flux (doc Identification §4) : inscription (téléphone + mot de passe) → envoi OTP →
 * vérification OTP (active le compte « base » + délivre un token Bearer) ; connexions
 * suivantes par téléphone + mot de passe. OTP simulé en dev (jamais de SMS réel).
 *
 * Le token est révocable immédiatement (logout) — exigence forte pour une donnée de
 * santé (§3.1 Sécurité). La rotation refresh / access court (§3.2) est une amélioration
 * documentée, prévue ultérieurement.
 */
class AuthController extends Controller
{
    /** Durée de vie du token d'accès (jours) — court, révocable au logout. */
    private const TOKEN_TTL_JOURS = 1;

    public function __construct(private readonly OtpService $otp)
    {
    }

    /**
     * Inscription : crée le compte (non vérifié) et envoie un code OTP par SMS (simulé en dev).
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'telephone' => $data['telephone'],
            'nom'       => $data['nom'],
            'prenom'    => $data['prenom'],
            'password'  => $data['password'], // haché par le cast 'hashed'.
        ]);

        $code = $this->otp->generer($user->telephone, 'inscription', $user->id);
        $this->otp->enregistrerEnvoi($user->telephone);

        return response()->json([
            'message'  => 'Compte créé. Un code de vérification a été envoyé par SMS.',
            'user_id'  => $user->id,
            'but'      => 'inscription',
            ...$this->otpDeDev($code),
        ], 201);
    }

    /**
     * Renvoi d'un code OTP (inscription non finalisée ou récupération).
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'telephone' => ['required', 'string', 'regex:/^\+225[0-9]{10}$/'],
            'but'       => ['sometimes', 'in:inscription,connexion,recuperation'],
        ]);
        $but = $data['but'] ?? 'inscription';

        if ($this->otp->tropDEnvois($data['telephone'])) {
            abort(429, 'Trop de demandes de code. Réessayez dans une heure.');
        }

        // On ne révèle pas si le numéro existe (anti-énumération) : réponse identique.
        $user = User::where('telephone', $data['telephone'])->first();
        $code = $this->otp->generer($data['telephone'], $but, $user?->id);
        $this->otp->enregistrerEnvoi($data['telephone']);

        return response()->json([
            'message' => 'Si ce numéro est valide, un code vient d\'être envoyé.',
            'but'     => $but,
            ...$this->otpDeDev($code),
        ]);
    }

    /**
     * Vérifie le code OTP : active le téléphone et délivre un token d'accès (Bearer).
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $data = $request->validated();
        $but = $data['but'] ?? 'inscription';

        $this->otp->verifier($data['telephone'], $but, $data['code']);

        $user = User::where('telephone', $data['telephone'])->first();
        abort_if($user === null, 404, 'Compte introuvable pour ce numéro.');

        if (! $user->telephoneEstVerifie()) {
            $user->forceFill(['telephone_verified_at' => now()])->save();
        }

        return response()->json([
            'message' => 'Téléphone vérifié.',
            ...$this->reponseToken($user),
        ]);
    }

    /**
     * Connexion par téléphone + mot de passe (le compte doit être vérifié).
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::where('telephone', $data['telephone'])->first();

        // Message générique identique (anti-énumération des comptes).
        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'telephone' => ['Identifiants invalides.'],
            ]);
        }

        if (! $user->telephoneEstVerifie()) {
            abort(403, 'Compte non vérifié. Validez d\'abord le code reçu par SMS.');
        }

        return response()->json($this->reponseToken($user));
    }

    /**
     * Déconnexion : révoque immédiatement le token courant (§3.1 Sécurité).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté.']);
    }

    /**
     * Profil de l'utilisateur authentifié.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
    }

    /** Construit la charge utile commune { token, token_type, user } après authentification. */
    private function reponseToken(User $user): array
    {
        $token = $user->createToken(
            'mobile',
            ['*'],
            now()->addDays(self::TOKEN_TTL_JOURS),
        )->plainTextToken;

        return [
            'token'      => $token,
            'token_type' => 'Bearer',
            'user'       => $user,
        ];
    }

    /** En dev uniquement : expose le code OTP pour les tests (jamais en production). */
    private function otpDeDev(string $code): array
    {
        return app()->environment('local') ? ['dev_code_otp' => $code] : [];
    }
}
