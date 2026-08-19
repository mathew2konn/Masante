<?php

namespace App\Services\Triage;

use App\Services\Protocole\SelecteurProtocoles;
use App\Support\NiveauTriage;
use App\Support\RegistreActionsProtocole;
use App\Support\RegistreContextesProtocole;

/**
 * P10b-1 puis P10b-2 — Le niveau de priorité, décidé par des PROTOCOLES et non par du code
 * (CDC_08 §1.2, §3, §8 ; CDC_05 §5.3).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * CE QUE CE SERVICE A REMPLACÉ (b-1), ET CE QUI CHANGE ICI (b-2)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `TriageService` portait `match (true) { $score <= 30 => 'leger', … }` et
 * `if ($drapeauRouge) { $score = max($score, 90); }` — des seuils cliniques en dur, le
 * contre-exemple littéral du §1.2. Ils vivent depuis b-1 dans un protocole versionné et signé.
 *
 * **b-2 supprime la dernière constante de désignation** : ce service allait chercher `TRIAGE-NIVEAU`
 * par son code. Il demande maintenant au sélecteur *tous* les protocoles en vigueur pour le
 * contexte `triage`, et c'est la cascade du §3/§8 qui décide lequel l'emporte s'ils divergent.
 *
 * Conséquence : ajouter un protocole de triage régional ne demande plus **aucune ligne de code**.
 * La constante `CODE` reste, mais elle ne désigne plus le protocole appliqué — seulement celui
 * que le déploiement doit publier pour que le triage fonctionne.
 *
 * ═══ REFUS BRUYANT CONSERVÉ, ET LA QUESTION A ÉTÉ REPOSÉE ═══
 *
 * Aucun protocole de triage en vigueur → **503**. Jamais de repli sur des seuils par défaut : un
 * repli laisserait un oubli de publication passer inaperçu, et le triage rendrait des niveaux que
 * personne n'a validés en croyant appliquer un protocole (décision de L1+L2, reprise en P10a puis
 * en b-1).
 *
 * P6.8e avait fait l'inverse pour les numéros d'urgence — son argument (« le consommateur n'a ni
 * réseau, ni session, ni compte ») ne vaut pas ici : le triage est un appel API, il n'existe pas
 * sans réseau.
 *
 * ═══ LA COHÉRENCE ÉVALUATION ⇄ ESTAMPILLE NE REPOSE PLUS SUR LA MÉMOÏSATION ═══
 *
 * En b-1, le service mémoïsait la version lue pour qu'un triage ne soit pas *jugé par une version
 * et estampillé par une autre*. Ici la garantie est **structurelle** : une seule invocation rend à
 * la fois la décision, la version retenue et la liste des protocoles évalués. Il n'y a plus deux
 * lectures à faire coïncider.
 */
final class ServiceNiveauTriage
{
    /**
     * Le protocole de triage que le déploiement doit publier.
     *
     * Ce n'est PLUS le protocole appliqué — le sélecteur les trouve tous. C'est un repère
     * d'exploitation : le guide de test et la commande de vérification s'en servent pour dire
     * « voilà celui sans lequel le triage répond 503 ».
     */
    public const CODE = 'TRIAGE-NIVEAU';

    public function __construct(private readonly SelecteurProtocoles $selecteur) {}

