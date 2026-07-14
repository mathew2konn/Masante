<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Medicament;
use App\Models\PrixPharmacie;
use App\Models\StructureSanitaire;
use App\Services\PrixMedicamentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 5 / 5.8 — Prix et stock d'une PHARMACIE partenaire (CdC FN7/FN8).
 *
 * C'est le « modèle freemium » du CdC : la pharmacie rejoint volontairement la plateforme, tient ses
 * prix à jour et déclare ses ruptures ; en échange, elle apparaît au comparateur avec la source la
 * plus fiable. Le pharmacien fait autorité sur SA propre officine : son relevé prime sur ceux des
 * patients ({@see PrixMedicamentService}).
 *
 * Réservé au gestionnaire d'une structure de type `pharmacie` (permission `medicament.manage`) : le
 * gestionnaire d'un CHU n'a rien à déclarer ici. Cloisonnement par `structure_id`, comme partout.
 *
 * On n'ÉCRASE jamais un relevé : chaque enregistrement en AJOUTE un, daté. C'est ce qui permet de
 * dire au patient depuis quand on sait — et de ne pas réécrire l'histoire d'un prix.
 */
class StockPharmacieController extends Controller
{
    public function __construct(private readonly PrixMedicamentService $prix)
    {
    }

    /** La pharmacie du gestionnaire connecté ; 403 si le compte n'en gère pas une. */
    private function pharmacie(): StructureSanitaire
    {
        $user = auth()->user();

        abort_if($user->structure_id === null, Response::HTTP_FORBIDDEN, 'Compte non rattaché à un établissement.');

        $structure = StructureSanitaire::findOrFail($user->structure_id);

        abort_if(
            $structure->type !== 'pharmacie',
            Response::HTTP_FORBIDDEN,
            'Cet écran est réservé aux pharmacies : votre établissement n\'en est pas une.',
        );

        return $structure;
    }

    /** Catalogue + l'état actuel (dernier relevé) de MA pharmacie pour chaque médicament. */
    public function index(Request $request): View
    {
        $pharmacie = $this->pharmacie();
        $recherche = trim((string) $request->query('q', ''));

        $medicaments = Medicament::query()
            ->when($recherche !== '', fn ($q) => $q->where(function ($sous) use ($recherche) {
                $sous->where('nom_generique', 'like', "%{$recherche}%")
                    ->orWhere('nom_commercial', 'like', "%{$recherche}%");
            }))
            ->orderBy('nom_generique')
            ->paginate(20)
            ->withQueryString();

        // Dernier relevé de MA pharmacie par médicament (toutes sources : je vois aussi ce que les
        // patients ont signalé chez moi — une rupture rapportée par un client me concerne).
        $etats = PrixPharmacie::where('structure_id', $pharmacie->id)
            ->whereIn('medicament_id', $medicaments->pluck('id'))
            ->orderByDesc('date_mise_a_jour')
            ->get()
            ->groupBy('medicament_id')
            ->map(fn ($releves) => $releves->first());

        return view('portail.stock.index', [
            'pharmacie'   => $pharmacie,
            'medicaments' => $medicaments,
            'etats'       => $etats,
            'recherche'   => $recherche,
        ]);
    }

    /**
     * Déclare le prix, ou la rupture, d'un médicament dans MA pharmacie.
     * Source `pharmacie_portail` : la parole du pharmacien sur sa propre officine.
     */
    public function declarer(Request $request, Medicament $medicament): RedirectResponse
    {
        $pharmacie = $this->pharmacie();

        $donnees = $request->validate([
            'etat'     => ['required', 'in:en_stock,rupture'],
            // Le prix n'a de sens que si le médicament est en rayon (cf. `prix_cfa` nullable).
            'prix_cfa' => ['required_if:etat,en_stock', 'nullable', 'integer', 'min:1'],
        ], [], ['prix_cfa' => 'prix']);

        if ($donnees['etat'] === 'rupture') {
            $this->prix->signalerRupture($medicament, $pharmacie, 'pharmacie_portail', auth()->user());

            return back()->with('statut', "Rupture déclarée : {$medicament->libelle} n'apparaîtra plus comme disponible chez vous.");
        }

        $this->prix->releverPrix(
            $medicament,
            $pharmacie,
            (int) $donnees['prix_cfa'],
            'pharmacie_portail',
            auth()->user(),
        );

        return back()->with('statut', "Prix mis à jour : {$medicament->libelle}.");
    }
}
