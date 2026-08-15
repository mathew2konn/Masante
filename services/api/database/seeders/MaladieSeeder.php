<?php

namespace Database\Seeders;

use App\Models\LibelleMaladie;
use App\Models\Maladie;
use App\Models\SurveillanceMaladie;
use App\Models\Vaccin;
use Illuminate\Database\Seeder;

/**
 * P6.8c — Jeu de DÉMONSTRATION du référentiel des maladies (CDC_09 §8).
 *
 * ═══ CE QUE CE JEU EST, ET CE QU'IL N'EST PAS ═══
 *
 * **Aucun code CIM n'y figure, et c'est délibéré.** CIM-10 et CIM-11 sont des publications de l'OMS ;
 * SNOMED CT suppose une licence de membre national. Écrire « B50 » de mémoire à côté de « Paludisme »
 * produirait un code *qui a l'air juste*, donc qui ne se ferait jamais corriger — exactement le
 * raisonnement de P6.4a sur les 33 régions sanitaires, et le motif de `analyses.loinc` (P6.7a) qui
 * existe et reste vide. Charger la CIM sera de la **donnée, zéro code**.
 *
 * Les LIBELLÉS, eux, ne sont pas inventés : ce sont les sept que `AlerteEpidemiqueController::MALADIES`
 * portait en dur depuis le Module 5, ceux que `VaccinSeeder` nomme dans `maladies_evitees`, et
 * quelques affections chroniques courantes. **Adopter plutôt qu'inventer** — même décision qu'en
 * P6.8a pour les codes de spécialité.
 *
 * ═══ LES SYNONYMES NE SONT PAS DÉCORATIFS ═══
 *
 * « palu », « typhoïde », « HTA », « drépano » sont ce qu'un agent tape réellement. Ils sont livrés
 * en langue pivot, donc **jamais principaux** : le libellé officiel vit sur la ligne `maladies`.
 * Les libellés anglais sont livrés `principal` pour leur langue — ils portent la même provenance de
 * démonstration que le reste et **ne sont attribués à aucune autorité**.
 *
 * **Aucun libellé en langue nationale ivoirienne n'est livré.** Je ne connais pas les dénominations
 * en dioula ou en baoulé et je n'en invente pas : la structure les accueille, les charger sera de la
 * donnée.
 *
 * ═══ CE SEEDER NE PUBLIE RIEN ═══
 *
 * Il alimente les tables ; la mise en vigueur passe par le cycle §10 (proposition, quatre-yeux,
 * publication). Publier depuis un seeder contournerait la gouvernance dès le premier jour —
 * décision de P6.3, appliquée sans exception depuis.
 *
 * IDEMPOTENT : rejouable sans créer de doublon ni écraser une correction faite depuis.
 */
class MaladieSeeder extends Seeder
{
    private const SOURCE_DETAIL = 'Jeu de démonstration. Libellés repris de l\'existant du projet ; '
        .'AUCUN code CIM n\'a été chargé, et ce contenu n\'a été validé par aucune autorité sanitaire.';

