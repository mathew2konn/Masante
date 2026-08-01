<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\BesoinSang;
use App\Services\DonSangService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 5 / 5.7 — Besoins en sang publiés par l'établissement (CdC FN6).
 *
 * C'est l'HÔPITAL qui publie, pas l'admin MaSanté : lui seul sait qu'il manque de O− ce matin
 * (« urgence signalée par un CHU », FN6). Permission `don_sang.manage`, accordée au gestionnaire ;
 * cloisonnement strict par `structure_id`, comme les services (4.3).
 *
 * MINIMISATION (loi n°2013-450) — le point à défendre en soutenance : l'établissement voit COMBIEN de
 * donneurs compatibles pourraient répondre, jamais QUI ils sont. Aucun nom, aucun numéro, aucun
 * export. Un hôpital n'a pas à repartir avec un fichier de porteurs de O−, et une application de
 * santé n'a pas à devenir un annuaire de groupes sanguins. Les donneurs sont alertés dans leur
 * application et se présentent d'eux-mêmes : donner reste une décision, pas une convocation.
 *
 * Deux niveaux, volontairement distincts : le besoin `courant` s'affiche dans la liste publique des
 * groupes demandés ; seule l'`urgent` alerte les donneurs compatibles. Si tout alertait, plus rien
 * n'alerterait.
 */
class BesoinSangController extends Controller
{
    /** Les 8 groupes sanguins (ordre d'affichage du formulaire). */
    public const GROUPES = ['O-', 'O+', 'A-', 'A+', 'B-', 'B+', 'AB-', 'AB+'];

    public function __construct(private readonly DonSangService $dons)
    {
    }

    /** ID de l'établissement du gestionnaire connecté ; 403 si le compte n'est rattaché à aucun. */
    private function structureId(): int
    {
        $id = auth()->user()->structure_id;
        abort_if($id === null, Response::HTTP_FORBIDDEN, 'Compte non rattaché à un établissement.');

        return $id;
    }

    /** Récupère un besoin DE MON établissement, ou 404 (empêche l'accès croisé). */
    private function besoinPossede(BesoinSang $besoin): BesoinSang
    {
        abort_if($besoin->structure_id !== $this->structureId(), Response::HTTP_NOT_FOUND);

        return $besoin;
    }

    public function index(): View
    {
        $besoins = BesoinSang::where('structure_id', $this->structureId())
            ->orderByDesc('actif')
            ->orderByDesc('date_debut')
            ->paginate(15);

        // Le vivier mobilisable par besoin : un NOMBRE, jamais une liste de personnes.
        $viviers = $besoins->mapWithKeys(
            fn (BesoinSang $b) => [$b->id => $this->dons->compterDonneursCompatibles($b)],
        );

        return view('portail.don-sang.index', [
            'besoins' => $besoins,
            'viviers' => $viviers,
        ]);
    }

    public function create(): View
    {
        return view('portail.don-sang.create', ['groupes' => self::GROUPES]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $this->valider($request);

        $besoin = new BesoinSang($donnees);
        // Jamais du formulaire : on ne publie que pour SON établissement, sous SON nom.
        $besoin->structure_id = $this->structureId();
        $besoin->publie_par_user_id = auth()->id();
        $besoin->save();

        if ($besoin->niveau === 'urgent') {
            $this->dons->notifierUrgence($besoin);
        }

        $vivier = $this->dons->compterDonneursCompatibles($besoin);

        return redirect()->route('portail.don-sang.index')->with(
            'statut',
            $besoin->niveau === 'urgent'
                ? "Urgence publiée. {$vivier} donneur(s) compatible(s) peuvent être alertés."
                : 'Besoin publié : il apparaît dans les groupes demandés de l\'application.',
        );
    }

    public function edit(BesoinSang $besoin): View
    {
        $this->besoinPossede($besoin);

        return view('portail.don-sang.edit', ['besoin' => $besoin, 'groupes' => self::GROUPES]);
    }

    public function update(Request $request, BesoinSang $besoin): RedirectResponse
    {
        $this->besoinPossede($besoin);
        $besoin->update($this->valider($request));

        return redirect()->route('portail.don-sang.index')->with('statut', 'Besoin mis à jour.');
    }

    /** Clôt (ou rouvre) un besoin. Pas de suppression : l'historique des tensions a une valeur. */
    public function toggleActif(BesoinSang $besoin): RedirectResponse
    {
        $this->besoinPossede($besoin);
        $besoin->update(['actif' => ! $besoin->actif]);

        return redirect()->route('portail.don-sang.index')->with(
            'statut',
            $besoin->actif ? 'Besoin rouvert.' : 'Besoin clôturé : les donneurs ne sont plus sollicités.',
        );
    }

    /** @return array<string, mixed> */
    private function valider(Request $request): array
    {
        return $request->validate([
            'groupe_sanguin' => ['required', Rule::in(self::GROUPES)],
            'niveau'         => ['required', Rule::in(['courant', 'urgent'])],
            'message'        => ['nullable', 'string', 'max:300'],
            'date_debut'     => ['required', 'date'],
            // Un besoin dont la fin est passée ne mobilise plus personne (scope `enCours`).
            'date_fin'       => ['nullable', 'date', 'after_or_equal:date_debut'],
        ], [], [
            'groupe_sanguin' => 'groupe sanguin',
        ]);
    }
}
