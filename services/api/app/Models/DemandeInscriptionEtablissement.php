<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * P11.1 — Candidature d'un établissement à la plateforme (CDC_11 §3, méthode 2).
 *
 * Une demande n'est pas un établissement : elle vit dans sa propre table et ne devient une ligne
 * de `structures_sanitaires` qu'à l'approbation. Voir la migration pour le raisonnement complet.
 */
class DemandeInscriptionEtablissement extends Model
{
    use HasFactory;

    protected $table = 'demandes_inscription_etablissement';

    public const EN_ATTENTE = 'en_attente';

    public const APPROUVEE = 'approuvee';

    public const REJETEE = 'rejetee';

    /**
     * `reference`, `statut`, la décision et `structure_id` sont HORS `$fillable`.
     *
     * Le formulaire est **public** : un candidat qui pourrait envoyer `statut=approuvee` en même
     * temps que son nom s'auto-inscrirait. Le motif est celui de `nis`/`code`/`identifiant_national`
     * (P6.1, P6.4a, P6.6a) — ce que le serveur décide n'entre jamais par la porte du client. La
     * garde vit à deux endroits, ici et dans les règles de validation, chacune avec son vecteur
     * (parade de P6.6b : un vecteur HTTP prouverait le validateur, pas le modèle).
     */
    protected $fillable = [
        'nom',
        'type',
        'statut_juridique',
        'numero_autorisation',
        'adresse',
        'commune',
        'telephone',
        'email',
        'demandeur_nom',
        'demandeur_prenom',
        'demandeur_fonction',
        'demandeur_email',
        'demandeur_telephone',
        'message',
    ];

    protected $casts = [
        'decide_le' => 'datetime',
    ];

    /**
     * Référence opaque remise au demandeur — son seul moyen de suivre une demande sans compte.
     *
     * Aléatoire et non séquentielle : un compteur laisserait deviner le volume de candidatures
     * et, surtout, énumérer celles des autres établissements (précédent anti-énumération du NIS,
     * P6.1, et des jetons de fiche de triage, P10a).
     */
    public static function genererReference(): string
    {
        do {
            $reference = 'DEM-'.Str::upper(Str::random(10));
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    public function estEnAttente(): bool
    {
        return $this->statut === self::EN_ATTENTE;
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }
}
