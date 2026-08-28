<?php

namespace App\Services\Triage;

use App\Http\Controllers\Api\V1\TriageController;
use App\Models\JeuDonneesEntrainement;
use App\Models\Medecin;
use App\Models\MembreFamille;
use App\Models\ProtocoleApplication;
use App\Models\Triage;
use App\Models\TriageConstante;
use App\Models\TriageReponse;
use App\Models\User;
use App\Services\Protocole\JournalApplicationProtocole;
use App\Support\RegistreContextesProtocole;
use App\Support\RegistreRetourTriage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * P10c-2-i — Le retour d'un soignant sur l'orientation rendue par un triage
 * (CDC_05 §5.5.4, §9.1 ; CDC_08 §10 ; ADR-044).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * CE SERVICE FAIT ENTRER LE PROFESSIONNEL DANS UNE BOUCLE QUI N'EN AVAIT AUCUN
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `TriageController` écrivait, depuis P10b-2 :
 *
 *   « `professionnel_id`, `decision_finale` et `ecart_justification` restent NULS : le triage
 *     citoyen n'a pas de soignant dans la boucle. Le §10 les nomme, ils existent, et le fait
 *     qu'ils soient vides est une limite écrite — pas un oubli. »
 *
 * C'est cette limite que ce service referme. Les trois colonnes existent depuis P10b-2 : on ne
 * crée aucune table pour ce que le corpus a déjà placé.
 *
 * ═══ UNE NOUVELLE ENTRÉE, JAMAIS UNE RÉÉCRITURE — ET LA MIGRATION L'AVAIT ÉCRIT ═══
 *
 * La migration de P10b-2 l'annonçait mot pour mot : *« Une décision prise PLUS TARD ne se
 * rattrapera pas par un `UPDATE` — ce serait réécrire le passé, exactement ce qu'un journal
 * immuable interdit — mais par une NOUVELLE entrée de la chaîne. »* Des déclencheurs refusent de
 * toute façon `UPDATE` et `DELETE` sur cette table.
 *
 * ═══ COMMENT UNE ENTRÉE DE RETOUR SE RECONNAÎT, ET POURQUOI PAS PAR UNE COLONNE ═══
 *
 * Par `decision_finale IS NOT NULL` — jamais renseignée par une évaluation, toujours renseignée
 * par un retour.
 *
 * Ajouter une colonne « nature » aurait été plus explicite et **aurait rompu tout l'historique** :
 * la charge hachée est définie par {@see JournalApplicationProtocole::charge()}, et y ajouter une
 * clé change l'empreinte recalculée de **chaque entrée déjà écrite**. La chaîne entière crierait à
 * l'altération alors que rien n'aurait bougé. On ne modifie pas la charge d'une chaîne vivante.
 *
 * ═══ L'ENTRÉE NE RECOPIE PAS LE PROTOCOLE, ET C'EST VOULU ═══
 *
 * `protocole_retenu`, `protocoles_json` et `recommandations_json` restent vides : **aucune
 * évaluation n'a eu lieu**, un médecin a donné un avis. Le protocole qui a jugé est déjà estampillé
 * sur `triages` et sur l'entrée d'évaluation d'origine ; le recopier ici en ferait une seconde
 * vérité, capable de diverger (motif de `reponses_json` laissée vide en P10b-3-i).
 *
 * Ce n'est pas gratuit non plus : `charge()` relit le libellé de version **dans**
 * `protocoles_json`. Y déclarer un protocole retenu absent de ce tableau ferait diverger l'empreinte
 * entre l'écriture et la relecture — une fausse alerte sur un journal médico-légal, ce que ce même
 * fichier appelle « crier au loup ».
 *
 * ═══ FRONTIÈRE ═══
 *
 * Ce service ne calcule aucune règle médicale : il vérifie une habilitation, une appartenance et
 * une liste blanche, puis enregistre une **déclaration humaine**. À la question « quelles règles
 * métier ce module calcule-t-il ? » : aucune.
 */
class ServiceRetourTriage
{
    public function __construct(private readonly JournalApplicationProtocole $journal) {}

