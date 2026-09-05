<?php

namespace App\Models;

use App\Support\ModeReglementCommande;
use App\Support\ModeRetraitCommande;
use App\Support\StatutCommande;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Une commande de médicaments (B3-d, CDC_11 §9.5). Change d'état : elle n'est pas append-only.
 *
 * `adresse_livraison` chiffrée au repos (donnée personnelle) — patron du reste du carnet.
 * `statut`/`reference`/`montant_indicatif_cfa`/les valeurs figées des lignes sont posés par le
 * serveur, jamais par le client : voir `App\Services\Medicament\ServiceCommande`.
 */
class Commande extends Model
{
    protected static function booted(): void
    {
        static::creating(static function (self $commande): void {
            $commande->reference ??= self::genererReference();
        });
    }

    /**
     * TOUTES POSÉES PAR LE SERVICE, JAMAIS PAR LE CLIENT — et pourtant `$fillable` : le chemin
     * d'écriture est une ASSIGNATION DE MASSE (`Commande::create()`/`$commande->update()`), donc
     * une clé absente d'ici serait écartée EN SILENCE (piège relevé par P6.7b sur les noms
     * figés, revu en B2-b/B3-a/B3-b). Ce qui protège réellement est la VALIDATION dans
     * `ServiceCommande`/`ServiceTraitementCommande`, qui seuls appellent ces méthodes — jamais
     * un contrôleur, jamais une requête HTTP directement mappée dessus.
     */
    protected $fillable = [
        'membre_id',
        'user_id',
        'structure_id',
        'ordonnance_id',
        'mode_retrait',
        'adresse_livraison',
        'commentaire',
        'statut',
        'montant_indicatif_cfa',
        'mode_reglement',
        'regle_le',
        'reference_reglement',
        'commande_geniuspay_id',
        'motif_refus',
        'traite_par_user_id',
        'acceptee_le',
        'prete_le',
        'remise_le',
        'annulee_le',
    ];

    protected function casts(): array
    {
        return [
            'adresse_livraison' => 'encrypted',
            'statut' => StatutCommande::class,
            'mode_retrait' => ModeRetraitCommande::class,
            'mode_reglement' => ModeReglementCommande::class,
            'regle_le' => 'datetime',
            'acceptee_le' => 'datetime',
            'prete_le' => 'datetime',
            'remise_le' => 'datetime',
            'annulee_le' => 'datetime',
        ];
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    public function ordonnance(): BelongsTo
    {
        return $this->belongsTo(Ordonnance::class, 'ordonnance_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(CommandeLigne::class, 'commande_id');
    }

    public function estReglee(): bool
    {
        return $this->regle_le !== null;
    }

    /** Référence unique CMD-XXXXXXXXXX (opaque, non séquentielle — patron `DEM-` de P11.1). */
    public static function genererReference(): string
    {
        do {
            $ref = 'CMD-'.strtoupper(Str::random(10));
        } while (self::where('reference', $ref)->exists());

        return $ref;
    }
}
