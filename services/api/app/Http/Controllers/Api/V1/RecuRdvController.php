<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Paiement;
use App\Models\RendezVous;
use App\Services\RecuRdvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 3 / N1-N2-N3 — Paiement (simulé) et reçu de RDV côté patient.
 *
 * ⚠️ Paiement SIMULÉ (pas de passerelle Mobile Money réelle). Anti-IDOR (§4.3) : on n'agit que sur
 * les RDV des membres du compte. Le QR de check-in (N3) est cloisonné du QR carnet et n'ouvre pas
 * le dossier ; son scan/validation à l'accueil relève du Module 4.
 */
class RecuRdvController extends Controller
{
    public function __construct(private readonly RecuRdvService $recus)
    {
    }

    /** Encaisse (simulé) un RDV et émet son reçu. */
    public function store(Request $request, RendezVous $rendezVous): JsonResponse
    {
        $this->autoriser($request, $rendezVous);

        $data = $request->validate([
            'mode' => ['required', Rule::in(Paiement::MODES)],
        ]);

        if (in_array($rendezVous->statut, ['annule', 'refuse'], true)) {
            throw ValidationException::withMessages([
                'statut' => 'Ce rendez-vous ne peut pas être réglé.',
            ]);
        }

        $recu = $this->recus->payer($rendezVous, $data['mode']);

        return response()->json(['recu' => $this->recus->vue($recu)], 201);
    }

    /** Reçu d'un RDV (+ code de check-in régénéré). */
    public function show(Request $request, RendezVous $rendezVous): JsonResponse
    {
        $this->autoriser($request, $rendezVous);

        $recu = $rendezVous->recu;
        abort_if($recu === null, 404, 'Aucun reçu pour ce rendez-vous.');

        return response()->json(['recu' => $this->recus->vue($recu)]);
    }

    /** B4-b (S7) — l'établissement de ce RDV peut-il encaisser en ligne aujourd'hui ? */
    public function disponibiliteEnLigne(Request $request, RendezVous $rendezVous): JsonResponse
    {
        $this->autoriser($request, $rendezVous);

        return response()->json(['disponible' => $this->recus->disponibiliteEnLigne($rendezVous)]);
    }

    /** B4-b — ouvre (ou réutilise) un checkout GeniusPay pour ce RDV. Ne règle rien : seule la
     *  notification, reçue plus tard, confirme le paiement (S6). */
    public function payerEnLigne(Request $request, RendezVous $rendezVous): JsonResponse
    {
        $this->autoriser($request, $rendezVous);

        if (in_array($rendezVous->statut, ['annule', 'refuse'], true)) {
            throw ValidationException::withMessages([
                'statut' => 'Ce rendez-vous ne peut pas être réglé.',
            ]);
        }

        return response()->json($this->recus->ouvrirPaiementEnLigne($rendezVous));
    }

    /** Anti-IDOR : le RDV doit concerner un membre du compte authentifié. */
    private function autoriser(Request $request, RendezVous $rendezVous): void
    {
        if ($rendezVous->membre->user_id !== $request->user()->id) {
            abort(403, 'Accès refusé.');
        }
    }
}
