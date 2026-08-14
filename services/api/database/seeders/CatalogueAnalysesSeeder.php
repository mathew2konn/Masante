<?php

namespace Database\Seeders;

use App\Models\Analyse;
use App\Services\Analyse\AttributeurCodeAnalyse;
use Illuminate\Database\Seeder;

/**
 * P6.7a — Jeu de DÉMONSTRATION du catalogue national des analyses (CDC_09 §7.3).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 * CE QUE CE JEU EST, ET CE QU'IL N'EST PAS — À LIRE AVANT DE S'EN SERVIR
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * Ces valeurs de référence sont des **ordres de grandeur usuels**, fournis pour démontrer que la
 * structure stratifiée fonctionne. Elles ne sont :
 *
 *   · NI validées cliniquement,
 *   · NI attribuées à une autorité sanitaire, une société savante ou un laboratoire,
 *   · NI établies sur la population ivoirienne.
 *
 * Chaque strate porte `source = 'demonstration'`, et l'API comme l'écran le disent. *Un intervalle
 * inventé qui porterait le nom d'une autorité serait pire qu'un intervalle inventé qui l'avoue.*
 *
 * ═══ POURQUOI LES INTERVALLES DEVRONT ÊTRE ÉTABLIS LOCALEMENT ═══
 *
 * Les valeurs usuelles dépendent de la population. Le cas le mieux documenté est celui des
 * **polynucléaires neutrophiles** : leur taux usuel est plus bas dans plusieurs populations
 * d'Afrique subsaharienne, au point qu'un intervalle établi ailleurs classe « anormaux » des sujets
 * parfaitement sains — et peut conduire à des explorations inutiles. Ce jeu inclut délibérément ce
 * paramètre pour rendre la question visible.
 *
 * ═══ CE QUE LE REMPLACEMENT COÛTERA ═══
 *
 * De la **donnée, zéro code**. La structure — analyse × sexe × tranche d'âge × état physiologique,
 * avec source obligatoire — est celle d'un référentiel réel. Charger un catalogue officiel revient à
 * remplacer les lignes et à publier une nouvelle version : le contrôle qualité refusera toute strate
 * sans source, et l'écran de gouvernance montrera que la provenance a changé.
 *
 * Idempotent : `firstOrCreate` sur le couple (libellé, milieu).
 */
class CatalogueAnalysesSeeder extends Seeder
{
    /** L'étiquette de provenance, écrite une fois et jamais recopiée. */
    private const DETAIL = 'Jeu de démonstration MaSanté — valeurs usuelles, non validées cliniquement '
        .'et non établies sur la population ivoirienne. À remplacer au déploiement.';

    public function run(): void
    {
        $attributeur = app(AttributeurCodeAnalyse::class);

        foreach ($this->catalogue() as $entree) {
            $strates = $entree['references'];
            unset($entree['references']);

            $analyse = Analyse::firstOrCreate(
                ['libelle' => $entree['libelle'], 'milieu_preleve' => $entree['milieu_preleve']],
                $entree,
            );

            $attributeur->attribuer($analyse);

            foreach ($strates as $strate) {
                $analyse->references()->firstOrCreate(
                    [
                        'sexe'               => $strate['sexe'],
                        'age_min_jours'      => $strate['age_min_jours'],
                        'age_max_jours'      => $strate['age_max_jours'],
                        'etat_physiologique' => $strate['etat_physiologique'],
                    ],
                    $strate + ['source' => 'demonstration', 'source_detail' => self::DETAIL],
                );
            }

            $this->command?->line("  {$analyse->code}  {$analyse->designation}  ("
                .count($strates).' strate(s))');
        }

        $this->command?->warn('  Valeurs de référence = JEU DE DÉMONSTRATION, non validé cliniquement.');
    }

