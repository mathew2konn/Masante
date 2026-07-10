<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Avis;
use App\Models\Signalement;
use App\Services\NoteStructureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Module 4 / 4.6 — Modération des avis et des signalements (CdC §5.4.2, F3.9 / F3.10).
 *
 * Réservée à l'ADMIN IVOIRSANTÉ (`permission:moderation.manage`) : le gestionnaire ne modère pas,
 * pas même les avis portant sur son propre établissement — il serait juge et partie.
 *
 * AVIS — masquer / rétablir, jamais supprimer : la modération reste réversible et l'auteur peut
 * contester. Masquer recalcule `note_moyenne` / `nb_avis` de la structure ({@see NoteStructureService}),
 * sinon la fiche publique afficherait une moyenne sans rapport avec les avis affichés.
 *
 * SIGNALEMENTS — deux décisions distinctes, volontairement :
 *   1. `statut` valide/rejete : le fait est-il avéré ?
 *   2. `visible_publiquement` : faut-il l'afficher dans l'historique public de la structure ?
 * Un signalement de pot-de-vin peut ainsi être reconnu et traité en interne SANS être publié —
 * publier une accusation nominative sur la fiche d'un hôpital n'est pas anodin.
 *
 * Toute décision est tracée (modérateur, instant, motif) ; l'anonymat du signalant est préservé
 * jusque dans le portail (`user_id` masqué). Les textes libres sont rendus par Blade, qui échappe
 * par défaut : aucun `{!! !!}` sur du contenu utilisateur (Sécurité §A03, XSS).
 */
class ModerationController extends Controller
{
    /** Types de signalement (miroir de l'enum de la migration). */
    public const TYPES = [
        'structure_fermee'   => 'Structure fermée',
        'hors_service'       => 'Équipement hors service',
        'pot_de_vin'         => 'Demande de pot-de-vin',
        'mauvais_traitement' => 'Mauvais traitement',
        'autre'              => 'Autre',
    ];

    public function __construct(private readonly NoteStructureService $notes)
    {
    }

    /**
     * File de modération. Par défaut, ce qui appelle une décision : les avis signalés ou masqués
     * et les signalements en attente.
     */
    public function index(Request $request): View
    {
        $onglet = $request->string('onglet')->toString() === 'avis' ? 'avis' : 'signalements';

        return view('portail.moderation.index', [
            'onglet'       => $onglet,
            'types'        => self::TYPES,
            'avis'         => $onglet === 'avis' ? $this->avisAModerer($request) : collect(),
            'signalements' => $onglet === 'signalements' ? $this->signalementsAModerer($request) : collect(),
            'filtre'       => $request->string('filtre')->toString(),
            'enAttente'    => Signalement::where('statut', 'en_attente')->count(),
            'aExaminer'    => Avis::where('signale', true)->orWhere('visible', false)->count(),
        ]);
    }

    /** Avis : `filtre` = signales (défaut) | masques | tous. */
    private function avisAModerer(Request $request)
    {
        $requete = Avis::with('structure:id,nom,commune')->latest();

        return match ($request->string('filtre')->toString()) {
            'masques' => $requete->where('visible', false)->paginate(15)->withQueryString(),
            'tous'    => $requete->paginate(15)->withQueryString(),
            default   => $requete->where('signale', true)->paginate(15)->withQueryString(),
        };
    }

    /** Signalements : `filtre` = en_attente (défaut) | valide | rejete | tous. */
    private function signalementsAModerer(Request $request)
    {
        $requete = Signalement::with('structure:id,nom,commune')->latest();
        $filtre = $request->string('filtre')->toString();

        return in_array($filtre, ['valide', 'rejete', 'tous'], true)
            ? ($filtre === 'tous' ? $requete : $requete->where('statut', $filtre))->paginate(15)->withQueryString()
            : $requete->where('statut', 'en_attente')->paginate(15)->withQueryString();
    }

    // ---- Avis ---------------------------------------------------------------

    /**
     * Masque ou rétablit un avis. Le motif est exigé pour masquer (décision défavorable à
     * l'auteur), facultatif pour rétablir. Le drapeau `signale` retombe dans les deux cas :
     * la décision humaine remplace l'alerte automatique par mots interdits.
     */
    public function basculerAvis(Request $request, Avis $avis): RedirectResponse
    {
        $masquer = (bool) $avis->visible;   // visible aujourd'hui → la bascule le masque

        $data = $request->validate([
            'motif' => [$masquer ? 'required' : 'nullable', 'string', 'max:500'],
        ], [], ['motif' => 'motif de modération']);

        $avis->update([
            'visible'            => ! $masquer,
            'signale'            => false,
            'modere_par_user_id' => auth()->id(),
            'modere_at'          => now(),
            'motif_moderation'   => $data['motif'] ?? null,
        ]);

        // La note publique de la structure doit refléter les seuls avis visibles.
        $this->notes->recalculer($avis->structure);

        return back()->with('statut', $masquer
            ? 'Avis masqué. La note de la structure a été recalculée.'
            : 'Avis rétabli. La note de la structure a été recalculée.');
    }

    // ---- Signalements -------------------------------------------------------

    /** Tranche un signalement : `valide` (fait avéré) ou `rejete`. Motif obligatoire au rejet. */
    public function trancher(Request $request, Signalement $signalement): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:valide,rejete'],
            'motif'    => [$request->input('decision') === 'rejete' ? 'required' : 'nullable', 'string', 'max:500'],
        ], [], ['motif' => 'motif de modération']);

        $signalement->update([
            'statut'             => $data['decision'],
            // Un signalement rejeté ne peut pas rester public s'il l'avait été par erreur.
            'visible_publiquement' => $data['decision'] === 'valide' ? $signalement->visible_publiquement : false,
            'modere_par_user_id' => auth()->id(),
            'modere_at'          => now(),
            'motif_moderation'   => $data['motif'] ?? null,
        ]);

        return back()->with('statut', $data['decision'] === 'valide'
            ? 'Signalement validé. Il n\'est pas encore public : utilisez « Publier » si nécessaire.'
            : 'Signalement rejeté.');
    }

    /**
     * Publie ou retire de l'historique public un signalement DÉJÀ VALIDÉ (décision distincte de
     * la validation, cf. en-tête). Publier un signalement non validé n'a pas de sens : 422.
     */
    public function basculerPublication(Signalement $signalement): RedirectResponse
    {
        if ($signalement->statut !== 'valide') {
            return back()->withErrors(['publication' => 'Seul un signalement validé peut être publié.']);
        }

        $publier = ! $signalement->visible_publiquement;

        $signalement->update([
            'visible_publiquement' => $publier,
            'modere_par_user_id'   => auth()->id(),
            'modere_at'            => now(),
        ]);

        return back()->with('statut', $publier
            ? 'Signalement publié dans l\'historique de la structure.'
            : 'Signalement retiré de l\'historique public.');
    }
}
