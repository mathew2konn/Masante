<?php

namespace App\Http\Controllers\Api\V1\Carnet;

use App\Models\MembreFamille;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notes & observations médicales d'un membre (F2.12).
 *
 * Append-only : aucune mise à jour (la route `update` n'est pas exposée) ; la suppression est un
 * soft-delete (rétractation tracée, cf. modèle NoteObservation). `contenu` est chiffré au repos
 * (cast du modèle). L'AUTEUR est fixé côté serveur (jamais depuis le client) : le compte authentifié
 * est un patient. L'enrichissement par un médecin (via accès QR) viendra aux Modules 3/4.
 */
class NoteObservationController extends CarnetSectionController
{
    protected function relation(): string
    {
        return 'notesObservations';
    }

    protected function regles(): array
    {
        return [
            'contenu'   => ['required', 'string', 'max:5000'],   // chiffré par le cast du modèle
            'triage_id' => ['nullable', 'integer', 'exists:triages,id'],
        ];
    }

    /** Surcharge : l'auteur (patient authentifié) est injecté côté serveur, jamais reçu du client. */
    public function store(Request $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('update', $membre);

        $donnees = $request->validate($this->regles());
        $donnees['auteur_type'] = 'patient';
        $donnees['auteur_user_id'] = $request->user()->id;

        $note = $membre->notesObservations()->create($donnees);

        return response()->json(['item' => $note], 201);
    }
}
