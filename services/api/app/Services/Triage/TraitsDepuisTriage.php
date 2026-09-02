<?php

namespace App\Services\Triage;

use App\Models\Triage;
use App\Models\TriageConstante;
use App\Models\TriageReponse;
use Illuminate\Support\Collection;

/**
 * P10c-3-ii lot B — Un triage, sous la forme PLATE que connaissent l'apprentissage et la dérive.
 *
 * ═══ POURQUOI CETTE CLASSE EXISTE : POUR NE PAS DUPLIQUER LES DONNÉES D'ENTRÉE ═══
 *
 * Mesurer une dérive suppose de connaître la distribution des entrées d'aujourd'hui. La tentation
 * était de recopier le vecteur de features à côté de chaque prédiction : ce serait rapide, et ce
 * serait une **seconde copie de données cliniques** — exactement ce que le §9.2 interdit en toutes
 * lettres (« les données d'entrée **référencées, non dupliquées en clair** »).
 *
 * La distribution de production est donc **re-dérivée** des tables du triage, à la demande. Ce qui
 * exige que « traduire un triage en ligne plate » existe à UN endroit — sans quoi la dérive
 * mesurerait une population légèrement différente de celle qui a nourri l'apprentissage, et
 * l'écart mesuré serait en partie le nôtre.
 *
 * `ServiceRetourTriage::alimenterJeuApprentissage()` portait cette traduction ; elle vit désormais
 * ici, et les deux appelants la partagent.
 *
 * ═══ CE QUI N'EST PAS ICI ═══
 *
 * Ni `label`, ni les trois faits captés : ce sont des CIBLES, pas des entrées (F36). Ni la
 * conversion en vecteur numérique — elle appartient au service Python, source unique du vecteur
 * (F25), et la refaire ici en ferait une seconde.
 */
final class TraitsDepuisTriage
{
    public function __construct(private readonly ServiceExportJeuEntrainement $export) {}

    /**
     * La ligne plate d'un triage : les mêmes clés que le jeu d'apprentissage, aux cibles près.
     *
     * @return array<string, mixed>
     */
    public function pour(Triage $triage): array
    {
        $constantes = TriageConstante::where('triage_id', $triage->id)->pluck('valeur', 'type_mesure');
        $reponses = TriageReponse::where('triage_id', $triage->id)->pluck('valeur', 'question_cle');

        return [
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
            // P10c-3-i (F14) — persistée à l'écriture du triage, JAMAIS recalculée : un retour peut
            // arriver des jours plus tard, quand la borne gouvernée qui l'a produite a changé de
            // version.
            'score_antecedents' => $triage->score_antecedents,
            'niveau_protocole' => $triage->niveau,
        ];
    }

    /**
     * Le profil de distribution d'un ensemble de triages, prêt à comparer à celui d'un export.
     *
     * Les catégories sont volontairement GROSSIÈRES (tranche d'âge, sexe, présence ou non d'une
     * constante, tranches larges pour les valeurs continues) : PSI compare des parts, pas des
     * valeurs, et des catégories trop fines produiraient un indice élevé pour la seule raison que
     * deux échantillons ne tombent jamais sur les mêmes décimales.
     *
     * @param  iterable<array<string, mixed>>  $lignes  Lignes plates (export ou re-dérivées).
     * @return array<string, array<string, int>>
     */
    public function profil(iterable $lignes): array
    {
        $profil = [];

        foreach ($lignes as $ligne) {
            foreach ($this->categories($ligne) as $feature => $categorie) {
                $profil[$feature][$categorie] = ($profil[$feature][$categorie] ?? 0) + 1;
            }
        }

        return $profil;
    }

