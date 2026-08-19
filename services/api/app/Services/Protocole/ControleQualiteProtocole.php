<?php

namespace App\Services\Protocole;

use App\Models\Protocole;
use App\Models\SpecialiteMedicale;
use App\Support\NiveauTriage;
use App\Support\RegistreActionsProtocole;
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
    /** Le score de triage se lit sur 100 (héritage du Module 1, cohérent avec `poids_severite`). */
    private const SCORE_MIN = 0;

    private const SCORE_MAX = 100;

    public function __construct(private readonly MessagesProtocole $messages) {}

    /**
     * @param  array{metadonnees: array<string, mixed>, regles: array<int, array<string, mixed>>, references: array<int, array<string, mixed>>}  $contenu
     * @return array<int, string>  Les anomalies bloquantes. Vide = publiable.
     */
    public function controler(array $contenu): array
    {
        $erreurs = array_merge(
            $this->controlerMetadonnees($contenu['metadonnees'] ?? []),
            $this->controlerReferences($contenu['references'] ?? []),
            $this->controlerRegles($contenu['regles'] ?? []),
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

    /** §7.4 — cohérence des règles : faits, opérateurs, actions, arités, valeurs. */
    private function controlerRegles(array $regles): array
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
                foreach ($this->erreursDeCondition($reference, $condition) as $erreur) {
                    $erreurs[] = $erreur;
                }
            }

            foreach ($actions as $action) {
                foreach ($this->erreursDAction($reference, $action) as $erreur) {
                    $erreurs[] = $erreur;
                }
            }
        }

        return $erreurs;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array<int, string>
     */
    private function erreursDeCondition(string $reference, array $condition): array
    {
        $fait = (string) ($condition['fait'] ?? '');
        $operateur = (string) ($condition['operateur'] ?? '');
        $valeur = $condition['valeur'] ?? null;

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
        $type = RegistreFaitsProtocole::type($fait);

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
     * @return array<int, string>
     */
    private function erreursDAction(string $reference, array $action): array
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
                    'min'     => (int) $valeur[0],
                    'max'     => (int) $valeur[1],
                    'libelle' => (string) ($regle['libelle'] ?? ''),
                ];
            }
        }

        if ($bandes === []) {
            return ['Aucune bande de score ne définit de niveau : un protocole de triage qui ne '
                .'produit aucun niveau laisserait chaque patient sans orientation.'];
        }

        $erreursMessage = $this->controlerMessagesDeNiveau($regles);

        usort($bandes, static fn (array $a, array $b): int => $a['min'] <=> $b['min']);

        $erreurs = $erreursMessage;
        $attendu = self::SCORE_MIN;

        foreach ($bandes as $bande) {
            if ($bande['min'] > $attendu) {
                $erreurs[] = "Trou dans les bandes de score : rien ne couvre {$attendu} à "
                    .($bande['min'] - 1).'. Un patient dont le score tombe dans cet intervalle '
                    .'ne recevrait aucun niveau, sans qu\'aucune erreur ne soit levée.';
            }

            if ($bande['min'] < $attendu) {
                $erreurs[] = "Recouvrement des bandes de score autour de {$bande['min']} "
                    ."(« {$bande['libelle']} ») : deux règles décideraient du même score.";
            }

            $attendu = max($attendu, $bande['max'] + 1);
        }

        if ($attendu <= self::SCORE_MAX) {
            $erreurs[] = "Les bandes de score s'arrêtent à ".($attendu - 1).' : rien ne couvre '
                ."{$attendu} à ".self::SCORE_MAX.'.';
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
