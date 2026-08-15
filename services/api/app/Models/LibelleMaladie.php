<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P6.8c — Un libellé ALTERNATIF d'une maladie : autre langue, ou synonyme de recherche (CDC_09 §8,
 * « libellés multilingues »).
 *
 * ═══ CE QU'IL N'EST PAS ═══
 *
 * Il n'est JAMAIS le libellé officiel français : celui-là vit sur `maladies.libelle` et nulle part
 * ailleurs. C'est ce qui rend la seconde vérité INEXPRIMABLE plutôt que simplement interdite —
 * aucune colonne `type` n'existe ici, donc rien ne peut prétendre à l'officialité en concurrence de
 * la ligne. Un déclencheur ferme le dernier chemin (recopier la chaîne à l'identique).
 *
 * `principal` désigne, PAR LANGUE, celui qu'on affiche ; les autres ne servent qu'à retrouver
 * (« palu » → Paludisme). « Exactement un principal par langue » est tenu par le CONTRÔLE QUALITÉ
 * et non par le moteur — MySQL 8 n'a pas d'index unique partiel —, et c'est dit plutôt que déguisé
 * en garantie du moteur (précédent du quota d'images de P6.4c).
 */
class LibelleMaladie extends Model
{
    protected $table = 'maladie_libelles';

    protected $fillable = [
        'langue',
        'libelle',
        'principal',
        'source',
        'source_detail',
    ];

    protected function casts(): array
    {
        return [
            'principal' => 'boolean',
        ];
    }

    public function maladie(): BelongsTo
    {
        return $this->belongsTo(Maladie::class, 'maladie_id');
    }
}
