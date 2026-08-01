<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\ActivationPortail;
use App\Models\StructureSanitaire;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 4 / 4.7 — Comptes du portail (CdC §5.4.2 « gérer tous les comptes »), admin uniquement.
 *
 * PÉRIMÈTRE VOLONTAIREMENT RESTREINT AU STAFF. « Tous les comptes » s'entend des comptes du
 * portail (admin, gestionnaires, agents) : les comptes PATIENTS n'apparaissent pas ici. Ils
 * portent des carnets de santé, et donner prise dessus à l'administration contredirait la règle
 * des trois voies d'accès au dossier (Sécurité §4.4), où la voie « admin » reste exceptionnelle
 * et auditée. Le portail n'est pas un annuaire des patients.
 *
 * Vue transversale des comptes créés en 4.2 (gestionnaires) et 4.3 (agents) : l'admin les voit
 * TOUS établissements confondus, peut suspendre un compte et régénérer un lien d'activation
 * resté inutilisé. La création reste là où elle a du sens (le gestionnaire crée ses agents).
 */
class CompteController extends Controller
{
    /** Rôles du portail, dans l'ordre hiérarchique. */
    public const ROLES = [
        'admin_ivoirsante'           => 'Admin IVOIRSANTÉ',
        'gestionnaire_etablissement' => 'Gestionnaire',
        'agent_garde'                => 'Agent de garde',
    ];

    public function index(Request $request): View
    {
        $role = $request->string('role')->toString();
        $structureId = $request->integer('structure');
        $recherche = trim($request->string('q')->toString());

        $comptes = User::query()
            ->whereHas('roles')                       // staff uniquement : un patient n'a aucun rôle
            ->with(['roles:id,name', 'structure:id,nom', 'service:id,nom_service'])
            ->when(array_key_exists($role, self::ROLES), fn ($q) => $q->role($role))
            ->when($structureId > 0, fn ($q) => $q->where('structure_id', $structureId))
            ->when($recherche !== '', fn ($q) => $q->where(function ($sq) use ($recherche) {
                $sq->where('nom', 'like', "%{$recherche}%")
                    ->orWhere('prenom', 'like', "%{$recherche}%")
                    ->orWhere('email', 'like', "%{$recherche}%");
            }))
            ->orderBy('nom')
            ->paginate(20)
            ->withQueryString();

        return view('portail.comptes.index', [
            'comptes'      => $comptes,
            'roles'        => self::ROLES,
            'etablissements' => StructureSanitaire::orderBy('nom')->get(['id', 'nom']),
            'role'         => $role,
            'structureId'  => $structureId,
            'recherche'    => $recherche,
        ]);
    }

    /**
     * Suspend ou réactive un compte staff.
     *
     * Deux garde-fous : l'admin ne peut pas se désactiver lui-même (il se verrouillerait dehors),
     * et le DERNIER admin actif ne peut pas être suspendu — sans quoi plus personne ne pourrait
     * administrer la plateforme, y compris pour réactiver le compte.
     */
    public function toggleActif(User $compte): RedirectResponse
    {
        $compte = $this->staff($compte);

        if ($compte->actif) {
            if ($compte->id === auth()->id()) {
                return back()->withErrors(['compte' => 'Vous ne pouvez pas désactiver votre propre compte.']);
            }

            if ($compte->hasRole('admin_ivoirsante') && $this->adminsActifs() <= 1) {
                return back()->withErrors(['compte' => 'Impossible de désactiver le dernier administrateur actif.']);
            }
        }

        $compte->update(['actif' => ! $compte->actif]);

        return back()->with('statut', $compte->actif ? 'Compte réactivé.' : 'Compte suspendu.');
    }

    /** Régénère le lien d'activation d'un compte staff jamais activé (mot de passe encore nul). */
    public function regenererLien(User $compte): RedirectResponse
    {
        $compte = $this->staff($compte);

        if ($compte->password !== null) {
            return back()->withErrors(['compte' => 'Ce compte est déjà activé.']);
        }

        $lien = route('portail.activation.show', ['token' => ActivationPortail::genererPour($compte)]);

        return back()->with('statut', 'Nouveau lien d\'activation généré.')->with('lien_activation', $lien);
    }

    /** Un compte sans rôle portail est un patient : il n'a rien à faire ici (404). */
    private function staff(User $compte): User
    {
        abort_if(! $compte->hasAnyRole(array_keys(self::ROLES)), Response::HTTP_NOT_FOUND);

        return $compte;
    }

    private function adminsActifs(): int
    {
        return User::role('admin_ivoirsante')->where('actif', true)->count();
    }
}
