<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\ActivationPortail;
use App\Rules\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Module 4 / 4.2 — Activation d'un compte staff du portail (CdC §5.4.1, étape 3, ACCÈS PUBLIC).
 *
 * Le titulaire (gestionnaire, puis agents en 4.3) clique le lien reçu et POSE lui-même son mot de
 * passe : il n'a jamais existé de mot de passe temporaire (sécurité renforcée). Le jeton est à usage
 * unique et expire en 24h ; on ne compare que son HASH. Le mot de passe suit la politique unique du
 * projet ({@see PasswordPolicy}). Anti-bruteforce via `throttle:login` sur la route POST.
 */
class ActivationController extends Controller
{
    public function show(string $token): View
    {
        $activation = $this->activationValide($token);

        return view('portail.activation.set-password', [
            'token'   => $token,
            'valide'  => $activation !== null,
            'user'    => $activation?->user,
        ]);
    }

    public function activate(Request $request, string $token): RedirectResponse
    {
        $activation = $this->activationValide($token);

        if ($activation === null) {
            return redirect()
                ->route('portail.activation.show', ['token' => $token])
                ->withErrors(['token' => 'Lien invalide ou expiré. Demandez un nouveau lien à l\'administrateur.']);
        }

        $request->validate([
            'password' => ['required', 'confirmed', ...PasswordPolicy::regles()],
        ]);

        DB::transaction(function () use ($activation, $request) {
            $activation->user->forceFill([
                'password'          => $request->input('password'), // haché via cast `hashed`
                'email_verified_at' => $activation->user->email_verified_at ?? now(),
            ])->save();

            $activation->update(['used_at' => now()]); // consommation → usage unique
        });

        return redirect()
            ->route('portail.login')
            ->with('statut', 'Compte activé. Vous pouvez maintenant vous connecter.');
    }

    /** Retourne l'activation encore utilisable pour ce jeton, ou null (invalide/expiré/consommé). */
    private function activationValide(string $token): ?ActivationPortail
    {
        $activation = ActivationPortail::with('user')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        return $activation && $activation->estValide() ? $activation : null;
    }
}
