<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyserTriageRequest;
use App\Models\MembreFamille;
use App\Models\Symptome;
use App\Models\Triage;
use App\Services\Triage\ServiceFicheTriage;
use App\Services\Triage\ServiceSymptomesTriage;
use App\Services\TriageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 1 — Triage et orientation médicale (F1.1 à F1.8).
 */
class TriageController extends Controller
{
    public function __construct(
        private readonly TriageService $triage,
        private readonly ServiceSymptomesTriage $referentiel,
        private readonly ServiceFicheTriage $fiches,
    ) {
    }

    /**
     * F1.1 — Liste des symptômes actifs, regroupés par catégorie (pour la sélection).
     *
     * ═══ P10a — LA LISTE VIENT DE LA VERSION PUBLIÉE, ET C'ÉTAIT INDISPENSABLE ═══
     *
     * Basculer le seul `analyser()` aurait laissé la SÉLECTION proposer un symptôme absent de la
     * version en vigueur : le citoyen l'aurait coché, et l'analyse l'aurait ignoré sans rien dire.
     * C'est très exactement le constat C1 de L1+L2 — *deux lectures contournaient le service* — où
     * la validation acceptait un type de mesure absent de la version publiée.
     *
     * `specialite_hint` DISPARAÎT de la réponse. Le type mobile la déclarait, aucun écran ne
     * l'affichait, et depuis P10a elle ne gouverne plus l'orientation : la servir encore
     * publierait une orientation périmée à côté de la vraie.
     */
    public function symptomes(): JsonResponse
    {
        $symptomes = $this->referentiel->actifs()->map(fn (Symptome $s): array => [
            'id'                             => $s->id,
            'nom_fr'                         => $s->nom_fr,
            'categorie'                      => $s->categorie,
            'questions_complementaires_json' => $s->questions_complementaires_json,
        ]);

        return response()->json([
            'total'               => $symptomes->count(),
            'par_categorie'       => $symptomes->groupBy('categorie'),
            'symptomes'           => $symptomes,
            // La version qui gouverne cette liste — la même que celle qui estampillera le triage.
            'referentiel_version' => $this->referentiel->version(),
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

            // ═══ P10a — L'ESTAMPILLE (CDC_04 §115, CDC_09 §10) ═══
            //
            // « Toute décision conserve la version du référentiel utilisée ». Sans elle, corriger un
            // `poids_severite` rendait tout triage antérieur inexplicable — c'était le constat F3
            // du G0 de P6.3, resté ouvert jusqu'ici.
            //
            // NULLABLE ET JAMAIS RÉTROACTIVE : les triages d'avant n'ont eu aucune version en
            // vigueur ; leur en attribuer une après coup serait un mensonge d'archive (précédent
            // exact de `mesures_sante.referentiel_version` en L1+L2).
            'referentiel_version'  => $resultat['referentiel_version'],
            'specialites_json'     => $resultat['specialites'],

            'recommandation_texte' => $resultat['recommandation_texte'],
            'fiche_generee'        => false,
        ]);

        return response()->json([
            'triage_id'            => $triage->id,
            'score_severite'       => $resultat['score_severite'],
            'niveau'               => $resultat['niveau'],
            'specialite_requise'   => $resultat['specialite_requise'],
            // D3 — le serveur renvoie les CODES, pas seulement un libellé : c'est avec eux que le
            // mobile ira chercher les établissements, sans jamais en déduire un lui-même.
            'specialites'          => $resultat['specialites'],
            'referentiel_version'  => $resultat['referentiel_version'],
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
     * F1.8 / CDC_05 §5.4 — Fiche de triage (livrable obligatoire).
     *
     * ═══ P10a — LA FICHE CESSE D'ÊTRE OUVERTE À TOUT LE MONDE ═══
     *
     * Elle était servie sans aucun contrôle de propriété, sur un identifiant SÉQUENTIEL : lire la
     * fiche du voisin demandait de changer un chiffre. Le G0 l'a vérifié par `route:list` — aucun
     * `auth:sanctum` sur la route, aucune vérification dans la méthode.
     *
     * On ne referme pas la porte au triage anonyme, qui est un choix du Module 1 : on lui met une
     * serrure. Deux clés, et l'identifiant n'en est pas une — voir `ServiceFicheTriage::peutLire()`.
     *
     * Le refus est un **404**, jamais un 403 : répondre « interdit » confirmerait qu'un triage
     * existe à cet identifiant, ce qui rend l'énumération à nouveau possible (motif anti-IDOR de
     * P7-D1, où tout part de `$user->notifications()` et répond 404).
     */
    public function fiche(Triage $triage, Request $request): JsonResponse
    {
        $position = $request->validate([
            'lat'   => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng'   => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'jeton' => ['nullable', 'string', 'max:64'],
        ]);

        abort_unless(
            $this->fiches->peutLire($triage, $request->user('sanctum'), $position['jeton'] ?? null),
            404,
            'Fiche de triage introuvable.',
        );

        // Marque la fiche comme générée/consultée (comportement hérité, inchangé).
        if (! $triage->fiche_generee) {
            $triage->update(['fiche_generee' => true]);
        }

        $fiche = $this->fiches->composer($triage, [
            'lat' => isset($position['lat']) ? (float) $position['lat'] : null,
            'lng' => isset($position['lng']) ? (float) $position['lng'] : null,
        ]);

        return response()->json([
            'fiche'         => $fiche,
            'texte_partage' => $this->fiches->textePartage($fiche),

            // ═══ LE QR DU §5.4 : « permettant au médecin d'accéder au triage » ═══
            //
            // Le serveur renvoie la CHARGE UTILE ; c'est le mobile qui dessine le code (avec le
            // logo au centre, décision D7). Dessiner l'image côté serveur imposerait une dépendance
            // de rendu là où le client en a déjà une, et le §2.6 interdit d'en ajouter sans accord.
            //
            // La charge utile est une URL absolue et non un identifiant nu : scannée par n'importe
            // quel lecteur, elle doit mener quelque part. Elle porte le jeton — c'est bien lui qu'on
            // remet au médecin, et c'est tout ce qu'on lui remet.
            'qr_payload'    => url('/api/v1/triage/'.$triage->id.'/fiche?jeton='.$triage->jeton_partage),
        ]);
    }

    /**
     * F1.6 — Historique des triages du COMPTE AUTHENTIFIÉ (récents d'abord).
     *
     * ═══ P10a — IL RENVOYAIT LES TRIAGES DE TOUT LE MONDE ═══
     *
     * Aucune authentification, aucun filtre sur l'utilisateur : cinquante triages, avec le nom du
     * patient, son âge, son sexe, ses symptômes et son score. Le mobile les affichait. C'est un
     * accès à des données de santé sans lien de prise en charge — CDC_00 §4 le range parmi les
     * interdits absolus, et la loi 2013-450 avec lui.
     *
     * Le défaut date du Module 1 et n'a pas été introduit ici ; mais il n'était pas tenable de
     * livrer une fiche gardée en laissant la même donnée sortir par l'endpoint d'à côté.
     *
     * CONSÉQUENCE ASSUMÉE : un triage fait **sans compte** n'apparaît dans aucun historique. C'est
     * la contrepartie exacte de l'anonymat — un triage sans propriétaire n'a personne à qui être
     * rattaché, et le lui inventer un serait pire.
     */
    public function historique(Request $request): JsonResponse
    {
        $utilisateur = $request->user('sanctum');

        abort_if($utilisateur === null, 401, 'Authentification requise pour consulter votre historique.');

        $membreId = $request->filled('membre_id') ? $request->integer('membre_id') : null;

        // Filtrer par membre suppose que ce membre est le vôtre — même garde que pour un triage
        // rattaché (anti-IDOR). Sans cela, `?membre_id=` rouvrirait la porte qu'on vient de fermer.
        if ($membreId !== null) {
            $membre = MembreFamille::find($membreId);
            abort_if($membre === null, 404, 'Membre introuvable.');
            abort_unless($membre->user_id === $utilisateur->id, 403, 'Accès non autorisé à ce membre.');
        }

        $triages = Triage::query()
            ->where('user_id', $utilisateur->id)
            ->when($membreId !== null, fn ($q) => $q->where('membre_id', $membreId))
            ->latest()
            ->limit(50)
            ->get([
                'id', 'membre_id', 'patient_nom', 'patient_age', 'patient_sexe',
                'symptomes_json', 'score_severite', 'niveau', 'specialite_requise',
                'specialites_json', 'referentiel_version', 'fiche_generee', 'created_at',
            ]);

        return response()->json([
            'total'   => $triages->count(),
            'triages' => $triages,
        ]);
    }
}
