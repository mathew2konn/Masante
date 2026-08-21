<?php

namespace App\Services\Protocole;

use App\Models\Protocole;
use App\Models\SpecialiteMedicale;
use App\Support\NiveauTriage;
use App\Support\RegistreActionsProtocole;
use App\Support\RegistreContextesProtocole;
use App\Support\RegistreFaitsProtocole;
use App\Support\RegistreOperateursProtocole;

/**
 * P10b-1 — Les contrôles qui INTERDISENT la publication (CDC_08 §7.4 « validation technique :
 * cohérence des règles, absence de conflits, tests automatiques », §10).
 *
 * Motif `SourceReferentiel::controlerQualite()` (P6.3) : un tableau vide signifie publiable. Le
 * §7.4 en fait la quatrième couche de validation — celle qu'une machine peut rendre, à la
 * différence des trois autres.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * LE CONTRÔLE CENTRAL : LA COUVERTURE DES BANDES DE SCORE
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * C'est le seul défaut de cette famille qui **ne fait aucun bruit**. Un protocole de triage dont
 * les bandes laisseraient un trou — disons rien entre 51 et 55 — se publierait sans erreur, et un
 * patient dont le score tombe dans le trou n'obtiendrait **aucun niveau**. Rien ne planterait au
 * moment de la publication ; le défaut n'apparaîtrait qu'au premier patient concerné.
 *
 * Même famille que « aucun numéro d'urgence actif » (P6.8e) et « orienter vers un terme désactivé »
 * (P10a) : le contrôle prouve la couverture **au moment où un humain décide**, et l'exécution
 * refuse plutôt que d'inventer un niveau.
 *
 * ═══ CE QUI EST DÉLIBÉRÉMENT *NON* CONTRÔLÉ ═══
 *
 * **La pertinence clinique d'une règle.** Que « score ≥ 76 » doive valoir « urgence » plutôt que
 * « consultation rapide » est un arbitrage médical : le §7 le confie à la validation **clinique**,
 * signée par des médecins spécialistes. Le socle technique n'a pas à le rendre, et prétendre le
 * faire donnerait à une machine l'apparence d'un avis médical.
 *
 * **Une règle sans condition.** Elle s'applique toujours — c'est un cas légitime (une consigne
 * systématique), pas un oubli. Les distinguer demanderait de lire dans les intentions du rédacteur.
 */
final class ControleQualiteProtocole
{
    /**
     * Les faits qu'une phase n'a PAS encore, avec la raison et le remplacement, par contexte.
     *
     * @var array<string, array<string, string>>
     */
    private const FAITS_HORS_PHASE = [
        RegistreContextesProtocole::TRIAGE_QUESTIONNAIRE => [
            'score' => "un protocole de questionnaire ne peut pas conditionner sur « score », qui n'est pas encore clos au moment où l'on interroge le patient — la règle répondrait différemment selon le tour. Utilisez « score_symptomes ».",
            'score_reponses' => "un protocole de questionnaire ne peut pas conditionner sur « score_reponses » : c'est ce qu'il est en train de produire.",
            'score_antecedents' => "un protocole de questionnaire ne peut pas conditionner sur les antécédents : l'interrogatoire s'exécute aussi sans membre identifié (POST /triage/questions), où le carnet est inconnu. Une telle règle ferait tomber cet endpoint et pas l'autre. Ce qui dépend des antécédents appartient à un protocole de contexte « triage_antecedents ».",
            'score_antecedents_brut' => "un protocole de questionnaire ne peut pas conditionner sur les antécédents : le carnet est inconnu quand l'interrogatoire s'exécute sans membre identifié. Utilisez un protocole de contexte « triage_antecedents ».",
            'nb_antecedents' => "un protocole de questionnaire ne peut pas conditionner sur les antécédents : le carnet est inconnu quand l'interrogatoire s'exécute sans membre identifié. Utilisez un protocole de contexte « triage_antecedents ».",
        ],
        RegistreContextesProtocole::TRIAGE_ANTECEDENTS => [
            'score' => "un protocole d'antécédents ne peut pas conditionner sur « score » : il s'évalue AVANT que le score ne soit assemblé. Utilisez « score_antecedents_brut » ou « nb_antecedents ».",
            'score_reponses' => "un protocole d'antécédents ne peut pas conditionner sur « score_reponses » : le questionnaire ne s'est pas encore exécuté.",
            'score_antecedents' => "un protocole d'antécédents ne peut pas conditionner sur « score_antecedents » : c'est la valeur qu'il DÉCIDE. Conditionnez sur « score_antecedents_brut », la somme avant borne.",
        ],
    ];

