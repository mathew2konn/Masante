<?php

namespace App\Services\Triage;

use App\Services\Protocole\DiffusionProtocole;
use App\Services\Protocole\ProtocoleException;
use App\Support\MoteurProtocole;
use App\Support\NiveauTriage;
use App\Support\RegistreActionsProtocole;

/**
 * P10b-1 — Le niveau de priorité, décidé par un PROTOCOLE et non par du code (CDC_08 §1.2 ;
 * CDC_05 §5.3).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * CE QUE CE SERVICE REMPLACE, ET POURQUOI C'ÉTAIT L'INTERDIT DU §1.2 *EN VIGUEUR*
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `TriageService` portait :
 *
 *     return match (true) {
 *         $score <= 30 => 'leger',
 *         $score <= 65 => 'modere',
 *         default      => 'urgent',
 *     };
 *
 *     if ($drapeauRouge) { $score = max($score, 90); }
 *
 * CDC_08 §1.2 donne son contre-exemple littéral — `if temperature > 39: urgence = True` — et c'est
 * la même famille : des **seuils cliniques en dur** décidant du niveau d'urgence d'un citoyen.
 *
 * P10a avait sorti l'ORIENTATION du code et laissé le NIVEAU dedans. L'orientation dit vers quelle
 * spécialité aller ; le niveau dit **s'il faut courir aux urgences**. C'était la plus lourde des
 * deux, et c'est celle qui restait codée.
 *
 * Les deux règles vivent désormais dans le protocole `TRIAGE-NIVEAU`, relues et signées par quatre
 * validateurs (§7) : les bandes sont des `entre`, et la priorité du drapeau rouge est simplement
 * **l'ordre d'une règle**. Aucune exception n'a été déplacée ailleurs dans le code — elle a
 * disparu.
 *
 * ═══ REFUS BRUYANT, ET LA QUESTION A ÉTÉ REPOSÉE ═══
 *
 * Sans version en vigueur, **503**. Jamais de repli sur des seuils par défaut : un repli laisserait
 * un oubli de publication passer inaperçu, et le triage rendrait des niveaux que personne n'a
 * validés en croyant appliquer un protocole (décision de L1+L2, reprise en P10a).
 *
 * P6.8e avait fait l'inverse pour les numéros d'urgence — mais son argument (« le consommateur n'a
 * ni réseau, ni session, ni compte ») ne vaut pas ici : le triage est un appel API, il n'existe pas
 * sans réseau. C'est le précédent L1+L2 qui s'applique.
 *
 * ═══ LA MÉMOÏSATION PINNE UNE VERSION PAR REQUÊTE ═══
 *
 * Lié en `scoped` : un triage évalue ses règles et s'estampille, et les deux doivent voir la même
 * version. Une publication survenant au milieu produirait un triage **jugé par une version et
 * estampillé par une autre** — motif de `MesureSanteService` (L1+L2) et de `ServiceSymptomesTriage`
 * (P10a), où le service `scoped` a été retenu pour cette raison exacte.
 */
final class ServiceNiveauTriage
{
    /**
     * Le code du protocole qui décide du niveau.
     *
     * C'est une CONSTANTE DE CODE et non une donnée, assumé : il faut bien un point d'entrée nommé
     * pour aller chercher le protocole. Ce qui compte est que **son contenu** — les seuils, les
     * bandes, la priorité — soit entièrement en base. Le code désigne un protocole ; il n'en décide
     * aucune règle.
     */
    public const CODE = 'TRIAGE-NIVEAU';

    /** @var array<string, mixed>|null */
    private ?array $publie = null;

    public function __construct(private readonly DiffusionProtocole $diffusion) {}

    /**
     * Applique le protocole aux faits du triage.
     *
     * @param  array<string, mixed>  $faits  Ce que le triage sait du patient, tel que
     *                                       {@see RegistreFaitsProtocole} les nomme.
     *
     * @return array{niveau: string, score: int, actions: array<int, array<string, mixed>>,
     *               protocole: array{code: string, version: string, numero: int},
     *               regles_declenchees: array<int, array{ordre: int, libelle: string}>}
     */
    public function appliquer(array $faits): array
    {
        $protocole = $this->charger();

        $resultat = MoteurProtocole::evaluer(
            $protocole['contenu']['regles'] ?? [],
            $faits,
        );

        $niveau = $this->niveauDepuisActions($resultat['actions']);

        if ($niveau === null) {
            // ═══ ON REFUSE PLUTÔT QUE D'INVENTER UN NIVEAU ═══
            //
            // Le contrôle de couverture ({@see ControleQualiteProtocole}) prouve à la publication
            // que toute la plage 0-100 est couverte : arriver ici signifie qu'une version est passée
            // à travers, ou que les faits sortent du domaine prévu. Retomber sur une valeur par
            // défaut recréerait le seuil en dur qu'on vient de retirer, et le ferait en silence.
            abort(500, 'Le protocole de triage en vigueur n\'a produit aucun niveau pour ce cas. '
                .'Aucun niveau n\'est inventé : le résultat serait une orientation que personne '
                .'n\'a validée.');
        }

        return [
            'niveau'  => $niveau,
            // Le score APRÈS chaînage avant : c'est celui que le protocole a réellement utilisé,
            // et donc celui qu'il faut montrer et archiver. Le montrer avant application du
            // plancher afficherait un score qui contredit le niveau affiché à côté.
            'score'   => (int) ($resultat['faits']['score'] ?? 0),
            // Les autres actions (ORIENTER, MESSAGE…) sont restituées telles quelles : le §9.1
            // les appelle « recommandations ». Aucune n'est interprétée ici.
            'actions' => array_values(array_filter(
                $resultat['actions'],
                fn (array $a): bool => $a['type'] !== RegistreActionsProtocole::DEFINIR_NIVEAU,
            )),
            'protocole' => [
                'code'    => $protocole['code'],
                'version' => $protocole['version'],
                'numero'  => $protocole['numero'],
            ],
            'regles_declenchees' => $resultat['regles_declenchees'],
        ];
    }

    /**
     * Une version est-elle en vigueur ?
     *
     * NE REPLIE PAS ET NE LÈVE PAS — vérité brute pour un écran d'exploitation.
     */
    public function estEnVigueur(): bool
    {
        return $this->diffusion->estEnVigueur(self::CODE);
    }

    /** La version qui gouverne cette requête, pour l'estampille (§6.1). */
    public function version(): int
    {
        return (int) $this->charger()['numero'];
    }

    /**
     * Le dernier `DEFINIR_NIVEAU` l'emporte.
     *
     * Les bandes ne se recouvrent pas — le contrôle de couverture le garantit à la publication —
     * donc une seule s'applique en pratique. « Le dernier » est néanmoins une règle explicite : sans
     * elle, deux règles concurrentes rendraient un résultat dépendant de l'ordre d'itération plutôt
     * que d'une décision écrite.
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

    /** @return array<string, mixed> */
    private function charger(): array
    {
        if ($this->publie !== null) {
            return $this->publie;
        }

        try {
            return $this->publie = $this->diffusion->lire(self::CODE);
        } catch (ProtocoleException) {
            // 503 et non 404 : le protocole existe peut-être en brouillon, c'est sa MISE EN VIGUEUR
            // qui manque. Même distinction qu'en P10a pour les symptômes.
            abort(503, 'Aucun protocole de triage n\'est en vigueur : aucun niveau de priorité ne '
                .'peut être rendu tant qu\'une version n\'a pas franchi les quatre validations du '
                .'§7 et n\'a pas été publiée (CDC_08 §1.6).');
        }
    }
}
