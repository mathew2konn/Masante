<?php

namespace Database\Seeders;

use App\Models\EcheanceVaccinale;
use App\Models\Vaccin;
use Illuminate\Database\Seeder;

/**
 * P6.8b — Jeu de DÉMONSTRATION du référentiel des vaccins et du calendrier vaccinal (CDC_09 §8).
 *
 * ═══ CE QUE CE JEU EST, ET CE QU'IL N'EST PAS ═══
 *
 * **Je n'ai vu ni l'arrêté du Programme Élargi de Vaccination ivoirien, ni le calendrier officiel
 * publié par le Ministère de la Santé.** Ce qui suit reprend la structure du calendrier élargi de
 * vaccination de l'OMS, largement standard en Afrique de l'Ouest — mais l'inscrire comme
 * « calendrier national ivoirien » sans l'avoir vu serait précisément *la liste inventée qui a l'air
 * juste, et qui pour cette raison ne se fait jamais corriger* (raisonnement de P6.4a sur les
 * 33 régions sanitaires).
 *
 * Donc, motif P6.7a repris à l'identique : **chaque échéance porte `source = 'demonstration'`**, le
 * contrôle qualité refuse de publier une échéance sans provenance, et l'écran affiche le compte
 * exact des échéances encore issues de la démonstration. Charger le calendrier officiel sera de la
 * **donnée, zéro code** — et tant que ce n'est pas fait, ce n'est pas un calendrier national.
 *
 * ═══ CE SEEDER NE PUBLIE RIEN ═══
 *
 * Il alimente les tables ; la mise en vigueur passe par le cycle §10 (proposition, quatre-yeux,
 * publication). Publier depuis un seeder contournerait la gouvernance dès le premier jour —
 * décision de P6.3, appliquée sans exception depuis.
 *
 * IDEMPOTENT : rejouable sans créer de doublon ni écraser une correction faite depuis.
 */
