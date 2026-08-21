<?php

namespace App\Services\Triage;

use App\Services\Protocole\DiffusionProtocole;
use App\Services\Protocole\SelecteurProtocoles;
use App\Support\MoteurProtocole;
use App\Support\RegistreActionsProtocole;
use App\Support\RegistreContextesProtocole;

/**
 * P10b-3-ii — La part des antécédents dans le score, décidée par un protocole publié (CDC_08 §1.2).
 *
 * ═══ CE QUE CET INCRÉMENT SORT DU CODE ═══
 *
 * `TriageService::PLAFOND_ANTECEDENTS = 20` était le dernier seuil de ce service. Il ne décidait
 * d'aucun niveau, mais il bornait une des trois parts du score — donc il pesait sur l'urgence.
 * C'était une **décision clinique** : *quel poids une déclaration non vérifiée du patient peut-elle
 * avoir sur son urgence ?* Elle passe sous les quatre validations du §7.
 *
 * ═══ ET C'EST LA RÉPONSE À `impact_triage`, PAS UNE INCOHÉRENCE AVEC LUI ═══
 *
 * `impact_triage` est saisi par le patient lui-même (constat Y1). On aurait pu croire indéfendable
 * de faire signer une borne posée sur une saisie libre. C'est l'inverse : **la borne existe
 * précisément parce que l'entrée n'est pas vérifiée**. La gouverner, c'est gouverner la seule
 * moitié qui puisse l'être.
 *
 * Les deux autres voies ont été examinées et écartées, et il faut dire pourquoi :
 *  - dériver l'impact d'une gravité gouvernée supposerait d'inventer, dans le référentiel des
 *    maladies, une échelle que personne n'a validée — ce que P6.8c avait refusé pour `categorie` ;
 *  - refuser la déclaration du patient ferait tomber cette part à 0 pour tout le carnet, donc
 *    **baisserait** les scores : un défaut qui pousse vers le SOUS-triage, la direction dangereuse.
 *
 * ═══ POURQUOI UN CONTEXTE PROPRE, CONTRE CE QUE DISAIT LE PLAN G1 ═══
 *
 * Le plan logeait ce protocole dans `triage_questionnaire`. L'implémentation a montré que cela
 * recréait le défaut Z1 : `POST /triage/questions` ne connaît pas le membre, donc pas ses
 * antécédents, alors que `POST /triage/analyser` les connaît. Une même règle aurait répondu
 * différemment selon l'endpoint. Le contexte propre rend la frontière **vérifiable** au lieu de
 * conventionnelle.
 *
 * ═══ REFUS BRUYANT ═══
 *
 * Sans protocole en vigueur, ce service **refuse** au lieu de replier sur 20. Un repli laisserait
 * un oubli de publication parfaitement invisible : tout fonctionnerait, et personne ne saurait que
 * la borne appliquée n'est pas celle qui a été signée (précédent L1+L2, puis P10b-3-i).
 */
final class ServicePlafondAntecedents
{
    /** Le protocole seedé qui transcrit l'ancien `PLAFOND_ANTECEDENTS`. */
    public const CODE = 'TRIAGE-ANTECEDENTS';

    public function __construct(
        private readonly SelecteurProtocoles $selecteur,
        private readonly DiffusionProtocole $diffusion,
    ) {}

    public function estEnVigueur(): bool
    {
        return $this->selecteur
            ->selectionner(RegistreContextesProtocole::TRIAGE_ANTECEDENTS)['retenus'] !== [];
    }

