<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Un protocole médical du registre national (CDC_08 §4.1).
 *
 * Le protocole est l'IDENTITÉ ; ce sont ses **versions** qui portent les règles, le dossier de
 * validation et l'état (§6.1). Un protocole n'est donc jamais « actif » : c'est une de ses versions
 * qui l'est, et c'est ce qui rend l'exigence médico-légale du §6.1 représentable — *chaque décision
 * conserve la version exacte utilisée*.
 */
class Protocole extends Model
{
    /** §5.4 — le seul domaine que P10b-1 publie (décision G1 N3). */
    public const DOMAINE_TRIAGE = 'triage';

    /** §3 — l'ordre de priorité entre référentiels, du plus fort au plus faible. */
    public const PRIORITE_SOURCES = [
        'national', 'regional', 'oms', 'societe_savante', 'hospitalier',
    ];

    protected $table = 'protocoles';

    /**
     * `code` et `pays_code` sont HORS `$fillable`.
     *
     * Ils sont posés à la création par le service et ne bougent plus : le code est inscrit dans
     * `triages.protocole_code` et dans le journal de gouvernance. Le renommer laisserait des
     * décisions médicales archivées désigner un protocole introuvable — même raisonnement que
     * l'immuabilité du code de spécialité (P6.8a) et de celui des numéros d'urgence (P6.8e).
     */
    protected $fillable = [
        'titre',
        'domaine',
        'niveau_source',
        'organisme',
        'auteur',
        'specialite_code',
        'langue',
        'mots_cles_json',
        'contextes_json',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'mots_cles_json' => 'array',
            'contextes_json' => 'array',
            'actif'          => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $protocole) {
            foreach (['code', 'pays_code'] as $champ) {
                if ($protocole->isDirty($champ)) {
                    throw new RuntimeException(
                        "L'identité d'un protocole est immuable : « {$champ} » ne peut pas changer."
                    );
                }
            }
        });

        static::deleting(function (self $protocole) {
            // §6.1 : « un protocole archivé reste consultable indéfiniment ». Supprimer la ligne
            // emporterait ses versions en cascade, donc les décisions qui les citent.
            if ($protocole->versions()->exists()) {
                throw new RuntimeException(
                    'Un protocole qui porte des versions ne se supprime pas : il se désactive '
                    .'(CDC_08 §6.1 — un protocole archivé reste consultable indéfiniment).'
                );
            }
        });
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProtocoleVersion::class, 'protocole_id');
    }

    /** La version en vigueur, ou `null` si le protocole n'a jamais été publié. */
    public function versionActive(): ?ProtocoleVersion
    {
        return $this->versions()->where('etat', ProtocoleVersion::ACTIF)->first();
    }

    /** Le brouillon en cours, ou `null`. Au plus un — garanti par `uq_protocole_version_verrou`. */
    public function brouillon(): ?ProtocoleVersion
    {
        return $this->versions()->where('etat', ProtocoleVersion::BROUILLON)->first();
    }

    public function scopeActif($query)
    {
        return $query->where('actif', true);
    }
}
