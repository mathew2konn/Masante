<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\ActivationPortail;
use App\Models\Medecin;
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

    /**
     * Module 5 / 5.6 — Fiches de praticien de MON établissement, pour le sélecteur « ce compte est
     * le Dr X ». On propose les fiches libres, plus celle déjà reliée à l'agent édité (sinon elle
     * disparaîtrait de son propre formulaire). Une fiche = au plus un compte.
     */
    private function mesMedecins(?User $agent = null)
    {
        return Medecin::where('structure_id', $this->structureId())
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $agent?->id))
            ->orderBy('nom')
            ->get();
    }

    /**
     * Applique le lien compte ↔ fiche d'annuaire. C'est ce lien qui rend la voie 2 opérante : sans
     * lui, un patient peut désigner le Dr X, mais le compte du Dr X ne verra rien. On délie d'abord
     * l'ancienne fiche (la colonne `user_id` est UNIQUE : un compte ne peut pas être deux médecins).
     */
    private function lierFicheMedecin(User $agent, ?int $medecinId): void
    {
        Medecin::where('user_id', $agent->id)->update(['user_id' => null]);

        if ($medecinId !== null) {
            Medecin::where('id', $medecinId)
                ->where('structure_id', $this->structureId())   // jamais une fiche d'un autre hôpital
                ->update(['user_id' => $agent->id]);
        }
    }

    /** Récupère un agent DE MON établissement, ou 404 (empêche l'accès croisé). */
    private function agentPossede(User $agent): User
    {
        abort_if(
            $agent->structure_id !== $this->structureId() || ! $agent->hasRole('personnel_accueil'),
            Response::HTTP_NOT_FOUND,
        );

        return $agent;
    }

    public function index(): View
    {
        $agents = User::role('personnel_accueil')
            ->where('structure_id', $this->structureId())
            ->with('service')
            ->orderBy('nom')
            ->paginate(15);

        return view('portail.agents.index', ['agents' => $agents]);
    }

    public function create(): View
    {
        return view('portail.agents.create', [
            'services' => $this->mesServices(),
            'medecins' => $this->mesMedecins(),
        ]);
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
        $agent->assignRole('personnel_accueil');

        // 5.6 — « ce compte est le Dr X » : rend la voie du médecin référent opérante pour ce praticien.
        $this->lierFicheMedecin($agent, $data['medecin_id'] ?? null);

        $lien = route('portail.activation.show', ['token' => ActivationPortail::genererPour($agent)]);

        return redirect()->route('portail.agents.index')
            ->with('statut', 'Agent créé. Transmettez-lui le lien d\'activation.')
            ->with('lien_activation', $lien);
    }

    public function edit(User $agent): View
    {
        $this->agentPossede($agent);

        return view('portail.agents.edit', [
            'agent'    => $agent,
            'services' => $this->mesServices(),
            'medecins' => $this->mesMedecins($agent),
        ]);
    }

    public function update(Request $request, User $agent): RedirectResponse
    {
        $this->agentPossede($agent);
        $data = $this->valider($request, $agent->id);

        // `medecin_id` vit sur la fiche d'annuaire, pas sur le compte : il ne passe pas par update().
        $agent->update(collect($data)->except('medecin_id')->all());
        $this->lierFicheMedecin($agent, $data['medecin_id'] ?? null);

        return redirect()->route('portail.agents.index')->with('statut', 'Agent mis à jour.');
    }

    /**
     * Module 5 / 5.3 — Habilite (ou retire l'habilitation) un agent au bris de glace.
     *
     * Permission accordée DIRECTEMENT à l'utilisateur, hors rôle (Note_Continuite §5.3 : « attribuée
     * par le gestionnaire aux seuls services d'urgences »). Le contrôle de spécialité est fait ici, et
     * refait au moment de l'accès : un agent habilité puis muté en ORL ne doit plus pouvoir ouvrir de
     * dossier en urgence.
     */
    public function toggleBrisDeGlace(User $agent): RedirectResponse
    {
        $this->agentPossede($agent);

        if ($agent->hasPermissionTo('urgence.bris_de_glace')) {
            $agent->revokePermissionTo('urgence.bris_de_glace');

            return back()->with('statut', 'Habilitation d\'urgence retirée.');
        }

        if ($agent->service?->specialite !== 'urgences') {
            return back()->withErrors([
                'agent' => 'Seul un agent affecté à un service d\'urgences peut être habilité au bris de glace.',
            ]);
        }

        $agent->givePermissionTo('urgence.bris_de_glace');

        return back()->with('statut', 'Agent habilité à l\'accès d\'urgence. Chaque accès sera justifié et audité.');
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
            // 5.6 — Fiche de praticien correspondant à ce compte (facultatif : tous les agents ne
            // sont pas médecins). Bornée à MON établissement : on ne s'approprie pas la fiche d'un autre.
            'medecin_id' => [
                'nullable',
                Rule::exists('medecins', 'id')->where('structure_id', $this->structureId()),
            ],
        ]);
    }
}