    /**
     * Enregistre l'appréciation d'un soignant sur l'orientation d'un triage.
     *
     * @param  Triage  $triage  Le triage visé — DÉSIGNÉ par le soignant, jamais deviné (décision F1).
     * @param  string  $retour  Une valeur de {@see RegistreRetourTriage}.
     * @param  string|null  $justification  Exigée dès que le retour signale un écart (§10).
     *
     * @throws \RuntimeException Refus destiné à être lu par un humain à l'écran.
     */
    public function enregistrer(
        User $soignant,
        MembreFamille $membre,
        Triage $triage,
        string $retour,
        ?string $justification = null,
    ): ProtocoleApplication {
        // ═══ 1) HABILITATION — VÉRIFIÉE EN SERVICE, PAS PAR LE MIDDLEWARE ═══
        //
        // Le piège de P4 : les permissions spatie sont sur le guard `web`, et un middleware
        // `permission:` posé sur une route au mauvais guard laisse passer. La garde qui fait
        // autorité est ici ; celle de la route n'évite qu'un formulaire inutile.
        if (! $soignant->can('triage.retour')) {
            throw new \RuntimeException(
                "Vous n'êtes pas habilité à donner un retour clinique sur une orientation."
            );
        }

        // ═══ 2) LE TRIAGE DOIT ÊTRE CELUI DU DOSSIER OUVERT (anti-IDOR par construction) ═══
        //
        // Aucun `membre_id` ne circule dans l'URL sur ce chemin (règle héritée du Module 4) : le
        // membre vient de la SESSION. Il reste à refuser qu'un triage d'un autre patient soit
        // désigné — sans quoi un soignant pourrait annoter, depuis le dossier qu'on lui a ouvert,
        // le triage de n'importe qui en changeant un identifiant de formulaire.
        if ($triage->membre_id === null || (int) $triage->membre_id !== (int) $membre->id) {
            throw new \RuntimeException('Ce triage ne figure pas au dossier ouvert.');
        }

        // ═══ 3) LISTE BLANCHE FERMÉE ═══
        //
        // La valeur vient d'un formulaire. `decision_finale` est un `string(200)` QUI ENTRE DANS
        // L'EMPREINTE : sans cette garde, du texte libre s'inscrirait dans une chaîne immuable, et
        // le jeu d'apprentissage se peuplerait d'étiquettes incomparables entre elles.
        if (! RegistreRetourTriage::existe($retour)) {
            throw new \RuntimeException(
                'Retour inconnu. Valeurs admises : '.implode(', ', RegistreRetourTriage::valeurs()).'.'
            );
        }

        $justification = $justification === null ? null : trim($justification);

        // ═══ 4) UN ÉCART SANS MOTIF EST UN SIGNAL QU'ON NE PEUT PAS EXPLOITER ═══
        //
        // Le §10 nomme `ecart_justification`. Un sous-triage signalé sans un mot ne dit ni ce qui
        // manquait au questionnaire, ni ce que le protocole n'a pas vu : il constate sans permettre
        // de corriger. Obligatoire et sans valeur par défaut — précédent du motif de scellement
        // (ADR-042) et de la commission sans seed (P5.5a).
        if (RegistreRetourTriage::estUnEcart($retour) && ($justification === null || $justification === '')) {
            throw new \RuntimeException(
                "Précisez ce que l'orientation n'a pas vu : un écart sans motif ne permet pas de corriger."
            );
        }

        // Sur un accord, le motif n'a pas de sens : on ne justifie pas un accord. On ne le
        // conserve pas non plus « au cas où » — ce serait du texte clinique libre inscrit dans une
        // chaîne immuable sans que rien ne l'exige.
        if (! RegistreRetourTriage::estUnEcart($retour)) {
            $justification = null;
        }

        // ═══ 5) L'IDENTITÉ PROFESSIONNELLE, QUAND ELLE EXISTE ═══
        //
        // `professionnel_id` désigne la fiche du référentiel (P6.5a), `user_id` le compte : deux
        // identifiants distincts et non redondants, comme la migration du §10 le dit. Un soignant
        // habilité mais sans fiche professionnelle reste identifié par son compte.
        $fiche = Medecin::where('user_id', $soignant->id)->first();

        return DB::transaction(function () use ($soignant, $membre, $triage, $retour, $justification, $fiche): ProtocoleApplication {
            $entree = $this->journal->inscrire(
                // Aucune évaluation : un avis humain sur une décision déjà rendue.
                [
                    'protocole_retenu' => null,
                    'protocoles' => [],
                    'actions' => [],
                    'conflits' => [],
                ],
                [
                    'contexte' => RegistreContextesProtocole::TRIAGE,
                    'pays_code' => config('referentiels.pays_defaut', 'CI'),
                    'membre_id' => $membre->id,
                    'user_id' => $soignant->id,
                    'professionnel_id' => $fiche?->id,
                    'triage_id' => $triage->id,
                    'decision_finale' => $retour,
                    'ecart_justification' => $justification,
                ],
            );

            $this->alimenterJeuApprentissage($triage, $retour);

            return $entree;
        });
    }