    /** Le score de triage se lit sur 100 (héritage du Module 1, cohérent avec `poids_severite`). */
    private const SCORE_MIN = 0;

    private const SCORE_MAX = 100;

    public function __construct(private readonly MessagesProtocole $messages) {}

    /**
     * @param  array{metadonnees: array<string, mixed>, regles: array<int, array<string, mixed>>, references: array<int, array<string, mixed>>}  $contenu
     * @return array<int, string> Les anomalies bloquantes. Vide = publiable.
     */
    public function controler(array $contenu): array
    {
        // P10b-3-i — Les questions sont résolues AVANT les règles : c'est contre elles que le
        // suffixe d'un `reponse.<cle>` est confronté, et c'est la condition que P10b-1 posait pour
        // ouvrir ce fait (« l'ajouter ouvrirait un suffixe libre sans rien pour le vérifier »).
        $questions = $this->indexerQuestions($contenu['questions'] ?? []);

        $erreurs = array_merge(
            $this->controlerMetadonnees($contenu['metadonnees'] ?? []),
            $this->controlerReferences($contenu['references'] ?? []),
            $this->controlerQuestions($contenu['questions'] ?? []),
            $this->controlerRegles($contenu['regles'] ?? [], $questions),
            $this->controlerFaitsDeLaPhase(
                $contenu['metadonnees']['contextes'] ?? [],
                $contenu['regles'] ?? [],
            ),
        );

        // La couverture n'a de sens que si les règles sont déjà bien formées : la contrôler sur
        // des conditions invalides produirait des messages trompeurs (« il manque la bande 26-50 »
        // alors que le vrai défaut est un opérateur inconnu).
        if ($erreurs === [] && ($contenu['metadonnees']['domaine'] ?? null) === Protocole::DOMAINE_TRIAGE) {
            $erreurs = array_merge($erreurs, $this->controlerCouverture($contenu['regles'] ?? []));
        }

        return $erreurs;
    }

    /** §4.1 — les métadonnées que le §9.1 promet de montrer au médecin. */
    private function controlerMetadonnees(array $meta): array
    {
        $erreurs = [];

        // Le §9.1 affiche le niveau de preuve à côté de chaque recommandation. Publier sans lui
        // afficherait une recommandation dont on ne saurait pas dire sur quoi elle repose.
        if (($meta['niveau_preuve'] ?? null) === null) {
            $erreurs[] = 'Niveau de preuve absent (§4.1) : une recommandation sans niveau de preuve '
                .'ne peut pas être présentée à un professionnel.';
        }

        // §4.2 « Population : Adultes ». Un protocole sans population déclarée s'appliquerait
        // implicitement à tous, nourrissons et femmes enceintes compris — les cas limites que le
        // §12 exige justement de tester.
        if (trim((string) ($meta['population'] ?? '')) === '') {
            $erreurs[] = 'Population concernée absente (§4.1) : sans elle, le protocole '
                .'s\'appliquerait implicitement à tout le monde.';
        }

        // ═══ P10b-2 — SANS CONTEXTE DÉCLARÉ, UN PROTOCOLE N'EST JAMAIS APPLIQUÉ ═══
        //
        // Le §9.1 fait porter chaque évaluation par un contexte (`triage|consultation|urgence`).
        // Un protocole qui n'en déclare aucun serait publié, en vigueur, et pourtant muet : le
        // sélecteur ne le retiendrait jamais. Publier un texte qui ne s'appliquera nulle part est
        // le genre de panne qui ne fait AUCUN bruit — tout fonctionne, et la recommandation
        // n'arrive simplement jamais.
        //
        // Refuser à la publication plutôt que de laisser un défaut muet : même famille que le
        // contrôle de couverture des bandes, livré en b-1.
        $contextes = RegistreContextesProtocole::filtrer($meta['contextes'] ?? null);

        if ($contextes === []) {
            $erreurs[] = 'Aucun contexte d\'application déclaré (§9.1) : ce protocole serait publié '
                .'sans jamais être sélectionné. Contextes admis : '
                .implode(', ', RegistreContextesProtocole::codes()).'.';
        }

        if (trim((string) ($meta['organisme'] ?? '')) === '') {
            $erreurs[] = 'Organisme absent (§4.1) : un protocole engage l\'autorité qui le publie.';
        }

        // §4.1 « date d'expiration ». Publier une version déjà périmée reviendrait à mettre en
        // vigueur une recommandation qu'on déclare soi-même dépassée.
        $expiration = $meta['date_expiration'] ?? null;

        if ($expiration !== null && strtotime((string) $expiration) < strtotime(now()->toDateString())) {
            $erreurs[] = "Date d'expiration déjà passée ({$expiration}) : cette version serait "
                .'périmée le jour même de sa mise en vigueur.';
        }

        // Le code du vocabulaire national (P6.8a), s'il est renseigné, doit désigner un terme
        // VIVANT — sinon le protocole orienterait vers une spécialité que l'annuaire ne sait plus
        // proposer, sans que rien ne le signale (défaut refermé en P10a, on ne le rouvre pas).
        $specialite = $meta['specialite_code'] ?? null;

        if ($specialite !== null && ! $this->specialiteVivante((string) $specialite, (string) ($meta['pays_code'] ?? 'CI'))) {
            $erreurs[] = "Spécialité « {$specialite} » inconnue ou désactivée du vocabulaire national.";
        }

        return $erreurs;
    }

