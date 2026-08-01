<?php

namespace Database\Seeders;

use App\Models\ReferentielMesure;
use Illuminate\Database\Seeder;

/**
 * Module 5 / 5.6 — Seuils des mesures du journal de bord (FN5).
 *
 * Valeurs de référence ADULTE (OMS / PNLMNT Côte d'Ivoire), à faire relire par un professionnel de
 * santé avant soutenance — c'est le rôle du référentiel en base que de pouvoir être corrigé sans
 * toucher au code (F1.3). Le nourrisson et l'enfant ont d'autres normes : hors périmètre du
 * prototype, et c'est dit dans les conseils.
 *
 * La glycémie est exprimée en g/L, unité usuelle des glucomètres vendus en Côte d'Ivoire
 * (1 g/L = 5,55 mmol/L). Idempotent (`updateOrCreate` sur `type_mesure`).
 */
class ReferentielMesureSeeder extends Seeder
{
    public function run(): void
    {
        $seuils = [
            [
                'type_mesure'   => 'glycemie',
                'libelle'       => 'Glycémie (à jeun)',
                'unite'         => 'g/L',
                'valeur_min'    => 0.10,   // en deçà : faute de frappe, pas un patient vivant
                'valeur_max'    => 8.00,
                'normal_min'    => 0.70,
                'normal_max'    => 1.10,
                'critique_bas'  => 0.50,   // hypoglycémie sévère
                'critique_haut' => 2.50,   // hyperglycémie majeure
                'decimales'     => 2,
                'ordre'         => 1,
                'conseil_anormal' => "Une glycémie hors norme se confirme par une seconde mesure, à jeun, "
                    ."appareil bien calibré. En cas de malaise, sueurs, confusion ou soif intense avec urines "
                    ."abondantes, consultez sans attendre. Hypoglycémie sévère : resucrer immédiatement "
                    ."(sucre, jus) et appeler le SAMU (185).",
            ],
            [
                'type_mesure'   => 'tension_systolique',
                'libelle'       => 'Tension — systolique',
                'unite'         => 'mmHg',
                'valeur_min'    => 50,
                'valeur_max'    => 300,
                'normal_min'    => 90,
                'normal_max'    => 139,
                'critique_bas'  => 70,
                'critique_haut' => 180,    // poussée hypertensive
                'decimales'     => 0,
                'ordre'         => 2,
                'conseil_anormal' => "Mesurez la tension au repos, assis depuis 5 minutes, bras posé à hauteur "
                    ."du cœur. Une tension élevée à plusieurs mesures doit être vue par un médecin. Au-delà de "
                    ."180/120, surtout avec maux de tête violents, troubles de la vue ou douleur thoracique : "
                    ."urgence, appelez le SAMU (185).",
            ],
            [
                'type_mesure'   => 'tension_diastolique',
                'libelle'       => 'Tension — diastolique',
                'unite'         => 'mmHg',
                'valeur_min'    => 20,
                'valeur_max'    => 200,
                'normal_min'    => 60,
                'normal_max'    => 89,
                'critique_bas'  => 40,
                'critique_haut' => 120,
                'decimales'     => 0,
                'ordre'         => 3,
                'conseil_anormal' => "La diastolique s'interprète avec la systolique : c'est le couple qui compte. "
                    ."Répétez la mesure après 5 minutes de repos. Au-delà de 120, avec des symptômes, considérez "
                    ."l'urgence (SAMU 185).",
            ],
            [
                'type_mesure'   => 'poids',
                'libelle'       => 'Poids',
                'unite'         => 'kg',
                'valeur_min'    => 0.5,
                'valeur_max'    => 300,
                'normal_min'    => 2,      // pas de « norme » de poids : ces bornes n'alertent pas
                'normal_max'    => 300,
                'critique_bas'  => null,
                'critique_haut' => null,
                'decimales'     => 1,
                'ordre'         => 4,
                'conseil_anormal' => "Le poids n'a pas de norme universelle : c'est son ÉVOLUTION qui parle. "
                    ."Une perte ou une prise rapide et involontaire mérite un avis médical.",
            ],
            [
                'type_mesure'   => 'temperature',
                'libelle'       => 'Température',
                'unite'         => '°C',
                'valeur_min'    => 30,
                'valeur_max'    => 45,
                'normal_min'    => 36.0,
                'normal_max'    => 37.5,
                'critique_bas'  => 35.0,   // hypothermie
                'critique_haut' => 39.5,   // fièvre élevée
                'decimales'     => 1,
                'ordre'         => 5,
                'conseil_anormal' => "En zone de paludisme, toute fièvre doit faire pratiquer un test rapide (TDR) "
                    ."sans délai — surtout chez l'enfant et la femme enceinte. Fièvre à 39,5 °C et plus, ou fièvre "
                    ."avec raideur de la nuque, convulsions ou somnolence : urgence (SAMU 185).",
            ],
            [
                'type_mesure'   => 'pouls',
                'libelle'       => 'Pouls',
                'unite'         => 'bpm',
                'valeur_min'    => 20,
                'valeur_max'    => 250,
                'normal_min'    => 60,
                'normal_max'    => 100,
                'critique_bas'  => 40,
                'critique_haut' => 130,
                'decimales'     => 0,
                'ordre'         => 6,
                'conseil_anormal' => "Mesurez le pouls au repos : l'effort, la fièvre et le stress l'accélèrent "
                    ."normalement. Un pouls très lent ou très rapide au repos, avec malaise, essoufflement ou "
                    ."douleur dans la poitrine, impose un avis immédiat (SAMU 185).",
            ],
            [
                'type_mesure'   => 'saturation_o2',
                'libelle'       => 'Saturation en oxygène',
                'unite'         => '%',
                'valeur_min'    => 50,
                'valeur_max'    => 100,
                'normal_min'    => 95,
                'normal_max'    => 100,
                'critique_bas'  => 90,     // détresse respiratoire
                'critique_haut' => null,   // une saturation ne peut pas être « critiquement haute »
                'decimales'     => 0,
                'ordre'         => 7,
                'conseil_anormal' => "Vérifiez que le doigt est chaud, propre et sans vernis : un doigt froid fausse "
                    ."la mesure. Une saturation sous 95 % avec essoufflement doit être vue rapidement ; sous 90 %, "
                    ."c'est une urgence : appelez le SAMU (185).",
            ],
        ];

        foreach ($seuils as $seuil) {
            ReferentielMesure::updateOrCreate(['type_mesure' => $seuil['type_mesure']], $seuil);
        }
    }
}