    /**
     * P10c-2-i (F4) — Une ligne du jeu d'apprentissage §5.5.4, à chaque retour donné.
     *
     * ═══ PLUSIEURS LIGNES PEUVENT PORTER LE MÊME `triage_id`, ET C'EST ASSUMÉ ═══
     *
     * Le journal ci-dessus est append-only ; cette table l'est aussi, pour la même raison qu'écrite
     * dans sa migration — `triage_id` y sert à l'idempotence et à la traçabilité, pas à empêcher
     * plusieurs lignes. Un second retour (un médecin qui se ravise, garanti possible par
     * {@see retoursDe}) produit donc une SECONDE ligne d'apprentissage, jamais une réécriture de la
     * première. Choisir laquelle fait foi à l'entraînement est une question d'EXPORT (P10c-3), pas
     * de cet incrément — trancher ici aurait empiété sur une décision qui n'est pas encore prise.
     *
     * ═══ AUCUNE IDENTITÉ — LES MÊMES SOURCES QUE {@see TriageController::appelerAssistanceIa()} ═══
     */
    private function alimenterJeuApprentissage(Triage $triage, string $retour): void
    {
        $constantes = TriageConstante::where('triage_id', $triage->id)->pluck('valeur', 'type_mesure');
        $reponses = TriageReponse::where('triage_id', $triage->id)->pluck('valeur', 'question_cle');

        JeuDonneesEntrainement::create([
            'triage_id' => $triage->id,
            'age' => $triage->patient_age,
            'sexe' => $triage->patient_sexe,
            'symptomes_json' => $triage->symptomes_json,
            'temperature' => $constantes['temperature'] ?? null,
            'pouls' => $constantes['pouls'] ?? null,
            'saturation_o2' => $constantes['saturation_o2'] ?? null,
            'tension_systolique' => $constantes['tension_systolique'] ?? null,
            'tension_diastolique' => $constantes['tension_diastolique'] ?? null,
            'poids' => $constantes['poids'] ?? null,
            'duree_jours' => isset($reponses['duree_jours']) ? (int) $reponses['duree_jours'] : null,
            'intensite' => isset($reponses['intensite']) ? (int) $reponses['intensite'] : null,
            'grossesse' => isset($reponses['grossesse']) ? $reponses['grossesse'] === 'oui' : null,
            'niveau_protocole' => $triage->niveau,
            'label' => $retour,
            'cree_le' => now(),
        ]);
    }

    /**
     * Les retours déjà donnés sur ce triage, du plus ancien au plus récent.
     *
     * ═══ POURQUOI UN SECOND RETOUR EST ACCEPTÉ ═══
     *
     * Le journal est append-only : un praticien qui se ravise ne peut pas corriger, il ajoute. Le
     * refuser figerait un avis rendu à la hâte, et P10b-1 a déjà payé ce prix dans l'autre sens —
     * *un relecteur corrigeant son avis voyait le précédent faire autorité*, faute d'ordre total.
     *
     * L'ordre est donc TOTAL — `cree_le` puis `id` — pour que deux retours écrits dans la même
     * seconde ne soient jamais départagés par le hasard du moteur. Le dernier fait foi ; les
     * précédents restent visibles, parce qu'un avis retiré est lui-même une information.
     *
     * @return Collection<int, ProtocoleApplication>
     */
    public function retoursDe(Triage $triage)
    {
        return ProtocoleApplication::query()
            ->where('triage_id', $triage->id)
            ->whereNotNull('decision_finale')
            ->orderBy('cree_le')
            ->orderBy('id')
            ->get();
    }
}
