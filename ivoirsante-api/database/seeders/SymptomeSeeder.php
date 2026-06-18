<?php

namespace Database\Seeders;

use App\Models\Symptome;
use Illuminate\Database\Seeder;

/**
 * Référentiel de symptômes adaptés au contexte ivoirien (paludisme, fièvre typhoïde,
 * méningite, choléra, dengue…) avec poids de sévérité, indices de spécialité (§5.1.3),
 * drapeaux rouges (§5.1.2) et questions complémentaires (F1.2).
 */
class SymptomeSeeder extends Seeder
{
    public function run(): void
    {
        // Questions réutilisables.
        $qDuree = [
            'cle' => 'duree_jours', 'libelle' => 'Depuis combien de jours ?',
            'type' => 'nombre', 'unite' => 'jours',
            'impact' => ['seuil' => 3, 'points_si_superieur' => 8],
        ];
        $qIntensite = [
            'cle' => 'intensite', 'libelle' => 'Intensité de 1 à 10 ?',
            'type' => 'echelle', 'min' => 1, 'max' => 10,
            'impact' => ['coef' => 1.2],
        ];

        $symptomes = [
            [
                'nom_fr' => 'Fièvre élevée', 'categorie' => 'fievre', 'poids_severite' => 25,
                'specialite_hint' => null, 'drapeau_rouge' => false,
                'questions_complementaires_json' => [
                    $qDuree,
                    ['cle' => 'fievre_sup_40', 'libelle' => 'Température supérieure à 40°C ?',
                        'type' => 'booleen', 'impact' => ['points_si_vrai' => 15, 'drapeau_rouge_si_vrai' => true]],
                ],
                'maladies_probables_json' => ['Paludisme', 'Fièvre typhoïde'],
            ],
            [
                'nom_fr' => 'Frissons', 'categorie' => 'fievre', 'poids_severite' => 12,
                'specialite_hint' => null, 'drapeau_rouge' => false,
                'questions_complementaires_json' => [$qDuree],
                'maladies_probables_json' => ['Paludisme'],
            ],
            [
                'nom_fr' => 'Courbatures (douleurs musculaires)', 'categorie' => 'douleur', 'poids_severite' => 8,
                'specialite_hint' => null, 'drapeau_rouge' => false,
                'questions_complementaires_json' => [$qDuree, $qIntensite],
                'maladies_probables_json' => ['Paludisme', 'Grippe'],
            ],
            [
                'nom_fr' => 'Maux de tête (céphalées)', 'categorie' => 'neurologique', 'poids_severite' => 10,
                'specialite_hint' => null, 'drapeau_rouge' => false,
                'questions_complementaires_json' => [$qDuree, $qIntensite],
                'maladies_probables_json' => ['Paludisme', 'Méningite'],
            ],
            [
                'nom_fr' => 'Toux', 'categorie' => 'respiratoire', 'poids_severite' => 10,
                'specialite_hint' => null, 'drapeau_rouge' => false,
                'questions_complementaires_json' => [
                    $qDuree,
                    ['cle' => 'type_toux', 'libelle' => 'Type de toux ?', 'type' => 'choix',
                        'options' => ['seche', 'grasse'], 'impact' => ['points_par_option' => ['seche' => 3, 'grasse' => 5]]],
                ],
                'maladies_probables_json' => ['Tuberculose', 'Infection respiratoire'],
            ],
            [
                'nom_fr' => 'Difficulté respiratoire (essoufflement)', 'categorie' => 'respiratoire', 'poids_severite' => 35,
                'specialite_hint' => 'Cardiologie / Urgences', 'drapeau_rouge' => true,
                'questions_complementaires_json' => [
                    ['cle' => 'au_repos', 'libelle' => 'Gêne respiratoire même au repos ?',
                        'type' => 'booleen', 'impact' => ['points_si_vrai' => 10, 'drapeau_rouge_si_vrai' => true]],
                ],
                'maladies_probables_json' => ['Détresse respiratoire', 'Pneumonie sévère'],
            ],
            [
                'nom_fr' => 'Douleur thoracique', 'categorie' => 'cardiaque', 'poids_severite' => 40,
                'specialite_hint' => 'Cardiologie / Urgences', 'drapeau_rouge' => true,
                'questions_complementaires_json' => [$qIntensite],
                'maladies_probables_json' => ['Problème cardiaque'],
            ],
            [
                'nom_fr' => 'Diarrhée', 'categorie' => 'digestif', 'poids_severite' => 12,
                'specialite_hint' => null, 'drapeau_rouge' => false,
                'questions_complementaires_json' => [
                    $qDuree,
                    ['cle' => 'selles_eau_de_riz', 'libelle' => 'Selles liquides « eau de riz » très fréquentes ?',
                        'type' => 'booleen', 'impact' => ['points_si_vrai' => 15]],
                ],
                'maladies_probables_json' => ['Choléra', 'Gastro-entérite'],
            ],
            [
                'nom_fr' => 'Vomissements', 'categorie' => 'digestif', 'poids_severite' => 12,
                'specialite_hint' => null, 'drapeau_rouge' => false,
                'questions_complementaires_json' => [$qDuree],
                'maladies_probables_json' => ['Gastro-entérite', 'Fièvre typhoïde'],
            ],
            [
                'nom_fr' => 'Douleur abdominale', 'categorie' => 'digestif', 'poids_severite' => 15,
                'specialite_hint' => null, 'drapeau_rouge' => false,
                'questions_complementaires_json' => [$qDuree, $qIntensite],
                'maladies_probables_json' => ['Fièvre typhoïde', 'Appendicite'],
            ],
            [
                'nom_fr' => 'Perte de connaissance', 'categorie' => 'neurologique', 'poids_severite' => 45,
                'specialite_hint' => 'Urgences', 'drapeau_rouge' => true,
                'questions_complementaires_json' => null,
                'maladies_probables_json' => ['Urgence neurologique'],
            ],
            [
                'nom_fr' => 'Convulsions', 'categorie' => 'neurologique', 'poids_severite' => 50,
                'specialite_hint' => 'Urgences', 'drapeau_rouge' => true,
                'questions_complementaires_json' => null,
                'maladies_probables_json' => ['Paludisme grave (neuropaludisme)', 'Méningite'],
            ],
            [
                'nom_fr' => 'Raideur de la nuque', 'categorie' => 'neurologique', 'poids_severite' => 30,
                'specialite_hint' => 'Urgences', 'drapeau_rouge' => false,
                'questions_complementaires_json' => [
                    ['cle' => 'photophobie', 'libelle' => 'Gêne importante à la lumière ?',
                        'type' => 'booleen', 'impact' => ['points_si_vrai' => 12]],
                ],
                'maladies_probables_json' => ['Méningite'],
            ],
            [
                'nom_fr' => 'Douleur dentaire', 'categorie' => 'dentaire', 'poids_severite' => 8,
                'specialite_hint' => 'Dentisterie', 'drapeau_rouge' => false,
                'questions_complementaires_json' => [$qIntensite],
                'maladies_probables_json' => ['Carie', 'Abcès dentaire'],
            ],
            [
                'nom_fr' => 'Douleur auriculaire / perte d\'audition', 'categorie' => 'orl', 'poids_severite' => 10,
                'specialite_hint' => 'ORL (Oto-Rhino-Laryngologie)', 'drapeau_rouge' => false,
                'questions_complementaires_json' => [$qDuree, $qIntensite],
                'maladies_probables_json' => ['Otite'],
            ],
            [
                'nom_fr' => 'Douleur oculaire / baisse de vision', 'categorie' => 'ophtalmologique', 'poids_severite' => 12,
                'specialite_hint' => 'Ophtalmologie', 'drapeau_rouge' => false,
                'questions_complementaires_json' => [$qDuree],
                'maladies_probables_json' => ['Conjonctivite', 'Trouble de la vision'],
            ],
            [
                'nom_fr' => 'Éruption cutanée', 'categorie' => 'dermatologique', 'poids_severite' => 8,
                'specialite_hint' => null, 'drapeau_rouge' => false,
                'questions_complementaires_json' => [$qDuree],
                'maladies_probables_json' => ['Dengue', 'Rougeole', 'Allergie'],
            ],
            [
                'nom_fr' => 'Saignement abondant (hémorragie)', 'categorie' => 'general', 'poids_severite' => 45,
                'specialite_hint' => 'Urgences / Traumatologie', 'drapeau_rouge' => true,
                'questions_complementaires_json' => null,
                'maladies_probables_json' => ['Hémorragie'],
            ],
            [
                'nom_fr' => 'Douleurs pelviennes (femme)', 'categorie' => 'gynecologique', 'poids_severite' => 15,
                'specialite_hint' => 'Gynécologie / Maternité', 'drapeau_rouge' => false,
                'questions_complementaires_json' => [$qDuree, $qIntensite],
                'maladies_probables_json' => ['Infection gynécologique', 'Grossesse'],
            ],
            [
                'nom_fr' => 'Traumatisme / fracture possible', 'categorie' => 'traumatologique', 'poids_severite' => 25,
                'specialite_hint' => 'Urgences / Traumatologie', 'drapeau_rouge' => false,
                'questions_complementaires_json' => [
                    ['cle' => 'deformation_visible', 'libelle' => 'Déformation visible ou impossibilité de bouger ?',
                        'type' => 'booleen', 'impact' => ['points_si_vrai' => 20, 'drapeau_rouge_si_vrai' => true]],
                ],
                'maladies_probables_json' => ['Fracture', 'Entorse'],
            ],
        ];

        foreach ($symptomes as $data) {
            // updateOrCreate (idempotent) : re-seeder ne crée pas de doublons.
            Symptome::updateOrCreate(
                ['nom_fr' => $data['nom_fr']],
                array_merge($data, ['actif' => true])
            );
        }
    }
}
