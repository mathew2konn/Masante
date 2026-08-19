<?php

namespace App\Services\Protocole;

use App\Models\Protocole;
use App\Models\ProtocoleVersion;
use App\Support\MoteurProtocole;
use App\Support\RegistreActionsProtocole;
use App\Support\RegistreContextesProtocole;
use App\Support\ReglesResolutionConflit;
use App\Support\ReglesSelectionProtocoles;
use Illuminate\Support\Carbon;

/**
 * P10b-2 — Le sélecteur : quels protocoles s'appliquent, et lequel l'emporte (CDC_08 §3, §8, §9.1).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * CHAQUE PROTOCOLE EST ÉVALUÉ INDÉPENDAMMENT, SUR LES MÊMES FAITS INITIAUX
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Le chaînage avant reste entier À L'INTÉRIEUR d'un protocole (b-1) : `DEFINIR_SCORE_MINIMUM`
 * relève le score vu par les règles suivantes **du même protocole**. Il ne franchit jamais la
 * frontière entre deux protocoles.
 *
 * *Sinon l'ordre d'évaluation changerait le résultat, et le §3 deviendrait un ordre de CALCUL
 * alors qu'il est un ordre de DÉPARTAGE.* Un protocole régional évalué avant le national ne doit
 * pas pouvoir relever le score que le national examine : le §3 dit qui l'emporte quand ils
 * divergent, pas qui parle en premier.
 *
 * ═══ CUMUL PAR DÉFAUT, DÉPARTAGE PAR EXCEPTION ═══
 *
 * Deux orientations s'additionnent, deux messages aussi. Seules les actions déclarées EXCLUSIVES
 * par {@see RegistreActionsProtocole} peuvent diverger — aujourd'hui `DEFINIR_NIVEAU` seule. Sans
 * cette distinction, le journal du §8 se remplirait de faux conflits et les vraies divergences y
 * seraient noyées.
 *
 * Et deux protocoles qui disent LA MÊME chose ne sont pas en conflit : la divergence suppose des
 * valeurs différentes, pas seulement deux émetteurs.
 *
 * ═══ CE SERVICE NE DÉCIDE D'AUCUNE RÈGLE MÉDICALE ═══
 *
 * Il choisit **quels textes consulter** et **lequel prime** selon un ordre écrit dans le corpus.
 * Le contenu des recommandations vient entièrement des protocoles publiés ; aucun seuil, aucun
 * niveau, aucune orientation n'est décidé ici.
 */
final class SelecteurProtocoles
{
    public function __construct(private readonly DiffusionProtocole $diffusion) {}

    /**
     * Sélectionne, évalue, départage.
     *
     * @param  array<string, mixed>  $faits  Ce que l'on sait du patient, tel que
     *                                       {@see \App\Support\RegistreFaitsProtocole} le nomme.
     * @return array{
     *     actions: array<int, array<string, mixed>>,
     *     faits: array<string, mixed>,
     *     protocole_retenu: ?array{code: string, version: string, numero: int},
     *     protocoles: array<int, array<string, mixed>>,
     *     ecartes: array<int, array{code: string, motif: string}>,
     *     conflits: array<int, array<string, mixed>>,
     *     regles_declenchees: array<int, array<string, mixed>>
     * }
     */
    public function evaluer(string $contexte, array $faits, ?string $paysCode = null): array
    {
        $paysCode ??= config('referentiels.pays_defaut', 'CI');

        if (! RegistreContextesProtocole::existe($contexte)) {
            throw new ProtocoleException(
                "Contexte d'évaluation inconnu : « {$contexte} ». Contextes admis : "
                .implode(', ', RegistreContextesProtocole::codes()).' (CDC_08 §9.1).',
                422,
            );
        }

        $tri = $this->selectionner($contexte, $paysCode);

        $evaluations = [];

        foreach ($tri['retenus'] as $candidat) {
            // L'instantané publié, jamais les tables de travail : c'est la garantie de b-1, et
            // elle vaut ici pour chaque protocole sélectionné.
            $publie = $this->diffusion->lire((string) $candidat['code'], $paysCode);

            $resultat = MoteurProtocole::evaluer($publie['contenu']['regles'] ?? [], $faits);

            $evaluations[] = [
                'code'          => $publie['code'],
                'titre'         => $publie['titre'],
                'version'       => $publie['version'],
                'numero'        => $publie['numero'],
                'niveau_source' => $candidat['niveau_source'],
                'niveau_preuve' => $candidat['niveau_preuve'],
                'publie_le'     => $publie['publie_le'],
                'actions'       => $resultat['actions'],
                'faits'         => $resultat['faits'],
                'regles'        => $resultat['regles_declenchees'],
            ];
        }

        return $this->fusionner($evaluations, $faits, $tri['ecartes']);
    }

