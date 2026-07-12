<?php

namespace Database\Seeders;

use App\Models\EtapePrenatale;
use Illuminate\Database\Seeder;

/**
 * Module 5 / 5.5 — Les 8 contacts prénatals du modèle OMS 2016, repris par le PNS ivoirien (FN4).
 *
 * Contenu médical de référence : calendrier (SA recommandée), objet de chaque contact et
 * conseils nutrition/hygiène adaptés à la Côte d'Ivoire (aliments locaux, TPI paludisme,
 * moustiquaire imprégnée). Idempotent (`updateOrCreate` sur `numero`) : re-seeder met à
 * jour les textes sans dupliquer. À faire relire par un professionnel de santé (mémoire).
 */
class EtapePrenataleSeeder extends Seeder
{
    public function run(): void
    {
        $etapes = [
            [
                'numero' => 1,
                'semaine_recommandee' => 12,
                'libelle' => 'Premier contact — confirmation de la grossesse',
                'description' => "Confirmation de la grossesse et ouverture du carnet de santé mère-enfant. "
                    ."Bilan initial : poids, tension, groupe sanguin, dépistages (VIH, syphilis, hépatite B), "
                    ."recherche d'anémie. Mise en route du fer + acide folique. Vaccination antitétanique (VAT) "
                    ."selon le statut. Remise d'une moustiquaire imprégnée.",
                'conseils_nutrition' => "Trois repas par jour sans sauter le petit-déjeuner. Aliments riches en fer "
                    ."et en folates : feuilles vertes (kplala, épinards, feuilles de patate), haricots, poisson. "
                    ."Boire une eau sûre (bouillie ou en sachet contrôlé). Éviter alcool, tabac et automédication.",
            ],
            [
                'numero' => 2,
                'semaine_recommandee' => 20,
                'libelle' => 'Deuxième contact — première échographie',
                'description' => "Échographie recommandée avant 24 SA pour dater la grossesse et dépister une "
                    ."grossesse multiple. Début du traitement préventif intermittent du paludisme (TPI à la "
                    ."sulfadoxine-pyriméthamine), poursuivi à chaque contact jusqu'à l'accouchement. Surveillance "
                    ."du poids et de la tension.",
                'conseils_nutrition' => "Poisson (thon, maquereau, appolo) ou œufs régulièrement pour les protéines. "
                    ."Fruits locaux chaque jour (orange, papaye, banane). Dormir TOUTES les nuits sous la "
                    ."moustiquaire imprégnée : le paludisme est la première cause d'anémie de la femme enceinte.",
            ],
            [
                'numero' => 3,
                'semaine_recommandee' => 26,
                'libelle' => 'Troisième contact — surveillance renforcée',
                'description' => "Mesure de la hauteur utérine, écoute des bruits du cœur fœtal, recherche d'œdèmes "
                    ."et de protéines dans les urines (pré-éclampsie). Dose de TPI. Vérification de la prise "
                    ."correcte du fer + acide folique et de la tolérance.",
                'conseils_nutrition' => "Continuer fer et acide folique même en l'absence de symptômes. Limiter le "
                    ."sel (les cubes assaisonnement en contiennent beaucoup) pour protéger la tension. Consulter "
                    ."SANS ATTENDRE en cas de saignement, fièvre, maux de tête violents ou vision floue.",
            ],
            [
                'numero' => 4,
                'semaine_recommandee' => 30,
                'libelle' => 'Quatrième contact — bilan du 3e trimestre',
                'description' => "Contrôle de l'anémie (pâleur, essoufflement), du poids et de la tension. Dose de "
                    ."TPI. Vérification de la position du bébé. Premiers conseils de préparation à l'accouchement : "
                    ."choix de la maternité, transport, personne à prévenir.",
                'conseils_nutrition' => "Fractionner les repas (5 petites prises) si l'estomac est comprimé. Sources "
                    ."de calcium : lait caillé, petits poissons entiers (avec arêtes). Surveiller les mouvements du "
                    ."bébé : s'il ne bouge plus, se rendre immédiatement à la maternité.",
            ],
            [
                'numero' => 5,
                'semaine_recommandee' => 34,
                'libelle' => 'Cinquième contact — préparation à l\'accouchement',
                'description' => "Vérification de la présentation du bébé et dépistage des grossesses à risque "
                    ."(bassin, cicatrice de césarienne, jumeaux) à orienter vers une structure SONU. Dose de TPI. "
                    ."Plan d'accouchement finalisé avec la famille.",
                'conseils_nutrition' => "Préparer le nécessaire d'accouchement (pagnes propres, carnet, argent "
                    ."transport). Repérer la structure d'urgence la plus proche et le moyen d'y aller de nuit. "
                    ."Poursuivre fer, TPI et moustiquaire jusqu'au bout.",
            ],
            [
                'numero' => 6,
                'semaine_recommandee' => 36,
                'libelle' => 'Sixième contact — vigilance fin de grossesse',
                'description' => "Surveillance rapprochée : tension, œdèmes, mouvements fœtaux, position du bébé. "
                    ."Reconnaissance des signes du vrai travail (contractions régulières, perte des eaux) et des "
                    ."signes de danger imposant une consultation immédiate.",
                'conseils_nutrition' => "Rester bien hydratée (eau sûre). Éviter les longs trajets loin d'une "
                    ."maternité. Aller à la maternité dès la perte des eaux, même sans contractions.",
            ],
            [
                'numero' => 7,
                'semaine_recommandee' => 38,
                'libelle' => 'Septième contact — à terme',
                'description' => "Contrôle complet à l'approche du terme : présentation, bruits du cœur, tension. "
                    ."Rappel du plan d'accouchement et des signes de danger. Conseils d'allaitement : mise au sein "
                    ."dans l'heure qui suit la naissance, colostrum à ne pas jeter.",
                'conseils_nutrition' => "Repas légers et réguliers. Le colostrum (premier lait jaune) protège le "
                    ."nouveau-né : il doit être donné, pas jeté. Prévoir l'allaitement exclusif jusqu'à 6 mois.",
            ],
            [
                'numero' => 8,
                'semaine_recommandee' => 40,
                'libelle' => 'Huitième contact — terme atteint',
                'description' => "Contact du terme : si l'accouchement n'a pas eu lieu, évaluation pour éviter le "
                    ."terme dépassé (au-delà de 41-42 SA, déclenchement à discuter en structure). Vérification "
                    ."ultime du plan de transport vers la maternité.",
                'conseils_nutrition' => "Ne pas s'éloigner de la maternité choisie. Consulter immédiatement si les "
                    ."mouvements du bébé diminuent, en cas de fièvre ou de saignement — appeler le SAMU (185) si "
                    ."besoin d'une évacuation urgente.",
            ],
        ];

        foreach ($etapes as $etape) {
            EtapePrenatale::updateOrCreate(['numero' => $etape['numero']], $etape);
        }
    }
}
