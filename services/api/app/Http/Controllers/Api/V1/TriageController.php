<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyserTriageRequest;
use App\Http\Requests\QuestionsTriageRequest;
use App\Models\MembreFamille;
use App\Models\Symptome;
use App\Models\Triage;
use App\Models\TriageConstante;
use App\Models\TriageReponse;
use App\Services\Protocole\JournalApplicationProtocole;
use App\Services\Triage\FaitsTriage;
use App\Services\Triage\ServiceConstantesTriage;
use App\Services\Triage\ServiceFicheTriage;
use App\Services\Triage\ServiceQuestionnaire;
use App\Services\Triage\ServiceSymptomesTriage;
use App\Services\TriageService;
use App\Support\RegistreContextesProtocole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Module 1 — Triage et orientation médicale (F1.1 à F1.8).
 */
class TriageController extends Controller
{
    public function __construct(
        private readonly TriageService $triage,
        private readonly ServiceSymptomesTriage $referentiel,
        private readonly ServiceFicheTriage $fiches,
        private readonly JournalApplicationProtocole $journal,
        private readonly ServiceQuestionnaire $questionnaire,
        private readonly ServiceConstantesTriage $constantes,
    ) {}

    /**
     * La valeur d'une réponse, telle qu'elle doit se LIRE dans un dossier de santé.
     *
     * `(string) false` vaut chaîne vide : sans cette conversion, un « non » explicite serait
     * indistinguable d'une absence de réponse — deux faits cliniques différents. Le patient qui a
     * répondu « non, pas de gêne au repos » a dit quelque chose ; celui qui n'a pas répondu, non.
     */
    private function valeurLisible(mixed $valeur): string
    {
        if (is_bool($valeur)) {
            return $valeur ? 'oui' : 'non';
        }

        return (string) $valeur;
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
     *
     * ═══ P10b-3-i — `questions_complementaires_json` DISPARAÎT AUSSI ═══
     *
     * Les questions ne sont plus une propriété du symptôme : elles vivent dans le protocole
     * `TRIAGE-QUESTIONNAIRE`, et l'on ne sait **lesquelles poser** qu'après avoir su quels
     * symptômes sont cochés — c'est tout l'objet de l'adaptativité du §4.3b. Les servir ici
     * reviendrait à les rendre toutes, ce que cet incrément supprime.
     *
     * Le client les obtient désormais par {@see questions()}, un tour à la fois.
     */
    public function symptomes(): JsonResponse
    {
        $symptomes = $this->referentiel->actifs()->map(fn (Symptome $s): array => [
            'id' => $s->id,
            'nom_fr' => $s->nom_fr,
            'categorie' => $s->categorie,
        ]);

        return response()->json([
            'total' => $symptomes->count(),
            'par_categorie' => $symptomes->groupBy('categorie'),
            'symptomes' => $symptomes,
            // La version qui gouverne cette liste — la même que celle qui estampillera le triage.
            'referentiel_version' => $this->referentiel->version(),
        ]);
    }

    /**
     * P10b-3-i — Un tour de questionnaire adaptatif (CDC_08 §4.3b, §13 étape 4).
     *
     * Le client envoie les symptômes cochés et les réponses déjà données ; le serveur répond avec
     * les questions **actuellement débloquées et pas encore répondues**. Quand la liste est vide,
     * `termine` vaut `true` et le client peut lancer l'analyse.
     *
     * ═══ POURQUOI LA BOUCLE VIT CHEZ LE CLIENT, ET CE QU'ELLE COÛTE ═══
     *
     * Chaque tour est un aller-retour réseau. Compiler l'arbre côté client l'éviterait — et
     * mettrait une **règle médicale dans le front**, ce que la règle de frontière interdit
     * (CDC_01 §0.1) : la condition « pose cette question si le patient a de la fièvre depuis plus
     * de trois jours » est une décision clinique, pas de l'affichage.
     *
     * L'atténuation est de rendre **toutes** les questions débloquées à chaque tour et non une
     * seule : l'arbre du §4.3b converge alors en quelques tours au lieu d'un par question. Le coût
     * est réduit, pas déguisé — il figure aux limites du G5.
     *
     * ═══ AUCUNE PERSISTANCE ═══
     *
     * Ce tour ne crée pas de triage et n'écrit rien. Un patient qui abandonne au milieu du
     * questionnaire ne laisse aucune trace — il n'a pris aucune décision de santé, et enregistrer
     * son interrogatoire inachevé constituerait une donnée médicale que personne n'a demandée.
     */
    public function questions(QuestionsTriageRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Le fond des réponses est confronté à la version publiée AVANT d'être transformé en
        // faits : sans cela, une valeur hors plage entrerait dans le moteur et débloquerait
        // peut-être une question qu'elle n'aurait pas dû déclencher.
        $reponses = $this->questionnaire->normaliser($data['reponses'] ?? []);

        // P10c-1 — Les constantes sont confrontées à la version publiée ICI AUSSI, et pas
        // seulement à l'analyse : sans cela une valeur hors bornes entrerait dans le moteur et
        // débloquerait peut-être une question qu'elle n'aurait pas dû déclencher. Aucun membre sur
        // ce chemin — donc aucune reprise du carnet possible, et l'origine sera « saisie ».
        $constantes = $this->constantes->normaliser($data['constantes'] ?? []);

        $symptomes = $this->referentiel->retenus($data['symptomes']);

        // Source unique de l'assemblage (constat Z1) : ce tableau était recopié ici et deux fois
        // dans `TriageService`, et il en manquait une clé — de quoi faire tomber CET endpoint dès
        // qu'une règle s'en serait servie. Les faits des antécédents n'y sont volontairement pas :
        // ce chemin ne connaît pas le membre. Les constantes, elles, y sont — le client les envoie
        // sur les deux endpoints, donc une règle qui s'en sert vaut des deux côtés.
        $tour = $this->questionnaire->prochainesQuestions(
            FaitsTriage::base(
                $symptomes,
                $data['patient_age'] ?? null,
                $data['patient_sexe'] ?? null,
                $this->constantes->faits($constantes),
            ),
            $reponses,
        );

        return response()->json([
            'questions' => $tour['questions'],
            'termine' => $tour['termine'],
            // §9.1 — le protocole appliqué et sa version, à côté de ce qu'il a produit.
            'protocoles' => $tour['protocoles'],
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
        $membre = $this->membreAutorise($membreId, $utilisateur);
        $antecedents = $this->antecedentsDuMembre($membre);

        // P10b-3-i — Les réponses sont confrontées à la version PUBLIÉE avant tout calcul : une
        // échelle 1-10 refuse 100 au lieu de le multiplier par un coefficient (constat X4), et une
        // clé inconnue est refusée au lieu de valoir 0 point en silence.
        $reponses = $this->questionnaire->normaliser($data['reponses'] ?? []);

        // P10c-1 — Les constantes du §5.2, confrontées à la version publiée des seuils : une valeur
        // hors des bornes de plausibilité est REFUSÉE, jamais ramenée dans la plage.
        //
        // Le membre est passé pour que le SERVEUR reconnaisse une valeur qu'il a lui-même proposée
        // depuis le carnet — le client n'a aucun moyen de déclarer sa propre provenance.
        $constantes = $this->constantes->normaliser($data['constantes'] ?? [], $membre);

        $resultat = $this->triage->analyser(
            symptomesIds: $data['symptomes'],
            reponses: $reponses,
            age: $data['patient_age'] ?? null,
            sexe: $data['patient_sexe'] ?? null,
            antecedents: $antecedents, // carnet du membre (2A.4) : alimente le score (F1.3)
            constantes: $this->constantes->faits($constantes),
        );

        // La trace d'exécution et le triage vivent ou meurent ENSEMBLE : un triage rendu au
        // patient sans trace archivée laisserait une décision de santé sans explication, et une
        // trace sans triage désignerait une décision qui n'a jamais eu lieu.
        $triage = DB::transaction(function () use ($resultat, $data, $utilisateur, $membreId, $constantes): Triage {
            $triage = Triage::create([
                'user_id' => $utilisateur?->id,
                'membre_id' => $membreId,
                'patient_nom' => $data['patient_nom'] ?? null,
                'patient_age' => $data['patient_age'] ?? null,
                'patient_sexe' => $data['patient_sexe'] ?? null,
                'symptomes_json' => $resultat['symptomes'],

                // ═══ P10b-3-i — `reponses_json` N'EST PLUS ÉCRITE (CDC_04 §115) ═══
                //
                // Les réponses vivent dans `triage_reponses`, que le §115 exige par ailleurs.
                // Écrire les deux ferait deux vérités sur le même fait, capables de diverger dès
                // qu'un chemin d'écriture oublierait l'une des deux.
                //
                // La colonne est laissée VIDE et non supprimée : les triages antérieurs la
                // portent, et la fiche §5.4 la lit encore pour eux. Leur fabriquer des lignes ici
                // serait un mensonge d'archive — précédent L2, où les mesures antérieures restent
                // sans version de référentiel plutôt que d'en recevoir une fausse.
                'reponses_json' => [],
                'score_severite' => $resultat['score_severite'],
                'niveau' => $resultat['niveau'],
                'specialite_requise' => $resultat['specialite_requise'],

                // ═══ P10a — L'ESTAMPILLE (CDC_04 §115, CDC_09 §10) ═══
                //
                // « Toute décision conserve la version du référentiel utilisée ». Sans elle, corriger un
                // `poids_severite` rendait tout triage antérieur inexplicable — c'était le constat F3
                // du G0 de P6.3, resté ouvert jusqu'ici.
                //
                // NULLABLE ET JAMAIS RÉTROACTIVE : les triages d'avant n'ont eu aucune version en
                // vigueur ; leur en attribuer une après coup serait un mensonge d'archive (précédent
                // exact de `mesures_sante.referentiel_version` en L1+L2).
                'referentiel_version' => $resultat['referentiel_version'],
                'specialites_json' => $resultat['specialites'],

                // ═══ P10b-1 — L'ESTAMPILLE DU PROTOCOLE (CDC_08 §6.1, CDC_04 §115) ═══
                //
                // « Chaque décision clinique conserve la version exacte du protocole utilisée » —
                // exigence médico-légale non négociable. Le CODE est dénormalisé à côté du numéro pour
                // que la trace reste lisible si le protocole disparaît du registre (motif de
                // l'établissement copié sur `acces_dossier` en P7-D2).
                //
                // NULLABLE ET JAMAIS RÉTROACTIVE : les triages d'avant P10b n'ont été jugés par aucun
                // protocole, leur en attribuer un serait un mensonge d'archive (précédent L1+L2).
                'protocole_code' => $resultat['protocole_code'],
                'protocole_version' => $resultat['protocole_version'],

                'recommandation_texte' => $resultat['recommandation_texte'],
                'fiche_generee' => false,
            ]);

            // ═══ P10b-3-i — LES RÉPONSES, AVEC LEUR ÉNONCÉ FIGÉ (CDC_04 §115) ═══
            //
            // `question_libelle` est copié tel que la version publiée le portait AU MOMENT du
            // triage. Le résoudre plus tard afficherait, dans un dossier de santé, une question
            // que ce patient n'a jamais lue — motif de la DCI figée dans une ordonnance (P6.6b),
            // de l'établissement copié dans le journal d'accès (P7-D2) et du libellé d'orientation
            // figé dans l'instantané (P10a).
            //
            // `protocole_code`/`protocole_version` désignent le protocole de QUESTIONNAIRE, qui
            // n'est pas celui du niveau porté par `triages` : deux protocoles, deux cycles de
            // validation, deux estampilles. Les confondre rendrait le triage inexplicable dès que
            // l'un des deux évolue.
            $questionnaire = $resultat['questionnaire'][0] ?? null;

            foreach ($resultat['reponses'] as $ligne) {
                TriageReponse::create([
                    'triage_id' => $triage->id,
                    'question_cle' => $ligne['cle'],
                    'question_libelle' => $ligne['libelle'],
                    // Le booléen est stocké lisiblement : `(string) false` vaut '' et rendrait un
                    // « non » indistinguable d'une absence de réponse dans le dossier.
                    'valeur' => $this->valeurLisible($ligne['valeur']),
                    'protocole_code' => $questionnaire['code'] ?? null,
                    'protocole_version' => $questionnaire['numero'] ?? null,
                ]);
            }

            // ═══ P10c-1 — LES CONSTANTES CLINIQUES DU §5.2 ═══
            //
            // Elles décrivent CET épisode de triage. `mesures_sante` n'est jamais écrite depuis
            // ici : ce serait un 4ᵉ chemin d'écriture dans une table du carnet, avec sa question de
            // rejeu et de suppression par le patient (motif W3 de P6.8b — le calendrier vaccinal
            // répond et prévient, il n'écrit rien).
            //
            // `origine` et `referentiel_version` viennent du service, jamais du client.
            foreach ($this->constantes->lignes($constantes) as $ligne) {
                TriageConstante::create($ligne + ['triage_id' => $triage->id]);
            }

            // ═══ P10b-2 — LE JOURNAL D'EXÉCUTION DU §10 ═══
            //
            // L'estampille ci-dessus nomme le protocole qui a EMPORTÉ le niveau. Elle est muette sur
            // les autres protocoles évalués, sur ceux qui ont été écartés et pourquoi, sur les
            // divergences et sur ce qui a été recommandé. Le §10 exige tout cela pour l'audit
            // médico-légal — ce sont deux faits distincts, pas la même vérité écrite deux fois.
            $this->journal->inscrire($resultat['evaluation'], [
                'contexte' => RegistreContextesProtocole::TRIAGE,
                'pays_code' => config('referentiels.pays_defaut', 'CI'),
                'membre_id' => $membreId,
                'user_id' => $utilisateur?->id,
                // `professionnel_id`, `decision_finale` et `ecart_justification` restent NULS : le
                // triage citoyen n'a pas de soignant dans la boucle. Le §10 les nomme, ils existent,
                // et le fait qu'ils soient vides est une limite écrite — pas un oubli.
                'triage_id' => $triage->id,
            ]);

            return $triage;
        });

        return response()->json([
            'triage_id' => $triage->id,
            'score_severite' => $resultat['score_severite'],
            'niveau' => $resultat['niveau'],
            // Le libellé citoyen vient du backend (CDC_05 §5.3), il n'est plus dérivé côté client.
            // Le mobile portait la table `leger|modere|urgent` en dur : trois valeurs là où le
            // corpus en exige quatre (constat W3 du G0).
            'niveau_libelle' => $resultat['niveau_libelle'],
            'specialite_requise' => $resultat['specialite_requise'],

            // §9.1 — le protocole appliqué et sa version, à côté de la décision qu'il a rendue.
            'protocole' => [
                'code' => $resultat['protocole_code'],
                'version' => $resultat['protocole_libelle'],
                'numero' => $resultat['protocole_version'],
            ],
            'regles_declenchees' => $resultat['regles_declenchees'],
            // D3 — le serveur renvoie les CODES, pas seulement un libellé : c'est avec eux que le
            // mobile ira chercher les établissements, sans jamais en déduire un lui-même.
            'specialites' => $resultat['specialites'],
            'referentiel_version' => $resultat['referentiel_version'],
            'recommandation_texte' => $resultat['recommandation_texte'],
            'drapeau_rouge' => $resultat['drapeau_rouge'],
            'details_score' => $resultat['details_score'],

            // P10c-1 — Ce que le serveur a RETENU, avec l'origine qu'il a lui-même décidée. Le
            // client peut ainsi vérifier qu'une valeur reprise du carnet a bien été comptée comme
            // telle — sans avoir jamais eu le droit de le déclarer.
            'constantes' => array_map(static fn (string $type, array $ligne): array => [
                'type_mesure' => $type,
                'libelle' => $ligne['libelle'],
                'valeur' => $ligne['valeur'],
                'unite' => $ligne['unite'],
                'origine' => $ligne['origine'],
            ], array_keys($constantes), $constantes),
        ], 201);
    }

    /**
     * P10c-1 — Le membre visé, APRÈS contrôle d'accès. `null` pour un triage anonyme.
     *
     * Extrait de `antecedentsDuMembre()`, dont c'était la première moitié : deux consommateurs en
     * ont désormais besoin (les antécédents et les constantes reprises du carnet), et recopier la
     * garde anti-IDOR pour le second aurait créé **deux endroits** où l'oublier.
     */
    private function membreAutorise(?int $membreId, $utilisateur): ?MembreFamille
    {
        if ($membreId === null) {
            return null;
        }

        // Rattacher un triage à un membre suppose un compte authentifié et propriétaire (anti-IDOR).
        abort_if($utilisateur === null, 401, 'Authentification requise pour un triage rattaché à un membre.');

        $membre = MembreFamille::find($membreId);
        abort_if($membre === null, 404, 'Membre introuvable.');
        abort_unless($membre->user_id === $utilisateur->id, 403, 'Accès non autorisé à ce membre.');

        return $membre;
    }

    /**
     * Antécédents du membre à injecter dans le score de triage (F1.3).
     * Renvoie [] pour un triage anonyme (sans membre).
     *
     * @return array<int, array{libelle: string, impact_triage: int}>
     */
    private function antecedentsDuMembre(?MembreFamille $membre): array
    {
        if ($membre === null) {
            return [];
        }

        return $membre->antecedents()
            ->get(['type', 'impact_triage'])
            ->map(fn ($a) => ['libelle' => $a->type, 'impact_triage' => (int) $a->impact_triage])
            ->all();
    }

    /**
     * P10c-1 — Ce que l'écran de triage peut demander, et ce que le carnet en propose (§5.2).
     *
     * ═══ TROIS ÉTATS, ET UN SEUL EST UNE AFFIRMATION SUR LE PRÉSENT ═══
     *
     * `proposition` = une mesure du carnet DANS sa fenêtre de fraîcheur : le champ est pré-rempli
     * **avec sa date**, et le patient corrige s'il veut. `contexte` = une mesure hors fenêtre :
     * montrée pour information, **jamais pré-remplie**, et elle n'entre dans aucune règle. Ni l'un
     * ni l'autre = rien à dire.
     *
     * C'est la leçon des trois sources de P6.4b : on dit **laquelle des trois** on tient. *Une
     * température prise il y a trois mois n'est pas une température*, et la pré-remplir la
     * présenterait comme le présent.
     *
     * Sans `membre_id` — triage anonyme — la liste des constantes est rendue sans aucune
     * proposition : il n'y a pas de carnet à consulter, et il n'y a rien à en dire.
     */
    public function constantes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'membre_id' => ['nullable', 'integer', 'min:1'],
        ]);

        // Même garde anti-IDOR que pour l'analyse : lire les constantes du carnet d'autrui serait
        // un accès à des données de santé sans lien de prise en charge (CDC_00 §4).
        $membre = $this->membreAutorise($data['membre_id'] ?? null, $request->user('sanctum'));

        return response()->json([
            'constantes' => $this->constantes->proposables($membre),

            // La version qui gouverne ces bornes — la même qui estampillera les constantes écrites.
            'referentiel_version' => $this->constantes->version(),
        ]);
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
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
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
            'fiche' => $fiche,
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
            'qr_payload' => url('/api/v1/triage/'.$triage->id.'/fiche?jeton='.$triage->jeton_partage),
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
            'total' => $triages->count(),
            'triages' => $triages,
        ]);
    }
}
