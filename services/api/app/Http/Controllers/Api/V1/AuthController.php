<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Models\User;
use App\Services\MfaService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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

    public function __construct(
        private readonly OtpService $otp,
        private readonly MfaService $mfa,
    ) {
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

        // P1 — rôle par défaut de tout compte citoyen (RBAC, CDC_10 §3.6). Requiert RoleSeeder.
        $user->assignRole('patient');

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

        // MFA « prêt à activer » (CDC_10 §3.5) : si le compte doit présenter un 2e facteur,
        // on ne délivre PAS encore le token — on renvoie un défi à vérifier. Gate off ⇒ inchangé.
        if ($this->mfa->estRequis($user)) {
            return response()->json($this->defiMfa($user));
        }

        return response()->json($this->reponseToken($user));
    }

    /**
     * Deuxième étape de connexion : vérifie le code du second facteur pour le compte mis au défi,
     * puis délivre le token d'accès. Le jeton de défi ne survit que quelques minutes (config).
     */
    public function verifyMfa(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mfa_token' => ['required', 'string'],
            'code'      => ['required', 'string'],
        ]);

        // Lecture (sans consommer) : un code faux laisse le défi valide pour un nouvel essai
        // (le limiteur throttle:login borne les tentatives). Le défi n'est purgé qu'au succès.
        $userId = Cache::get($this->cleDefiMfa($data['mfa_token']));
        abort_if($userId === null, 422, 'Session de vérification expirée. Reconnectez-vous.');

        $user = User::findOrFail($userId);
        $this->mfa->verify($user, $data['code']);

        Cache::forget($this->cleDefiMfa($data['mfa_token']));

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
        return response()->json(['user' => $this->userPayload($request->user())]);
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
            'user'       => $this->userPayload($user),
        ];
    }

    /**
     * Charge utile utilisateur exposée au front : attributs visibles + rôles + PERMISSIONS.
     * Les deux viennent du backend (autorité) ; le front les affiche, ne les déduit jamais.
     *
     * ═══ P11.0 — POURQUOI LES PERMISSIONS ENTRENT ICI ═══
     *
     * Le portail garde ses routes sur des PERMISSIONS (`dossier.ecrire`, `rdv.validate`,
     * `protocole.publier`…), et quatorze d'entre elles n'appartiennent délibérément à aucun
     * rôle — elles sont accordées nominativement. Or cette charge utile ne renvoyait que les
     * rôles. Le front était donc **structurellement incapable de reproduire les gardes du
     * backend** : il ne pouvait qu'afficher un menu au jugé, et laisser l'utilisateur découvrir
     * par un 403 ce qu'il n'avait pas le droit de faire.
     *
     * CE N'EST PAS UN DÉPLACEMENT D'AUTORITÉ. La décision reste entièrement au backend, qui
     * revérifie à chaque requête ; le front s'en sert uniquement pour **n'afficher que ce qui
     * est atteignable**. C'est exactement la défense en profondeur du module fraude (ADR-020
     * §B2), où Next vérifie le rôle avant de signer un principal que le paiement revérifie.
     *
     * Elles sont renvoyées à plat (`getAllPermissions`), donc rôles ET attributions
     * nominatives confondus : la distinction intéresse celui qui administre les comptes, pas
     * celui qui affiche un menu.
     *
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            ...$user->toArray(),
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            // P6.1 (ADR-021 §2.1) — le BACKEND dit si le dossier de santé du titulaire existe ;
            // le mobile ne le déduit jamais de la liste des membres (règle de frontière).
            'a_dossier_titulaire' => $user->membresFamille()->where('est_titulaire', true)->exists(),
        ];
    }

    /**
     * Construit un défi MFA : jeton opaque à courte durée de vie, associé au compte en cache.
     * Aucun token Bearer n'est délivré tant que le second facteur n'est pas vérifié.
     *
     * @return array<string, mixed>
     */
    private function defiMfa(User $user): array
    {
        $mfaToken = Str::random(64);
        Cache::put(
            $this->cleDefiMfa($mfaToken),
            $user->id,
            now()->addMinutes((int) config('mfa.defi_ttl_minutes')),
        );

        return [
            'mfa_required' => true,
            'mfa_token'    => $mfaToken,
            'message'      => 'Vérification en deux étapes requise. Saisissez le code de votre application.',
        ];
    }

    private function cleDefiMfa(string $mfaToken): string
    {
        return 'mfa-defi:'.hash('sha256', $mfaToken);
    }

    /** En dev uniquement : expose le code OTP pour les tests (jamais en production). */
    private function otpDeDev(string $code): array
    {
        return app()->environment('local') ? ['dev_code_otp' => $code] : [];
    }
}
