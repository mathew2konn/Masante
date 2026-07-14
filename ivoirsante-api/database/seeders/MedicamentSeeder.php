<?php

namespace Database\Seeders;

use App\Models\Medicament;
use Illuminate\Database\Seeder;

/**
 * Module 5 / 5.8 — Catalogue initial des médicaments (CdC FN7 : « base initiale : prix officiels
 * CENAME + DPM »).
 *
 * Sélection de médicaments de la liste des ESSENTIELS en Côte d'Ivoire (paludisme, infections
 * courantes, chroniques, mère-enfant), avec un prix de référence indicatif en FCFA. **Ces prix sont
 * des ordres de grandeur** destinés au prototype : en production, le catalogue serait alimenté par
 * un import CENAME/DPM. C'est précisément parce qu'il vit en base qu'il se corrige sans redéployer.
 *
 * Les couples DCI/marque sont volontaires : c'est ce qui permet à FN7 de suggérer le générique
 * moins cher (même `nom_generique`, `prix_reference_cfa` inférieur).
 *
 * Idempotent (`updateOrCreate` sur le couple DCI + nom commercial).
 */
class MedicamentSeeder extends Seeder
{
    public function run(): void
    {
        $medicaments = [
            // --- Antalgiques / antipyrétiques : la DCI la plus achetée du pays, avec sa marque.
            ['nom_generique' => 'Paracétamol 500 mg', 'nom_commercial' => null, 'categorie' => 'Antalgique',
                'prix_reference_cfa' => 300, 'ordonnance_requise' => false, 'disponible_generique' => false, 'cename_reference' => 'CEN-PARA-500'],
            ['nom_generique' => 'Paracétamol 500 mg', 'nom_commercial' => 'Doliprane', 'categorie' => 'Antalgique',
                'prix_reference_cfa' => 1200, 'ordonnance_requise' => false, 'disponible_generique' => true, 'cename_reference' => null],
            ['nom_generique' => 'Ibuprofène 400 mg', 'nom_commercial' => null, 'categorie' => 'Anti-inflammatoire',
                'prix_reference_cfa' => 600, 'ordonnance_requise' => false, 'disponible_generique' => false, 'cename_reference' => 'CEN-IBU-400'],

            // --- Paludisme (première cause de consultation en CI).
            ['nom_generique' => 'Artéméther-Luméfantrine 20/120 mg', 'nom_commercial' => null, 'categorie' => 'Antipaludique',
                'prix_reference_cfa' => 1500, 'ordonnance_requise' => true, 'disponible_generique' => false, 'cename_reference' => 'CEN-ACT-20120'],
            ['nom_generique' => 'Artéméther-Luméfantrine 20/120 mg', 'nom_commercial' => 'Coartem', 'categorie' => 'Antipaludique',
                'prix_reference_cfa' => 3500, 'ordonnance_requise' => true, 'disponible_generique' => true, 'cename_reference' => null],
            ['nom_generique' => 'Sulfadoxine-Pyriméthamine 500/25 mg', 'nom_commercial' => null, 'categorie' => 'Antipaludique',
                'prix_reference_cfa' => 500, 'ordonnance_requise' => true, 'disponible_generique' => false, 'cename_reference' => 'CEN-SP-50025'],

            // --- Antibiotiques.
            ['nom_generique' => 'Amoxicilline 500 mg', 'nom_commercial' => null, 'categorie' => 'Antibiotique',
                'prix_reference_cfa' => 1200, 'ordonnance_requise' => true, 'disponible_generique' => false, 'cename_reference' => 'CEN-AMOX-500'],
            ['nom_generique' => 'Amoxicilline 500 mg', 'nom_commercial' => 'Clamoxyl', 'categorie' => 'Antibiotique',
                'prix_reference_cfa' => 3000, 'ordonnance_requise' => true, 'disponible_generique' => true, 'cename_reference' => null],
            ['nom_generique' => 'Métronidazole 500 mg', 'nom_commercial' => null, 'categorie' => 'Antibiotique',
                'prix_reference_cfa' => 800, 'ordonnance_requise' => true, 'disponible_generique' => false, 'cename_reference' => 'CEN-METRO-500'],

            // --- Maladies chroniques (cohérent avec le journal de bord FN5).
            ['nom_generique' => 'Metformine 500 mg', 'nom_commercial' => null, 'categorie' => 'Antidiabétique',
                'prix_reference_cfa' => 1000, 'ordonnance_requise' => true, 'disponible_generique' => false, 'cename_reference' => 'CEN-METF-500'],
            ['nom_generique' => 'Amlodipine 5 mg', 'nom_commercial' => null, 'categorie' => 'Antihypertenseur',
                'prix_reference_cfa' => 1500, 'ordonnance_requise' => true, 'disponible_generique' => false, 'cename_reference' => 'CEN-AMLO-5'],
            ['nom_generique' => 'Salbutamol spray 100 µg', 'nom_commercial' => 'Ventoline', 'categorie' => 'Antiasthmatique',
                'prix_reference_cfa' => 2500, 'ordonnance_requise' => true, 'disponible_generique' => false, 'cename_reference' => null],

            // --- Mère-enfant (cohérent avec le suivi de grossesse FN4).
            ['nom_generique' => 'Fer + Acide folique', 'nom_commercial' => null, 'categorie' => 'Supplément',
                'prix_reference_cfa' => 400, 'ordonnance_requise' => false, 'disponible_generique' => false, 'cename_reference' => 'CEN-FER-AF'],
            ['nom_generique' => 'Sels de réhydratation orale (SRO)', 'nom_commercial' => null, 'categorie' => 'Réhydratation',
                'prix_reference_cfa' => 200, 'ordonnance_requise' => false, 'disponible_generique' => false, 'cename_reference' => 'CEN-SRO'],
            ['nom_generique' => 'Zinc 20 mg', 'nom_commercial' => null, 'categorie' => 'Supplément',
                'prix_reference_cfa' => 300, 'ordonnance_requise' => false, 'disponible_generique' => false, 'cename_reference' => 'CEN-ZN-20'],

            // --- Divers courants.
            ['nom_generique' => 'Oméprazole 20 mg', 'nom_commercial' => null, 'categorie' => 'Antiulcéreux',
                'prix_reference_cfa' => 1500, 'ordonnance_requise' => true, 'disponible_generique' => false, 'cename_reference' => 'CEN-OME-20'],
            ['nom_generique' => 'Cétirizine 10 mg', 'nom_commercial' => null, 'categorie' => 'Antihistaminique',
                'prix_reference_cfa' => 700, 'ordonnance_requise' => false, 'disponible_generique' => false, 'cename_reference' => 'CEN-CET-10'],
            ['nom_generique' => 'Albendazole 400 mg', 'nom_commercial' => null, 'categorie' => 'Antiparasitaire',
                'prix_reference_cfa' => 400, 'ordonnance_requise' => false, 'disponible_generique' => false, 'cename_reference' => 'CEN-ALB-400'],
        ];

        foreach ($medicaments as $medicament) {
            Medicament::updateOrCreate(
                [
                    'nom_generique'  => $medicament['nom_generique'],
                    'nom_commercial' => $medicament['nom_commercial'],
                ],
                $medicament,
            );
        }
    }
}