class VaccinSeeder extends Seeder
{
    /**
     * Les âges sont EN JOURS. 0 = à la naissance, 42 = six semaines, 270 = neuf mois. Un calendrier
     * exprimé en mois ne saurait pas dire « six semaines », qui est l'échéance la plus dense.
     *
     * `tolerance_jours` = le délai de grâce avant qu'une dose ne soit dite « en retard ».
     * `age_jours_limite` = la borne de rattrapage ; `null` = pas de borne.
     */
    private const CATALOGUE = [
        [
            'libelle'             => 'BCG (tuberculose)',
            'abreviation'         => 'BCG',
            'maladies_evitees'    => 'Formes graves de la tuberculose (méningite tuberculeuse, '
                .'tuberculose miliaire) chez le nourrisson.',
            'voie_administration' => 'intradermique',
            'echeances'           => [
                ['dose' => 1, 'age' => 0, 'tolerance' => 30, 'limite' => 365, 'obligatoire' => true,
                    'libelle' => 'À la naissance'],
            ],
        ],
        [
            'libelle'             => 'Poliomyélite orale — dose zéro',
            'abreviation'         => 'VPO-0',
            'maladies_evitees'    => 'Poliomyélite.',
            'voie_administration' => 'orale',
            'echeances'           => [
                ['dose' => 1, 'age' => 0, 'tolerance' => 14, 'limite' => 90, 'obligatoire' => true,
                    'libelle' => 'À la naissance'],
            ],
        ],
        [
            'libelle'             => 'Pentavalent (DTC — hépatite B — Hib)',
            'abreviation'         => 'Penta',
            'maladies_evitees'    => 'Diphtérie, tétanos, coqueluche, hépatite B, infections à '
                .'Haemophilus influenzae de type b.',
            'voie_administration' => 'intramusculaire',
            'echeances'           => [
                ['dose' => 1, 'age' => 42, 'tolerance' => 14, 'limite' => 730, 'obligatoire' => true,
                    'libelle' => '6 semaines'],
                ['dose' => 2, 'age' => 70, 'tolerance' => 14, 'limite' => 730, 'obligatoire' => true,
                    'libelle' => '10 semaines'],
                ['dose' => 3, 'age' => 98, 'tolerance' => 14, 'limite' => 730, 'obligatoire' => true,
                    'libelle' => '14 semaines'],
            ],
        ],
        [
            'libelle'             => 'Poliomyélite orale',
            'abreviation'         => 'VPO',
            'maladies_evitees'    => 'Poliomyélite.',
            'voie_administration' => 'orale',
            'echeances'           => [
                ['dose' => 1, 'age' => 42, 'tolerance' => 14, 'limite' => 730, 'obligatoire' => true,
                    'libelle' => '6 semaines'],
                ['dose' => 2, 'age' => 70, 'tolerance' => 14, 'limite' => 730, 'obligatoire' => true,
                    'libelle' => '10 semaines'],
                ['dose' => 3, 'age' => 98, 'tolerance' => 14, 'limite' => 730, 'obligatoire' => true,
                    'libelle' => '14 semaines'],
            ],
        ],
        [
            'libelle'             => 'Poliomyélite injectable',
            'abreviation'         => 'VPI',
            'maladies_evitees'    => 'Poliomyélite.',
            'voie_administration' => 'intramusculaire',
            'echeances'           => [
                ['dose' => 1, 'age' => 98, 'tolerance' => 14, 'limite' => 730, 'obligatoire' => true,
                    'libelle' => '14 semaines'],
            ],
        ],
        [
            'libelle'             => 'Pneumocoque conjugué',
            'abreviation'         => 'PCV',
            'maladies_evitees'    => 'Pneumonies, méningites et otites à pneumocoque.',
            'voie_administration' => 'intramusculaire',
            'echeances'           => [
                ['dose' => 1, 'age' => 42, 'tolerance' => 14, 'limite' => 730, 'obligatoire' => true,
                    'libelle' => '6 semaines'],
                ['dose' => 2, 'age' => 70, 'tolerance' => 14, 'limite' => 730, 'obligatoire' => true,
                    'libelle' => '10 semaines'],
                ['dose' => 3, 'age' => 98, 'tolerance' => 14, 'limite' => 730, 'obligatoire' => true,
                    'libelle' => '14 semaines'],
            ],
        ],
        [
            'libelle'             => 'Rotavirus',
            'abreviation'         => 'Rota',
            'maladies_evitees'    => 'Diarrhées sévères à rotavirus du nourrisson.',
            'voie_administration' => 'orale',
            'echeances'           => [
                // Borne de rattrapage COURTE, et c'est le cas qui justifie la colonne : ce vaccin
                // n'est pas administré au-delà d'un certain âge. Sans borne, l'écran proposerait à
                // un enfant de trois ans une dose que le calendrier ne prévoit plus.
                ['dose' => 1, 'age' => 42, 'tolerance' => 14, 'limite' => 105, 'obligatoire' => false,
                    'libelle' => '6 semaines'],
                ['dose' => 2, 'age' => 70, 'tolerance' => 14, 'limite' => 224, 'obligatoire' => false,
                    'libelle' => '10 semaines'],
            ],
        ],
        [
            'libelle'             => 'Rougeole — Rubéole',
            'abreviation'         => 'RR',
            'maladies_evitees'    => 'Rougeole, rubéole.',
            'voie_administration' => 'sous_cutanee',
            'echeances'           => [
                ['dose' => 1, 'age' => 270, 'tolerance' => 30, 'limite' => null, 'obligatoire' => true,
                    'libelle' => '9 mois'],
                ['dose' => 2, 'age' => 450, 'tolerance' => 30, 'limite' => null, 'obligatoire' => true,
                    'libelle' => '15 mois'],
            ],
        ],
        [
            'libelle'             => 'Fièvre jaune',
            'abreviation'         => 'VAA',
            'maladies_evitees'    => 'Fièvre jaune.',
            'voie_administration' => 'sous_cutanee',
            'echeances'           => [
                ['dose' => 1, 'age' => 270, 'tolerance' => 30, 'limite' => null, 'obligatoire' => true,
                    'libelle' => '9 mois'],
            ],
        ],
    ];

    /**
     * La provenance, écrite sur CHAQUE échéance. Elle n'est pas décorative : c'est elle que le
     * contrôle qualité exige, et elle que l'écran compte pour dire au parent ce qu'il regarde.
     */
    private const SOURCE_DETAIL = 'Jeu de démonstration reprenant la structure du calendrier élargi '
        .'de vaccination (OMS). NON vérifié contre le calendrier officiel du PEV Côte d\'Ivoire, '
        .'et non validé par une autorité sanitaire.';

    public function run(): void
    {
        foreach (self::CATALOGUE as $entree) {
            // Clé d'idempotence = le LIBELLÉ, pas le code : le code national est attribué par le
            // backfill, donc il n'existe pas au premier passage. Même choix qu'en P6.6a.
            $vaccin = Vaccin::firstOrCreate(
                ['libelle' => $entree['libelle']],
                [
                    'abreviation'         => $entree['abreviation'],
                    'maladies_evitees'    => $entree['maladies_evitees'],
                    'voie_administration' => $entree['voie_administration'],
                    'nb_doses'            => count($entree['echeances']),
                    'statut_marche'       => 'disponible',
                    'actif'               => true,
                ],
            );

            foreach ($entree['echeances'] as $e) {
                EcheanceVaccinale::firstOrCreate(
                    ['vaccin_id' => $vaccin->id, 'numero_dose' => $e['dose']],
                    [
                        'age_jours_du'     => $e['age'],
                        'tolerance_jours'  => $e['tolerance'],
                        'age_jours_limite' => $e['limite'],
                        'obligatoire'      => $e['obligatoire'],
                        'libelle_echeance' => $e['libelle'],
                        'source'           => 'demonstration',
                        'source_detail'    => self::SOURCE_DETAIL,
                    ],
                );
            }
        }
    }
}
