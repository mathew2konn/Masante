<?php

namespace App\Http\Controllers\Api\V1\Carnet;

use App\Http\Controllers\Controller;
use App\Models\MembreFamille;
use App\Services\Vaccin\ServiceCalendrierVaccinal;
use Illuminate\Http\JsonResponse;

/**
 * P6.8b — « Qu'est-ce qui est dû pour cette personne, aujourd'hui ? » (CDC_09 §8).
 *
 * ═══ LECTURE SEULE, ET C'EST LA DÉCISION W3 ═══
 *
 * Le calendrier RÉPOND. Il n'écrit rien : ni dans `vaccinations`, ni dans `rappels`. Générer des
 * lignes serait un quatrième chemin d'écriture dans une table du carnet, avec la question du rejeu
 * et de la suppression par le patient. Ce qui PRÉVIENT, c'est la notification d'échéance
 * ({@see App\Console\Commands\NotifierEcheancesVaccinales}), qui n'écrit pas non plus dans le carnet.
 *
 * ═══ AUTORISATION : LA BARRIÈRE EXISTANTE, PAS UNE NOUVELLE ═══
 *
 * `view` — donc le propriétaire du carnet OU un délégué en lecture (P7-A). Le calendrier n'est que
 * la mise en regard de ce que le lecteur peut DÉJÀ lire (les vaccinations du carnet) et d'un
 * référentiel PUBLIC (le calendrier national) : il ne révèle rien de neuf. Élargir ou restreindre
 * la capacité aurait été une décision de sécurité déguisée en détail d'implémentation — précédent
 * de la fiche de parcours (P7-D2), où la capacité a été ajoutée explicitement plutôt que la
 * barrière déplacée.
 */
class CalendrierVaccinalController extends Controller
{
    public function __construct(private readonly ServiceCalendrierVaccinal $calendrier) {}

    /** GET /api/v1/membres/{membre}/calendrier-vaccinal */
    public function show(MembreFamille $membre): JsonResponse
    {
        $this->authorize('view', $membre);

        return response()->json($this->calendrier->pour($membre));
    }
}
