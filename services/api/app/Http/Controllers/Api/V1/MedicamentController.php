<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Medicament;
use App\Models\StructureSanitaire;
use App\Services\Medicament\ServiceInteractions;
use App\Services\Medicament\ServiceLienMedicament;
use App\Services\PrixMedicamentService;
use App\Services\RecuOcrService;
use App\Services\Referentiel\DiffusionReferentiel;
use App\Services\Referentiel\SourceMedicaments;
use App\Support\Medicaments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 5 / 5.8 — Comparateur de prix (FN7) et ruptures (FN8), côté patient.
 *
 * Le catalogue, les prix et les ruptures sont PUBLICS en lecture : savoir où trouver un médicament
 * et à quel prix ne demande aucune identité (doc Identification — accès léger), et une information
 * de prix n'a d'utilité que largement diffusée.
 *
 * SIGNALER, en revanche, exige un compte : un relevé anonyme ne se conteste pas, et un comparateur
 * ouvert aux signalements anonymes se fait empoisonner en une nuit. `signale_par_user_id` est la
 * contrepartie du droit de parler.
 */
class MedicamentController extends Controller
{
    public function __construct(
        private readonly PrixMedicamentService $prix,
        private readonly RecuOcrService $ocr,
    ) {
    }

    /**
     * Recherche au catalogue (public), par code national, DCI, nom commercial ou catégorie.
     *
     * LES ÉNUMÉRATIONS ACCOMPAGNENT LA RÉPONSE, et ce n'est pas de l'ornement : sans elles, chaque
     * client recopierait la liste des formes et des voies pour afficher « Comprimé » plutôt que
     * `comprime`. C'est exactement le défaut trouvé en P6.4b, où sept libellés de catégorie vivaient
     * en dur côté mobile et avaient divergé de la base sans que le typecheck le voie.
     */
    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'q'         => ['nullable', 'string', 'max:120'],
            'categorie' => ['nullable', 'string', 'max:100'],
        ]);

        $medicaments = Medicament::query()
            ->when($filtres['q'] ?? null, fn ($q, $terme) => $q->where(
                fn ($sous) => $sous->where('nom_generique', 'like', "%{$terme}%")
                    ->orWhere('nom_commercial', 'like', "%{$terme}%")
                    ->orWhere('code', 'like', "%{$terme}%")
            ))
            ->when($filtres['categorie'] ?? null, fn ($q, $c) => $q->where('categorie', $c))
            ->orderBy('nom_generique')
            ->limit(50)
            ->get();

        return response()->json([
            'medicaments'  => $medicaments,
            'enumerations' => Medicaments::pourApi(),
        ]);
    }

    /**
     * P6.6b — Les interactions DÉCLARÉES entre plusieurs médicaments (CDC_09 §6.2).
     *
     * ═══ CE QUE CET ENDPOINT EST, ET CE QU'IL N'EST PAS ═══
     *
     * Il RAPPORTE ce que le référentiel national affirme. Il ne calcule aucun risque, ne classe
     * aucune gravité de son propre chef, ne propose aucune alternative et n'adapte aucune dose :
     * tout cela appartient au `interaction-service` de CDC_05 §2. Rien n'est bloqué, rien n'est
     * décidé — une machine qui refuserait une association prendrait une décision médicale
     * (CDC_00 §4).
     *
     * LA RÉPONSE CITE LA VERSION DU RÉFÉRENTIEL qui l'affirme. Sans elle, une interaction lue
     * aujourd'hui serait inexplicable demain si le référentiel change — c'est exactement le défaut
     * que L1/L2 ont refermé sur les seuils de mesure.
     *
     * ON RÉSOUT PAR MOLÉCULE, pas par identifiant : une interaction déclarée sur l'aspirine
     * générique vaut pour la marque qui contient la même aspirine. Ne chercher que les
     * identifiants prescrits produirait un silence qui ressemblerait à « aucune interaction ».
     */
    public function interactions(Request $request, ServiceInteractions $interactions, ServiceLienMedicament $lien, DiffusionReferentiel $diffusion): JsonResponse
    {
        $filtres = $request->validate([
            'medicament_id'   => ['required', 'array', 'min:2'],
            'medicament_id.*' => ['integer'],
        ]);

        $etendus = $lien->etendreParMolecule($filtres['medicament_id']);

        $declarees = $interactions->entre($etendus)->map(fn ($i) => [
            'niveau'           => $i->niveau,
            'niveau_libelle'   => Medicaments::libelleNiveau($i->niveau),
            'medicament_a'     => ['code' => $i->medicamentA?->code, 'libelle' => $i->medicamentA?->libelle],
            'medicament_b'     => ['code' => $i->medicamentB?->code, 'libelle' => $i->medicamentB?->libelle],
            'description'      => $i->description,
            'conduite_a_tenir' => $i->conduite_a_tenir,
            'source'           => $i->source,
        ])->values();

        return response()->json([
            'interactions' => $declarees,
            // L'estampille du référentiel — nulle tant qu'aucune version n'est publiée, et on le
            // dit plutôt que de laisser croire que la réponse fait autorité.
            'referentiel'  => $diffusion->estampille(SourceMedicaments::CODE),
            'avertissement' => 'Le référentiel rapporte les interactions déclarées. Il ne remplace '
                .'pas l\'analyse d\'un professionnel de santé et ne tient compte ni du terrain du '
                .'patient, ni des doses, ni des durées de traitement.',
        ]);
    }

    /**
     * Le comparateur d'UN médicament (public) : prix par pharmacie (du moins cher au plus cher),
     * référence officielle CENAME, et génériques moins chers (FN7).
     */
    public function prix(Request $request, Medicament $medicament): JsonResponse
    {
        $filtres = $request->validate(['commune' => ['nullable', 'string', 'max:100']]);

        return response()->json([
            'medicament' => $medicament,
            'offres'     => $this->prix->comparer($medicament, $filtres),
            'generiques' => $this->prix->generiquesMoinsChers($medicament),
        ]);
    }

    /** FN8 — Vue agrégée des ruptures du moment (public) : où ça manque, et dans combien d'officines. */
    public function ruptures(Request $request): JsonResponse
    {
        $filtres = $request->validate(['commune' => ['nullable', 'string', 'max:100']]);

        return response()->json(['ruptures' => $this->prix->ruptures($filtres['commune'] ?? null)]);
    }

    /** Le patient rapporte un prix payé (crowdsourcing). Plausibilité vérifiée avant écriture. */
    public function releverPrix(Request $request, Medicament $medicament): JsonResponse
    {
        $donnees = $request->validate([
            'structure_id' => ['required', 'integer', 'exists:structures_sanitaires,id'],
            'prix_cfa'     => ['required', 'integer', 'min:1'],
        ]);

        $releve = $this->prix->releverPrix(
            $medicament,
            StructureSanitaire::findOrFail($donnees['structure_id']),
            $donnees['prix_cfa'],
            'crowdsource_patient',
            $request->user(),
        );

        return response()->json(['releve' => $releve], 201);
    }

    /** FN8 — Le patient signale une rupture (« je l'ai cherché, il n'y en avait pas »). */
    public function signalerRupture(Request $request, Medicament $medicament): JsonResponse
    {
        $donnees = $request->validate([
            'structure_id' => ['required', 'integer', 'exists:structures_sanitaires,id'],
        ]);

        $releve = $this->prix->signalerRupture(
            $medicament,
            StructureSanitaire::findOrFail($donnees['structure_id']),
            'crowdsource_patient',
            $request->user(),
        );

        return response()->json(['releve' => $releve], 201);
    }

    /**
     * FN7 — « Scan de reçu » : lit la photo d'un ticket et PROPOSE des montants.
     *
     * Ne crée AUCUN relevé : le patient choisit le montant, le corrige au besoin, puis le soumet
     * par `releverPrix`. L'OCR aide à taper, il ne décide pas. La photo est détruite dès la lecture
     * faite ({@see RecuOcrService}) : ce qu'on garde, c'est un nombre, pas une image du ticket.
     */
    public function lireRecu(Request $request): JsonResponse
    {
        if (! $this->ocr->estDisponible()) {
            return response()->json([
                'message'  => 'La lecture automatique est indisponible : saisissez le prix à la main.',
                'montants' => [],
            ], 503);
        }

        $request->validate([
            'recu' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:'.config('masante.ocr.max_ko')],
        ]);

        return response()->json($this->ocr->lire($request->file('recu')));
    }
}
