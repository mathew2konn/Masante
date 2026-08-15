<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CouvertureMembre;
use App\Models\MembreFamille;
use App\Services\Assurance\ServiceCouvertures;
use App\Support\TypesOrganismeAssurance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * P6.8d — Les couvertures santé déclarées d'un membre (CDC_09 §8, CDC_06 §8).
 *
 * ═══ CE QUI REMPLACE LES TROIS COLONNES `cmu_*` ═══
 *
 * Une couverture est un **contrat entre une personne et un organisme**, et il peut y en avoir
 * plusieurs — le §8 du CDC_06 enchaîne « CNAM, **puis** assurances privées » sur la même facture.
 *
 * ═══ HABILITATION ═══
 *
 * Lecture : Policy `view` — donc le propriétaire ET le délégué en lecture (P7-A). Écriture : Policy
 * `update` — **le propriétaire seul**. Un délégué lit le carnet, il ne souscrit pas au nom d'autrui.
 *
 * ═══ CE QUE LE SERVEUR NE CROIT PAS DU CLIENT ═══
 *
 * `provenance` n'est pas validée ici, et le service l'efface de toute façon : elle vaut `declare`,
 * et rien d'autre n'est atteignable tant qu'aucune vérification auprès d'un organisme n'existe
 * (décision F2 ; l'étape 2 du §8.1 du CDC_06 n'existe pas dans ce projet).
 *
 * ═══ CE QUE L'ÉCRAN DOIT DIRE, ET QUE LA RÉPONSE PORTE ═══
 *
 * Chaque réponse porte `mention_provenance`. La carte F2.3 affirmait « il confirme votre statut
 * CMU » d'une case remplie par l'intéressé lui-même : la mention n'est pas décorative, c'est la
 * correction du seul défaut que ce module pouvait corriger — le mot, faute d'API CNAM.
 */
class CouvertureMembreController extends Controller
{
    public function __construct(private readonly ServiceCouvertures $couvertures) {}

    /** GET /membres/{membre}/couvertures */
    public function index(MembreFamille $membre): JsonResponse
    {
        $this->authorize('view', $membre);

        return response()->json([
            'couvertures'        => $this->presenter($membre),
            'mention_provenance' => CouvertureMembre::MENTION_PROVENANCE,
        ]);
    }

    /** POST /membres/{membre}/couvertures */
    public function store(Request $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('update', $membre);

        $donnees    = $this->couvertures->preparer($this->valider($request));
        $couverture = $this->couvertures->enregistrer($membre, $donnees);

        return response()->json([
            'couverture'         => $this->presenterUne($couverture),
            // NON BLOQUANTS : un agrément suspendu est signalé, jamais opposé à l'assuré — il l'est
            // toujours, et refuser sa déclaration effacerait un fait réel.
            'avertissements'     => $this->couvertures->avertissements($couverture->organisme_assurance_id),
            'mention_provenance' => CouvertureMembre::MENTION_PROVENANCE,
        ], 201);
    }

    /** PUT /membres/{membre}/couvertures/{couverture} */
    public function update(Request $request, MembreFamille $membre, CouvertureMembre $couverture): JsonResponse
    {
        $this->authorize('update', $membre);
        $this->exigerLaMeme($membre, $couverture);

        $donnees = $this->couvertures->preparer($this->valider($request));
        $this->couvertures->mettreAJour($couverture, $donnees);

        return response()->json([
            'couverture'         => $this->presenterUne($couverture->fresh()),
            'avertissements'     => $this->couvertures->avertissements($couverture->organisme_assurance_id),
            'mention_provenance' => CouvertureMembre::MENTION_PROVENANCE,
        ]);
    }

