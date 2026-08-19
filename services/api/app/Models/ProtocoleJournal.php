<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Le journal de GOUVERNANCE des protocoles — append-only, chaîne de hachage globale (CDC_08 §10,
 * CDC_10).
 *
 * ═══ DEUX JOURNAUX, DEUX NATURES ═══
 *
 * Celui-ci trace **qui** a rédigé, validé, publié ou archivé un protocole. Il ne contient AUCUN
 * contenu clinique : deux copies du contenu seraient deux vérités, et l'instantané de la version
 * porte déjà ce qui a changé (règle établie en P6.3, et miroir de P7-D0 où `donnees_ajoutees` ne
 * recopie jamais l'entrée du carnet).
 *
 * Le journal d'EXÉCUTION (`protocole_applications`, §10, P10b-2) sera l'inverse : il portera
 * l'identifiant du patient, les recommandations affichées, la décision du médecin et sa
 * justification d'écart, **parce que le §10 l'exige pour l'audit médico-légal**. Les confondre
 * ferait perdre l'un ou l'autre.
 *
 * CHAÎNE GLOBALE et non par protocole : une chaîne par protocole permettrait d'effacer l'historique
 * entier d'un protocole sans que rien ne le révèle (raisonnement de P6.3, repris de la chaîne
 * `audit_entries` du paiement).
 */
class ProtocoleJournal extends Model
{
    public const CREATION = 'creation';

    public const BROUILLON_OUVERT = 'brouillon_ouvert';

    public const BROUILLON_MODIFIE = 'brouillon_modifie';

    public const VALIDATION = 'validation';

    public const PUBLICATION = 'publication';

    public const ARCHIVAGE = 'archivage';

    protected $table = 'protocole_journal';

    public $timestamps = false;

    protected $fillable = [
        'protocole_code', 'pays_code', 'protocole_id', 'version_numero', 'action',
        'acteur_id', 'acteur_nom', 'details_json',
        'empreinte_precedente', 'empreinte', 'cree_le',
    ];

    protected function casts(): array
    {
        return [
            'details_json'   => 'array',
            'version_numero' => 'integer',
            'cree_le'        => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Le journal des protocoles est immuable (CDC_08 §10).');
        });

        static::deleting(function () {
            throw new RuntimeException('Le journal des protocoles est immuable (CDC_08 §10).');
        });
    }

    public function protocole(): BelongsTo
    {
        return $this->belongsTo(Protocole::class, 'protocole_id');
    }

    public function acteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acteur_id');
    }
}
