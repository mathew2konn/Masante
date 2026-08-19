<?php

namespace App\Models;

use App\Support\RegistreFaitsProtocole;
use App\Support\RegistreOperateursProtocole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une condition d'une règle (CDC_08 §4.3a) : `fait` `operateur` `valeur`.
 *
 * Les trois viennent de listes blanches FERMÉES ({@see RegistreFaitsProtocole},
 * {@see RegistreOperateursProtocole}) : le nom du fait et l'opérateur arrivent par la donnée, donc
 * par l'écran d'authoring. Sans liste blanche, ils deviendraient un choix libre du rédacteur —
 * le raisonnement de `RegistreSectionsCarnet` (P7-C) et de `RegistreReferentiels` (P6.3).
 *
 * `valeur_json` plutôt que deux colonnes : `entre` porte `[0, 25]`, `=` porte un scalaire,
 * `existe` ne porte rien. Deux colonnes `min`/`max` laisseraient l'une vide dans la majorité des
 * cas, et il faudrait décider laquelle fait foi.
 */
class ProtocoleCondition extends Model
{
    protected $table = 'protocole_conditions';

    protected $fillable = ['regle_id', 'ordre', 'fait', 'operateur', 'valeur_json'];

    protected function casts(): array
    {
        return [
            'valeur_json' => 'array',
            'ordre'       => 'integer',
        ];
    }

    public function regle(): BelongsTo
    {
        return $this->belongsTo(ProtocoleRegle::class, 'regle_id');
    }

    /**
     * La valeur telle que le moteur l'attend.
     *
     * `valeur_json` est un tableau côté cast (`entre` en a besoin), mais `=` porte un scalaire.
     * On déballe ici, à UN seul endroit, plutôt que dans le moteur : le moteur est pur et ne doit
     * rien savoir de la façon dont la base range ses valeurs.
     */
    public function valeur(): mixed
    {
        $valeur = $this->valeur_json;

        if ($valeur === null) {
            return null;
        }

        // Une valeur simple est rangée comme `[x]`, un intervalle comme `[min, max]`.
        if (is_array($valeur) && array_is_list($valeur) && count($valeur) === 1) {
            return $valeur[0];
        }

        return $valeur;
    }

    /**
     * La condition, en français, pour un relecteur clinique du §7.
     *
     * Elle vit sur le modèle et non dans un écran : le portail d'authoring, l'API de consultation
     * et le dossier de validation l'afficheront — *une phrase recopiée trois fois finit par
     * diverger deux fois* (précédent `MENTION_PROVENANCE`, P6.8d).
     */
    public function enFrancais(): string
    {
        $fait = RegistreFaitsProtocole::libelle($this->fait);
        $operateur = RegistreOperateursProtocole::libelle($this->operateur);
        $valeur = $this->valeur();

        if ($valeur === null) {
            return trim("{$fait} {$operateur}");
        }

        if (is_array($valeur)) {
            return "{$fait} {$operateur} ".implode(' et ', array_map('strval', $valeur));
        }

        return "{$fait} {$operateur} {$valeur}";
    }
}
