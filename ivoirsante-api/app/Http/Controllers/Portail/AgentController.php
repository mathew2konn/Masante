<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\ActivationPortail;
use App\Models\ServiceEtablissement;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 4 / 4.3 — Agents de garde, créés par le gestionnaire de l'établissement (CdC §5.4.1 étapes 4-5).
 *
 * Même flux que le gestionnaire (4.2) : compte créé SANS mot de passe + lien d'activation à usage unique.
 * L'agent est affecté à UN service de l'établissement du gestionnaire (rôle `agent_garde`, accès limité à
 * son service — §5.4.2). Cloisonnement strict par `structure_id`. Protégé par `permission:agent.manage`.
 */
class AgentController extends Controller
{
    /** ID de l'établissement du gestionnaire connecté ; 403 si non rattaché. */
    private function structureId(): int
    {
        $id = auth()->user()->structure_id;
        abort_if($id === null, Response::HTTP_FORBIDDEN, 'Compte non rattaché à un établissement.');

        return $id;
    }

    /** Services de MON établissement (pour le sélecteur d'affectation). */
    private function mesServices()
    {
        return ServiceEtablissement::where('structure_id', $this->structureId())->orderBy('nom_service')->get();
    }

    /** Récupère un agent DE MON établissement, ou 404 (empêche l'accès croisé). */
    private function agentPossede(User $agent): User
    {
        abort_if(
            $agent->structure_id !== $this->structureId() || ! $agent->hasRole('agent_garde'),
            Response::HTTP_NOT_FOUND,
        );

        return $agent;
    }

    public function index(): View
    {
        $agents = User::role('agent_garde')
            ->where('structure_id', $this->structureId())
            ->with('service')
            ->orderBy('nom')
            ->paginate(15);

        return view('portail.agents.index', ['agents' => $agents]);
    }

    public function create(): View
    {
        return view('portail.agents.create', ['services' => $this->mesServices()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->valider($request);

        $agent = User::create([
            'prenom'       => $data['prenom'],
            'nom'          => $data['nom'],
            'email'        => $data['email'],
            'password'     => null,                       // activation obligatoire (§5.4.1)
            'structure_id' => $this->structureId(),
            'service_id'   => $data['service_id'],
            'actif'        => true,
        ]);
        $agent->assignRole('agent_garde');

        $lien = route('portail.activation.show', ['token' => ActivationPortail::genererPour($agent)]);

        return redirect()->route('portail.agents.index')
            ->with('statut', 'Agent créé. Transmettez-lui le lien d\'activation.')
            ->with('lien_activation', $lien);
    }

    public function edit(User $agent): View
    {
        $this->agentPossede($agent);

        return view('portail.agents.edit', ['agent' => $agent, 'services' => $this->mesServices()]);
    }

    public function update(Request $request, User $agent): RedirectResponse
    {
        $this->agentPossede($agent);
        $data = $this->valider($request, $agent->id);
        $agent->update($data);

        return redirect()->route('portail.agents.index')->with('statut', 'Agent mis à jour.');
    }

    public function toggleActif(User $agent): RedirectResponse
    {
        $this->agentPossede($agent);
        $agent->update(['actif' => ! $agent->actif]);

        return redirect()->route('portail.agents.index')
            ->with('statut', $agent->actif ? 'Agent réactivé.' : 'Agent désactivé.');
    }

    /** Régénère le lien d'activation d'un agent non encore activé. */
    public function regenererLien(User $agent): RedirectResponse
    {
        $this->agentPossede($agent);

        if ($agent->password !== null) {
            return back()->withErrors(['agent' => 'Ce compte est déjà activé.']);
        }

        $lien = route('portail.activation.show', ['token' => ActivationPortail::genererPour($agent)]);

        return redirect()->route('portail.agents.index')
            ->with('statut', 'Nouveau lien d\'activation généré.')
            ->with('lien_activation', $lien);
    }

    /** Valide les champs de l'agent. Le service choisi doit appartenir à MON établissement. */
    private function valider(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'prenom'     => ['required', 'string', 'max:100'],
            'nom'        => ['required', 'string', 'max:100'],
            'email'      => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($ignoreId)],
            'service_id' => [
                'required',
                Rule::exists('services_etablissement', 'id')->where('structure_id', $this->structureId()),
            ],
        ]);
    }
}
