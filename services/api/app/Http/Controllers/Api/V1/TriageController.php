<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyserTriageRequest;
use App\Models\MembreFamille;
use App\Models\Symptome;
use App\Models\Triage;
use App\Services\TriageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 1 — Triage et orientation médicale (F1.1 à F1.8).
 */
class TriageController extends Controller
{
    public function __construct(private readonly TriageService $triage)
    {
    }

    /**
     * F1.1 — Liste des symptômes actifs, regroupés par catégorie (pour la sélection).
     */
    public function symptomes(): JsonResponse
    {
        $symptomes = Symptome::actif()
            ->orderBy('categorie')
            ->orderBy('nom_fr')
            ->get(['id', 'nom_fr', 'categorie', 'specialite_hint', 'questions_complementaires_json']);

        return response()->json([
            'total'   => $symptomes->count(),
            'par_categorie' => $symptomes->groupBy('categorie'),
            'symptomes' => $symptomes,
        ]);
    }

    /**
     * F1.3 — Analyse un triage, l'enregistre et renvoie le résultat (score, niveau, reco).
     */
    public function analyser(AnalyserTriageRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Le token (s'il est présent) est résolu sans imposer l'auth : un triage anonyme
        // reste possible. Mais un triage RATTACHÉ à un membre exige l'auth + l'appartenance.
        $utilisateur = $request->user('sanctum');
        $membreId = $data['membre_id'] ?? null;
        $antecedents = $this->antecedentsDuMembre($membreId, $utilisateur);

        $resultat = $this->triage->analyser(
            symptomesIds: $data['symptomes'],
            reponses: $data['reponses'] ?? [],
            age: $data['patient_age'] ?? null,
            sexe: $data['patient_sexe'] ?? null,
            antecedents: $antecedents, // carnet du membre (2A.4) : alimente le score (F1.3)
        );

        $triage = Triage::create([
            'user_id'              => $utilisateur?->id,
            'membre_id'            => $membreId,
            'patient_nom'          => $data['patient_nom'] ?? null,
            'patient_age'          => $data['patient_age'] ?? null,
            'patient_sexe'         => $data['patient_sexe'] ?? null,
            'symptomes_json'       => $resultat['symptomes'],
            'reponses_json'        => $resultat['reponses'],
            'score_severite'       => $resultat['score_severite'],
            'niveau'               => $resultat['niveau'],
            'specialite_requise'   => $resultat['specialite_requise'],
            'recommandation_texte' => $resultat['recommandation_texte'],
            'fiche_generee'        => false,
        ]);

        return response()->json([
            'triage_id'            => $triage->id,
            'score_severite'       => $resultat['score_severite'],
            'niveau'               => $resultat['niveau'],
            'specialite_requise'   => $resultat['specialite_requise'],
            'recommandation_texte' => $resultat['recommandation_texte'],
            'drapeau_rouge'        => $resultat['drapeau_rouge'],
            'details_score'        => $resultat['details_score'],
        ], 201);
    }

    /**
     * Antécédents du membre à injecter dans le score de triage (F1.3), avec contrôle d'accès.
     * Renvoie [] pour un triage anonyme (sans membre).
     *
     * @return array<int, array{libelle: string, impact_triage: int}>
     */
    private function antecedentsDuMembre(?int $membreId, $utilisateur): array
    {
        if ($membreId === null) {
            return [];
        }

        // Rattacher un triage à un membre suppose un compte authentifié et propriétaire (anti-IDOR).
        abort_if($utilisateur === null, 401, 'Authentification requise pour un triage rattaché à un membre.');

        $membre = MembreFamille::find($membreId);
        abort_if($membre === null, 404, 'Membre introuvable.');
        abort_unless($membre->user_id === $utilisateur->id, 403, 'Accès non autorisé à ce membre.');

        return $membre->antecedents()
            ->get(['type', 'impact_triage'])
            ->map(fn ($a) => ['libelle' => $a->type, 'impact_triage' => (int) $a->impact_triage])
            ->all();
    }

    /**
     * F1.8 — Fiche de triage partageable (données structurées + texte prêt pour WhatsApp).
     */
    public function fiche(Triage $triage): JsonResponse
    {
        // Marque la fiche comme générée/consultée.
        if (! $triage->fiche_generee) {
            $triage->update(['fiche_generee' => true]);
        }

        $libelleNiveau = ['leger' => 'LÉGER', 'modere' => 'MODÉRÉ', 'urgent' => 'URGENT'][$triage->niveau];
        $couleur = ['leger' => 'vert', 'modere' => 'orange', 'urgent' => 'rouge'][$triage->niveau];
        $noms = collect($triage->symptomes_json)->pluck('nom')->implode(', ');

        $textePartage = "🏥 MaSante — Fiche de triage\n"
            . 'Patient : ' . ($triage->patient_nom ?: 'N/C')
            . ($triage->patient_age !== null ? ', ' . $triage->patient_age . ' ans' : '')
            . ($triage->patient_sexe ? ', ' . $triage->patient_sexe : '') . "\n"
            . 'Symptômes : ' . ($noms ?: 'N/C') . "\n"
            . 'Niveau : ' . $libelleNiveau . ' (score ' . $triage->score_severite . "/100)\n"
            . ($triage->specialite_requise ? 'Spécialité : ' . $triage->specialite_requise . "\n" : '')
            . 'Recommandation : ' . $triage->recommandation_texte . "\n"
            . 'Fait le ' . $triage->created_at->format('d/m/Y à H:i');

        return response()->json([
            'fiche' => [
                'triage_id'            => $triage->id,
                'patient' => [
                    'nom'  => $triage->patient_nom,
                    'age'  => $triage->patient_age,
                    'sexe' => $triage->patient_sexe,
                ],
                'symptomes'            => $triage->symptomes_json,
                'score_severite'       => $triage->score_severite,
                'niveau'               => $triage->niveau,
                'niveau_libelle'       => $libelleNiveau,
                'couleur'              => $couleur,
                'specialite_requise'   => $triage->specialite_requise,
                'recommandation_texte' => $triage->recommandation_texte,
                'date'                 => $triage->created_at->toIso8601String(),
            ],
            'texte_partage' => $textePartage,
        ]);
    }

    /**
     * F1.6 — Historique des triages (récents d'abord), filtrable par membre.
     */
    public function historique(Request $request): JsonResponse
    {
        $triages = Triage::query()
            ->when($request->filled('membre_id'), fn ($q) => $q->where('membre_id', $request->integer('membre_id')))
            ->latest()
            ->limit(50)
            ->get([
                'id', 'membre_id', 'patient_nom', 'patient_age', 'patient_sexe',
                'symptomes_json', 'score_severite', 'niveau', 'specialite_requise',
                'fiche_generee', 'created_at',
            ]);

        return response()->json([
            'total'   => $triages->count(),
            'triages' => $triages,
        ]);
    }
}
