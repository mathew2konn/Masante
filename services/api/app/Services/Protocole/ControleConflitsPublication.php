<?php

namespace App\Services\Protocole;

use App\Models\Protocole;
use App\Models\ProtocoleVersion;
use App\Support\RegistreActionsProtocole;
use App\Support\RegistreContextesProtocole;
use App\Support\ReglesResolutionConflit;

/**
 * P10b-2 — Le refus de publier une version que seule la DATE départagerait (CDC_08 §7, §8, §10).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * POURQUOI CE CONTRÔLE N'EST PAS « REFUSER LES CONFLITS INSOLUBLES »
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Avec la récence dans la cascade du §8, un conflit *insoluble* est presque impossible : deux
 * versions ne sont jamais publiées au même instant, donc il y a toujours un plus récent. Un
 * contrôle qui refuserait « les conflits qu'on ne sait pas trancher » serait donc **toujours
 * vert** — exactement ce que P5.3b-4 a appris à ne pas livrer (« un contrôle toujours vert ne
 * prouve rien »).
 *
 * Ce qui est refusé est autre chose, et c'est réel : **une version qui ne serait départagée que
 * par la date de publication**.
 *
 * Être départagé par le **rang** (§3) reflète une propriété *écrite, relue et signée* par les
 * quatre validateurs du §7. Être départagé par la date ne reflète **aucune décision** : le
 * résultat d'un triage bascule au moment de la publication, pour des cas que personne n'a
 * examinés — les validateurs ont relu ce protocole **isolément**, jamais face à celui qui était
 * déjà en vigueur.
 *
 * ═══ ET LE NIVEAU DE PREUVE NE SAUVE RIEN, CONTRAIREMENT À CE QU'ON POURRAIT CROIRE ═══
 *
 * Le §8 place la récence AVANT le niveau de preuve. Une version qu'on publie aujourd'hui est
 * par construction la plus récente : elle gagnerait par la date avant qu'on ne regarde ses
 * preuves. Le seul critère qui puisse départager deux protocoles à la publication est donc le
 * rang — et c'est lui, et lui seul, que ce contrôle interroge.
 *
 * ═══ DIVERGENCE ASSUMÉE AVEC LE §8, ET DITE ═══
 *
 * Le §8 autorise la récence comme critère 2. On est donc **plus strict à la publication qu'à
 * l'exécution**. Ce n'est pas une contradiction : le §8 décrit comment le moteur *résout* une
 * situation donnée, le §7 et le §10 comment une version *entre en vigueur*. Le moteur, lui,
 * implémente le §8 en entier — des versions publiées avant ce contrôle peuvent exister, et il doit
 * savoir les départager.
 *
 * Précédent : P6.4d a fait passer le couple région/district de la détection à l'interdiction alors
 * que le §4 n'exigeait que la cohérence. *Détecter après coup oblige à retrouver qui a publié quoi ;
 * au moment de la publication, l'agent a encore l'information sous les yeux.*
 *
 * ═══ CE QUI N'EST PAS CONTRÔLÉ ═══
 *
 * Que les deux protocoles divergent RÉELLEMENT sur un cas concret. Le savoir supposerait
 * d'énumérer toutes les combinaisons de faits possibles — infaisable, et surtout inutile : deux
 * protocoles qui prétendent tous deux fixer le niveau de priorité sur le même contexte se
 * disputent la décision, qu'ils tombent d'accord aujourd'hui ou non. C'est la PRÉTENTION qui est
 * examinée, pas la coïncidence du jour.
 */
