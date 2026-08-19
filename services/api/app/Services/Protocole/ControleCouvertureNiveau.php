<?php

namespace App\Services\Protocole;

use App\Models\Protocole;
use App\Models\ProtocoleVersion;
use App\Support\RegistreActionsProtocole;
use App\Support\RegistreContextesProtocole;

/**
 * P10b-2 — Tout patient reçoit-il un niveau ? La question a changé de PORTÉE (CDC_08 §7.4, §1.6).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * UN DÉFAUT DE CONCEPTION HÉRITÉ DE b-1, RÉVÉLÉ PAR b-2
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * b-1 vérifiait la couverture des bandes 0-100 **protocole par protocole**. C'était exact tant
 * qu'un seul protocole existait : lui seul pouvait orienter, donc lui seul devait tout couvrir.
 *
 * Dès que b-2 autorise plusieurs protocoles, cette vérification **interdit toute surcouche** : un
 * protocole régional qui ne traite qu'un cas particulier serait refusé parce qu'il « ne couvre pas
 * 0-100 » — alors que c'est le national qui couvre, et que faire cohabiter les deux est
 * exactement l'objet du §3.
 *
 * *Une garde plus stricte que sa propre règle est un défaut même quand elle refuse par prudence*
 * (leçon de la collation, P6.8c). La question reste posée, mais au bon niveau : **l'ensemble** des
 * protocoles en vigueur couvre-t-il toute la plage ?
 *
 * ═══ LE RECOUVREMENT NE CHANGE PAS DE CAMP, ET CE N'EST PAS UNE SYMÉTRIE MANQUÉE ═══
 *
 * À l'intérieur d'un protocole, deux bandes qui se recouvrent restent une erreur : le protocole se
 * contredit lui-même, et aucune cascade ne peut le départager — c'est ce que
 * {@see ControleQualiteProtocole} continue de refuser.
 *
 * ENTRE protocoles, le recouvrement est **le cas normal** : c'est précisément ce que le §8 existe
 * pour trancher. Le refuser reviendrait à interdire les conflits qu'on vient d'apprendre à
 * résoudre.
 *
 * ═══ POURQUOI SEUL LE CONTEXTE `triage` EST CONCERNÉ ═══
 *
 * Parce que c'est lui qui a un consommateur qui ne peut pas rester sans réponse :
 * {@see \App\Services\Triage\ServiceNiveauTriage} refuse d'inventer un niveau (500). Ce contrôle
 * existe pour rendre ce 500 inatteignable.
 *
 * En consultation, un protocole qui ne dit rien sur un cas est légitime : le professionnel décide.
 * Exiger là une couverture totale imposerait aux rédacteurs de se prononcer sur des situations
 * qu'ils n'ont pas examinées.
 */
final class ControleCouvertureNiveau
{
    private const SCORE_MIN = 0;

    private const SCORE_MAX = 100;

    /**
     * @param  array<string, mixed>  $contenu  L'instantané compilé de la version à publier.
     * @return array<int, string>  Les anomalies bloquantes. Vide = publiable.
     */
    public function controler(Protocole $protocole, array $contenu): array
    {
        $contextes = RegistreContextesProtocole::filtrer(
            $contenu['metadonnees']['contextes'] ?? $protocole->contextes_json
        );

        if (! in_array(RegistreContextesProtocole::TRIAGE, $contextes, true)) {
            return [];
        }

        // Les bandes de CETTE version, plus celles de tous les autres protocoles en vigueur pour
        // le même contexte. La version en cours de publication est prise dans son contenu compilé
        // et non en base : elle n'y est pas encore.
        $bandes = array_merge(
            $this->bandes($contenu),
            $this->bandesDesAutres($protocole),
        );

        if ($bandes === []) {
            return ['Après cette publication, aucun protocole de triage en vigueur ne définirait '
                .'de niveau : chaque patient resterait sans orientation (CDC_08 §1.6).'];
        }

        usort($bandes, static fn (array $a, array $b): int => $a['min'] <=> $b['min']);

        $erreurs = [];
        $attendu = self::SCORE_MIN;

        foreach ($bandes as $bande) {
            if ($bande['min'] > $attendu) {
                $erreurs[] = "Trou dans les bandes de score : après cette publication, aucun "
                    ."protocole de triage en vigueur ne couvrirait {$attendu} à "
                    .($bande['min'] - 1).'. Un patient dont le score y tombe ne recevrait aucun '
                    .'niveau, sans qu\'aucune erreur ne soit levée.';
            }

            $attendu = max($attendu, $bande['max'] + 1);
        }

        if ($attendu <= self::SCORE_MAX) {
            $erreurs[] = "Les bandes de score s'arrêtent à ".($attendu - 1).' : après cette '
                ."publication, rien ne couvrirait {$attendu} à ".self::SCORE_MAX.'.';
        }

        return $erreurs;
    }

    /**
     * Les bandes d'un contenu compilé.
     *
     * Même forme reconnue qu'en b-1 : une règle dont l'UNIQUE condition est `score entre [a,b]` et
     * qui porte un `DEFINIR_NIVEAU`. Une règle conditionnée à autre chose (l'âge, un symptôme)
     * n'est pas une bande — elle ne s'applique pas à tous les patients de cet intervalle, et la
     * compter comme couvrante ferait croire à une couverture qui n'existe pas.
     *
     * @param  array<string, mixed>  $contenu
     * @return array<int, array{min: int, max: int}>
     */
    private function bandes(array $contenu): array
    {
        $bandes = [];

        foreach ($contenu['regles'] ?? [] as $regle) {
            $conditions = $regle['conditions'] ?? [];

            $porteUnNiveau = false;

            foreach ($regle['actions'] ?? [] as $action) {
                if (($action['type'] ?? null) === RegistreActionsProtocole::DEFINIR_NIVEAU) {
                    $porteUnNiveau = true;
                }
            }

            if (! $porteUnNiveau || count($conditions) !== 1) {
                continue;
            }

            $condition = $conditions[0];

            if (($condition['fait'] ?? null) !== 'score' || ($condition['operateur'] ?? null) !== 'entre') {
                continue;
            }

            $valeur = $condition['valeur'] ?? null;

            if (is_array($valeur) && count($valeur) === 2) {
                $bandes[] = ['min' => (int) $valeur[0], 'max' => (int) $valeur[1]];
            }
        }

        return $bandes;
    }

    /**
     * Les bandes des autres protocoles en vigueur pour le contexte `triage`.
     *
     * Lues dans leur INSTANTANÉ publié, jamais dans les tables de travail : c'est ce qui s'applique
     * réellement. Un protocole désactivé est exclu — il ne sera plus sélectionné, donc il ne
     * couvre plus rien.
     *
     * @return array<int, array{min: int, max: int}>
     */
    private function bandesDesAutres(Protocole $protocole): array
    {
        $bandes = [];

        $versions = ProtocoleVersion::query()
            ->with('protocole')
            ->where('etat', ProtocoleVersion::ACTIF)
            ->whereHas('protocole', fn ($q) => $q
                ->where('pays_code', $protocole->pays_code)
                ->where('id', '!=', $protocole->id)
                ->where('actif', true))
            ->get();

        foreach ($versions as $version) {
            // L'instantané publié, jamais la table (même raison que le sélecteur).
            $contextes = RegistreContextesProtocole::filtrer(
                $version->contenu_json['metadonnees']['contextes'] ?? []
            );

            if (! in_array(RegistreContextesProtocole::TRIAGE, $contextes, true)) {
                continue;
            }

            $bandes = array_merge($bandes, $this->bandes($version->contenu_json ?? []));
        }

        return $bandes;
    }
}