    /**
     * Le catalogue de démonstration.
     *
     * Les âges sont EN JOURS : 28 j = période néonatale, 365 j = un an, 6 570 j = 18 ans.
     *
     * @return array<int, array<string, mixed>>
     */
    private function catalogue(): array
    {
        $adulte = 6570;   // 18 ans

        return [
            [
                'libelle'        => 'Hémoglobine',
                'categorie'      => 'hematologie',
                'milieu_preleve' => 'sang_veineux',
                'unite'          => 'g/dL',
                'methode'        => 'Cytométrie en flux',
                'conditions_prelevement' => 'Tube EDTA. Pas de jeûne nécessaire.',
                'conservation'   => '24 h à température ambiante.',
                'delai_rendu_heures' => 4,
                'description'    => 'Concentration en hémoglobine du sang total.',
                'references'     => [
                    ['sexe' => 'M', 'age_min_jours' => $adulte, 'age_max_jours' => null,
                        'etat_physiologique' => 'standard', 'valeur_min' => 13.0, 'valeur_max' => 17.0,
                        'critique_bas' => 7.0, 'critique_haut' => null, 'libelle_strate' => 'Homme adulte'],
                    ['sexe' => 'F', 'age_min_jours' => $adulte, 'age_max_jours' => null,
                        'etat_physiologique' => 'standard', 'valeur_min' => 12.0, 'valeur_max' => 16.0,
                        'critique_bas' => 7.0, 'critique_haut' => null, 'libelle_strate' => 'Femme adulte'],
                    // LA STRATE QUI JUSTIFIE TOUTE LA STRUCTURE : sans elle, 11 g/dL serait annoncé
                    // « bas » à une femme enceinte pour qui c'est normal.
                    ['sexe' => 'F', 'age_min_jours' => $adulte, 'age_max_jours' => null,
                        'etat_physiologique' => 'grossesse_t2', 'valeur_min' => 10.5, 'valeur_max' => 14.0,
                        'critique_bas' => 7.0, 'critique_haut' => null, 'libelle_strate' => 'Grossesse — 2ᵉ trimestre'],
                    ['sexe' => 'F', 'age_min_jours' => $adulte, 'age_max_jours' => null,
                        'etat_physiologique' => 'grossesse_t3', 'valeur_min' => 11.0, 'valeur_max' => 14.0,
                        'critique_bas' => 7.0, 'critique_haut' => null, 'libelle_strate' => 'Grossesse — 3ᵉ trimestre'],
                    ['sexe' => 'tous', 'age_min_jours' => 0, 'age_max_jours' => 28,
                        'etat_physiologique' => 'nouveau_ne', 'valeur_min' => 14.0, 'valeur_max' => 22.0,
                        'critique_bas' => 10.0, 'critique_haut' => null, 'libelle_strate' => 'Nouveau-né (0 à 28 jours)'],
                    ['sexe' => 'tous', 'age_min_jours' => 29, 'age_max_jours' => $adulte - 1,
                        'etat_physiologique' => 'standard', 'valeur_min' => 11.0, 'valeur_max' => 15.0,
                        'critique_bas' => 7.0, 'critique_haut' => null, 'libelle_strate' => 'Enfant et adolescent'],
                ],
            ],
            [
                'libelle'        => 'Polynucléaires neutrophiles',
                'categorie'      => 'hematologie',
                'milieu_preleve' => 'sang_veineux',
                'unite'          => '/mm³',
                'methode'        => 'Cytométrie en flux',
                'conditions_prelevement' => 'Tube EDTA.',
                'delai_rendu_heures' => 4,
                'description'    => 'Numération des polynucléaires neutrophiles. LE TAUX USUEL DÉPEND '
                    .'DE LA POPULATION : il est plus bas dans plusieurs populations d\'Afrique '
                    .'subsaharienne, et un intervalle établi ailleurs classe « anormaux » des sujets '
                    .'sains. C\'est le meilleur exemple de la nécessité d\'intervalles établis localement.',
                'references'     => [
                    ['sexe' => 'tous', 'age_min_jours' => $adulte, 'age_max_jours' => null,
                        'etat_physiologique' => 'standard', 'valeur_min' => 1500.0, 'valeur_max' => 7000.0,
                        'critique_bas' => 500.0, 'critique_haut' => null, 'libelle_strate' => 'Adulte'],
                ],
            ],
            [
                'libelle'        => 'Glycémie à jeun',
                'categorie'      => 'biochimie',
                'milieu_preleve' => 'plasma',
                'unite'          => 'g/L',
                'methode'        => 'Enzymatique (hexokinase)',
                'conditions_prelevement' => 'Jeûne de 8 à 12 h. Tube fluorure.',
                'conservation'   => '2 h à température ambiante, 24 h à +4 °C.',
                'delai_rendu_heures' => 4,
                'description'    => 'Glucose plasmatique à jeun. À NE PAS CONFONDRE avec la glycémie '
                    .'capillaire, qui est une autre entrée du catalogue.',
                'references'     => [
                    ['sexe' => 'tous', 'age_min_jours' => $adulte, 'age_max_jours' => null,
                        'etat_physiologique' => 'standard', 'valeur_min' => 0.70, 'valeur_max' => 1.10,
                        'critique_bas' => 0.50, 'critique_haut' => 4.00, 'libelle_strate' => 'Adulte'],
                ],
            ],
            [
                // Deux entrées pour « la glycémie » : c'est exactement le point du §7.3.
                'libelle'        => 'Glycémie capillaire',
                'categorie'      => 'biochimie',
                'milieu_preleve' => 'sang_capillaire',
                'unite'          => 'g/L',
                'methode'        => 'Lecteur de glycémie (bandelette)',
                'conditions_prelevement' => 'Piqûre au doigt.',
                'delai_rendu_heures' => 0,
                'description'    => 'Mesure au lit du patient ou en autosurveillance. Entrée DISTINCTE '
                    .'de la glycémie à jeun sur plasma : ni la même méthode, ni le même milieu.',
                'references'     => [
                    ['sexe' => 'tous', 'age_min_jours' => $adulte, 'age_max_jours' => null,
                        'etat_physiologique' => 'standard', 'valeur_min' => 0.70, 'valeur_max' => 1.40,
                        'critique_bas' => 0.50, 'critique_haut' => 4.00, 'libelle_strate' => 'Adulte'],
                ],
            ],
            [
                'libelle'        => 'Créatinine',
                'categorie'      => 'biochimie',
                'milieu_preleve' => 'serum',
                'unite'          => 'mg/L',
                'methode'        => 'Jaffé compensé',
                'conditions_prelevement' => 'Tube sec. Pas de jeûne obligatoire.',
                'delai_rendu_heures' => 6,
                'description'    => 'Marqueur de la fonction rénale. La référence dépend de la masse '
                    .'musculaire, donc du sexe.',
                'references'     => [
                    ['sexe' => 'M', 'age_min_jours' => $adulte, 'age_max_jours' => null,
                        'etat_physiologique' => 'standard', 'valeur_min' => 7.0, 'valeur_max' => 13.0,
                        'critique_bas' => null, 'critique_haut' => 40.0, 'libelle_strate' => 'Homme adulte'],
                    ['sexe' => 'F', 'age_min_jours' => $adulte, 'age_max_jours' => null,
                        'etat_physiologique' => 'standard', 'valeur_min' => 6.0, 'valeur_max' => 11.0,
                        'critique_bas' => null, 'critique_haut' => 40.0, 'libelle_strate' => 'Femme adulte'],
                ],
            ],
            [
                'libelle'        => 'Protéine C réactive (CRP)',
                'categorie'      => 'immunologie',
                'milieu_preleve' => 'serum',
                'unite'          => 'mg/L',
                'methode'        => 'Immunoturbidimétrie',
                'conditions_prelevement' => 'Tube sec.',
                'delai_rendu_heures' => 4,
                'description'    => 'Marqueur d\'inflammation aiguë.',
                'references'     => [
                    // Borne haute seule : « < 5 » est une référence légitime, et la structure
                    // l'accepte sans inventer une borne basse.
                    ['sexe' => 'tous', 'age_min_jours' => null, 'age_max_jours' => null,
                        'etat_physiologique' => 'standard', 'valeur_min' => null, 'valeur_max' => 5.0,
                        'critique_bas' => null, 'critique_haut' => null, 'libelle_strate' => 'Tous âges'],
                ],
            ],
            [
                'libelle'        => 'Plaquettes',
                'categorie'      => 'hematologie',
                'milieu_preleve' => 'sang_veineux',
                'unite'          => '/mm³',
                'methode'        => 'Cytométrie en flux',
                'conditions_prelevement' => 'Tube EDTA.',
                'delai_rendu_heures' => 4,
                'references'     => [
                    ['sexe' => 'tous', 'age_min_jours' => null, 'age_max_jours' => null,
                        'etat_physiologique' => 'standard', 'valeur_min' => 150000.0, 'valeur_max' => 400000.0,
                        'critique_bas' => 50000.0, 'critique_haut' => null, 'libelle_strate' => 'Tous âges'],
                ],
            ],
            [
                'libelle'        => 'Goutte épaisse (paludisme)',
                'categorie'      => 'parasitologie',
                'milieu_preleve' => 'sang_capillaire',
                'unite'          => 'parasites/µL',
                'methode'        => 'Microscopie après coloration',
                'conditions_prelevement' => 'Prélèvement au doigt, idéalement pendant le pic fébrile.',
                'delai_rendu_heures' => 2,
                'description'    => 'Recherche et quantification de Plasmodium. Entrée majeure du '
                    .'catalogue ivoirien.',
                'references'     => [
                    ['sexe' => 'tous', 'age_min_jours' => null, 'age_max_jours' => null,
                        'etat_physiologique' => 'standard', 'valeur_min' => null, 'valeur_max' => 0.0,
                        'critique_bas' => null, 'critique_haut' => null,
                        'libelle_strate' => 'Absence de parasite (tous âges)'],
                ],
            ],
        ];
    }
}
