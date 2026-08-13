<?php

namespace Database\Seeders;

use App\Models\CategorieImageEtablissement;
use Illuminate\Database\Seeder;

/**
 * P6.4c — Les cinq catégories d'images nommées par CDC_11 §3.1.
 *
 * > « Images (formulaire dédié) : logo, photos, salle d'attente, bloc opératoire, accueil,
 * >   parking s'il existe. »
 *
 * Elles sont posées ici comme DONNÉES, pas comme énumération PHP : en ajouter une sixième
 * (« pharmacie interne », « laboratoire ») doit coûter une ligne et aucun déploiement (§1.2.4).
 *
 * `max_par_etablissement` porte la seule vraie règle métier du lot : **un établissement n'a qu'un
 * logo**. L'écrire en donnée plutôt qu'en `if ($categorie === 'logo')` est ce qui permet de la
 * changer sans toucher au moteur — même principe que `villes.affiche_communes` (P6.4b).
 *
 * IDEMPOTENT, et il ne réécrit PAS un maximum déjà ajusté à la main : si un administrateur a porté
 * les photos d'accueil à 10, un rejeu du seeder ne doit pas le ramener à 5.
 */
class CategoriesImageEtablissementSeeder extends Seeder
{
    /** @var list<array{code:string, libelle:string, max:int, ordre:int}> */
    private const CATEGORIES = [
        ['code' => 'logo',            'libelle' => 'Logo',            'max' => 1, 'ordre' => 1],
        ['code' => 'accueil',         'libelle' => 'Accueil',         'max' => 5, 'ordre' => 2],
        ['code' => 'salle_attente',   'libelle' => "Salle d'attente", 'max' => 5, 'ordre' => 3],
        ['code' => 'bloc_operatoire', 'libelle' => 'Bloc opératoire', 'max' => 5, 'ordre' => 4],
        ['code' => 'parking',         'libelle' => 'Parking',         'max' => 3, 'ordre' => 5],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $categorie) {
            CategorieImageEtablissement::firstOrCreate(
                ['code' => $categorie['code']],
                [
                    'libelle'               => $categorie['libelle'],
                    'max_par_etablissement' => $categorie['max'],
                    'ordre'                 => $categorie['ordre'],
                    'actif'                 => true,
                ],
            );
        }
    }
}
