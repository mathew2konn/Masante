<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une interaction déclarée entre deux médicaments (CDC_09 §6.2).
 *
 * CE MODÈLE NE DÉCIDE RIEN. Il porte ce que le référentiel national AFFIRME : que deux produits
 * sont déclarés en interaction, à quel niveau, et d'après quelle source. Même
 * `contre_indication` ne bloque aucune prescription — refuser serait une décision médicale prise
 * par une machine (CDC_00 §4). L'analyse, les alternatives thérapeutiques et l'adaptation de doses
 * appartiennent au `interaction-service` de CDC_05 §2.
 *
 * LE COUPLE EST ORDONNÉ (`medicament_a_id < medicament_b_id`), posé ainsi par
 * {@see App\Services\Medicament\ServiceInteractions}. C'est ce qui rend l'unicité DÉCLARATIVE :
 * sans cet ordre, « A avec B » et « B avec A » seraient deux lignes acceptées par le moteur, donc
 * deux vérités possiblement divergentes sur le même fait clinique.
 */
class InteractionMedicamenteuse extends Model
{
    protected $table = 'interactions_medicamenteuses';

    /**
     * Les identifiants sont hors `$fillable` : l'ordre du couple est une garantie du service, pas
     * une donnée que l'appelant compose lui-même.
     */
    protected $fillable = [
        'niveau',
        'description',
        'conduite_a_tenir',
        'source',
    ];

    public function medicamentA(): BelongsTo
    {
        return $this->belongsTo(Medicament::class, 'medicament_a_id');
    }

    public function medicamentB(): BelongsTo
    {
        return $this->belongsTo(Medicament::class, 'medicament_b_id');
    }

    /** L'autre médicament du couple, vu depuis l'un d'eux. */
    public function autreQue(int $medicamentId): ?Medicament
    {
        if ((int) $this->medicament_a_id === $medicamentId) {
            return $this->medicamentB;
        }

        if ((int) $this->medicament_b_id === $medicamentId) {
            return $this->medicamentA;
        }

        return null;
    }
}
