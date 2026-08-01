<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Services\ReferentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Module 5 / 5.6 — Médecin référent d'un membre, côté patient (voie 2 ; Sécurité §4.4).
 *
 * Désigner un référent, c'est ouvrir un accès PERMANENT à son dossier : l'acte le plus engageant
 * du carnet. D'où trois exigences, dans l'ordre :
 *
 *  1. la Policy `update` (anti-IDOR §4.3) — on ne désigne un référent que sur SES membres ;
 *  2. le palier « compte vérifié » — cohérent avec la règle « partager le dossier exige une identité
 *     confirmée » (Note_Continuite §4.2, déjà appliquée à la délégation). Le stub de dev suit le
 *     même drapeau de configuration que la carte CMU, pour rester testable sans CNI ;
 *  3. le verrou applicatif — imposé côté mobile avant l'écran, comme pour la génération de QR.
 *
 * La révocation, elle, n'exige RIEN : reprendre le contrôle de ses données doit toujours être plus
 * facile que de le céder.
 */
class ReferentController extends Controller
{
    public function __construct(private readonly ReferentService $referents)
    {
    }

    /** Référent actif du membre + historique des désignations révoquées (transparence, §10.3). */
    public function index(MembreFamille $membre): JsonResponse
    {
        $this->authorize('view', $membre);

        return response()->json([
            'referent'   => $this->referents->actif($membre),
            'historique' => $membre->referents()
                ->whereNotNull('revoquee_at')
                ->with('medecin.structure')
                ->orderByDesc('revoquee_at')
                ->get(),
        ]);
    }

    /** Désigne (ou remplace) le médecin référent du membre. */
    public function store(Request $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('update', $membre);
        $this->exigerCompteVerifie($request);

        $donnees = $request->validate([
            'medecin_id' => ['required', 'integer', 'exists:medecins,id'],
        ]);

        $medecin = Medecin::findOrFail($donnees['medecin_id']);

        if (! $medecin->actif) {
            throw ValidationException::withMessages([
                'medecin_id' => 'Ce médecin n\'exerce plus dans l\'annuaire : choisissez-en un autre.',
            ]);
        }

        return response()->json([
            'referent' => $this->referents->designer($membre, $medecin, $request->user()),
        ], 201);
    }

    /**
     * Révoque la désignation : effet immédiat. La ligne reste à l'historique (loi n°2013-450) —
     * le patient doit pouvoir prouver plus tard qui a eu accès à son dossier, et jusqu'à quand.
     */
    public function destroy(MembreFamille $membre, int $id): JsonResponse
    {
        $this->authorize('update', $membre);

        $referent = $membre->referents()->findOrFail($id);

        $this->referents->revoquer($referent);

        return response()->json(['referent' => $referent->fresh()->load('medecin.structure')]);
    }

    /**
     * Palier « vérifié » exigé pour PARTAGER le dossier (Identification §niveaux de compte ;
     * Note_Continuite §4.2). Drapeau propre à la voie 2, dormant en dev faute de flux CMU/CNI —
     * même stub que la délégation (voie 3) et la carte CMU (F2.3).
     */
    private function exigerCompteVerifie(Request $request): void
    {
        if (! config('masante.referent.exiger_titulaire_verifie')) {
            return;
        }

        if (! $request->user()->compteEstVerifie()) {
            throw ValidationException::withMessages([
                'compte' => 'Désigner un médecin référent ouvre un accès permanent à votre dossier : '
                    .'votre identité doit d\'abord être vérifiée.',
            ]);
        }
    }
}
