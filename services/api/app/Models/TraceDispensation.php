<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Le registre national de traçabilité (B3-c, CDC_11 §7.6) — APPEND-ONLY, et DÉNOMINALISÉ.
 *
 * AUCUNE DONNÉE NOMINATIVE : ni patient, ni prescripteur, ni ordonnance, ni posologie. C'est ce qui
 * rend sa survie acceptable — sinon on aurait construit un dossier médical qui survit à la
 * suppression du dossier médical (E2). `delivrance_ligne_id` ET `medicament_id` restent des
 * IDENTIFIANTS SANS CLÉ ÉTRANGÈRE (ADR-042 D1) — `medicament_id` l'a appris d'un vecteur de G3 : une
 * FK `nullOnDelete` ferait exécuter par le moteur un UPDATE sur cette ligne à la suppression du
 * produit, qu'un déclencheur append-only bloquant tout aurait refusé, empêchant purement et
 * simplement de retirer un produit du référentiel. Dénominalisé n'est pas anonyme, et c'est dit
 * avant de coder (E3) — tant que la délivrance existe, qui tient la base peut remonter au patient ;
 * une fois l'ordonnance supprimée, la trace devient réellement orpheline.
 *
 * Refusé à DEUX niveaux, comme `mouvements_stock` (B3-b) : ici par le modèle, et par le moteur
 * (déclencheurs dans les deux dialectes) — le second tient même face à un accès direct.
 */
class TraceDispensation extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'traces_dispensation';

    protected $fillable = [];

    protected function casts(): array
    {
        return ['dispensee_le' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException(
                'Une trace de dispensation ne se modifie pas : le registre national est append-only.'
            );
        });

        static::deleting(static function (): void {
            throw new RuntimeException(
                'Une trace de dispensation ne s\'efface pas : le registre national est append-only.'
            );
        });
    }
}