    /**
     * §7.3 — la validation scientifique repose sur « des publications revues par les pairs ».
     *
     * Une recommandation sans aucune référence est une affirmation sans source. Le contrôle est
     * volontairement minimal — **une** référence — parce qu'exiger un nombre serait arbitraire.
     */
    private function controlerReferences(array $references): array
    {
        if ($references === []) {
            return ['Aucune référence bibliographique (§4.1, §7.3) : une recommandation clinique '
                .'sans source ne peut pas être validée scientifiquement.'];
        }

        $erreurs = [];

        foreach ($references as $i => $reference) {
            if (trim((string) ($reference['libelle'] ?? '')) === '') {
                $erreurs[] = 'Référence n°'.($i + 1).' sans libellé.';
            }
        }

        return $erreurs;
    }

    /**
     * P10b-3-i, élargi en P10b-3-ii — UNE RÈGLE NE PEUT PAS CONDITIONNER SUR UN FAIT QUI N'EXISTE
     * PAS ENCORE À SA PHASE.
     *
     * ═══ POURQUOI CE REFUS N'EST PAS UNE COQUETTERIE ═══
     *
     * Au moment où le patient est interrogé, le score **n'est pas encore clos** : c'est le
     * questionnaire lui-même qui l'alimente, réponse après réponse. Une règle qui lirait `score`
     * y verrait un total partiel, différent selon le tour où elle s'évalue — donc une règle dont
     * le résultat dépend du **moment** de l'évaluation et non des faits. C'est la même famille de
     * défaut que celle refermée par R5 sur le nombre d'allers-retours.
     *
     * ═══ CE QUE P10b-3-ii Y AJOUTE, ET IL FALLAIT LE CORRIGER ═══
     *
     * Le message de b-3-i proposait `score_antecedents` comme repli. **C'est devenu faux** : le
     * questionnaire s'exécute aussi depuis `POST /triage/questions`, où le membre — donc son
     * carnet — est inconnu. Les faits d'antécédents n'y sont pas passés du tout, et une règle qui
     * s'en servirait ferait tomber cet endpoint-là seulement (un fait inconnu lève, depuis
     * P10b-1). C'est le constat Z1, rendu **vérifiable** ici plutôt que laissé à une convention.
     *
     * Symétriquement, un protocole d'antécédents ne peut pas lire un score qui n'est pas encore
     * assemblé, ni la part qu'il est lui-même en train de décider.
     *
     * Chaque refus NOMME ce qu'il faut utiliser à la place : refuser sans le dire ramènerait la
     * faute qu'on ferme (précédent P6.8a).
     *
     * @param  array<int, string>  $contextes
     * @param  array<int, array<string, mixed>>  $regles
     * @return array<int, string>
     */
    private function controlerFaitsDeLaPhase(array $contextes, array $regles): array
    {
        $interdits = [];

        foreach (self::FAITS_HORS_PHASE as $contexte => $faits) {
            if (in_array($contexte, $contextes, true)) {
                $interdits += $faits;
            }
        }

        if ($interdits === []) {
            return [];
        }

        $erreurs = [];

        foreach ($regles as $regle) {
            foreach ($regle['conditions'] ?? [] as $condition) {
                $fait = (string) ($condition['fait'] ?? '');

                if (! isset($interdits[$fait])) {
                    continue;
                }

                $nom = trim((string) ($regle['libelle'] ?? ''));
                $reference = $nom !== '' ? "« {$nom} »" : 'Règle n°'.($regle['ordre'] ?? '?');

                $erreurs[] = "{$reference} : {$interdits[$fait]}";
            }
        }

        return $erreurs;
    }