final class ControleConflitsPublication
{
    /**
     * @param  array<string, mixed>  $contenu  L'instantané compilé de la version à publier.
     * @return array<int, string>  Les anomalies bloquantes. Vide = publiable.
     */
    public function controler(Protocole $protocole, array $contenu): array
    {
        $exclusives = $this->actionsExclusives($contenu);

        if ($exclusives === []) {
            return [];
        }

        $contextes = RegistreContextesProtocole::filtrer(
            $contenu['metadonnees']['contextes'] ?? $protocole->contextes_json
        );

        if ($contextes === []) {
            // Le contrôle qualité refuse déjà ce cas pour une autre raison (§9.1). Ici on
            // s'abstient plutôt que de doubler le message : un protocole sans contexte n'entrera
            // en compétition avec personne, puisqu'il ne sera jamais sélectionné.
            return [];
        }

        $erreurs = [];

        foreach ($this->concurrents($protocole) as $concurrent) {
            $communs = array_intersect($contextes, $concurrent['contextes']);

            if ($communs === []) {
                continue;
            }

            $partagees = array_intersect($exclusives, $concurrent['exclusives']);

            if ($partagees === []) {
                continue;
            }

            // ═══ SEUL LE RANG PEUT SAUVER CETTE PUBLICATION ═══
            //
            // On n'interroge PAS la cascade complète, et c'est un correctif. La version qu'on
            // publie est, par construction, **la plus récente** : le critère 2 du §8 la
            // couronnerait donc toujours, et le critère 3 (niveau de preuve) ne serait jamais
            // atteint. Un meilleur niveau de preuve ne peut pas sauver cette publication, parce
            // que le §8 place la récence avant lui.
            //
            // Interroger la cascade faisait dépendre le verdict de la GRANULARITÉ DE L'HORLOGE :
            // deux publications tombant dans la même seconde donnaient une égalité de dates, la
            // cascade descendait au niveau de preuve, et le contrôle laissait passer ce qu'il
            // refusait une seconde plus tard. *Une garde dont le verdict change selon la vitesse
            // de la machine n'est pas une garde.* Défaut trouvé par la suite complète, invisible
            // au run filtré.
            if (ReglesResolutionConflit::rang($protocole->niveau_source)
                !== ReglesResolutionConflit::rang($concurrent['descripteur']['niveau_source'])) {
                continue;
            }

            $erreurs[] = sprintf(
                'Conflit non arbitré avec « %s » (%s, en vigueur) : les deux protocoles fixent '
                .'%s sur le contexte %s, avec le même rang (%s). Seule la date de publication les '
                .'départagerait — ce qui ferait décider le calendrier, pour des cas que les '
                .'validateurs du §7 n\'ont pas examinés, puisqu\'ils ont relu ce protocole seul. '
                .'Un meilleur niveau de preuve n\'y changerait rien : le §8 place la récence avant '
                .'lui. Changez le rang, le contexte, ou retirez l\'action en cause.',
                $concurrent['code'],
                $concurrent['titre'],
                implode(', ', $partagees),
                implode(', ', $communs),
                $protocole->niveau_source,
            );
        }

        return $erreurs;
    }

    /**
     * Les types d'actions exclusives que ce contenu prétend fixer.
     *
     * @param  array<string, mixed>  $contenu
     * @return array<int, string>
     */
    private function actionsExclusives(array $contenu): array
    {
        $types = [];

        foreach ($contenu['regles'] ?? [] as $regle) {
            foreach ($regle['actions'] ?? [] as $action) {
                $type = (string) ($action['type'] ?? '');

                if (RegistreActionsProtocole::estExclusive($type)) {
                    $types[$type] = true;
                }
            }
        }

        return array_keys($types);
    }

    /**
     * Les protocoles en vigueur du même pays, hors celui qu'on publie.
     *
     * Leurs actions exclusives sont lues dans leur INSTANTANÉ publié, jamais dans les tables de
     * travail : c'est ce qui s'applique réellement aujourd'hui. Lire les tables ferait porter le
     * contrôle sur un brouillon en cours de rédaction chez quelqu'un d'autre.
     *
     * @return array<int, array<string, mixed>>
     */
    private function concurrents(Protocole $protocole): array
    {
        $concurrents = [];

        $versions = ProtocoleVersion::query()
            ->with('protocole')
            ->where('etat', ProtocoleVersion::ACTIF)
            ->whereHas('protocole', fn ($q) => $q
                ->where('pays_code', $protocole->pays_code)
                ->where('id', '!=', $protocole->id)
                ->where('actif', true))
            ->get();

        foreach ($versions as $version) {
            $autre = $version->protocole;

            $concurrents[] = [
                'code'       => $autre->code,
                'titre'      => $autre->titre,
                // L'instantané publié, jamais la table : un `UPDATE` sur `contextes_json`
                // ne doit pas pouvoir faire entrer — ou sortir — un protocole du champ de ce
                // contrôle sans passer par une publication.
                'contextes'  => RegistreContextesProtocole::filtrer(
                    $version->contenu_json['metadonnees']['contextes'] ?? []
                ),
                'exclusives' => $this->actionsExclusives($version->contenu_json ?? []),
                'descripteur' => [
                    'code'          => $autre->code,
                    'niveau_source' => $autre->niveau_source,
                    'niveau_preuve' => $version->niveau_preuve,
                    'publie_le'     => $version->publie_le?->toIso8601String(),
                    'numero'        => (int) $version->numero,
                ],
            ];
        }

        return $concurrents;
    }
}
