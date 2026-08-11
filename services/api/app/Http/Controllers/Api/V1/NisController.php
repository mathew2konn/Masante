<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MembreFamille;
use App\Services\Nis\CalculateurNis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Identifiant National de Santé — vérification et lecture (CDC_09 §3.4, P6.1).
 *
 * FRONTIÈRE (CDC_01 §0.1) : ce contrôleur ne calcule aucune règle métier. Il délègue au
 * `CalculateurNis` (classe pure) et se contente de traduire en HTTP.
 *
 * ANTI-ÉNUMÉRATION — décision de conception structurante : `verifier()` ne consulte JAMAIS la
 * base. Un endpoint public qui confirmerait l'EXISTENCE d'un NIS serait un oracle permettant
 * de balayer la population nationale (CDC_10 §5, IDOR / exfiltration). On valide donc le
 * FORMAT et la CLÉ, rien d'autre — ce qui suffit au feedback de saisie exigé par CDC_09 §3.4.
 */
class NisController extends Controller
{
    public function __construct(private readonly CalculateurNis $calculateur) {}

    /**
     * GET /api/v1/nis/{nis}/verifier — public, rate-limité.
     *
     * Réponse volontairement pauvre : { valide, motif? }. Aucune information sur le porteur,
     * aucune confirmation d'existence.
     */
    public function verifier(string $nis): JsonResponse
    {
        $resultat = $this->calculateur->verifier($nis);

        return response()->json([
            'data' => [
                'valide' => $resultat['valide'],
                'motif'  => $resultat['motif'] ?? null,
            ],
        ]);
    }

    /**
     * GET /api/v1/membres/{membre}/nis — authentifié, réservé au propriétaire du dossier.
     *
     * L'autorisation passe par la Policy existante (`view`), qui porte déjà la règle
     * d'isolation des dossiers — on ne la réécrit pas (P2 validé G5).
     */
    public function afficher(Request $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('view', $membre);

        return response()->json([
            'data' => [
                'nis'             => $membre->nis,
                'nis_attribue_le' => $membre->nis_attribue_le,
                'pays_code'       => $membre->pays_code,
            ],
        ]);
    }
}
