<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un diplôme d'un professionnel de santé (CDC_04 §5.2 `professionnel_diplomes`).
 *
 * §5.2 les qualifie explicitement d'OPTIONNELS — d'où l'absence de tout contrôle de complétude :
 * un professionnel sans diplôme enregistré n'est pas une anomalie du référentiel, seulement une
 * information que personne n'a encore saisie.
 *
 * AUCUNE VÉRIFICATION D'AUTHENTICITÉ N'EST FAITE NI PROMISE. Ce qui autorise à exercer, ce n'est
 * pas le diplôme déclaré ici mais l'autorisation d'exercer portée par `medecins` et délivrée par
 * un ordre professionnel — c'est elle, et elle seule, que le §5.4 interrogera avant une signature.
 */
class DiplomeProfessionnel extends Model
{
    protected $table = 'professionnel_diplomes';

    protected $fillable = [
        'medecin_id',
        'intitule',
        'universite',
        'pays_obtention',
        'annee_obtention',
    ];

    protected function casts(): array
    {
        return [
            'annee_obtention' => 'integer',
        ];
    }

    public function professionnel(): BelongsTo
    {
        return $this->belongsTo(Medecin::class, 'medecin_id');
    }
}
