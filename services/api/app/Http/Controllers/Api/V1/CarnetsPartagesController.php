<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Delegation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Carnet familial partagé / A — les carnets qu'on m'a partagés.
 *
 * POURQUOI UN ENDPOINT SÉPARÉ ET NON `GET /membres` ÉLARGI : `MembreController::index` est le
 * contrat de P2, validé G5, et le cache hors-ligne chiffré s'appuie dessus. Y mélanger des
 * carnets qui n'appartiennent pas au compte changerait la signification de « mes membres » sous
 * les pieds de tout ce qui le consomme. Le mobile compose les deux listes à l'affichage — c'est
 * une décision de présentation, pas de contrat.
 *
 * FRONTIÈRE : aucune règle métier. Le serveur dit quels carnets sont partagés et par qui ; le
 * client affiche.
 */
class CarnetsPartagesController extends Controller
{
    /**
     * GET /api/v1/membres/partages
     *
     * Les carnets sur lesquels le compte authentifié détient une délégation ACTIVE emportant la
     * lecture. Une délégation `qr_generation` (périmètre historique) n'ouvre pas le dossier et
     * n'apparaît donc pas ici.
     */
    public function index(Request $request): JsonResponse
    {
        $delegations = Delegation::query()
            ->where('delegue_user_id', $request->user()->id)
            ->whereIn('droits', Delegation::DROITS_LECTURE)
            ->active()
            ->with(['membre', 'titulaire:id,nom,prenom'])
            ->latest('acceptee_at')
            ->get();

        $partages = $delegations
            // Un membre supprimé entre-temps laisse une délégation orpheline : on ne la montre pas.
            ->filter(fn (Delegation $d) => $d->membre !== null)
            ->map(fn (Delegation $d) => [
                'delegation_id' => $d->id,
                'droits'        => $d->droits,
                'depuis'        => $d->acceptee_at,
                'partage_par'   => [
                    'nom'    => $d->titulaire?->nom,
                    'prenom' => $d->titulaire?->prenom,
                ],
                'membre'        => $d->membre,
            ])
            ->values();

        return response()->json(['partages' => $partages]);
    }
}
