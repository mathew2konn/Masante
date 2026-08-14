<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SpecialiteMedicale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * P6.8a — Le vocabulaire national des spécialités (CDC_09 §8).
 *
 * PUBLIC EN LECTURE, comme le reste de l'annuaire (`/structures`, `/villes`, `/medicaments`) :
 * savoir quelles spécialités existent ne demande aucune formalité d'identité, et un écran a besoin
 * des libellés avant toute connexion pour afficher un filtre lisible.
 *
 * ═══ POURQUOI CET ENDPOINT EXISTE ═══
 *
 * Pour que plus aucun client ne recopie un code. Le G0 de P6.8 a trouvé `don_sang` EN DUR dans
 * `apps/mobile/src/api/donSang.ts` — récidive exacte du constat G-a de P6.4b, où sept communes et
 * sept libellés de catégorie vivaient côté mobile et avaient déjà divergé de la base. Un code
 * recopié dans un client est un code qu'aucun typecheck ne relie à la base : le jour où il diverge,
 * l'écran ne montre rien et personne n'est prévenu.
 *
 * FRONTIÈRE : ce contrôleur ne décide de rien. Il ne déduit pas la spécialité d'un symptôme, ne
 * classe pas, ne recommande pas — il expose un vocabulaire. Le rapprochement entre un triage et un
 * service appartient à P10.
 */
class SpecialiteController extends Controller
{
    /**
     * GET /api/v1/specialites — le vocabulaire en vigueur.
     *
     * `?nature=specialite_medicale` sépare les spécialités médicales des activités de service
     * (pharmacie, biologie, collecte de sang) : un écran qui demande « choisissez une spécialité »
     * n'a pas à proposer « Collecte de sang ».
     */
    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'nature' => ['nullable', Rule::in(['specialite_medicale', 'activite'])],
        ]);

        $termes = SpecialiteMedicale::query()
            ->active()
            ->where('pays_code', config('referentiels.pays_defaut', 'CI'))
            ->when($filtres['nature'] ?? null, fn ($q, $nature) => $q->where('nature', $nature))
            ->ordonnee()
            ->get()
            ->map(fn (SpecialiteMedicale $s): array => [
                'code'       => $s->code,
                'libelle'    => $s->libelle,
                'nature'     => $s->nature,
                'profession' => $s->profession,
            ]);

        return response()->json(['specialites' => $termes]);
    }
}
