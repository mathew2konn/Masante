<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Déclaration d'origine d'une chaîne d'audit (CDC_10 ; loi 2013-450).
 *
 * ═══ POURQUOI UNE TABLE, ET NON UNE ENTRÉE DANS LE JOURNAL LUI-MÊME ═══
 *
 * L'idée naturelle était d'écrire l'ouverture comme un maillon ordinaire : elle serait alors
 * protégée par le hachage chaîné. Elle ne tient pas pour les quatre journaux du projet :
 *
 *  - `protocole_journal` exige un `protocole_code` et un `protocole_id` ; une ouverture n'en a
 *    aucun, il aurait fallu en inventer ;
 *  - `protocole_applications` est un journal d'ÉVALUATIONS CLINIQUES (§10) : y insérer une ligne
 *    qui n'est pas une évaluation mettrait deux natures de vérité dans la même table — exactement
 *    ce que ce projet refuse depuis P6.6a.
 *
 * ═══ CE QUE CETTE TABLE PROTÈGE, ET CE QU'ELLE NE PROTÈGE PAS ═══
 *
 * Elle n'est pas chaînée. **Supprimer une déclaration est donc détecté** — la chaîne concernée
 * repasse à « origine non déclarée », c'est-à-dire au rouge : la disparition joue dans le sens sûr.
 * En revanche, **forger** une déclaration pour couvrir une chaîne tronquée reste possible à qui
 * tient la base. C'est une limite assumée et écrite : une déclaration est nominative et motivée,
 * c'est tout ce qu'un journal peut opposer à celui qui possède le serveur.
 */
class AuditChaine extends Model
{
    protected $table = 'audit_chaines';

    public $timestamps = false;

    protected $fillable = [
        'journal',
        'numero',
        'motif',
        'acteur_nom',
        'empreinte_premiere',
        'entrees_scellees',
        'empreinte_scellee',
        'verdict_scelle_json',
        'cree_le',
    ];

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'entrees_scellees' => 'integer',
            'verdict_scelle_json' => 'array',
            'cree_le' => 'datetime',
        ];
    }
}
