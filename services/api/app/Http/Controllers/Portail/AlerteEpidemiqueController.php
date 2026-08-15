<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\AlerteEpidemique;
use App\Services\Maladie\ServiceLienMaladie;
use App\Services\Maladie\ServiceMaladies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Module 5 / 5.4 — Gestion des alertes épidémiques (CdC FN3), admin IVOIRSANTÉ uniquement
 * (`permission:sante_publique.manage`).
 *
 * L'admin reporte les bulletins OMS / Ministère de la Santé CI. Une alerte se désactive plutôt
 * qu'elle ne se supprime : on conserve l'historique des épisodes épidémiques.
 *
 * ═══ P6.8c — LA LISTE EN DUR A DISPARU, ET LA PORTE RESTE ENTROUVERTE ═══
 *
 * Cet écran portait sept libellés EN DUR dans une constante, offerts par un `<datalist>` — pendant
 * que la validation acceptait `required|string|max:100`. Le commentaire du code l'avouait : « champ
 * libre malgré tout ». Le menu **ressemblait** à une contrainte sans en être une, et ce libellé part
 * BRUT dans la bannière du mobile : une faute de frappe s'affichait telle quelle à toute une commune,
 * et « combien d'alertes de choléra cette année ? » restait insoluble.
 *
 * La liste vient désormais de la **version publiée** du référentiel national. Mais le lien reste
 * **FACULTATIF** (décision propriétaire E4), et ce n'est pas un relâchement :
 *
 * *Une maladie émergente n'est dans aucune nomenclature au moment où elle émerge.* En décembre 2019
 * la bonne alerte s'appelait « pneumonie atypique d'origine inconnue ». Imposer un référentiel dont
 * on dit soi-même qu'il est incomplet ferait payer ses lacunes à une alerte **urgente** — c'est
 * l'argument de P6.6b, ici sur un sujet où le refus a un coût de santé publique.
 *
 * L'écart n'est donc pas supprimé : il est **compté et affiché** sur la liste. *Ce qu'on ne peut pas
 * fermer, on le rend visible.*
 */
class AlerteEpidemiqueController extends Controller
{
    public const NIVEAUX = [
        'information' => 'Information',
        'vigilance'   => 'Vigilance',
        'alerte'      => 'Alerte',
    ];

    public function __construct(
        private readonly ServiceMaladies $maladies,
        private readonly ServiceLienMaladie $lien,
    ) {}

    public function index(): View
    {
        return view('portail.sante-publique.index', [
            'alertes' => AlerteEpidemique::with('publiePar:id,prenom,nom')
                ->orderByDesc('actif')
                ->orderByDesc('date_debut')
                ->paginate(20),
            // LE TÉMOIN DE L'ÉCART (E4) : ce qui n'a pas pu être rattaché est compté, jamais tu.
            'horsReferentiel' => AlerteEpidemique::horsReferentiel()->count(),
            'totalAlertes'    => AlerteEpidemique::count(),
        ]);
    }

    public function create(): View
    {
        return view('portail.sante-publique.form', [
            'alerte'   => new AlerteEpidemique(['date_debut' => now()->toDateString(), 'niveau_alerte' => 'vigilance']),
            'niveaux'  => self::NIVEAUX,
            ...$this->referentiel(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $alerte = AlerteEpidemique::create([
            ...$this->valider($request),
            'publie_par_user_id' => auth()->id(),
        ]);

        return redirect()->route('portail.sante-publique.index')
            ->with('statut', "Alerte « {$alerte->titre} » publiée.");
    }

    public function edit(AlerteEpidemique $alerte): View
    {
        return view('portail.sante-publique.form', [
            'alerte'   => $alerte,
            'niveaux'  => self::NIVEAUX,
            ...$this->referentiel(),
        ]);
    }

    public function update(Request $request, AlerteEpidemique $alerte): RedirectResponse
    {
        $alerte->update($this->valider($request));

        return redirect()->route('portail.sante-publique.index')->with('statut', 'Alerte mise à jour.');
    }

    /** Active / désactive sans supprimer (conservation de l'historique). */
    public function toggleActif(AlerteEpidemique $alerte): RedirectResponse
    {
        $alerte->update(['actif' => ! $alerte->actif]);

        return back()->with('statut', $alerte->actif ? 'Alerte réactivée.' : 'Alerte désactivée.');
    }

    /**
     * Ce que le formulaire propose, DEPUIS LA VERSION PUBLIÉE.
     *
     * `listePubliee()` NE LÈVE RIEN, à la différence de l'API : une liste vide veut dire « aucune
     * version du référentiel n'est en vigueur », et l'écran le dit. Un 503 au milieu d'une saisie
     * d'alerte sanitaire serait la panne au pire moment — et la saisie libre reste ouverte (E4).
     *
     * @return array<string, mixed>
     */
    private function referentiel(): array
    {
        return [
            'maladiesReferentiel' => $this->maladies->listePubliee(),
            'referentielEnVigueur' => $this->maladies->estEnVigueur(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function valider(Request $request): array
    {
        // La portée est saisie via un choix (commune / nationale) + un champ texte : on en dérive
        // la valeur réelle de `commune` avant validation. Une alerte nationale porte la sentinelle.
        $request->merge([
            'commune' => $request->input('portee') === 'nationale'
                ? AlerteEpidemique::NATIONALE
                : trim((string) $request->input('commune_saisie')),
        ]);

        $valide = $request->validate([
            'commune'       => ['required', 'string', 'max:100'],
            'titre'         => ['required', 'string', 'max:300'],
            'description'   => ['required', 'string', 'max:5000'],
            // P6.8c — le lien au référentiel. L'existence est vérifiée par le service et non par
            // `exists:`, pour que le message nomme la maladie introuvable (précédent P6.6b).
            'maladie_id'    => ['nullable', 'integer'],
            // `required_without` et non `required` : quand un lien est fourni, le libellé est REPRIS
            // du référentiel, jamais du client. Sans lien, il redevient obligatoire — une alerte
            // sans maladie nommée ne dirait rien à personne.
            'maladie'       => ['required_without:maladie_id', 'nullable', 'string', 'max:100'],
            'niveau_alerte' => ['required', Rule::in(array_keys(self::NIVEAUX))],
            'source'        => ['required', 'string', 'max:200'],
            'date_debut'    => ['required', 'date'],
            'date_fin'      => ['nullable', 'date', 'after_or_equal:date_debut'],
            // `actif` n'est pas édité ici : l'activation passe par `toggleActif` (une case à cocher
            // décochée serait absente de la requête, donc ambiguë en mise à jour).
        ], [
            'maladie.required_without' => 'Nommez la maladie, ou choisissez-la dans le référentiel '
                .'national.',
        ]);

        return $this->lien->resoudreAlerte($valide);
    }
}