    /**
     * P10b-3-i — Les questions, indexées par clé, pour confronter les `reponse.<cle>`.
     *
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<string, array<string, mixed>>
     */
    private function indexerQuestions(array $questions): array
    {
        $indexees = [];

        foreach ($questions as $question) {
            $cle = (string) ($question['cle'] ?? '');

            if ($cle !== '') {
                $indexees[$cle] = $question;
            }
        }

        return $indexees;
    }

    /**
     * P10b-3-i — Les questions elles-mêmes (§4.3b).
     *
     * ═══ LE CONTRÔLE CENTRAL : UN CHOIX SANS RÉPONSE POSSIBLE ═══
     *
     * Il ne casse rien de visible. La question s'affiche, aucun bouton n'apparaît, le patient
     * passe à la suivante et **rien ne le signale** — la famille de défaut muet que ce projet
     * ferme depuis P10a (« orienter vers un terme désactivé ») et P6.8e (« plus aucun numéro
     * actif »). C'est aussi le seul cas où l'ancien modèle échouait en silence : `options` vide et
     * `points_par_option` rempli donnaient exactement cela.
     *
     * ═══ CE QUI N'EST DÉLIBÉRÉMENT PAS CONTRÔLÉ ═══
     *
     * **Une question que rien ne pose.** Elle est inerte, pas dangereuse, et le rédacteur peut
     * préparer un énoncé avant d'écrire la règle qui le déclenche. La refuser rendrait le travail
     * en deux temps impossible ; l'inverse — poser une question qui n'existe pas — est arrêté par
     * `erreursDAction`, et c'est celui-là qui produirait un tour de questionnaire vide.
     *
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<int, string>
     */
    private function controlerQuestions(array $questions): array
    {
        $erreurs = [];
        $clesVues = [];

        foreach ($questions as $question) {
            $cle = (string) ($question['cle'] ?? '');
            $reference = $cle !== '' ? "Question « {$cle} »" : 'Question sans clé';

            if ($cle === '') {
                $erreurs[] = 'Une question sans clé : aucune règle ne pourrait la désigner.';

                continue;
            }

            // L'unicité est déjà déclarative en base (`uq_protocole_question_cle`). On la
            // re-vérifie ici parce que le contrôle juge un CONTENU proposé, qui peut venir d'un
            // import et non d'une écriture Eloquent — même raison qu'en P6.3, où le contrôle
            // qualité ne fait pas confiance au chemin qui a produit le contenu.
            if (isset($clesVues[$cle])) {
                $erreurs[] = "{$reference} : clé en double — `reponse.{$cle}` désignerait deux "
                    .'énoncés, et une règle ne saurait plus lequel elle interroge.';
            }
            $clesVues[$cle] = true;

            if (trim((string) ($question['libelle'] ?? '')) === '') {
                $erreurs[] = "{$reference} : énoncé absent — le patient verrait un champ sans question.";
            }

            $type = (string) ($question['type'] ?? '');
            $reponses = $question['reponses'] ?? [];

            if ($type === 'choix' && $reponses === []) {
                $erreurs[] = "{$reference} : question à choix sans aucune réponse possible. "
                    ."L'écran afficherait l'énoncé et aucun bouton, et rien ne le signalerait.";
            }

            // Symétrique : des réponses possibles sur un type qui n'en prend pas seraient
            // silencieusement ignorées à l'affichage. Le rédacteur croirait les avoir publiées.
            if ($type !== 'choix' && $reponses !== []) {
                $erreurs[] = "{$reference} : des réponses possibles sont déclarées alors que le "
                    ."type « {$type} » n'en affiche aucune — elles ne seraient jamais proposées.";
            }

            if ($type === 'echelle') {
                $min = $question['valeur_min'] ?? null;
                $max = $question['valeur_max'] ?? null;

                if ($min === null || $max === null) {
                    // Sans bornes publiées, il n'y a rien à opposer à une valeur aberrante : c'est
                    // exactement l'état d'avant cet incrément (constat X4), où `intensite = 100`
                    // sur une échelle de 1 à 10 saturait le score.
                    $erreurs[] = "{$reference} : échelle sans bornes — aucune valeur ne pourrait "
                        .'être refusée, si aberrante soit-elle.';
                } elseif ((int) $min >= (int) $max) {
                    $erreurs[] = "{$reference} : bornes d'échelle incohérentes ({$min} ≥ {$max}).";
                }
            }
        }

        return $erreurs;
    }