    /**
     * Les catégories d'une ligne — la seule fonction qui décide comment on découpe.
     *
     * Elle accepte indifféremment une ligne d'EXPORT (déjà généralisée : `bande_age`, `constantes`
     * imbriquées) et une ligne RE-DÉRIVÉE (`age` exact, constantes à plat) : sans cela, comparer
     * les deux reviendrait à comparer deux conventions plutôt que deux populations.
     *
     * @param  array<string, mixed>  $ligne
     * @return array<string, string>
     */
    private function categories(array $ligne): array
    {
        $constantes = is_array($ligne['constantes'] ?? null) ? $ligne['constantes'] : $ligne;

        return [
            'bande_age' => (string) ($ligne['bande_age'] ?? $this->export->bandePour(
                isset($ligne['age']) ? (int) $ligne['age'] : null) ?? 'inconnu'),
            'sexe' => (string) ($ligne['sexe'] ?? 'inconnu'),
            'nb_symptomes' => $this->nombreDeSymptomes($ligne),
            'temperature' => $this->tranche($constantes['temperature'] ?? null, [37.0, 38.0, 39.0]),
            'pouls' => $this->tranche($constantes['pouls'] ?? null, [60, 90, 120]),
            'saturation_o2' => $this->tranche($constantes['saturation_o2'] ?? null, [92, 95, 98]),
            'duree_jours' => $this->tranche($ligne['duree_jours'] ?? null, [1, 3, 7]),
            'intensite' => $this->tranche($ligne['intensite'] ?? null, [3, 6, 8]),
            'grossesse' => match ($ligne['grossesse'] ?? null) {
                true => 'oui', false => 'non', default => 'inconnu',
            },
            'score_antecedents' => $this->tranche($ligne['score_antecedents'] ?? null, [1, 5, 10]),
        ];
    }

    /**
     * Le nombre de symptômes — ou `illisible`, mais JAMAIS un chiffre inventé.
     *
     * ═══ DÉFAUT RÉEL TROUVÉ AU G2 LIVE : LA PRODUCTION N'EST PAS HOMOGÈNE ═══
     *
     * Ce service est la première pièce de cet incrément à parcourir des données historiques
     * ARBITRAIRES plutôt que des données qu'elle a elle-même écrites. La base réelle en portait une
     * qui l'a fait tomber : un triage dont `symptomes_json` était **doublement encodé**
     * (`"[\"fievre\"]"`, hérité d'un chemin plus ancien). Le cast le décode une fois et rend une
     * chaîne — `count()` levait, et **une seule ligne malformée tuait le rapport quotidien entier**.
     *
     * ═══ POURQUOI `illisible` PLUTÔT QUE ZÉRO ═══
     *
     * Compter zéro symptôme serait fabriquer une donnée : la ligne rejoindrait la catégorie
     * « aucun symptôme » et biaiserait la distribution dans un sens précis. `illisible` est une
     * catégorie à part entière — et si de telles lignes se multipliaient, **PSI le signalerait, ce
     * qui est exactement juste** : une dégradation de la qualité des saisies EST un changement de
     * la population que le modèle rencontre.
     *
     * Même raisonnement que la catégorie `absent` d'une constante non mesurée.
     *
     * @param  array<string, mixed>  $ligne
     */
    private function nombreDeSymptomes(array $ligne): string
    {
        $symptomes = $ligne['symptomes'] ?? $ligne['symptomes_json'] ?? [];

        // Une chaîne peut être un JSON encodé une fois de trop — on tente, sans jamais insister.
        if (is_string($symptomes)) {
            $decode = json_decode($symptomes, true);
            $symptomes = is_array($decode) ? $decode : null;
        }

        return is_array($symptomes) ? $this->tranche(count($symptomes), [1, 3, 5]) : 'illisible';
    }

    /**
     * Une valeur continue, rangée dans une tranche nommée.
     *
     * L'absence a sa PROPRE catégorie (`absent`) et n'est jamais assimilée à zéro : une constante
     * non mesurée et une constante mesurée à zéro sont deux faits différents, et les confondre
     * ferait apparaître une dérive là où seule la complétude des saisies a changé.
     *
     * @param  array<int, int|float>  $bornes
     */
    private function tranche(mixed $valeur, array $bornes): string
    {
        if ($valeur === null || $valeur === '') {
            return 'absent';
        }

        $valeur = (float) $valeur;
        $precedente = null;

        foreach ($bornes as $borne) {
            if ($valeur < $borne) {
                return $precedente === null ? '<'.$borne : $precedente.'-'.$borne;
            }
            $precedente = $borne;
        }

        return '>='.$precedente;
    }

    /**
     * Les triages d'une fenêtre, en lignes plates.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function fenetre(\DateTimeInterface $depuis): Collection
    {
        return Triage::query()
            ->where('created_at', '>=', $depuis)
            ->get()
            ->map(fn (Triage $t): array => $this->pour($t));
    }
}
