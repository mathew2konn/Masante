<?php

namespace App\Models;

use App\Support\Analyses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une STRATE de référence d'une analyse (CDC_09 §7.3 « valeurs de référence »).
 *
 * ═══ POURQUOI UNE STRATE ET NON UNE PLAGE ═══
 *
 * Une plage biologique dépend de la personne. Le même 11 g/dL d'hémoglobine est bas chez l'homme
 * adulte, normal chez la femme enceinte, normal chez l'enfant de deux ans. Une plage unique
 * produirait donc des affirmations fausses — avec l'autorité d'une machine, dans un carnet de santé.
 *
 * ═══ CE MODÈLE NE JUGE RIEN ═══
 *
 * Il porte ce qu'une source affirme pour une population donnée. Il ne dit pas si un résultat est
 * normal : la plateforme affiche la valeur et la référence côte à côte, et laisse juger. Décision du
 * propriétaire, et elle tient à ce que §7.3 ne décrit aucune stratification — qualifier sur une
 * référence unique reviendrait à conclure sur une base qu'on sait insuffisante.
 *
 * `source` est NON NULLE : un intervalle sans provenance est une rumeur.
 */
class AnalyseReference extends Model
{
    protected $table = 'analyse_references';

    /** `analyse_id` reste hors `$fillable` : une strate se crée par la relation de son analyse. */
    protected $fillable = [
        'sexe',
        'age_min_jours',
        'age_max_jours',
        'etat_physiologique',
        'valeur_min',
        'valeur_max',
        'critique_bas',
        'critique_haut',
        'libelle_strate',
        'source',
        'source_detail',
    ];

    protected function casts(): array
    {
        return [
            'age_min_jours' => 'integer',
            'age_max_jours' => 'integer',
            'valeur_min'    => 'float',
            'valeur_max'    => 'float',
            'critique_bas'  => 'float',
            'critique_haut' => 'float',
        ];
    }

    public function analyse(): BelongsTo
    {
        return $this->belongsTo(Analyse::class, 'analyse_id');
    }

    /**
     * La strate ne concerne-t-elle qu'une partie des patients (grossesse) ?
     *
     * Ce que l'écran en fait : il l'affiche EN PLUS, sans la choisir. Décider qu'une patiente est
     * concernée serait un jugement clinique — et le carnet connaît la grossesse, ce qui rend la
     * tentation réelle. On s'en abstient.
     */
    public function estConditionnelle(): bool
    {
        return Analyses::estConditionnel($this->etat_physiologique);
    }

    /** « 12,0 – 16,0 », « < 5,0 », « > 60,0 » — la plage telle qu'un humain la lit. */
    public function plageLisible(): string
    {
        $format = static fn (?float $v): string => $v === null ? '' : rtrim(rtrim(number_format($v, 4, ',', ' '), '0'), ',');

        if ($this->valeur_min !== null && $this->valeur_max !== null) {
            return $format($this->valeur_min).' – '.$format($this->valeur_max);
        }

        if ($this->valeur_max !== null) {
            return '< '.$format($this->valeur_max);
        }

        if ($this->valeur_min !== null) {
            return '> '.$format($this->valeur_min);
        }

        return 'non renseignée';
    }
}
