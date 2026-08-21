<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Journal d'audit des référentiels nationaux — append-only, chaîne de hachage GLOBALE (CDC_09 §11).
 *
 * §11 : « toute modification d'un référentiel produit une entrée d'audit immuable ». Une table
 * ordinaire ne suffit pas : un `UPDATE` ou un `DELETE` y passerait sans laisser de trace. Chaque
 * entrée porte donc l'empreinte de la précédente — retirer ou altérer une ligne casse la chaîne
 * en aval, et la rupture est détectable.
 *
 * LA CHAÎNE EST GLOBALE, pas une par référentiel. Une chaîne par référentiel laisserait effacer
 * l'historique entier d'un référentiel sans que rien ne le révèle. C'est le motif de la chaîne
 * `audit_entries` du service paiement (P5.1), porté ici en PHP.
 *
 * CE QU'IL NE CONTIENT PAS : le contenu du référentiel. Le journal prouve QU'UN changement a eu
 * lieu, par qui et quand ; ce QUI a changé vit dans l'instantané de la version. Les dupliquer
 * créerait deux vérités — même raisonnement qu'en P7-D0, où l'identité du soignant reste dans
 * `acces_dossier` plutôt que d'être recopiée dans le carnet.
 */
class ReferentielJournal extends Model
{
    public const ENREGISTRE = 'REFERENTIEL_ENREGISTRE';

    public const PROPOSITION_DEPOSEE = 'PROPOSITION_DEPOSEE';

    public const VERSION_PUBLIEE = 'VERSION_PUBLIEE';

    public const VERSION_REJETEE = 'VERSION_REJETEE';

    public const VERSION_ARCHIVEE = 'VERSION_ARCHIVEE';

    public $timestamps = false;

    protected $table = 'referentiel_journal';

    protected $fillable = [
        // Numéro de chaîne d'audit ({@see ChaineAudit}) : une chaîne scellée n'est jamais réécrite.
        'chaine',
        'referentiel_code',
        'pays_code',
        'referentiel_id',
        'version_numero',
        'action',
        'acteur_id',
        'acteur_nom',
        'details_json',
        'empreinte_precedente',
        'empreinte',
        'cree_le',
    ];

    protected function casts(): array
    {
        return [
            'details_json' => 'array',
            'version_numero' => 'integer',
            'cree_le' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Le journal des référentiels est append-only (CDC_09 §11).');
        });

        static::deleting(function () {
            throw new RuntimeException('Le journal des référentiels est append-only (CDC_09 §11).');
        });
    }

    public function referentiel(): BelongsTo
    {
        return $this->belongsTo(Referentiel::class, 'referentiel_id');
    }

    public function acteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acteur_id');
    }
}
