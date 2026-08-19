<?php

namespace App\Models;

use App\Support\RegistreActionsProtocole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une action d'une règle (CDC_08 §4.3a « ALORS Urgence / Hospitalisation / … »).
 *
 * Le `type` vient de la liste blanche fermée {@see RegistreActionsProtocole}. Le §4.4 l'exige
 * nommément : « les conditions et actions utilisent les codes des référentiels nationaux plutôt
 * que du texte libre ».
 */
class ProtocoleAction extends Model
{
    protected $table = 'protocole_actions';

    protected $fillable = ['regle_id', 'ordre', 'type', 'valeur_json', 'justification'];

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

    /** Voir {@see ProtocoleCondition::valeur()} — même déballage, même raison. */
    public function valeur(): mixed
    {
        $valeur = $this->valeur_json;

        if (is_array($valeur) && array_is_list($valeur) && count($valeur) === 1) {
            return $valeur[0];
        }

        return $valeur;
    }

    /** L'action, en français, pour un relecteur clinique du §7. */
    public function enFrancais(): string
    {
        $libelle = RegistreActionsProtocole::libelle($this->type);
        $valeur = $this->valeur();

        if ($valeur === null || is_array($valeur)) {
            return $libelle;
        }

        return "{$libelle} : {$valeur}";
    }
}
