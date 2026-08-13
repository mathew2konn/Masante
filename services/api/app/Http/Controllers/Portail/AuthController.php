<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Services\SessionDossierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Module 4 / 4.1 — Authentification du portail administratif (Sécurité §3-§4).
 *
 * Login navigateur par email + mot de passe (guard `web`, à sessions), DISTINCT de l'auth mobile
 * (téléphone + OTP + Sanctum, stateless). Seuls les comptes STAFF (porteurs d'un rôle portail)
 * peuvent entrer : un compte patient (sans rôle) est refusé même avec des identifiants valides.
 */
class AuthController extends Controller
{
    /**
     * Rôles autorisés à accéder au portail.
     *
     * P6.5a — `medecin` rejoint la liste (décision propriétaire P5). Ce rôle était créé par le
     * `RoleSeeder` de P1 et n'était utilisable NULLE PART : un praticien qui écrivait au carnet en
     * P7-D0 le faisait sous un compte `agent_garde`, c'est-à-dire sous l'identité d'un agent
     * d'accueil. Tant qu'il en allait ainsi, aucune signature électronique n'aurait pu désigner un
     * professionnel — elle aurait désigné un guichet.
     *
     * LES DIX AUTRES MÉTIERS DU §5.1 RESTENT DEHORS, et c'est une limite annoncée, pas un oubli :
     * `infirmier`, `pharmacien`, `laborantin` et `radiologue` existent aussi depuis P1, mais leur
     * ouvrir le portail sans définir ni prouver ce qu'ils y font produirait des comptes capables
     * d'entrer sans que quiconque ait décidé de ce qu'ils peuvent y faire.
     */
    private const ROLES_PORTAIL = [
        'admin_ivoirsante', 'gestionnaire_etablissement', 'agent_garde', 'medecin',
    ];

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('portail.dashboard');
        }

        return view('portail.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Anti-brute force : réutilise le limiteur global (AppServiceProvider) via throttle sur la route.
        if (! Auth::attempt($data, $request->boolean('remember'))) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Identifiants incorrects.']);
        }

        // Cloisonnement : seul un compte STAFF (avec rôle portail) ET actif peut entrer. Un compte
        // sans rôle (patient) ou désactivé (établissement suspendu / compte révoqué) est refusé même
        // avec des identifiants valides. Message volontairement identique pour ne pas révéler la cause.
        $utilisateur = $request->user();
        if (! $utilisateur->hasAnyRole(self::ROLES_PORTAIL) || ! $utilisateur->actif) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Ce compte n\'a pas accès au portail.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('portail.dashboard'));
    }

    public function logout(Request $request, SessionDossierService $dossier): RedirectResponse
    {
        // 4.5 — un dossier resté ouvert est clos AVANT de détruire la session : sans cela, la
        // ligne d'audit de clôture (durée réelle + sections consultées) serait perdue.
        $dossier->fermer('deconnexion');

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portail.login');
    }
}
