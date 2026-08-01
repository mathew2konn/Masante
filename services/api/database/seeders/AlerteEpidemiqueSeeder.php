<?php

namespace Database\Seeders;

use App\Models\AlerteEpidemique;
use Illuminate\Database\Seeder;

/**
 * Alertes épidémiques de démonstration (CdC FN3) — données réalistes pour la Côte d'Ivoire.
 *
 * DEV / soutenance uniquement : en production, l'admin les saisit depuis le portail d'après les
 * bulletins OMS / Ministère. Idempotent (updateOrCreate sur le titre).
 */
class AlerteEpidemiqueSeeder extends Seeder
{
    public function run(): void
    {
        $alertes = [
            [
                'commune' => 'Cocody', 'maladie' => 'Paludisme', 'niveau_alerte' => 'alerte',
                'titre' => 'Recrudescence du paludisme à Cocody',
                'description' => 'Forte hausse des cas de paludisme. Dormez sous moustiquaire imprégnée, éliminez les eaux stagnantes. En cas de fièvre, consultez sans délai.',
                'source' => 'Ministère de la Santé CI', 'date_debut' => now()->subDays(3)->toDateString(),
            ],
            [
                'commune' => AlerteEpidemique::NATIONALE, 'maladie' => 'Choléra', 'niveau_alerte' => 'vigilance',
                'titre' => 'Vigilance choléra — saison des pluies',
                'description' => 'Lavez-vous les mains, buvez de l\'eau traitée ou en bouteille, lavez fruits et légumes. Diarrhée aiguë : rendez-vous au centre de santé le plus proche.',
                'source' => 'OMS', 'date_debut' => now()->subWeek()->toDateString(),
            ],
            [
                'commune' => 'Yopougon', 'maladie' => 'Dengue', 'niveau_alerte' => 'information',
                'titre' => 'Cas de dengue signalés à Yopougon',
                'description' => 'Quelques cas de dengue confirmés. Protégez-vous des piqûres de moustiques en journée. Détruisez les gîtes larvaires (soucoupes, pneus, récipients).',
                'source' => 'MSCI', 'date_debut' => now()->subDays(5)->toDateString(),
                'date_fin' => now()->addWeeks(2)->toDateString(),
            ],
        ];

        foreach ($alertes as $a) {
            AlerteEpidemique::updateOrCreate(['titre' => $a['titre']], [...$a, 'actif' => true]);
        }
    }
}