    /**
     * `surveillance` : `[déclaration obligatoire, surveillance prioritaire]`, ou `null` si la
     * maladie n'est pas sous surveillance nationale — l'absence se dit, elle ne se remplit pas.
     *
     * `alias` : `[langue, libellé, principal]`.
     */
    private const CATALOGUE = [
        // ── Les sept que le contrôleur d'alerte portait EN DUR ────────────────────────────────────
        ['libelle' => 'Paludisme', 'surveillance' => [true, true],
            'description' => 'Maladie parasitaire transmise par les moustiques du genre Anopheles.',
            'alias' => [['fr', 'palu', false], ['en', 'Malaria', true]]],
        ['libelle' => 'Choléra', 'surveillance' => [true, true],
            'description' => 'Infection intestinale aiguë à Vibrio cholerae, transmission hydrique.',
            'alias' => [['en', 'Cholera', true]]],
        ['libelle' => 'Méningite', 'surveillance' => [true, true],
            'description' => 'Inflammation des méninges, d\'origine bactérienne ou virale.',
            'alias' => [['en', 'Meningitis', true]]],
        ['libelle' => 'Fièvre typhoïde', 'surveillance' => [true, true],
            'description' => 'Infection à Salmonella Typhi, transmission oro-fécale.',
            'alias' => [['fr', 'typhoïde', false], ['en', 'Typhoid fever', true]]],
        ['libelle' => 'Dengue', 'surveillance' => [true, true],
            'description' => 'Arbovirose transmise par Aedes aegypti.',
            'alias' => [['en', 'Dengue fever', true]]],
        ['libelle' => 'Fièvre jaune', 'surveillance' => [true, true],
            'description' => 'Arbovirose hémorragique, évitable par la vaccination.',
            'alias' => [['en', 'Yellow fever', true]]],
        ['libelle' => 'Rougeole', 'surveillance' => [true, true],
            'description' => 'Maladie virale éruptive très contagieuse, évitable par la vaccination.',
            'alias' => [['en', 'Measles', true]]],

        // ── Celles que `VaccinSeeder` nomme dans `maladies_evitees` (promesse de P6.8b) ───────────
        ['libelle' => 'Tuberculose', 'surveillance' => [true, true],
            'description' => 'Infection à Mycobacterium tuberculosis, principalement pulmonaire.',
            'alias' => [['en', 'Tuberculosis', true]]],
        ['libelle' => 'Poliomyélite', 'surveillance' => [true, true],
            'description' => 'Infection virale pouvant entraîner une paralysie flasque aiguë.',
            'alias' => [['en', 'Poliomyelitis', true]]],
        ['libelle' => 'Diphtérie', 'surveillance' => [true, false], 'description' => null,
            'alias' => [['en', 'Diphtheria', true]]],
        ['libelle' => 'Tétanos', 'surveillance' => [true, false], 'description' => null,
            'alias' => [['en', 'Tetanus', true]]],
        ['libelle' => 'Coqueluche', 'surveillance' => [true, false], 'description' => null,
            'alias' => [['en', 'Pertussis', true]]],
        ['libelle' => 'Hépatite B', 'surveillance' => [true, false],
            'description' => 'Infection virale du foie, transmission sanguine et périnatale.',
            'alias' => [['en', 'Hepatitis B', true]]],
        ['libelle' => 'Infections à Haemophilus influenzae type b', 'surveillance' => null,
            'description' => null, 'alias' => []],
        ['libelle' => 'Infections à pneumocoque', 'surveillance' => null,
            'description' => 'Pneumonies, méningites et otites à Streptococcus pneumoniae.',
            'alias' => []],
        ['libelle' => 'Diarrhée à rotavirus', 'surveillance' => null,
            'description' => 'Première cause de diarrhée sévère du nourrisson.', 'alias' => []],
        ['libelle' => 'Rubéole', 'surveillance' => [true, false], 'description' => null,
            'alias' => [['en', 'Rubella', true]]],

        // ── Affections chroniques — le consommateur `antecedents` (décision E1) ───────────────────
        ['libelle' => 'Diabète sucré', 'surveillance' => null,
            'description' => 'Trouble chronique de la régulation de la glycémie.',
            'alias' => [['fr', 'diabète', false], ['en', 'Diabetes mellitus', true]]],
        ['libelle' => 'Hypertension artérielle', 'surveillance' => null,
            'description' => 'Élévation chronique de la pression artérielle.',
            'alias' => [['fr', 'HTA', false], ['en', 'Hypertension', true]]],
        ['libelle' => 'Drépanocytose', 'surveillance' => null,
            'description' => 'Maladie génétique de l\'hémoglobine, fréquente en Afrique de l\'Ouest.',
            'alias' => [['fr', 'drépano', false], ['en', 'Sickle cell disease', true]]],
        ['libelle' => 'Asthme', 'surveillance' => null,
            'description' => 'Maladie inflammatoire chronique des voies aériennes.',
            'alias' => [['en', 'Asthma', true]]],
    ];

