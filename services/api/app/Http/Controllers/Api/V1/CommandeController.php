<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\MembreFamille;
use App\Models\Ordonnance;
use App\Models\StructureSanitaire;
use App\Services\Medicament\ServiceCommande;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * B3-d — le patient passe, consulte, règle et annule ses commandes de médicaments (CDC_11 §9.5).
 *
 * Anti-IDOR (patron RDV, `RecuRdvController`) : on n'agit que sur les commandes des membres du
 * compte authentifié — vérifié ICI, dans le contrôleur, pas dans le service (`ServiceCommande` ne
 * connaît pas de session HTTP).
 */
class CommandeController extends Controller
{
    public function __construct(private readonly ServiceCommande $commandes) {}

    /** Les commandes des membres du compte. */
    public function index(Request $request): JsonResponse
    {
        $commandes = Commande::whereHas('membre', fn ($m) => $m->where('user_id', $request->user()->id))
            ->with('lignes', 'structure:id,nom,commune')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['commandes' => $commandes]);
    }

    public function show(Request $request, Commande $commande): JsonResponse
    {
        $this->autoriser($request, $commande);

        return response()->json(['commande' => $commande->load('lignes', 'structure:id,nom,commune')]);
    }

    /**
     * Passe une commande.
     *
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'membre_id' => ['required', 'integer', 'exists:membres_famille,id'],
            'structure_id' => ['required', 'integer', 'exists:structures_sanitaires,id'],
            'ordonnance_id' => ['nullable', 'integer', 'exists:ordonnances,id'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.medicament_id' => ['required', 'integer', 'exists:medicaments,id'],
            'lignes.*.quantite' => ['required', 'integer', 'min:1'],
            'mode_retrait' => ['required', 'string', 'in:retrait,livraison'],
            'adresse_livraison' => ['nullable', 'string', 'max:500'],
            'commentaire' => ['nullable', 'string', 'max:500'],
        ]);

        // Anti-IDOR : le membre doit appartenir au compte (patron RendezVousController).
        $membre = MembreFamille::findOrFail($data['membre_id']);
        if ($membre->user_id !== $request->user()->id) {
            abort(403, 'Ce membre n\'appartient pas à votre compte.');
        }

        $ordonnance = isset($data['ordonnance_id']) ? Ordonnance::findOrFail($data['ordonnance_id']) : null;

        $commande = $this->commandes->passer(
            $request->user(),
            $membre,
            StructureSanitaire::findOrFail($data['structure_id']),
            $data['lignes'],
            $data['mode_retrait'],
            $data['adresse_livraison'] ?? null,
            $ordonnance,
            $data['commentaire'] ?? null,
        );

        return response()->json(['commande' => $commande], 201);
    }

    public function annuler(Request $request, Commande $commande): JsonResponse
    {
        $this->autoriser($request, $commande);

        return response()->json(['commande' => $this->commandes->annuler($commande)]);
    }

    /** B3-d (F6) — cette officine peut-elle encaisser cette commande en ligne aujourd'hui ? */
    public function disponibiliteEnLigne(Request $request, Commande $commande): JsonResponse
    {
        $this->autoriser($request, $commande);

        return response()->json(['disponible' => $this->commandes->disponibiliteEnLigne($commande)]);
    }

    /** Ouvre (ou réutilise) un checkout GeniusPay réel pour cette commande. */
    public function payerEnLigne(Request $request, Commande $commande): JsonResponse
    {
        $this->autoriser($request, $commande);

        return response()->json($this->commandes->ouvrirPaiementEnLigne($commande));
    }

    /** Anti-IDOR : la commande doit concerner un membre du compte authentifié. */
    private function autoriser(Request $request, Commande $commande): void
    {
        if ($commande->membre->user_id !== $request->user()->id) {
            abort(403, 'Accès refusé.');
        }
    }
}
