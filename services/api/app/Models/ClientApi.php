<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * P11.2 — Un client d'API partenaire : le logiciel d'un établissement qui pousse ses données.
 *
 * Troisième population d'authentification du projet, à côté de Sanctum (le citoyen) et du
 * principal signé (nos propres services). Voir la migration pour le raisonnement.
 */
class ClientApi extends Model
{
    protected $table = 'clients_api';

    /** Les domaines qu'un client peut se voir ouvrir — liste blanche FERMÉE. */
    public const DOMAINES = ['stock_officine'];

    /**
     * `identifiant` et `secret_chiffre` sont hors `$fillable` : ils sont décidés par le serveur,
     * jamais reçus. Même motif que `nis`, `code` et `identifiant_national` (P6.1, P6.4a, P6.6a).
     */
    protected $fillable = ['structure_id', 'libelle', 'domaines_json'];

    protected $casts = [
        'domaines_json' => 'array',
        'secret_chiffre' => 'encrypted',
        'dernier_appel_le' => 'datetime',
        'revoque_le' => 'datetime',
    ];

    /** Identifiant opaque : il SÉLECTIONNE le secret candidat, il ne prouve rien à lui seul. */
    public static function genererIdentifiant(): string
    {
        do {
            $identifiant = 'API-'.Str::upper(Str::random(20));
        } while (static::query()->where('identifiant', $identifiant)->exists());

        return $identifiant;
    }

    /** Secret partagé, rendu UNE SEULE FOIS à l'émission. */
    public static function genererSecret(): string
    {
        return base64_encode(random_bytes(32));
    }

    public function estActif(): bool
    {
        return $this->revoque_le === null;
    }

    public function couvre(string $domaine): bool
    {
        return in_array($domaine, $this->domaines_json ?? [], true);
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }
}
