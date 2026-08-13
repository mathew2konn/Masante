<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une image d'établissement (P6.4c).
 *
 * `chemin` est CACHÉ à la sérialisation : c'est un détail de stockage, et l'exposer inviterait un
 * client à le manipuler. Le contrat public est `{ id, categorie, url }`, où `url` est **relative** —
 * l'API est atteinte tantôt en localhost, tantôt par Ngrok, et une URL absolue mise en cache par le
 * mobile deviendrait fausse au prochain redémarrage du tunnel (constat H3 du G0).
 */
class EtablissementImage extends Model
{
    protected $table = 'etablissement_images';

    protected $fillable = [
        'structure_id', 'categorie_code', 'chemin', 'mime',
        'taille_octets', 'empreinte', 'largeur', 'hauteur', 'ordre', 'depose_par',
    ];

    /**
     * Ne sortent jamais :
     *
     *  · `chemin` — détail de stockage ; l'exposer inviterait un client à le manipuler ;
     *  · `depose_par` — la diffusion des fiches est PUBLIQUE. Savoir quel compte a mis en ligne la
     *    photo d'un bloc opératoire ne regarde personne au-dehors ; l'information reste en base
     *    pour répondre à « qui a publié ceci ? », elle ne devient pas une donnée d'annuaire.
     *
     * L'empreinte, elle, est exposée : elle est déjà servie comme `ETag` à chaque téléchargement,
     * la cacher dans le JSON ne protégerait rien.
     */
    protected $hidden = ['chemin', 'depose_par'];

    protected $appends = ['url'];

    protected function casts(): array
    {
        return [
            'taille_octets' => 'integer',
            'largeur'       => 'integer',
            'hauteur'       => 'integer',
            'ordre'         => 'integer',
        ];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(StructureSanitaire::class, 'structure_id');
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(CategorieImageEtablissement::class, 'categorie_code', 'code');
    }

    /** Chemin d'accès public, RELATIF (voir l'entête de classe). */
    public function getUrlAttribute(): string
    {
        return "/api/v1/structures/{$this->structure_id}/images/{$this->id}";
    }
}
