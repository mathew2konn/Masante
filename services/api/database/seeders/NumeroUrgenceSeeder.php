<?php

namespace Database\Seeders;

use App\Models\NumeroUrgence;
use Illuminate\Database\Seeder;

/**
 * P6.8e — Les numéros d'urgence nationaux (CDC_09 §8).
 *
 * ═══ CE JEU EST DÉCLARÉ, PAS VÉRIFIÉ — ET C'EST ÉCRIT DANS LA DONNÉE ═══
 *
 * Le SAMU **185** est le seul numéro que le corpus nomme (dix occurrences, dont CDC_00 §4 qui
 * l'oppose explicitement au « 15 » français). Les deux autres — **police 100** et **pompiers 180** —
 * ont été **déclarés par le propriétaire le 2026-08-15**, et **aucun des trois n'a été confronté à
 * un arrêté ou à une publication officielle**.
 *
 * C'est pourquoi ils portent `source = declaration_projet` et non `autorite_nationale` : la
 * provenance dit exactement ce qui s'est passé, *quelqu'un d'identifié les a déclarés, et personne
 * ne les a vérifiés*. L'écran du portail le compte et l'affiche.
 *
 * **Un numéro d'urgence faux est plus dangereux qu'un numéro absent, parce qu'il sera composé** :
 * c'est la raison pour laquelle je n'en ai ajouté aucun de ma propre initiative — ni antipoison, ni
 * numéro européen, ni renseignement — et la raison pour laquelle la provenance de ceux-ci est dite
 * plutôt que présumée.
 *
 * ═══ IDEMPOTENT, ET IL NE PUBLIE RIEN ═══
 *
 * Rejouer ne duplique rien et ne met aucune version en vigueur. La première publication passe par le
 * cycle §10 — publier depuis un seeder contournerait le quatre-yeux dès le premier jour (précédent
 * `ReferentielRegistreSeeder`, tenu par tous les référentiels depuis P6.3).
 */
class NumeroUrgenceSeeder extends Seeder
{
    /**
     * code => [numéro, libellé, description, ordre].
     *
     * L'ORDRE N'EST PAS DÉCORATIF : `samu` est en tête parce que ceci est une application de santé,
     * et que l'écran SOS met en avant le secours médical. Le retirer ferait remonter la police au
     * premier bouton d'un écran conçu pour un malaise.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: int}>
     */
    private const NUMEROS = [
        'samu' => [
            '185',
            'SAMU',
            'Service d\'aide médicale urgente — malaise, accident, détresse vitale.',
            10,
        ],
        'pompiers' => [
            '180',
            'Sapeurs-pompiers',
            'Incendie, accident, secours aux personnes et désincarcération.',
            20,
        ],
        'police' => [
            '100',
            'Police secours',
            'Agression, violence, danger immédiat lié à une personne.',
            30,
        ],
    ];

    public function run(): void
    {
        $pays = config('referentiels.pays_defaut', 'CI');

        foreach (self::NUMEROS as $code => [$numero, $libelle, $description, $ordre]) {
            // `updateOrCreate` sur la clé métier : rejouer met à jour un numéro corrigé sans jamais
            // créer de doublon, et sans faire bouger l'`id`.
            NumeroUrgence::updateOrCreate(
                ['pays_code' => $pays, 'code' => $code],
                [
                    'numero'      => $numero,
                    'libelle'     => $libelle,
                    'description' => $description,
                    'ordre'       => $ordre,
                    'actif'       => true,
                    // Voir l'en-tête : ni « autorité nationale », ni « démonstration ». Ni l'un ni
                    // l'autre ne serait vrai.
                    'source'        => 'declaration_projet',
                    'source_detail' => $code === 'samu'
                        ? 'Corpus du projet (CDC_00 §4, CDC_01, CDC_02 §37) — non confronté à un arrêté.'
                        : 'Déclaré par le propriétaire du projet le 2026-08-15 — non confronté à un arrêté.',
                ],
            );
        }

        $this->command?->info(count(self::NUMEROS)." numéros d'urgence enregistrés ({$pays}) — "
            .'provenance « déclaration du projet », aucun vérifié auprès d\'une autorité.');
    }
}
