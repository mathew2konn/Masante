<?php

namespace App\Http\Controllers\Api\V1\Carnet;

use App\Http\Controllers\Controller;
use App\Models\EtapePrenatale;
use App\Models\MembreFamille;
use App\Models\Rappel;
use App\Models\SuiviGrossesse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 5 / 5.5 — Suivi de grossesse (CdC FN4).
 *
 * Règles produit :
 *  - réservé aux membres de sexe F ; UNE seule grossesse `en_cours` par membre ;
 *  - DDG plausible : ni future, ni au-delà de 43 SA révolues ; terme = DDG + 280 j (serveur) ;
 *  - pas de suppression : clôture par `statut` (termine/interruption) — rétention médicale ;
 *  - consultations : append-only via l'endpoint dédié, le tableau JSON n'est jamais accepté du client ;
 *  - rappels CPN générés dans la table `rappels` (F2.7), marqués par `suivi_grossesse_id` :
 *    régénérés si la DDG est ajustée (échographie), SUPPRIMÉS à la clôture (plus aucun rendez-vous à
 *    venir). Les rappels créés à la main par l'utilisateur (FK NULL) ne sont jamais touchés.
 *
 * La fiche vitale (FN2) n'expose PAS ce suivi (exposition minimale, §5.2 Sécurité).
 */
class GrossesseController extends Controller
{
    /**
     * Suivi en cours + historique clos + calendrier des 8 contacts OMS.
     * Le calendrier est renvoyé même sans grossesse déclarée (contenu éducatif).
     */
    public function index(MembreFamille $membre): JsonResponse
    {
        $this->authorize('view', $membre);

        $enCours = $membre->suivisGrossesse()->where('statut', 'en_cours')->first();

        return response()->json([
            'suivi'      => $enCours,
            'historique' => $membre->suivisGrossesse()
                ->where('statut', '!=', 'en_cours')
                ->orderByDesc('date_debut_grossesse')
                ->get(),
            'calendrier' => $this->calendrier($enCours),
        ]);
    }

    /** Déclare une grossesse : crée le suivi et génère les rappels CPN à venir. */
    public function store(Request $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('update', $membre);

        if ($membre->sexe !== 'F') {
            throw ValidationException::withMessages([
                'membre' => 'Le suivi de grossesse ne peut être ouvert que pour un membre de sexe féminin.',
            ]);
        }

        if ($membre->suivisGrossesse()->where('statut', 'en_cours')->exists()) {
            throw ValidationException::withMessages([
                'suivi' => 'Une grossesse est déjà suivie pour ce membre. Clôturez-la avant d\'en déclarer une nouvelle.',
            ]);
        }

        $donnees = $request->validate($this->reglesDdg());

        $suivi = new SuiviGrossesse(['date_debut_grossesse' => $donnees['date_debut_grossesse']]);
        // Terme calculé par le serveur (DDG + 280 j) : jamais accepté du client.
        $suivi->date_terme_prevue = Carbon::parse($donnees['date_debut_grossesse'])
            ->addDays(SuiviGrossesse::DUREE_JOURS);
        $membre->suivisGrossesse()->save($suivi);
        // `statut` vient d'un défaut SQL : sans refresh, la réponse renverrait null (cf. fix SOS).
        $suivi->refresh();

        $crees = $this->genererRappelsCpn($membre, $suivi);

        return response()->json(['suivi' => $suivi, 'rappels_crees' => $crees], 201);
    }

    /**
     * Ajuste la DDG (après datation échographique) et/ou clôt le suivi.
     * Un suivi clos n'est plus modifiable ; `consultations_json` est ignoré ici par construction.
     */
    public function update(Request $request, MembreFamille $membre, int $id): JsonResponse
    {
        $this->authorize('update', $membre);

        $suivi = $membre->suivisGrossesse()->findOrFail($id);

        if ($suivi->statut !== 'en_cours') {
            throw ValidationException::withMessages([
                'suivi' => 'Ce suivi de grossesse est clôturé : il n\'est plus modifiable (dossier conservé).',
            ]);
        }

        $donnees = $request->validate([
            'date_debut_grossesse' => array_merge(['sometimes'], $this->reglesDdg()['date_debut_grossesse']),
            'statut'               => ['sometimes', Rule::in(['termine', 'interruption'])],
        ]);

        if (array_key_exists('date_debut_grossesse', $donnees)) {
            $suivi->date_debut_grossesse = $donnees['date_debut_grossesse'];
            $suivi->date_terme_prevue = Carbon::parse($donnees['date_debut_grossesse'])
                ->addDays(SuiviGrossesse::DUREE_JOURS);
            $suivi->save();

            // Le calendrier a bougé : on remplace les rappels CPN auto (jamais ceux de l'utilisateur).
            $suivi->rappelsCpn()->delete();
            $this->genererRappelsCpn($membre, $suivi);
        }

        if (array_key_exists('statut', $donnees)) {
            $suivi->statut = $donnees['statut'];
            $suivi->save();

            // Clôture : la grossesse n'a plus aucun rendez-vous à venir. Les rappels CPN (aides
            // opérationnelles, pas des données médicales) prennent fin et sont supprimés — l'historique
            // médical reste dans `consultations_json`. Les rappels créés à la main (FK nulle) intacts.
            $suivi->rappelsCpn()->delete();
        }

        return response()->json(['suivi' => $suivi->refresh()]);
    }

