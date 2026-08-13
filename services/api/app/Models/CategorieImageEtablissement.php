<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Catégorie d'image d'établissement (P6.4c) — table de RÉFÉRENCE, pas une énumération PHP.
 *
 * Les cinq sujets nommés par CDC_11 §3.1 (logo, accueil, salle d'attente, bloc opératoire, parking)
 * vivent en base. En ajouter un sixième est une ligne de données : aucun code ne les énumère.
 *
 * `max_par_etablissement` porte la règle « un établissement n'a qu'un logo » — en donnée, jamais en
 * `if`. C'est le même principe que `villes.affiche_communes` (P6.4b).
 */
class CategorieImageEtablissement extends Model
{
    protected $table = 'categories_image_etablissement';

    protected $fillable = ['code', 'libelle', 'max_par_etablissement', 'ordre', 'actif'];

    protected function casts(): array
    {
        return [
            'max_par_etablissement' => 'integer',
            'ordre'                 => 'integer',
            'actif'                 => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('actif', true);
    }
}
