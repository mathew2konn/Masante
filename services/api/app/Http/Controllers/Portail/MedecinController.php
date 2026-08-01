<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Medecin;
use App\Models\ServiceEtablissement;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 5 / 5.6 — Fiches des praticiens d'un établissement, gérées par SON gestionnaire.
 *
 * L'annuaire `medecins` existait depuis le Module 3 (F3.5, choix du praticien à la prise de RDV) mais
 * n'était alimenté QUE par le seeder : le CdC renvoyait sa configuration « au portail admin »,
 * et l'écran n'avait jamais été construit. Conséquence concrète : un établissement créé après coup
 * n'avait aucun praticien réservable, et surtout aucune fiche à relier à un compte — la voie 2
 * (médecin référent) y était donc inutilisable. Cet écran comble ce trou.
 *
 * Deux usages, une seule table :
 *  - annuaire PUBLIC : le patient voit ces fiches à la prise de RDV (F3.5) et pour désigner son
 *    référent (5.6). Données non sensibles (nom, spécialité, tarif indicatif) ;
 *  - lien vers un COMPTE (`user_id`) : c'est lui qui permet au praticien désigné référent d'ouvrir
 *    le dossier depuis « Mes patients suivis ». Sans lien, la fiche est visible mais muette.
 *
 * Cloisonnement strict par `structure_id`, comme les services (4.3) : un gestionnaire ne touche
 * jamais aux praticiens d'un autre établissement. Aucune suppression — une fiche se DÉSACTIVE
 * (elle est référencée par des rendez-vous et, potentiellement, par des désignations de référent).
 */
class MedecinController extends Controller
{
    /** ID de l'établissement du gestionnaire connecté ; 403 si le compte n'est rattaché à aucun. */
    private function structureId(): int
    {
        $id = auth()->user()->structure_id;
        abort_if($id === null, Response::HTTP_FORBIDDEN, 'Compte non rattaché à un établissement.');

        return $id;
    }

    /** Récupère une fiche DE MON établissement, ou 404 (empêche l'accès croisé). */
    private function fichePossedee(Medecin $medecin): Medecin
    {
        abort_if($medecin->structure_id !== $this->structureId(), Response::HTTP_NOT_FOUND);

        return $medecin;
    }

    /** Services de MON établissement (un praticien est rattaché à UN service). */
    private function mesServices()
    {
        return ServiceEtablissement::where('structure_id', $this->structureId())
            ->orderBy('nom_service')
            ->get();
    }

    /**
     * Comptes d'agent de MON établissement proposables comme titulaire de la fiche : ceux qui ne
     * sont encore reliés à aucune fiche, plus celui déjà relié à la fiche éditée (sans quoi il
     * disparaîtrait de son propre formulaire). Un compte = au plus une fiche (`user_id` UNIQUE).
     */
    private function mesAgents(?Medecin $medecin = null)
    {
        $relies = Medecin::whereNotNull('user_id')
            ->where('id', '!=', $medecin?->id ?? 0)
            ->pluck('user_id');

        return User::role('agent_garde')
            ->where('structure_id', $this->structureId())
            ->whereNotIn('id', $relies)
            ->orderBy('nom')
            ->get();
    }

    public function index(Request $request): View
    {
        $recherche = trim((string) $request->query('q', ''));

        $medecins = Medecin::query()
            ->where('structure_id', $this->structureId())
            ->when($recherche !== '', fn ($q) => $q->where(function ($sous) use ($recherche) {
                $sous->where('nom', 'like', "%{$recherche}%")
                    ->orWhere('prenom', 'like', "%{$recherche}%")
                    ->orWhere('specialite', 'like', "%{$recherche}%");
            }))
            ->with(['service:id,nom_service', 'user:id,prenom,nom'])
            ->orderBy('nom')
            ->paginate(15)
            ->withQueryString();

        return view('portail.medecins.index', ['medecins' => $medecins, 'recherche' => $recherche]);
    }

    public function create(): View
    {
        return view('portail.medecins.create', [
            'services' => $this->mesServices(),
            'agents'   => $this->mesAgents(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->valider($request);

        Medecin::create([...$data, 'structure_id' => $this->structureId(), 'actif' => true]);

        return redirect()->route('portail.medecins.index')
            ->with('statut', 'Praticien ajouté à l\'annuaire de votre établissement.');
    }

    public function edit(Medecin $medecin): View
    {
        $this->fichePossedee($medecin);

        return view('portail.medecins.edit', [
            'medecin'  => $medecin,
            'services' => $this->mesServices(),
            'agents'   => $this->mesAgents($medecin),
        ]);
    }

    public function update(Request $request, Medecin $medecin): RedirectResponse
    {
        $this->fichePossedee($medecin);

        $medecin->update($this->valider($request, $medecin));

        return redirect()->route('portail.medecins.index')->with('statut', 'Fiche du praticien mise à jour.');
    }

    /**
     * Désactive (ou réactive) la fiche. Pas de suppression : elle est référencée par des
     * rendez-vous et peut l'être par des désignations de référent. Une fiche désactivée sort de
     * l'annuaire public — les désignations en cours restent, mais le patient ne peut plus la choisir.
     */
    public function toggleActif(Medecin $medecin): RedirectResponse
    {
        $this->fichePossedee($medecin);
        $medecin->update(['actif' => ! $medecin->actif]);

        return redirect()->route('portail.medecins.index')
            ->with('statut', $medecin->actif ? 'Praticien réactivé.' : 'Praticien retiré de l\'annuaire public.');
    }

    /**
     * Valide la fiche. Le service et le compte doivent appartenir à MON établissement : on ne
     * s'approprie ni le service ni l'agent d'un autre hôpital.
     *
     * @return array<string, mixed>
     */
    private function valider(Request $request, ?Medecin $medecin = null): array
    {
        return $request->validate([
            'titre'              => ['required', Rule::in(['Dr', 'Pr'])],
            'prenom'             => ['required', 'string', 'max:120'],
            'nom'                => ['required', 'string', 'max:120'],
            'specialite'         => ['required', 'string', 'max:100'],
            'tarif_consultation' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'service_id'         => [
                'required',
                Rule::exists('services_etablissement', 'id')->where('structure_id', $this->structureId()),
            ],
            // Le compte du praticien : facultatif (une fiche peut n'exister que pour l'annuaire).
            // UNIQUE en base — on l'exige aussi ici, pour une erreur lisible plutôt qu'une 500.
            'user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where('structure_id', $this->structureId()),
                Rule::unique('medecins', 'user_id')->ignore($medecin?->id),
            ],
        ], [], [
            'user_id'    => 'compte du praticien',
            'service_id' => 'service',
        ]);
    }
}
