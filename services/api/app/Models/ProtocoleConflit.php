<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * P10b-2 — Une divergence constatée entre deux protocoles (CDC_08 §4.4, §8).
 *
 * ═══ LES DEUX CÔTÉS SONT CONSERVÉS, PAS SEULEMENT LE GAGNANT ═══
 *
 * Le §8 exige que « toutes les divergences soient consignées afin de garantir la transparence des
 * décisions », et qu'un conflit non résolu soit « présenté au médecin avec **les deux**
 * recommandations et leurs sources ». Ne garder que la valeur retenue ferait disparaître
 * exactement la moitié dont on a besoin pour comprendre — et rendrait impossible de contester le
 * départage.
 *
 * ═══ CE N'EST PAS UN REGISTRE DE CONFLITS CONNUS, C'EST UN JOURNAL ═══
 *
 * Une ligne = une divergence rencontrée lors d'UNE évaluation, rattachée à elle. Un registre des
 * couples de protocoles qui « se contredisent en général » aurait supposé de connaître à l'avance
 * les cas où ils divergent — or cela dépend des faits du patient, qui ne sont connus qu'au moment
 * de l'évaluation.
 *
 * Append-only, comme l'évaluation qui le porte : une divergence effacée ferait croire à une
 * décision unanime.
 */
class ProtocoleConflit extends Model
{
    protected $table = 'protocole_conflits';

    public $timestamps = false;

    protected $fillable = [
        'application_id',
        'action_type',
        'valeur_retenue',
        'protocole_retenu_code',
        'protocole_retenu_version',
        'source_retenue',
        'valeur_ecartee',
        'protocole_ecarte_code',
        'protocole_ecarte_version',
        'source_ecartee',
        'critere',
        'cree_le',
    ];

    protected function casts(): array
    {
        return ['cree_le' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'Le journal des divergences est append-only (CDC_08 §8) : une divergence '
                .'constatée ne se réécrit pas.'
            );
        });

        static::deleting(function () {
            throw new RuntimeException(
                'Le journal des divergences est append-only (CDC_08 §8) : effacer une divergence '
                .'ferait croire à une décision unanime.'
            );
        });
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ProtocoleApplication::class, 'application_id');
    }
}