    /**
     * La sélection seule, sans évaluer — pour répondre à « le triage est-il opérationnel ? » sans
     * faire tourner le moteur sur des faits vides.
     *
     * @return array{retenus: array<int, array<string, mixed>>, ecartes: array<int, array{code: string, motif: string}>}
     */
    public function selectionner(string $contexte, ?string $paysCode = null): array
    {
        return ReglesSelectionProtocoles::trier(
            $this->candidats($paysCode ?? config('referentiels.pays_defaut', 'CI')),
            $contexte,
            Carbon::now()->toDateString(),
        );
    }

    /**
     * Les protocoles en vigueur du pays, avec ce qu'il faut pour les trier et les départager.
     *
     * Une seule requête, jointure sur la version ACTIVE : le §11 vise « moins de 100 ms », et
     * autant de requêtes que de protocoles serait le contraire de ce qu'on veut quand le catalogue
     * grandira.
     *
     * @return array<int, array<string, mixed>>
     */
    private function candidats(string $paysCode): array
    {
        return Protocole::query()
            ->join('protocole_versions', function ($jointure) {
                $jointure->on('protocole_versions.protocole_id', '=', 'protocoles.id')
                    ->where('protocole_versions.etat', '=', ProtocoleVersion::ACTIF);
            })
            ->where('protocoles.pays_code', $paysCode)
            ->select([
                'protocoles.code',
                'protocoles.actif',
                'protocole_versions.contenu_json',
                'protocoles.niveau_source',
                'protocole_versions.niveau_preuve',
                'protocole_versions.date_expiration',
                'protocole_versions.numero',
            ])
            // Ordre stable : le départage ne dépend pas de l'ordre d'arrivée, mais le JOURNAL en
            // dépend — deux exécutions identiques doivent produire la même liste, sinon deux
            // audits du même cas se contrediraient sur l'ordre.
            ->orderBy('protocoles.code')
            ->get()
            ->map(fn ($ligne): array => [
                'code'            => $ligne->code,
                'actif'           => (bool) $ligne->actif,
                'contextes'       => $this->contextesPublies($ligne->contenu_json),
                'niveau_source'   => $ligne->niveau_source,
                'niveau_preuve'   => $ligne->niveau_preuve,
                'date_expiration' => $ligne->date_expiration,
                'numero'          => (int) $ligne->numero,
            ])
            ->all();
    }

    /**
     * Les contextes tels que la version PUBLIÉE les déclare — jamais ceux de la table.
     *
     * ═══ LA MÊME BASCULE QUE L1+L2, POUR LA MÊME RAISON ═══
     *
     * `protocoles.contextes_json` est une table de TRAVAIL. La lire ici laisserait un simple
     * `UPDATE` élargir ou restreindre le champ d'application d'un protocole en vigueur — sans
     * quatre-yeux, sans relecture, et sans que rien ne le signale. C'est exactement le défaut que
     * L1+L2 a refermé pour `seuils_mesure` et P10a pour `symptomes_triage`.
     *
     * Conséquence assumée, et c'est une ÉTAPE DE DÉPLOIEMENT : une version publiée avant P10b-2 ne
     * porte aucun contexte dans son instantané. Elle cesse donc d'être sélectionnée, et le triage
     * répond 503 tant qu'une nouvelle version n'a pas été publiée. Le refus est bruyant : c'est
     * préférable à un protocole qui s'appliquerait sur la foi d'une colonne que personne n'a
     * relue.
     *
     * @return array<int, string>
     */
    private function contextesPublies(mixed $contenu): array
    {
        $decode = $this->decoder($contenu);

        return $decode['metadonnees']['contextes'] ?? [];
    }

    /** @return array<mixed> */
    private function decoder(mixed $valeur): array
    {
        if (is_array($valeur)) {
            return $valeur;
        }

        if (! is_string($valeur) || $valeur === '') {
            return [];
        }

        $decode = json_decode($valeur, true);

        return is_array($decode) ? $decode : [];
    }

