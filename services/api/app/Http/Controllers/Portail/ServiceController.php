<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\ServiceEtablissement;
use App\Models\SpecialiteMedicale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 4 / 4.3 — Services d'un établissement, gérés par SON gestionnaire (CdC §5.4.2).
 *
 * Cloisonnement strict : le gestionnaire n'agit QUE sur les services de `structure_id` = le sien
 * (« ne voit pas les autres établissements »). L'admin (sans établissement) n'a rien à gérer ici.
 * Protégé par `permission:service.manage`.
 *
 * ═══ P6.8a — DE LA SUGGESTION À L'INTERDICTION ═══
 *
 * Ce formulaire validait `regex:/^[a-z_]+$/` : n'importe quel mot en minuscules passait. Et il
 * proposait « les codes déjà en base » — autrement dit, le vocabulaire national était défini par ce
 * qui avait été tapé en premier, et une faute de frappe devenait une spécialité permanente
 * d'apparence légitime. C'est le défaut que P6.4a avait décrit pour le découpage sanitaire
 * (« Abidjan » / « ABIDJAN » / « Abidjan 1 ») et que P6.4d avait laissé ouvert en le nommant.
 *
 * Le terme se CHOISIT désormais dans le vocabulaire national. Même renversement qu'en P6.4d pour le
 * couple région/district : détecter après coup obligerait à retrouver qui a saisi quoi, alors qu'au
 * formulaire l'agent a encore l'information sous les yeux.
 */
class ServiceController extends Controller
{
    /**
     * Le vocabulaire national — et non plus « ce qui traîne déjà en base ».
     *
     * Les termes INACTIFS en sont exclus : ils restent lisibles sur les services déjà rattachés,
     * mais on ne peut plus en rattacher de nouveaux. C'est tout l'intérêt de désactiver plutôt que
     * de supprimer.
     */
    private function vocabulaire(): Collection
    {
        return SpecialiteMedicale::query()->active()->ordonnee()->get();
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
        return view('portail.services.create', ['specialites' => $this->vocabulaire()]);
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
            'specialites' => $this->vocabulaire(),
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

    /**
     * @return array<string, mixed>
     */
    private function valider(Request $request): array
    {
        $vocabulaire = $this->vocabulaire();

        $donnees = $request->validate([
            'nom_service' => ['required', 'string', 'max:200'],
            // Le code doit exister AU VOCABULAIRE. Le message nomme les termes admis : refuser sans
            // dire quoi saisir obligerait l'agent à deviner, et deviner ramènerait la faute de
            // frappe qu'on vient de fermer.
            'specialite'  => ['required', Rule::in($vocabulaire->pluck('code')->all())],
        ], [
            'specialite.in' => 'Ce code ne fait pas partie du vocabulaire national des spécialités. '
                .'Termes admis : '.$vocabulaire->pluck('code')->implode(', ').'.',
        ], ['specialite' => 'spécialité']);

        // LE RATTACHEMENT EST RÉSOLU PAR LE SERVEUR, JAMAIS REÇU DU CLIENT. `specialite_id` est
        // `fillable` (le chemin d'écriture est une assignation de masse), mais il n'apparaît dans
        // aucune règle : un `specialite_id` envoyé dans la requête est donc écarté par `validate()`
        // et écrasé ici. Deux couches, deux vecteurs de test — la leçon de mutation de P6.6b.
        $donnees['specialite_id'] = $vocabulaire->firstWhere('code', $donnees['specialite'])?->id;

        return $donnees;
    }
}