    /** DELETE /membres/{membre}/couvertures/{couverture} */
    public function destroy(MembreFamille $membre, CouvertureMembre $couverture): JsonResponse
    {
        $this->authorize('update', $membre);
        $this->exigerLaMeme($membre, $couverture);

        $couverture->delete();

        return response()->json(['supprime' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function valider(Request $request): array
    {
        return $request->validate([
            'organisme_assurance_id' => ['nullable', 'integer', Rule::exists('organismes_assurance', 'id')],
            // Le repli hors référentiel (motif E4). `required_without` plutôt qu'un simple
            // `nullable` : une couverture qui ne nomme aucun organisme n'affirme rien — « je suis
            // assuré » sans dire chez qui n'est pas une information. Le moteur le refuse aussi
            // (déclencheur), et les deux gardes ont deux publics.
            'organisme_libelle'      => ['nullable', 'required_without:organisme_assurance_id', 'string', 'max:200'],
            'numero_assure'          => ['nullable', 'string', 'max:60'],
            'date_debut'             => ['nullable', 'date'],
            'date_fin'               => ['nullable', 'date', 'after_or_equal:date_debut'],
            'resiliee_le'            => ['nullable', 'date'],
        ], [
            'organisme_libelle.required_without' => 'Indiquez l\'organisme : choisissez-le dans la '
                .'liste, ou saisissez son nom s\'il n\'y figure pas.',
            'date_fin.after_or_equal' => 'La couverture ne peut pas se terminer avant de commencer.',
        ]);
    }

    /**
     * Anti-IDOR : une couverture d'un autre membre répond 404, jamais une modification transversale
     * (même principe que les libellés de maladie en P6.8c et les sections du carnet en P7-C).
     */
    private function exigerLaMeme(MembreFamille $membre, CouvertureMembre $couverture): void
    {
        if ($couverture->membre_id !== $membre->id) {
            abort(404);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presenter(MembreFamille $membre): array
    {
        return $membre->couvertures()
            ->with('organisme')
            ->orderByDesc('date_debut')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CouvertureMembre $c): array => $this->presenterUne($c))
            ->all();
    }

    /**
     * ═══ LE NOM VIENT DE L'ORGANISME, IL N'EST PAS FIGÉ SUR LA LIGNE ═══
     *
     * Rupture assumée avec P6.6b / P6.7b / P6.8c, et la raison est de nature : ceux-là inscrivaient
     * un fait HISTORIQUE dans un carnet. Une couverture est un ÉTAT COURANT — « je suis assuré chez
     * X aujourd'hui ». Si X est renommé, la phrase reste vraie sous le nouveau nom, et afficher
     * l'ancien ferait porter à l'assuré un nom que le guichet ne reconnaît plus.
     *
     * @return array<string, mixed>
     */
    private function presenterUne(CouvertureMembre $couverture): array
    {
        $couverture->loadMissing('organisme');
        $organisme = $couverture->organisme;

        return [
            'id'                     => $couverture->id,
            'organisme_assurance_id' => $couverture->organisme_assurance_id,
            'organisme_code'         => $organisme?->code,
            // Le libellé libre ne sert QUE quand aucun organisme n'est rattaché : les deux ensemble
            // seraient deux vérités sur la même ligne (motif P6.7b).
            'organisme_nom'          => $organisme?->nom ?? $couverture->organisme_libelle,
            'organisme_sigle'        => $organisme?->sigle,
            'type'                   => $organisme?->type,
            'type_libelle'           => $organisme !== null
                ? TypesOrganismeAssurance::libelle((string) $organisme->type)
                : null,
            // Le témoin de l'écart (motif E4) : compté, affiché, jamais bloqué.
            'hors_referentiel'       => $couverture->estHorsReferentiel(),
            'numero_masque'          => $couverture->numero_masque,
            'date_debut'             => $couverture->date_debut?->toDateString(),
            'date_fin'               => $couverture->date_fin?->toDateString(),
            'resiliee_le'            => $couverture->resiliee_le?->toDateString(),
            // CALCULÉ, jamais déclaré (P6.8d) — et par une fonction des seules colonnes de la ligne.
            'statut'                 => $couverture->statut,
            'provenance'             => $couverture->provenance,
        ];
    }
}