    /**
     * §7.4 — cohérence des règles : faits, opérateurs, actions, arités, valeurs.
     *
     * @param  array<int, array<string, mixed>>  $regles
     * @param  array<string, array<string, mixed>>  $questions
     * @return array<int, string>
     */
    private function controlerRegles(array $regles, array $questions = []): array
    {
        if ($regles === []) {
            return ['Le protocole ne porte aucune règle : il serait publié sans rien pouvoir décider.'];
        }

        $erreurs = [];

        foreach ($regles as $regle) {
            $nom = trim((string) ($regle['libelle'] ?? ''));
            $reference = $nom !== '' ? "« {$nom} »" : 'Règle n°'.($regle['ordre'] ?? '?');

            if ($nom === '') {
                $erreurs[] = "Règle d'ordre {$regle['ordre']} sans libellé : le §7 fait signer des "
                    .'médecins, leur montrer une condition sans phrase reviendrait à leur faire '
                    .'signer du code.';
            }

            $actions = $regle['actions'] ?? [];

            if ($actions === []) {
                $erreurs[] = "{$reference} : règle sans action — elle ne produirait rien.";
            }

            foreach ($regle['conditions'] ?? [] as $condition) {
                foreach ($this->erreursDeCondition($reference, $condition, $questions) as $erreur) {
                    $erreurs[] = $erreur;
                }
            }

            foreach ($actions as $action) {
                foreach ($this->erreursDAction($reference, $action, $questions) as $erreur) {
                    $erreurs[] = $erreur;
                }
            }
        }

        return $erreurs;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, array<string, mixed>>  $questions
     * @return array<int, string>
     */
    private function erreursDeCondition(string $reference, array $condition, array $questions = []): array
    {
        $fait = (string) ($condition['fait'] ?? '');
        $operateur = (string) ($condition['operateur'] ?? '');
        $valeur = $condition['valeur'] ?? null;

        // ═══ P10b-3-i — LE SUFFIXE D'UN `reponse.<cle>` EST CONFRONTÉ ICI, ET NULLE PART AILLEURS ═══
        //
        // C'est la condition que P10b-1 posait pour ouvrir ce fait. `RegistreFaitsProtocole` ne
        // valide que la FORME (il ne connaît aucun protocole) ; le fond se vérifie ici, où la
        // version est sous les yeux. Sans ce contrôle, `reponse.duree` au lieu de
        // `reponse.duree_jours` produirait une règle qui ne se déclenche JAMAIS — et rien ne le
        // signalerait, ce qui est exactement le défaut que le moteur refuse de commettre en levant.
        if (RegistreFaitsProtocole::estReponse($fait)) {
            $cle = RegistreFaitsProtocole::cleReponse($fait);

            if (! isset($questions[$cle])) {
                return ["{$reference} : la condition interroge la réponse à « {$cle} », qui n'est "
                    .'pas une question de cette version. La règle ne se déclencherait jamais, et '
                    .'rien ne le signalerait. Questions de cette version : '
                    .($questions === [] ? 'aucune' : implode(', ', array_keys($questions))).'.'];
            }
        }

        // ═══ C'EST ICI QUE « LE FAIT INCONNU » EST ARRÊTÉ ═══
        //
        // Le moteur lève s'il en rencontre un ; ce contrôle fait en sorte qu'il n'en rencontre
        // jamais. L'ordre compte : on ne publie pas d'abord pour découvrir le problème au premier
        // patient.
        if (! RegistreFaitsProtocole::existe($fait)) {
            return ["{$reference} : fait inconnu « {$fait} ». Une condition portant un fait que le "
                .'système ne produit pas ne se déclencherait jamais, et rien ne le signalerait. '
                .'Faits connus : '.implode(', ', RegistreFaitsProtocole::codes()).'.'];
        }

        if (! RegistreOperateursProtocole::existe($operateur)) {
            return ["{$reference} : opérateur inconnu « {$operateur} ». Opérateurs connus : "
                .implode(', ', RegistreOperateursProtocole::codes()).'.'];
        }

        $erreurs = [];

        // Le type d'un `reponse.<cle>` est déclaré par la QUESTION, pas par le registre — qui ne
        // connaît aucun protocole et renvoie `null`. Le résoudre ici rend le contrôle de
        // compatibilité fait/opérateur applicable sans le modifier : `>=` sur une question
        // booléenne est refusé exactement comme `>=` sur `drapeau_rouge` l'était déjà.
        $type = RegistreFaitsProtocole::estReponse($fait)
            ? (string) ($questions[RegistreFaitsProtocole::cleReponse($fait)]['type_fait'] ?? '')
            : RegistreFaitsProtocole::type($fait);

        // L'erreur la plus banale et la plus muette de ce modèle : une comparaison numérique sur
        // une liste (`symptome_id >= 5`). Elle ne veut rien dire, et sans ce contrôle elle se
        // contenterait de ne jamais se déclencher.
        if (! RegistreOperateursProtocole::accepteType($operateur, $type)) {
            $erreurs[] = "{$reference} : l'opérateur « {$operateur} » ne s'applique pas au fait "
                ."« {$fait} » (de type {$type}).";
        }

        $arite = RegistreOperateursProtocole::arite($operateur);

        if ($arite === RegistreOperateursProtocole::ARITE_AUCUNE && $valeur !== null) {
            $erreurs[] = "{$reference} : l'opérateur « {$operateur} » n'attend aucune valeur.";
        }

        if ($arite === RegistreOperateursProtocole::ARITE_SIMPLE && (is_array($valeur) || $valeur === null)) {
            $erreurs[] = "{$reference} : l'opérateur « {$operateur} » attend une valeur simple.";
        }

        if ($arite === RegistreOperateursProtocole::ARITE_INTERVALLE) {
            if (! is_array($valeur) || count($valeur) !== 2) {
                $erreurs[] = "{$reference} : l'opérateur « {$operateur} » attend deux bornes.";
            } elseif ((float) $valeur[0] > (float) $valeur[1]) {
                // Bornes inversées : la condition ne se déclencherait jamais. Cousin exact de la
                // garde sur les intervalles de référence (P6.7a) et sur les âges du calendrier
                // vaccinal (P6.8b), où c'est un déclencheur de base qui refuse.
                $erreurs[] = "{$reference} : bornes inversées ({$valeur[0]} > {$valeur[1]}) — "
                    .'la condition ne pourrait jamais être remplie.';
            }
        }

        return $erreurs;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, array<string, mixed>>  $questions
     * @return array<int, string>
     */
    private function erreursDAction(string $reference, array $action, array $questions = []): array
    {
        $type = (string) ($action['type'] ?? '');
        $valeur = $action['valeur'] ?? null;

        if (! RegistreActionsProtocole::existe($type)) {
            return ["{$reference} : action inconnue « {$type} ». Actions connues : "
                .implode(', ', RegistreActionsProtocole::codes()).'.'];
        }

        $erreurs = [];

        if (RegistreActionsProtocole::attendUneValeur($type) && ($valeur === null || $valeur === '')) {
            $erreurs[] = "{$reference} : l'action « {$type} » attend une valeur.";
        }

        // Le niveau produit doit appartenir aux quatre de CDC_05 §5.3. Les trois valeurs héritées
        // du Module 1 sont REFUSÉES ici : elles restent lisibles dans l'historique, mais un
        // protocole neuf qui en produirait une figerait le vocabulaire qu'on vient de corriger.
        if ($type === RegistreActionsProtocole::DEFINIR_NIVEAU && ! NiveauTriage::estValide((string) $valeur)) {
            $erreurs[] = "{$reference} : niveau « {$valeur} » inconnu. Niveaux patient (CDC_05 §5.3) : "
                .implode(', ', NiveauTriage::PATIENT).'.';
        }

        if ($type === RegistreActionsProtocole::DEFINIR_SCORE_MINIMUM
            && (! is_numeric($valeur) || (int) $valeur < self::SCORE_MIN || (int) $valeur > self::SCORE_MAX)) {
            $erreurs[] = "{$reference} : score minimum aberrant « {$valeur} » "
                .'(attendu entre '.self::SCORE_MIN.' et '.self::SCORE_MAX.').';
        }

        // ═══ UN MESSAGE NE PART JAMAIS AVEC UN MARQUEUR CASSÉ ═══
        //
        // « Appelez le SAMU au {urgence:samu} » affiché à quelqu'un qui doit joindre des secours
        // serait pire que pas de phrase du tout. Le contrôle est fait ici, à la publication, par
        // le MÊME code que la résolution à l'exécution ({@see MessagesProtocole}) : un seul endroit
        // décide de ce qui résout, donc les deux ne peuvent pas diverger.
        if ($type === RegistreActionsProtocole::MESSAGE) {
            foreach ($this->messages->marqueursNonResolus((string) $valeur) as $code) {
                $erreurs[] = "{$reference} : le message cite le numéro d'urgence « {$code} », "
                    .'qui n\'est pas publié au référentiel national.';
            }
        }

        // ═══ P10b-3-i — POSER UNE QUESTION QUI N'EXISTE PAS ═══
        //
        // Le symétrique du contrôle sur `reponse.<cle>`, et le plus muet des deux : le tour de
        // questionnaire renverrait une clé que l'écran ne sait pas rendre, donc **aucune question**,
        // et le patient passerait directement au résultat sans que rien ne signale qu'on avait
        // prévu de l'interroger. Même famille que « orienter vers un terme désactivé » (P10a).
        if ($type === RegistreActionsProtocole::POSER_QUESTION && ! isset($questions[(string) $valeur])) {
            $erreurs[] = "{$reference} : la règle pose la question « {$valeur} », qui n'existe pas "
                .'dans cette version. Aucune question ne serait affichée et rien ne le signalerait. '
                .'Questions de cette version : '
                .($questions === [] ? 'aucune' : implode(', ', array_keys($questions))).'.';
        }

        // Le score s'ajoute, il ne se substitue pas : une valeur non numérique produirait un
        // `(int) 'beaucoup' = 0` silencieux. Les valeurs négatives sont ADMISES — une réponse peut
        // rassurer autant qu'alerter, et l'interdire serait un arbitrage clinique que le §7
        // confie aux validateurs, pas au socle technique.
        if ($type === RegistreActionsProtocole::AJOUTER_SCORE && ! is_numeric($valeur)) {
            $erreurs[] = "{$reference} : « {$valeur} » n'est pas un nombre de points.";
        }

        if ($type === RegistreActionsProtocole::AJOUTER_SCORE
            && is_numeric($valeur)
            && abs((int) $valeur) > self::SCORE_MAX) {
            $erreurs[] = "{$reference} : ajout au score aberrant « {$valeur} » "
                .'(le score se lit sur '.self::SCORE_MAX.').';
        }

        if ($type === RegistreActionsProtocole::ORIENTER && ! $this->specialiteVivante((string) $valeur, 'CI')) {
            $erreurs[] = "{$reference} : orientation vers « {$valeur} », terme inconnu ou désactivé "
                .'du vocabulaire national — le patient serait envoyé vers une spécialité que '
                ."l'annuaire ne peut pas proposer.";
        }

        return $erreurs;
    }

    /**
     * ═══ LA COUVERTURE DES BANDES DE SCORE — LE CONTRÔLE QUI ÉVITE LA PANNE MUETTE ═══
     *
     * On ne compte comme « bande » qu'une règle dont **l'unique condition** est `score entre [a, b]`
     * et qui porte un `DEFINIR_NIVEAU`. Les règles à conditions supplémentaires (« score entre 0 et
     * 25 ET âge > 60 ») sont des **modulateurs** : elles affinent, elles ne sont pas censées couvrir
     * l'intervalle à elles seules, et les inclure rendrait le contrôle faux dès la première règle
     * conditionnelle.
     *
     * Trois exigences, chacune répondant à un défaut réel :
     *   - au moins une bande — sinon aucun niveau ne serait jamais produit ;
     *   - aucun trou — sinon un score tombant dedans ne recevrait aucun niveau ;
     *   - aucun recouvrement — sinon deux règles décideraient du même score, et le résultat
     *     dépendrait de l'ordre plutôt que d'une décision explicite.
     *
     * @param  array<int, array<string, mixed>>  $regles
     * @return array<int, string>
     */
    private function controlerCouverture(array $regles): array
    {
        $bandes = [];

        foreach ($regles as $regle) {
            $conditions = $regle['conditions'] ?? [];
            $porteUnNiveau = collect($regle['actions'] ?? [])
                ->contains(fn (array $a): bool => ($a['type'] ?? null) === RegistreActionsProtocole::DEFINIR_NIVEAU);

            if (! $porteUnNiveau || count($conditions) !== 1) {
                continue;
            }

            $condition = $conditions[0];

            if (($condition['fait'] ?? null) !== 'score' || ($condition['operateur'] ?? null) !== 'entre') {
                continue;
            }

            $valeur = $condition['valeur'] ?? null;

            if (is_array($valeur) && count($valeur) === 2) {
                $bandes[] = [
                    'min' => (int) $valeur[0],
                    'max' => (int) $valeur[1],
                    'libelle' => (string) ($regle['libelle'] ?? ''),
                ];
            }
        }

        // ═══ P10b-2 — UN PROTOCOLE SANS BANDE N'EST PLUS UNE ANOMALIE ═══
        //
        // b-1 refusait ce cas : avec un seul protocole en vigueur, ne définir aucun niveau
        // signifiait n'orienter personne. Depuis b-2 plusieurs protocoles cohabitent, et une
        // SURCOUCHE — un protocole régional qui ne traite qu'un cas particulier — est parfaitement
        // légitime. La question « tout patient reçoit-il un niveau ? » a changé de portée : elle
        // ne concerne plus un protocole, mais l'ENSEMBLE de ceux en vigueur.
        //
        // Elle est posée par {@see ControleCouvertureNiveau}, à la publication.
        if ($bandes === []) {
            return $this->controlerMessagesDeNiveau($regles);
        }

        $erreursMessage = $this->controlerMessagesDeNiveau($regles);

        usort($bandes, static fn (array $a, array $b): int => $a['min'] <=> $b['min']);

        $erreurs = $erreursMessage;
        $attendu = self::SCORE_MIN;

        foreach ($bandes as $bande) {
            if ($bande['min'] < $attendu) {
                $erreurs[] = "Recouvrement des bandes de score autour de {$bande['min']} "
                    ."(« {$bande['libelle']} ») : deux règles décideraient du même score.";
            }

            $attendu = max($attendu, $bande['max'] + 1);
        }

        return $erreurs;
    }

    /**
     * Toute règle qui fixe un niveau doit dire au patient QUOI FAIRE.
     *
     * Un niveau sans consigne laisse le citoyen devant une couleur et un mot. CDC_05 §5.3 associe
     * explicitement à chacun des quatre niveaux une conduite (« surveillance à domicile »,
     * « consultation dans les 24 heures »…) : la couleur seule n'est pas le livrable.
     *
     * Ce contrôle est aussi ce qui permet à `TriageService` de ne pas porter de consigne de repli.
     * Sans lui, il faudrait bien écrire quelque part une phrase par défaut — c'est-à-dire remettre
     * dans le code exactement ce que cet incrément en sort, et à l'endroit le moins visible.
     *
     * @param  array<int, array<string, mixed>>  $regles
     * @return array<int, string>
     */
    private function controlerMessagesDeNiveau(array $regles): array
    {
        $erreurs = [];

        foreach ($regles as $regle) {
            $actions = collect($regle['actions'] ?? []);

            $fixeUnNiveau = $actions->contains(
                fn (array $a): bool => ($a['type'] ?? null) === RegistreActionsProtocole::DEFINIR_NIVEAU
            );

            if (! $fixeUnNiveau) {
                continue;
            }

            $porteUnMessage = $actions->contains(
                fn (array $a): bool => ($a['type'] ?? null) === RegistreActionsProtocole::MESSAGE
                    && trim((string) ($a['valeur'] ?? '')) !== ''
            );

            if (! $porteUnMessage) {
                $erreurs[] = "« {$regle['libelle']} » : cette règle fixe un niveau sans dire au "
                    .'patient quoi faire. CDC_05 §5.3 associe une conduite à chaque niveau ; '
                    .'une couleur seule n\'est pas une orientation.';
            }
        }

        return $erreurs;
    }

    /**
     * Le terme existe-t-il et est-il vivant au vocabulaire national (P6.8a) ?
     *
     * Lecture directe de la table, et c'est la limite L1/L2 d'ADR-025 que P6.8a a déclarée pour son
     * propre référentiel : le contrôle juge sur l'état courant du vocabulaire, pas sur sa version
     * publiée. C'est le bon choix ici — publier un protocole qui oriente vers un terme retiré hier
     * doit échouer aujourd'hui, pas à la prochaine publication du vocabulaire.
     */
    private function specialiteVivante(string $code, string $paysCode): bool
    {
        if ($code === '') {
            return false;
        }

        return SpecialiteMedicale::query()
            ->where('pays_code', $paysCode)
            ->where('code', $code)
            ->where('actif', true)
            ->exists();
    }
}