    /**
     * Applique les protocoles de triage en vigueur aux faits du patient.
     *
     * @param  array<string, mixed>  $faits  Ce que le triage sait du patient, tel que
     *                                       {@see \App\Support\RegistreFaitsProtocole} les nomme.
     * @return array{niveau: string, score: int, actions: array<int, array<string, mixed>>,
     *               protocole: array{code: string, version: string, numero: int},
     *               regles_declenchees: array<int, array<string, mixed>>,
     *               evaluation: array<string, mixed>}
     */
    public function appliquer(array $faits): array
    {
        $resultat = $this->selecteur->evaluer(RegistreContextesProtocole::TRIAGE, $faits);

        if ($resultat['protocoles'] === []) {
            // 503 et non 404 : le protocole existe peut-être en brouillon, c'est sa MISE EN VIGUEUR
            // qui manque. Même distinction qu'en P10a pour les symptômes.
            abort(503, 'Aucun protocole de triage n\'est en vigueur : aucun niveau de priorité ne '
                .'peut être rendu tant qu\'une version n\'a pas franchi les quatre validations du '
                .'§7 et n\'a pas été publiée (CDC_08 §1.6).');
        }

        $niveau = $this->niveauDepuisActions($resultat['actions']);

        if ($niveau === null) {
            // ═══ ON REFUSE PLUTÔT QUE D'INVENTER UN NIVEAU ═══
            //
            // Le contrôle de couverture ({@see \App\Services\Protocole\ControleQualiteProtocole})
            // prouve à la publication que toute la plage 0-100 est couverte : arriver ici signifie
            // qu'une version est passée à travers, ou que les faits sortent du domaine prévu.
            // Retomber sur une valeur par défaut recréerait le seuil en dur qu'on vient de
            // retirer, et le ferait en silence.
            abort(500, 'Les protocoles de triage en vigueur n\'ont produit aucun niveau pour ce cas. '
                .'Aucun niveau n\'est inventé : le résultat serait une orientation que personne '
                .'n\'a validée.');
        }

        return [
            'niveau' => $niveau,
            // Le score APRÈS chaînage avant, tel que le protocole RETENU l'a établi : c'est celui
            // qui explique le niveau affiché à côté. Montrer celui d'un protocole écarté afficherait
            // un score qui contredit la décision.
            'score'  => (int) ($resultat['faits']['score'] ?? 0),
            // Les autres actions (ORIENTER, MESSAGE…) telles quelles : le §9.1 les appelle
            // « recommandations ». Aucune n'est interprétée ici.
            'actions' => array_values(array_filter(
                $resultat['actions'],
                fn (array $a): bool => $a['type'] !== RegistreActionsProtocole::DEFINIR_NIVEAU,
            )),
            'protocole'          => $resultat['protocole_retenu'],
            'regles_declenchees' => $resultat['regles_declenchees'],
            // L'évaluation complète — protocoles évalués, écartés, divergences — pour le journal
            // du §10. Le triage ne l'interprète pas : il la transmet.
            'evaluation'         => $resultat,
        ];
    }

    /**
     * Y a-t-il au moins un protocole de triage en vigueur ?
     *
     * NE REPLIE PAS ET NE LÈVE PAS — vérité brute pour un écran d'exploitation (motif
     * `ServiceNumerosUrgence::estEnVigueur()` en P6.8e).
     */
    public function estEnVigueur(): bool
    {
        // La SÉLECTION suffit : faire tourner le moteur sur des faits vides répondrait à
        // une autre question (« ce cas produit-il une recommandation ? ») et coûterait une
        // évaluation complète pour un simple témoin d'exploitation.
        return $this->selecteur->selectionner(RegistreContextesProtocole::TRIAGE)['retenus'] !== [];
    }

    /**
     * Le dernier `DEFINIR_NIVEAU` l'emporte.
     *
     * Le sélecteur a déjà départagé les protocoles entre eux (§8) et n'en laisse qu'un par action
     * exclusive. Il peut en revanche rester plusieurs `DEFINIR_NIVEAU` issus du MÊME protocole si
     * deux de ses bandes se recouvraient — ce que le contrôle de couverture interdit à la
     * publication. « Le dernier » reste une règle explicite : sans elle, le résultat dépendrait de
     * l'ordre d'itération plutôt que d'une décision écrite.
     *
     * @param  array<int, array<string, mixed>>  $actions
     */
    private function niveauDepuisActions(array $actions): ?string
    {
        $niveau = null;

        foreach ($actions as $action) {
            if ($action['type'] === RegistreActionsProtocole::DEFINIR_NIVEAU) {
                $candidat = (string) $action['valeur'];

                // Le contrôle qualité refuse déjà un niveau inconnu à la publication. Ce second
                // filtre existe parce qu'un instantané publié AVANT un durcissement du contrôle
                // resterait en vigueur : mieux vaut ne pas conclure que conclure faux.
                if (NiveauTriage::estValide($candidat)) {
                    $niveau = $candidat;
                }
            }
        }

        return $niveau;
    }
}
