<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ResponsableFamille;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Carnet familial partagé / C — responsables de famille.
 *
 * Le propriétaire d'un carnet est responsable DE DROIT : il n'a pas besoin d'être désigné, et il
 * ne peut pas se retirer ce pouvoir. Ce contrôleur ne gère que les responsables SUPPLÉMENTAIRES.
 *
 * « Dans certaines familles, il peut ne pas y avoir de père comme de mère » (propriétaire, G1) :
 * la règle est donc « celui qui a créé les carnets décide », jamais « les parents ».
 */
class ResponsableFamilleController extends Controller
{
    /** GET /api/v1/responsables — qui décide avec moi, et pour qui je décide. */
    public function index(Request $request): JsonResponse
    {
        $uid = $request->user()->id;

        return response()->json([
            'designes' => ResponsableFamille::where('titulaire_user_id', $uid)
                ->actif()
                ->with('responsable:id,nom,prenom,telephone')
                ->get(),
            'je_suis_responsable_de' => ResponsableFamille::where('responsable_user_id', $uid)
                ->actif()
                ->with('titulaire:id,nom,prenom,telephone')
                ->get(),
        ]);
    }

    /** POST /api/v1/responsables — désigner un second responsable, par téléphone. */
    public function store(Request $request): JsonResponse
    {
        $valide = $request->validate([
            'telephone' => ['required', 'string', 'regex:/^\+225[0-9]{10}$/'],
        ], [
            'telephone.regex' => 'Le numéro doit être au format +225 suivi de 10 chiffres.',
        ]);

        $titulaire   = $request->user();
        $responsable = User::where('telephone', $valide['telephone'])->first();

        abort_if($responsable === null, 422, 'Aucun compte MaSanté associé à ce numéro.');
        abort_if(! $responsable->telephoneEstVerifie(), 422, "Le compte associé à ce numéro n'est pas encore vérifié.");
        abort_if($responsable->id === $titulaire->id, 422, 'Vous êtes déjà responsable de vos carnets.');

        // Réarme une désignation révoquée (contrainte UNIQUE titulaire+responsable).
        $ligne = ResponsableFamille::updateOrCreate(
            ['titulaire_user_id' => $titulaire->id, 'responsable_user_id' => $responsable->id],
            ['designe_le' => now(), 'revoque_le' => null],
        );

        return response()->json([
            'responsable' => $ligne->load('responsable:id,nom,prenom,telephone'),
        ], 201);
    }

    /**
     * DELETE /api/v1/responsables/{responsable} — retirer la désignation.
     *
     * Le titulaire retire ; le désigné peut aussi se retirer lui-même. Reprendre la main sur ses
     * propres carnets, comme s'en retirer, doit rester plus facile que de céder le pouvoir.
     */
    public function destroy(Request $request, ResponsableFamille $responsable): JsonResponse
    {
        $uid = $request->user()->id;

        abort_unless(
            $responsable->titulaire_user_id === $uid || $responsable->responsable_user_id === $uid,
            403,
            'Action non autorisée.',
        );

        if ($responsable->revoque_le === null) {
            $responsable->update(['revoque_le' => now()]);
        }

        return response()->json(['message' => 'Désignation retirée.']);
    }
}