    /**
     * Ajoute UNE consultation prénatale réalisée (append-only, forme CdC `consultations_json`).
     * Le serveur reconstruit le tableau : le client ne peut ni réécrire l'historique ni y
     * glisser des champs inattendus (même principe que les notes F2.12).
     */
    public function ajouterConsultation(Request $request, MembreFamille $membre, int $id): JsonResponse
    {
        $this->authorize('update', $membre);

        $suivi = $membre->suivisGrossesse()->findOrFail($id);

        if ($suivi->statut !== 'en_cours') {
            throw ValidationException::withMessages([
                'suivi' => 'Ce suivi de grossesse est clôturé : plus aucune consultation ne peut y être ajoutée.',
            ]);
        }

        $donnees = $request->validate([
            'date'      => [
                'required', 'date', 'before_or_equal:today',
                'after_or_equal:'.$suivi->date_debut_grossesse->toDateString(),
            ],
            'medecin'   => ['nullable', 'string', 'max:200'],
            'structure' => ['nullable', 'string', 'max:200'],
            'notes'     => ['nullable', 'string', 'max:1000'],
        ]);

        $consultations = $suivi->consultations_json ?? [];

        if (count($consultations) >= SuiviGrossesse::MAX_CONSULTATIONS) {
            throw ValidationException::withMessages([
                'consultations' => 'Le nombre maximal de consultations enregistrées est atteint.',
            ]);
        }

        // Horodatage serveur de la saisie : distingue la date de la CPN de celle de l'enregistrement.
        $donnees['enregistree_le'] = now()->toDateTimeString();
        $consultations[] = $donnees;

        usort($consultations, fn ($a, $b) => strcmp($a['date'], $b['date']));

        $suivi->consultations_json = $consultations;
        $suivi->save();

        return response()->json(['suivi' => $suivi], 201);
    }

    /**
     * Règles de plausibilité de la DDG : ni future, ni plus vieille que 43 SA (301 jours).
     *
     * @return array<string, array<int, mixed>>
     */
    protected function reglesDdg(): array
    {
        return [
            'date_debut_grossesse' => [
                'required', 'date', 'before_or_equal:today',
                'after:'.now()->subDays(SuiviGrossesse::SEMAINE_MAX * 7)->toDateString(),
            ],
        ];
    }

    /**
     * Calendrier des 8 contacts, daté sur la grossesse en cours le cas échéant :
     * `date_estimee` = DDG + SA×7, `passee` = contact dont la semaine est déjà dépassée.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function calendrier(?SuiviGrossesse $suivi)
    {
        $semaine = $suivi?->semaine_actuelle;

        return EtapePrenatale::orderBy('numero')->get()->map(fn (EtapePrenatale $etape) => [
            'numero'              => $etape->numero,
            'semaine_recommandee' => $etape->semaine_recommandee,
            'libelle'             => $etape->libelle,
            'description'         => $etape->description,
            'conseils_nutrition'  => $etape->conseils_nutrition,
            'date_estimee'        => $suivi?->date_debut_grossesse
                ->copy()->addWeeks($etape->semaine_recommandee)->toDateString(),
            'passee'              => $semaine !== null ? $semaine > $etape->semaine_recommandee : null,
        ]);
    }

    /** Crée les rappels CPN À VENIR dans la table `rappels` (F2.7). Renvoie le nombre créé. */
    protected function genererRappelsCpn(MembreFamille $membre, SuiviGrossesse $suivi): int
    {
        $crees = 0;

        foreach (EtapePrenatale::orderBy('numero')->get() as $etape) {
            $date = $suivi->date_debut_grossesse->copy()->addWeeks($etape->semaine_recommandee);

            if ($date->isPast()) {
                continue; // contact déjà dépassé au moment de la déclaration : pas de rappel rétroactif
            }

            $rappel = new Rappel([
                'type'       => 'rendez_vous',
                'titre'      => 'CPN '.$etape->numero.' — '.$etape->libelle,
                'contenu'    => 'Consultation prénatale recommandée autour de la semaine '
                    .$etape->semaine_recommandee.". Prenez rendez-vous dans votre structure de suivi.",
                'frequence'  => 'unique',
                'heure'      => '08:00',
                'date_debut' => $date->toDateString(),
                'actif'      => true,
            ]);
            // FK de gestion auto (hors $fillable de Rappel) : posée explicitement par le serveur.
            $rappel->suivi_grossesse_id = $suivi->id;
            $membre->rappels()->save($rappel);
            $crees++;
        }

        return $crees;
    }
}
