<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\ServiceEtablissement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 4 / 4.3 — Services d'un établissement, gérés par SON gestionnaire (CdC §5.4.2).
 *
 * Cloisonnement strict : le gestionnaire n'agit QUE sur les services de `structure_id` = le sien
 * (« ne voit pas les autres établissements »). L'admin (sans établissement) n'a rien à gérer ici.
 * Protégé par `permission:service.manage`. Le code `specialite` alimente le matching triage (F1.5).
 */
class ServiceController extends Controller
{
    /** Codes de spécialité déjà en base (suggestions du formulaire, cohérence matching triage). */
    private function specialitesConnues(): array
    {
        return ServiceEtablissement::query()->distinct()->orderBy('specialite')->pluck('specialite')->all();
    }

    /** ID de l'établissement du gestionnaire connecté ; 403 si le compte n'est rattaché à aucun. */
    private function structureId(): int
    {
        $id = auth()->user()->structure_id;
        abort_if($id === null, Response::HTTP_FORBIDDEN, 'Compte non rattaché à un établissement.');

        return $id;
    }

    /** Récupère un service DE MON établissement, ou 404 (empêche l'accès croisé). */
    private function servicePossede(ServiceEtablissement $service): ServiceEtablissement
    {
        abort_if($service->structure_id !== $this->structureId(), Response::HTTP_NOT_FOUND);

        return $service;
    }

    public function index(Request $request): View
    {
        $recherche = trim((string) $request->query('q', ''));

        $services = ServiceEtablissement::query()
            ->where('structure_id', $this->structureId())
            ->withCount('agents')
            ->when($recherche !== '', fn ($q) => $q->where(function ($sous) use ($recherche) {
                $sous->where('nom_service', 'like', "%{$recherche}%")
                    ->orWhere('specialite', 'like', "%{$recherche}%");
            }))
            ->orderBy('nom_service')
            ->paginate(15)
            ->withQueryString();

        return view('portail.services.index', ['services' => $services, 'recherche' => $recherche]);
    }

    public function create(): View
    {
        return view('portail.services.create', ['specialites' => $this->specialitesConnues()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->valider($request);
        $data['structure_id'] = $this->structureId();
        ServiceEtablissement::create($data);

        return redirect()->route('portail.services.index')->with('statut', 'Service créé.');
    }

    public function edit(ServiceEtablissement $service): View
    {
        $this->servicePossede($service);

        return view('portail.services.edit', [
            'service'     => $service,
            'specialites' => $this->specialitesConnues(),
        ]);
    }

    public function update(Request $request, ServiceEtablissement $service): RedirectResponse
    {
        $this->servicePossede($service);
        $service->update($this->valider($request));

        return redirect()->route('portail.services.index')->with('statut', 'Service mis à jour.');
    }

    /** Active/désactive le service (retiré de l'annuaire public sans casser RDV/dispo/médecins liés). */
    public function toggleActif(ServiceEtablissement $service): RedirectResponse
    {
        $this->servicePossede($service);
        $service->update(['actif' => ! $service->actif]);

        return redirect()->route('portail.services.index')
            ->with('statut', $service->actif ? 'Service réactivé.' : 'Service désactivé.');
    }

    private function valider(Request $request): array
    {
        return $request->validate([
            'nom_service' => ['required', 'string', 'max:200'],
            'specialite'  => ['required', 'string', 'max:100', 'regex:/^[a-z_]+$/'],
        ], [
            'specialite.regex' => 'Le code spécialité doit être en minuscules avec des underscores (ex. medecine_generale).',
        ]);
    }
}