    /**
     * La part des antécédents retenue pour ce patient.
     *
     * @param  array<string, mixed>  $faits  Les faits de base ({@see FaitsTriage::base()}).
     * @return array{valeur: int, borne: int, brut: int, protocoles: array<int, array<string, mixed>>, regles_declenchees: array<int, array<string, mixed>>}
     */
    public function part(array $faits): array
    {
        $retenus = $this->selecteur
            ->selectionner(RegistreContextesProtocole::TRIAGE_ANTECEDENTS)['retenus'];

        if ($retenus === []) {
            $this->refuser();
        }

        // Le fait produit ne doit pas préexister : une valeur d'entrée se ferait passer pour une
        // décision de protocole si aucune règle ne se déclenchait.
        unset($faits['score_antecedents']);

        $valeurs = [];
        $protocoles = [];
        $declenchees = [];

        foreach ($retenus as $candidat) {
            $publie = $this->diffusion->lire((string) $candidat['code']);

            $resultat = MoteurProtocole::evaluer($publie['contenu']['regles'] ?? [], $faits);

            foreach ($resultat['actions'] as $action) {
                if ($action['type'] === RegistreActionsProtocole::BORNER_SCORE_ANTECEDENTS) {
                    $valeurs[] = (int) $action['valeur'];
                }
            }

            foreach ($resultat['regles_declenchees'] as $regle) {
                $declenchees[] = $regle;
            }

            $protocoles[] = [
                'code' => $publie['code'],
                'version' => $publie['version'],
                'numero' => $publie['numero'],
            ];
        }

        $distinctes = array_values(array_unique($valeurs));

        // ═══ DEUX BORNES DIFFÉRENTES NE SE DÉPARTAGENT PAS TOUTES SEULES ═══
        //
        // DIVERGENCE ANNONCÉE AVEC LE §8, plutôt que déguisée : à rang égal, P10b-2 refuse déjà la
        // publication d'une seconde version que seule la date départagerait — la garde d'amont fait
        // son travail. À rangs différents, le §8 saurait départager, et ce service **refuse au lieu
        // de le faire**. C'est plus strict que le corpus, et c'est délibéré : la cascade du §8
        // départage des recommandations qu'un clinicien lit, alors qu'ici la valeur retenue
        // modifierait un score EN SILENCE. Un refus se voit ; un départage tacite, non.
        //
        // `BORNER_SCORE_ANTECEDENTS` est déclarée exclusive : à la différence d'un plancher, deux
        // bornes se contredisent. En choisir une ici serait inventer une sémantique que personne
        // n'a signée — le §8 confie ce départage à un humain, et aucun écran ne le fait encore.
        if (count($distinctes) > 1) {
            abort(503, 'Deux protocoles en vigueur bornent différemment la part des antécédents ('
                .implode(', ', $distinctes).') : le §8 doit les départager avant tout triage.');
        }

        // Aucune borne décidée : on REFUSE, on ne se rabat pas sur la somme brute. Un repli
        // laisserait un protocole publié mais inopérant — des règles qui ne se déclenchent jamais —
        // se comporter exactement comme s'il n'y avait aucune borne, et sans bruit.
        if ($distinctes === []) {
            abort(503, 'Aucune règle en vigueur ne borne la part des antécédents : le protocole est '
                ."publié, mais aucune de ses règles ne s'applique à ce patient.");
        }

        $borne = (int) $distinctes[0];
        $brut = (int) ($faits['score_antecedents_brut'] ?? 0);

        return [
            // Le `min` est de l'arithmétique, pas une décision : le chiffre qui décide est la
            // borne, et il vient du protocole.
            'valeur' => max(0, min($brut, $borne)),
            'borne' => $borne,
            'brut' => $brut,
            'protocoles' => $protocoles,
            'regles_declenchees' => $declenchees,
        ];
    }

    /** @return never */
    private function refuser(): void
    {
        // 503 et non 404 : le protocole existe peut-être en brouillon, c'est sa MISE EN VIGUEUR qui
        // manque. Le message nomme ce qu'il faut publier, pour que l'exploitant sache quoi faire.
        abort(503, 'Aucun protocole en vigueur ne borne la part des antécédents dans le score : '
            .'la mise en vigueur est une étape de déploiement, jamais un repli du code (CDC_08 §1.6).');
    }
}
