<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * P11.2 — La référence D'UN PARTENAIRE et ce qu'elle désigne chez nous.
 *
 * Le logiciel d'une officine a ses codes produits ; lui demander de parler les nôtres serait la
 * ressaisie que CDC_11 §7.7 dit supprimer. Cette table porte l'équivalence — **déclarée par le
 * partenaire, jamais devinée par le serveur.**
 */
class CorrespondancePartenaire extends Model
{
    protected $table = 'correspondances_partenaire';

    protected $fillable = ['structure_id', 'domaine', 'reference_externe', 'code_masante'];
}