    /**
     * Le lien vaccin → maladies, par LIBELLÉ des deux côtés.
     *
     * Par libellé et non par code national : au premier passage, aucun code n'a encore été attribué
     * (c'est le rôle du backfill). Même choix d'idempotence qu'en P6.6a et P6.8b.
     */
    private const PROTECTIONS = [
        'BCG (tuberculose)'                  => ['Tuberculose'],
        'Poliomyélite orale — dose zéro'     => ['Poliomyélite'],
        'Poliomyélite orale'                 => ['Poliomyélite'],
        'Poliomyélite injectable'            => ['Poliomyélite'],
        'Pentavalent (DTC — hépatite B — Hib)' => [
            'Diphtérie', 'Tétanos', 'Coqueluche', 'Hépatite B',
            'Infections à Haemophilus influenzae type b',
        ],
        'Pneumocoque conjugué'               => ['Infections à pneumocoque'],
        'Rotavirus'                          => ['Diarrhée à rotavirus'],
        'Rougeole — Rubéole'                 => ['Rougeole', 'Rubéole'],
        'Fièvre jaune'                       => ['Fièvre jaune'],
    ];

    public function run(): void
    {
        $pays = strtoupper((string) config('referentiels.pays_defaut', 'CI'));

        foreach (self::CATALOGUE as $entree) {
            // Clé d'idempotence = le LIBELLÉ, pas le code : le code national est attribué par le
            // backfill, donc il n'existe pas au premier passage (précédents P6.6a, P6.8b).
            $maladie = Maladie::firstOrCreate(
                ['libelle' => $entree['libelle']],
                [
                    'description'   => $entree['description'],
                    'source'        => 'demonstration',
                    'source_detail' => self::SOURCE_DETAIL,
                    'actif'         => true,
                ],
            );

            foreach ($entree['alias'] as [$langue, $libelle, $principal]) {
                LibelleMaladie::firstOrCreate(
                    ['maladie_id' => $maladie->id, 'langue' => $langue, 'libelle' => $libelle],
                    [
                        'principal'     => $principal,
                        'source'        => 'demonstration',
                        'source_detail' => self::SOURCE_DETAIL,
                    ],
                );
            }

            // L'ABSENCE DE SURVEILLANCE SE DIT PAR L'ABSENCE DE LIGNE. Créer une ligne « ni
            // déclaration obligatoire ni priorité » affirmerait qu'une décision a été prise pour ce
            // pays ; ne rien écrire dit qu'on n'en sait rien (précédent P6.4a, « tout nullable »).
            if ($entree['surveillance'] !== null) {
                [$declaration, $prioritaire] = $entree['surveillance'];

                SurveillanceMaladie::firstOrCreate(
                    ['maladie_id' => $maladie->id, 'pays_code' => $pays],
                    [
                        'declaration_obligatoire'  => $declaration,
                        'surveillance_prioritaire' => $prioritaire,
                        'source'                   => 'demonstration',
                        'source_detail'            => self::SOURCE_DETAIL,
                    ],
                );
            }
        }

        $this->relierLesVaccins();
    }

    /**
     * Tient la promesse écrite dans la migration de P6.8b.
     *
     * `syncWithoutDetaching` et non `sync` : un rattachement ajouté à la main depuis le portail ne
     * doit pas disparaître au rejeu du seeder.
     */
    private function relierLesVaccins(): void
    {
        foreach (self::PROTECTIONS as $libelleVaccin => $maladies) {
            $vaccin = Vaccin::where('libelle', $libelleVaccin)->first();

            if ($vaccin === null) {
                continue;   // `VaccinSeeder` n'a pas tourné : rien à relier, et rien à inventer.
            }

            $ids = Maladie::whereIn('libelle', $maladies)->pluck('id')->all();

            $vaccin->maladies()->syncWithoutDetaching($ids);
        }
    }
}
