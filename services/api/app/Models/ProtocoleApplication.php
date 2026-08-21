<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * P10b-2 — Le journal d'EXÉCUTION des protocoles (CDC_08 §10) : append-only, chaîne de hachage
 * globale.
 *
 * ═══ IL EST L'INVERSE DE `ProtocoleJournal`, ET LES DEUX SONT NÉCESSAIRES ═══
 *
 * `protocole_journal` trace **qui gouverne** — rédaction, validation, publication — et ne contient
 * aucun contenu clinique. Celui-ci trace **ce qui a été recommandé à qui**, et en contient
 * forcément : le §10 exige d'historiser « les recommandations affichées », et un journal
 * d'exécution qui tairait ce qui a été recommandé ne servirait à rien le jour d'un litige — sa
 * seule raison d'être.
 *
 * Les confondre ferait perdre l'un ou l'autre : une chaîne unique lierait la validité de l'audit
 * médico-légal à celle de l'audit de gouvernance, et une altération dans l'un ferait crier l'autre
 * sans qu'on puisse dire lequel a bougé (raisonnement de b-1 pour ne pas fusionner avec le socle).
 *
 * ═══ APPEND-ONLY À DEUX NIVEAUX ═══
 *
 * Ici (Eloquent) et au moteur (déclencheurs). Aucune ne rattrape l'autre : la garde applicative
 * donne un message clair au développeur, le déclencheur résiste à un client MySQL.
 *
 * Conséquence dite : la décision finale du §10 ne se « complète » pas après coup. Un professionnel
 * qui décide plus tard produit une NOUVELLE entrée — réécrire la précédente serait réécrire le
 * passé, ce qu'un journal immuable existe pour empêcher.
 */
class ProtocoleApplication extends Model
{
    protected $table = 'protocole_applications';

    public $timestamps = false;

    protected $fillable = [
        // Numéro de chaîne d'audit ({@see ChaineAudit}) : une chaîne scellée n'est jamais réécrite.
        'chaine',
        'trace_id',
        'contexte',
        'pays_code',
        'membre_id',
        'user_id',
        'professionnel_id',
        'triage_id',
        'protocole_retenu_code',
        'protocole_retenu_version',
        'protocoles_json',
        'recommandations_json',
        'decision_finale',
        'ecart_justification',
        'empreinte',
        'empreinte_precedente',
        'cree_le',
    ];

    protected function casts(): array
    {
        return [
            'protocoles_json' => 'array',
            'recommandations_json' => 'array',
            'cree_le' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'Le journal d\'exécution des protocoles est append-only (CDC_08 §10) : une '
                .'évaluation ne se modifie pas après coup. Une décision prise plus tard s\'inscrit '
                .'comme une nouvelle entrée.'
            );
        });

        static::deleting(function () {
            throw new RuntimeException(
                'Le journal d\'exécution des protocoles est append-only (CDC_08 §10) : supprimer '
                .'une entrée effacerait la trace d\'une recommandation faite à un patient.'
            );
        });
    }

    public function conflits(): HasMany
    {
        return $this->hasMany(ProtocoleConflit::class, 'application_id');
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    public function triage(): BelongsTo
    {
        return $this->belongsTo(Triage::class, 'triage_id');
    }
}
