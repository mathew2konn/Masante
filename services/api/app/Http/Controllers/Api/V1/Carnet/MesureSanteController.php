<?php

namespace App\Http\Controllers\Api\V1\Carnet;

use App\Http\Controllers\Controller;
use App\Models\MembreFamille;
use App\Models\ReferentielMesure;
use App\Services\MesureSanteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 5 / 5.6 — Journal de bord des maladies chroniques (CdC FN5).
 *
 * Le mobile ne connaît AUCUN seuil : il reçoit le référentiel (`referentiels`), s'en sert pour
 * saisir (unité, décimales, bornes) et afficher (couleur du statut, conseil), mais c'est le serveur
 * qui qualifie chaque valeur. Corriger une norme médicale se fait donc toujours sans redéployer
 * l'app — mais depuis L1 (ADR-025 §5) cela ne se fait plus par un `UPDATE` : il faut PUBLIER une
 * nouvelle version du référentiel national (proposition + validation par un second agent, §10).
 * L'app, elle, n'a toujours rien à réapprendre.
 *
 * La tension se saisit d'un geste (`type_mesure = tension`, systolique + diastolique) et s'écrit en
 * deux lignes, comme le veut l'ENUM du CdC : voir {@see MesureSanteService}.
 *
 * Pas d'`update` : une mesure est un FAIT daté. Une saisie erronée se supprime (si elle vient du
 * patient) et se ressaisit — corriger silencieusement une valeur passée réécrirait une courbe
 * médicale, ce qu'aucun dossier ne permet.
 */
class MesureSanteController extends Controller
{
    /** Profondeur d'historique renvoyée par défaut (jours) : trois mois de courbe. */
    private const FENETRE_JOURS = 90;

    public function __construct(private readonly MesureSanteService $mesures)
    {
    }

    /**
     * Journal + référentiel + résumé par type (dernière valeur connue et son statut).
     * Renvoyé même sans aucune mesure : le référentiel seul permet déjà d'ouvrir l'écran de saisie.
     */
    public function index(Request $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('view', $membre);

        $filtres = $request->validate([
            'type'  => ['nullable', Rule::in($this->mesures->typesConnus())],
            'jours' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);

        $jours = $filtres['jours'] ?? self::FENETRE_JOURS;

        $journal = $membre->mesuresSante()
            ->when($filtres['type'] ?? null, fn ($q, $type) => $q->where('type_mesure', $type))
            ->where('date_mesure', '>=', now()->subDays($jours))
            ->orderByDesc('date_mesure')
            ->get();

        return response()->json([
            'referentiels' => $this->mesures->referentiels(),
            'mesures'      => $journal,
            'resume'       => $this->resume($membre),
            'jours'        => $jours,
        ]);
    }

    /** Enregistre une saisie (une mesure simple, ou une tension = deux lignes liées). */
    public function store(Request $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('update', $membre);

        $donnees = $request->validate($this->regles($request));

        $mesures = $this->mesures->enregistrer($membre, $donnees);

        // Le mobile affiche l'alerte : c'est le serveur qui dit si la valeur est critique, et quel
        // conseil médical l'accompagne (référentiel en base, jamais de texte médical figé dans l'app).
        $critiques = $mesures->where('statut_norme', 'critique');

        return response()->json([
            'mesures' => $mesures->values(),
            'alerte'  => $critiques->isEmpty() ? null : [
                'statut'  => 'critique',
                'conseil' => $this->mesures->referentiel($critiques->first()->type_mesure)?->conseil_anormal,
            ],
        ], 201);
    }

    /**
     * Supprime une saisie erronée du patient. Une mesure enregistrée par une structure n'est pas
     * la sienne à effacer (F2.13) : elle appartient au dossier hospitalier.
     */
    public function destroy(MembreFamille $membre, int $id): JsonResponse
    {
        $this->authorize('update', $membre);

        $mesure = $membre->mesuresSante()->findOrFail($id);

        if (! $mesure->estSupprimableParPatient()) {
            throw ValidationException::withMessages([
                'mesure' => 'Cette mesure a été enregistrée par une structure de santé : elle ne peut pas '
                    .'être supprimée depuis le carnet.',
            ]);
        }

        return response()->json(['supprimees' => $this->mesures->supprimer($membre, $mesure)]);
    }

    /**
     * Règles de saisie, construites À PARTIR DU RÉFÉRENTIEL EN VIGUEUR : les bornes de plausibilité
     * (`valeur_min`/`valeur_max`) viennent de la version publiée, jamais du code. Une glycémie à
     * 500 g/L est une faute de frappe — on la refuse avant d'écrire, plutôt que d'alerter sur une
     * valeur absurde.
     *
     * @return array<string, array<int, mixed>>
     */
    private function regles(Request $request): array
    {
        $types = $this->mesures->typesConnus();
        $type = $request->input('type_mesure');
        $estTension = $type === MesureSanteService::TYPE_TENSION;

        $regles = [
            'type_mesure' => ['required', Rule::in([...$types, MesureSanteService::TYPE_TENSION])],
            // Une mesure ne peut pas être future ; on tolère la saisie a posteriori (« hier soir »).
            'date_mesure' => ['required', 'date', 'before_or_equal:now'],
            'note'        => ['nullable', 'string', 'max:500'],
        ];

        if ($estTension) {
            $regles['systolique'] = $this->bornes('tension_systolique');
            $regles['diastolique'] = $this->bornes('tension_diastolique');

            return $regles;
        }

        $regles['valeur'] = $type !== null && in_array($type, $types, true)
            ? $this->bornes($type)
            : ['required', 'numeric'];   // type inconnu : `type_mesure` échouera de toute façon

        return $regles;
    }

    /**
     * Bornes de plausibilité d'un type, lues au référentiel.
     *
     * @return array<int, mixed>
     */
    private function bornes(string $type): array
    {
        $seuils = $this->mesures->referentiel($type);

        return ['required', 'numeric', 'min:'.$seuils->valeur_min, 'max:'.$seuils->valeur_max];
    }

    /**
     * Dernière valeur connue par type de mesure — ce que le patient veut voir d'un coup d'œil en
     * ouvrant l'écran, sans dérouler tout le journal.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resume(MembreFamille $membre): array
    {
        return $this->mesures->referentiels()
            ->map(function (ReferentielMesure $seuils) use ($membre) {
                $derniere = $membre->mesuresSante()
                    ->where('type_mesure', $seuils->type_mesure)
                    ->orderByDesc('date_mesure')
                    ->first();

                return [
                    'type_mesure'  => $seuils->type_mesure,
                    'libelle'      => $seuils->libelle,
                    'unite'        => $seuils->unite,
                    'valeur'       => $derniere?->valeur,
                    'statut_norme' => $derniere?->statut_norme,
                    'date_mesure'  => $derniere?->date_mesure?->toIso8601String(),
                ];
            })
            ->all();
    }
}
