<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Une version d'un référentiel national — le cycle de vie §10 et l'instantané figé.
 *
 * ÉTATS : proposition → publiee → archivee, ou proposition → rejetee.
 *
 * CE QUI EST IMMUABLE, ET POURQUOI. Dès qu'une version est décidée, son CONTENU ne bouge plus :
 * `contenu_json`, `empreinte`, `numero`, `propose_par`, `decide_par` sont scellés. Sans cela,
 * l'estampille « ce triage a utilisé la version 7 » ne prouverait rien — on pourrait réécrire la
 * version 7 après coup. Seuls `statut` et `verrou_unicite` peuvent encore changer, et dans un seul
 * sens : publiee → archivee, quand une version plus récente prend sa place.
 *
 * La garde ci-dessous double les CHECK de la base : le moteur empêche les états incohérents,
 * ce hook empêche la réécriture silencieuse d'un instantané scellé.
 */
class ReferentielVersion extends Model
{
    public const PROPOSITION = 'proposition';

    public const PUBLIEE = 'publiee';

    public const ARCHIVEE = 'archivee';

    public const REJETEE = 'rejetee';

    /** Champs scellés dès qu'une version quitte l'état `proposition`. */
    private const SCELLES = [
        'referentiel_id',
        'numero',
        'contenu_json',
        'empreinte',
        'nb_entrees',
        'propose_par',
        'propose_le',
        'decide_par',
        'decide_le',
        'motif',
        'motif_decision',
    ];

    protected $table = 'referentiel_versions';

    protected $fillable = [
        'referentiel_id',
        'numero',
        'statut',
        'verrou_unicite',
        'motif',
        'contenu_json',
        'empreinte',
        'nb_entrees',
        'propose_par',
        'propose_le',
        'decide_par',
        'decide_le',
        'motif_decision',
    ];

    protected function casts(): array
    {
        return [
            'contenu_json' => 'array',
            'numero'       => 'integer',
            'nb_entrees'   => 'integer',
            'propose_le'   => 'datetime',
            'decide_le'    => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version) {
            // L'état AVANT modification décide : une proposition se modifie librement, une
            // version décidée est scellée.
            if ($version->getOriginal('statut') === self::PROPOSITION) {
                return;
            }

            foreach (self::SCELLES as $champ) {
                if ($version->isDirty($champ)) {
                    throw new RuntimeException(
                        "Version de référentiel scellée : « {$champ} » ne peut plus être modifiée."
                    );
                }
            }
        });

        static::deleting(function () {
            throw new RuntimeException(
                'Une version de référentiel ne se supprime pas : elle s\'archive (CDC_09 §10).'
            );
        });
    }

    public function referentiel(): BelongsTo
    {
        return $this->belongsTo(Referentiel::class, 'referentiel_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'propose_par');
    }

    public function decideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decide_par');
    }

    /** La valeur du verrou d'unicité correspondant à un état — `null` si l'état n'en porte pas. */
    public static function verrouPour(string $statut, int $referentielId): ?string
    {
        return match ($statut) {
            self::PROPOSITION => "P:{$referentielId}",
            self::PUBLIEE     => "V:{$referentielId}",
            default           => null,
        };
    }
}