    /**
     * Fusionne les évaluations : cumul des actions, départage des exclusives.
     *
     * @param  array<int, array<string, mixed>>  $evaluations
     * @param  array<string, mixed>  $faitsInitiaux
     * @param  array<int, array{code: string, motif: string}>  $ecartes
     * @return array<string, mixed>
     */
    private function fusionner(array $evaluations, array $faitsInitiaux, array $ecartes): array
    {
        $actions = [];
        $regles = [];
        /** @var array<string, array<string, mixed>> $exclusives  type => gagnant courant */
        $exclusives = [];
        $conflits = [];
        $protocoles = [];

        foreach ($evaluations as $evaluation) {
            $aContribue = false;

            foreach ($evaluation['actions'] as $action) {
                $type = (string) $action['type'];

                if (! RegistreActionsProtocole::estExclusive($type)) {
                    $actions[] = $action + ['protocole' => $evaluation['code']];
                    $aContribue = true;

                    continue;
                }

                $pretendant = [
                    'code'          => $evaluation['code'],
                    'niveau_source' => $evaluation['niveau_source'],
                    'niveau_preuve' => $evaluation['niveau_preuve'],
                    'publie_le'     => $evaluation['publie_le'],
                    'numero'        => $evaluation['numero'],
                    'version'       => $evaluation['version'],
                    'action'        => $action,
                ];

                if (! isset($exclusives[$type])) {
                    $exclusives[$type] = $pretendant;
                    $aContribue = true;

                    continue;
                }

                $tenant = $exclusives[$type];

                // Même valeur des deux côtés : ce n'est pas une divergence. Le §8 parle de
                // recommandations qui « divergent » — deux protocoles d'accord confortent la
                // décision, ils ne la disputent pas.
                if ($this->memeValeur($tenant['action']['valeur'] ?? null, $action['valeur'] ?? null)) {
                    $aContribue = true;

                    continue;
                }

                $verdict = ReglesResolutionConflit::departager($tenant, $pretendant);
                $gagnant = $verdict['gagnant'] === $tenant['code'] ? $tenant : $pretendant;
                $perdant = $verdict['gagnant'] === $tenant['code'] ? $pretendant : $tenant;

                $exclusives[$type] = $gagnant;

                $conflits[] = [
                    'action_type'              => $type,
                    // Chaîne : la colonne l'est, et l'empreinte du journal doit se
                    // recalculer à l'identique après un aller-retour en base.
                    'valeur_retenue'           => self::texte($gagnant['action']['valeur'] ?? null),
                    'protocole_retenu_code'    => $gagnant['code'],
                    'protocole_retenu_version' => $gagnant['numero'],
                    'source_retenue'           => $gagnant['niveau_source'],
                    'valeur_ecartee'           => self::texte($perdant['action']['valeur'] ?? null),
                    'protocole_ecarte_code'    => $perdant['code'],
                    'protocole_ecarte_version' => $perdant['numero'],
                    'source_ecartee'           => $perdant['niveau_source'],
                    'critere'                  => $verdict['critere'],
                ];

                $aContribue = true;
            }

            foreach ($evaluation['regles'] as $regle) {
                $regles[] = $regle + ['protocole' => $evaluation['code']];
            }

            $protocoles[] = [
                'code'          => $evaluation['code'],
                'titre'         => $evaluation['titre'],
                'version'       => $evaluation['version'],
                'numero'        => $evaluation['numero'],
                'niveau_source' => $evaluation['niveau_source'],
                'niveau_preuve' => $evaluation['niveau_preuve'],
                'publie_le'     => $evaluation['publie_le'],
                'a_contribue'   => $aContribue,
            ];
        }

        // Les gagnantes rejoignent les actions cumulées, à la fin : leur position n'a pas de sens
        // clinique, mais elle doit être STABLE pour que deux évaluations identiques produisent le
        // même journal.
        $retenu = null;

        foreach ($exclusives as $gagnant) {
            $actions[] = $gagnant['action'] + ['protocole' => $gagnant['code']];
            $retenu ??= [
                'code'    => $gagnant['code'],
                'version' => $gagnant['version'],
                'numero'  => $gagnant['numero'],
            ];
        }

        return [
            'actions'            => $actions,
            // Les faits restitués sont ceux du protocole RETENU quand il y en a un : c'est son
            // chaînage avant qui a produit le score affiché à côté de son niveau. Prendre ceux
            // d'un autre protocole afficherait un score qui contredit la décision.
            'faits'              => $this->faitsDuRetenu($evaluations, $retenu, $faitsInitiaux),
            'protocole_retenu'   => $retenu,
            'protocoles'         => $protocoles,
            'ecartes'            => $ecartes,
            'conflits'           => $conflits,
            'regles_declenchees' => $regles,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $evaluations
     * @param  array<string, mixed>|null  $retenu
     * @param  array<string, mixed>  $faitsInitiaux
     * @return array<string, mixed>
     */
    private function faitsDuRetenu(array $evaluations, ?array $retenu, array $faitsInitiaux): array
    {
        if ($retenu === null) {
            return $faitsInitiaux;
        }

        foreach ($evaluations as $evaluation) {
            if ($evaluation['code'] === $retenu['code']) {
                return $evaluation['faits'];
            }
        }

        return $faitsInitiaux;
    }

    /** La forme sous laquelle une valeur d'action est consignée : une chaîne, ou rien. */
    private static function texte(mixed $valeur): ?string
    {
        return $valeur === null ? null : (string) $valeur;
    }

    /**
     * Comparaison en CHAÎNE, jamais en `==`.
     *
     * Les valeurs d'action viennent d'un JSON publié : `90` et `"90"` y désignent la même consigne.
     * Une comparaison lâche ferait en revanche coïncider `0` et `"faible"` — le motif exact retenu
     * par `MoteurProtocole::comparables()` en b-1.
     */
    private function memeValeur(mixed $a, mixed $b): bool
    {
        return (string) ($a ?? '') === (string) ($b ?? '');
    }
}
