<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un référentiel national gouverné (CDC_09 §10) — la ligne du REGISTRE.
 *
 * Elle ne contient AUCUNE donnée de référence : le contenu vit dans la table métier
 * (`referentiels_mesure`, `symptomes`…) et son instantané publié dans `referentiel_versions`.
 * Cette ligne dit seulement : ce référentiel existe, voici son responsable, et voici la version
 * actuellement publiée.
 *
 * `version_publiee_numero` est la clé de voûte de la diffusion : c'est elle qui entre dans la clé
 * de cache. Publier une version change ce numéro, donc change la clé, donc périme l'ancienne
 * entrée sans avoir à la supprimer — l'« invalidation par événement lors d'une nouvelle version »
 * du §10, obtenue sans dépendre des tags de cache (que le driver `database` ne fournit pas).
 */
class Referentiel extends Model
{
    protected $table = 'referentiels';

    protected $fillable = [
        'code',
        'pays_code',
        'libelle',
        'role_responsable',
        'version_publiee_numero',
        'publiee_le',
    ];

    protected function casts(): array
    {
        return [
            'version_publiee_numero' => 'integer',
            'publiee_le'             => 'datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ReferentielVersion::class, 'referentiel_id');
    }

    /** La version en vigueur, ou `null` si le référentiel n'a jamais été publié. */
    public function versionPubliee(): ?ReferentielVersion
    {
        if ($this->version_publiee_numero === null) {
            return null;
        }

        return $this->versions()
            ->where('statut', ReferentielVersion::PUBLIEE)
            ->first();
    }

    /** La proposition en cours, s'il y en a une (au plus une, garantie par `verrou_unicite`). */
    public function propositionEnCours(): ?ReferentielVersion
    {
        return $this->versions()->where('statut', ReferentielVersion::PROPOSITION)->first();
    }

    public function estPublie(): bool
    {
        return $this->version_publiee_numero !== null;
    }
}
