<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Module 5 / 5.6 — Seuils d'un type de mesure (FN5). Contenu public non sensible (aucun chiffrement).
 *
 * DEUX USAGES, À NE PAS CONFONDRE DEPUIS L1 (ADR-025 §5) :
 *  - la TABLE `referentiels_mesure` est le contenu de travail, celui qu'une proposition fige ;
 *  - les instances que renvoie {@see App\Services\MesureSanteService} sont HYDRATÉES DEPUIS LA
 *    VERSION PUBLIÉE et n'existent pas en base (pas d'`id`, `exists` à faux). Ne jamais les
 *    sauvegarder : un `save()` créerait un doublon dans la table.
 *
 * `statutPour()` fonctionne dans les deux cas — c'est un calcul sur les attributs, et c'est
 * précisément ce qui a permis de basculer la lecture sans toucher aux quatre consommateurs.
 */
class ReferentielMesure extends Model
{
    protected $table = 'referentiels_mesure';

    protected $fillable = [
        'type_mesure',
        'libelle',
        'unite',
        'valeur_min',
        'valeur_max',
        'normal_min',
        'normal_max',
        'critique_bas',
        'critique_haut',
        'decimales',

        // P10c-1 — Combien de temps une mesure du carnet reste PROPOSABLE au triage.
        //
        // `null` = jamais pré-remplie. C'est le sens sûr : une donnée absente ne doit pas autoriser
        // silencieusement la réutilisation d'une mesure ancienne, et *une température prise il y a
        // trois mois n'est pas une température*. La valeur diffère radicalement selon le type —
        // quelques heures pour un pouls, plusieurs mois pour un poids —, donc elle ne pouvait ni
        // être une constante de code ni être unique.
        'fraicheur_max_minutes',

        'ordre',
        'conseil_anormal',
    ];

    protected function casts(): array
    {
        return [
            'valeur_min'    => 'float',
            'valeur_max'    => 'float',
            'normal_min'    => 'float',
            'normal_max'    => 'float',
            'critique_bas'  => 'float',
            'critique_haut' => 'float',
            'decimales'     => 'integer',
            'fraicheur_max_minutes' => 'integer',
            'ordre'         => 'integer',
        ];
    }

    /**
     * Situe une valeur par rapport aux seuils : `critique` (urgence), `bas`, `eleve` ou `normal`.
     *
     * Le critique l'emporte sur tout : une valeur sous `critique_bas` n'est pas seulement « basse »,
     * elle appelle un avis médical immédiat. Un seuil critique NULL signifie qu'il n'y en a pas de
     * ce côté (une saturation ne peut pas être « critiquement haute »).
     */
    public function statutPour(float $valeur): string
    {
        if ($this->critique_bas !== null && $valeur <= $this->critique_bas) {
            return 'critique';
        }

        if ($this->critique_haut !== null && $valeur >= $this->critique_haut) {
            return 'critique';
        }

        if ($valeur < $this->normal_min) {
            return 'bas';
        }

        if ($valeur > $this->normal_max) {
            return 'eleve';
        }

        return 'normal';
    }
}
