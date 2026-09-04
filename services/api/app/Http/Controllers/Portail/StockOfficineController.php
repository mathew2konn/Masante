<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Medicament;
use App\Models\StockOfficine;
use App\Models\StructureSanitaire;
use App\Services\Medicament\ServiceCodeBarres;
use App\Services\Medicament\ServiceStockOfficine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * B3-b — l'inventaire de MON officine (CDC_11 §7.3 et §7.5).
 *
 * À NE PAS CONFONDRE AVEC {@see PrixOfficineController}, qui déclare un PRIX au comparateur public.
 * Celui-ci tient le STOCK : entrées, sorties, péremptions, seuil d'alerte. Les deux existaient sous
 * le même nom jusqu'à ce lot — c'est précisément pourquoi le premier a été renommé.
 *
 * AUCUN IDENTIFIANT D'OFFICINE DANS L'URL : on tient le stock de SA structure, celle que porte le
 * compte. Un pharmacien ne peut donc pas toucher l'inventaire d'un confrère en changeant l'adresse
 * (anti-IDOR par construction, comme le dossier au Module 4).
 */
class StockOfficineController extends Controller
{
    public function __construct(
        private readonly ServiceStockOfficine $stocks,
        private readonly ServiceCodeBarres $codesBarres,
    ) {}

    /** L'inventaire, ses alertes et ses péremptions proches. */
    public function index(Request $request): View
    {
        $officine = $this->officine();
        $recherche = trim((string) $request->query('q', ''));

        $articles = StockOfficine::with('medicament:id,nom_generique,nom_commercial,dosage,forme')
            ->where('structure_id', $officine->id)
            ->when($recherche !== '', fn ($q) => $q->whereHas(
                'medicament',
                fn ($m) => $m->where('nom_generique', 'like', "%{$recherche}%")
                    ->orWhere('nom_commercial', 'like', "%{$recherche}%")
            ))
            ->get()
            ->sortBy(fn (StockOfficine $a): string => (string) $a->medicament?->nom_generique)
            ->values();

        // B3-c (E6, E9) — le champ de saisie EST le scanner : trouver un produit par son
        // code-barres, pour éviter de saisir un identifiant technique à la main. `identifier()`
        // lit la TABLE, pas l'instantané publié (E9) : la colonne est trop neuve pour y figurer.
        $scan = trim((string) $request->query('scan', ''));
        $scanResultat = $scan === '' ? null : $this->codesBarres->identifier($scan);

        return view('portail.stock-officine.index', [
            'officine' => $officine,
            'articles' => $articles,
            'recherche' => $recherche,
            'alertes' => $this->stocks->alertes($officine),
            'peremptions' => $this->stocks->peremptions($officine),
            'scanSaisie' => $scan,
            'scanResultat' => $scanResultat,
        ]);
    }

    /** Ajoute un produit à l'inventaire (sans mouvement : l'article naît à zéro). */
    public function ajouter(Request $request): RedirectResponse
    {
        $valide = $request->validate([
            'medicament_id' => ['required', 'integer', 'exists:medicaments,id'],
        ], [
            'medicament_id.required' => 'Choisissez un médicament.',
            'medicament_id.exists' => 'Ce médicament ne figure pas au référentiel national.',
        ]);

        try {
            $this->stocks->article(
                $this->officine(),
                Medicament::findOrFail($valide['medicament_id']),
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('portail.stock-officine.index')
            ->with('succes', 'Produit ajouté à votre inventaire.');
    }

    /** Enregistre un mouvement (entrée, sortie, péremption, ajustement). */
    public function mouvement(Request $request, StockOfficine $article): RedirectResponse
    {
        $this->assertMonArticle($article);

        $valide = $request->validate([
            'type' => ['required', 'in:entree,sortie,peremption,ajustement'],
            'quantite' => ['required', 'integer', 'not_in:0'],
            'lot' => ['nullable', 'string', 'max:60'],
            'date_peremption' => ['nullable', 'date'],
            'motif' => ['nullable', 'string', 'max:200'],
        ], [
            'type.required' => 'Indiquez la nature du mouvement.',
            'type.in' => 'Cette nature de mouvement n\'existe pas.',
            'quantite.required' => 'Indiquez une quantité.',
            'quantite.not_in' => 'Un mouvement de stock porte une quantité non nulle.',
            'quantite.integer' => 'Une quantité est un nombre entier.',
        ]);

        try {
            $this->stocks->mouvement(
                $request->user(),
                $article,
                $valide['type'],
                (int) $valide['quantite'],
                [
                    'lot' => $valide['lot'] ?? null,
                    'date_peremption' => $valide['date_peremption'] ?? null,
                    'motif' => $valide['motif'] ?? null,
                ],
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('portail.stock-officine.index')
            ->with('succes', 'Mouvement enregistré.');
    }

    /** Fixe le prix de vente et le seuil d'alerte. */
    public function parametrer(Request $request, StockOfficine $article): RedirectResponse
    {
        $this->assertMonArticle($article);

        $valide = $request->validate([
            'prix_cfa' => ['nullable', 'integer', 'min:1'],
            'seuil_alerte' => ['nullable', 'integer', 'min:0'],
        ], [
            'prix_cfa.min' => 'Un prix de vente est strictement positif.',
            'seuil_alerte.min' => 'Un seuil d\'alerte ne peut pas être négatif.',
        ]);

        try {
            $this->stocks->fixerPrix(
                $request->user(),
                $article,
                isset($valide['prix_cfa']) ? (int) $valide['prix_cfa'] : null,
                isset($valide['seuil_alerte']) ? (int) $valide['seuil_alerte'] : null,
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('portail.stock-officine.index')
            ->with('succes', 'Prix et seuil enregistrés ; le comparateur est à jour.');
    }

    /**
     * L'article doit appartenir à MON officine.
     *
     * **404 et jamais 403** : un 403 confirmerait que cet article existe chez un confrère, et
     * permettrait de découvrir par balayage ce que les autres officines tiennent en rayon.
     */
    private function assertMonArticle(StockOfficine $article): void
    {
        abort_if($article->structure_id !== $this->officine()->id, Response::HTTP_NOT_FOUND);
    }

    /** L'officine du compte connecté — jamais un identifiant reçu. */
    private function officine(): StructureSanitaire
    {
        $structure = auth()->user()?->structure;

        abort_if($structure === null, Response::HTTP_FORBIDDEN, 'Votre compte n\'est rattaché à aucune officine.');
        abort_if(! $structure->estPharmacie(), Response::HTTP_FORBIDDEN, 'Cet espace est réservé aux pharmacies.');

        return $structure;
    }
}
