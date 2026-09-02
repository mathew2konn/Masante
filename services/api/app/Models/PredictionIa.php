<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * P10c-2-i (F10), durcie par P10c-3-ii (F28) — Traçabilité d'un appel à `triage-service`
 * (CDC_05 §9.2 ; CDC_04 §115/§123).
 *
 * ═══ ELLE A REJOINT LE RÉGIME `ProtocoleApplication`, ET LA DATE ÉTAIT ANNONCÉE ═══
 *
 * P10c-2-i avait écrit noir sur blanc que le durcissement viendrait « quand une explication réelle
 * nommera pour la première fois des valeurs cliniques » — durcir plus tôt aurait été le socle à
 * vide refusé par P6.3-D3, puisque chaque ligne ne portait alors qu'un refus honnête. Depuis
 * P10c-3-ii, un modèle actif écrit `probabilite`, `facteurs_json` et `explication_json` : la table
 * porte du clinique, donc elle est chaînée et append-only.
 *
 * ═══ APPEND-ONLY À DEUX NIVEAUX ═══
 *
 * Ici (Eloquent) et au moteur (déclencheurs). Aucune ne rattrape l'autre : la garde applicative
 * donne un message clair au développeur, le déclencheur résiste à un client MySQL.
 *
 * Conséquence dite : une prédiction ne se « complète » jamais après coup. Un nouvel appel produit
 * une NOUVELLE entrée — c'est aussi ce qui garantit qu'aucun re-scoring rétroactif ne peut
 * silencieusement réécrire ce qu'un modèle avait dit (F30).
 */
class PredictionIa extends Model
{
    protected $table = 'predictions_ia';

    public $timestamps = false;

    protected $fillable = [
        // Numéro de chaîne d'audit ({@see ChaineAudit}). NULL sur les entrées antérieures au
        // mécanisme : elles ne sont pas scellées rétroactivement, et la déclaration d'origine
        // porte leur nombre plutôt que de laisser deviner un trou.
        'chaine',
        'triage_id',
        'modele_version',
        'mode',
        'motif_degradation',
        'latence_ms',
        'probabilite',
        'facteurs_json',
        'explication_json',
        'confiance',
        'limites',
        'cree_le',
        'empreinte',
        'empreinte_precedente',
    ];

    protected function casts(): array
    {
        return [
            'facteurs_json' => 'array',
            'explication_json' => 'array',
            'cree_le' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'Le journal des prédictions IA est append-only (CDC_05 §9.2) : une prédiction ne se '
                .'modifie pas après coup. Un nouvel appel s\'inscrit comme une nouvelle entrée.'
            );
        });

        static::deleting(function () {
            throw new RuntimeException(
                'Le journal des prédictions IA est append-only (CDC_05 §9.2) : supprimer une entrée '
                .'effacerait la trace de ce qu\'un modèle a dit sur un triage donné.'
            );
        });
    }
}
