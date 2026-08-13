<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ville couverte par la plateforme (P6.4b).
 *
 * `affiche_communes` mérite un mot : c'est la réponse à « faut-il proposer des filtres de commune
 * pour cette ville ? ». Abidjan oui, Yamoussoukro et Bouaké non. Cette réponse vit ICI, en donnée,
 * et non dans un `if` du mobile — sans quoi ouvrir une quatrième ville subdivisée demanderait un
 * déploiement d'application au lieu d'une ligne en base.
 *
 * Le couple centre + rayon n'est pas une description : c'est ce qui permet au backend de dire
 * « vous êtes à Bouaké ». Voir `LocalisateurVille`.
 */
class Ville extends Model
{
    protected $table = 'villes';

    protected $fillable = [
        'pays_code', 'code', 'nom', 'latitude', 'longitude',
        'rayon_km', 'affiche_communes', 'ordre', 'actif',
    ];

    protected function casts(): array
    {
        return [
            'latitude'         => 'float',
            'longitude'        => 'float',
            'rayon_km'         => 'integer',
            'affiche_communes' => 'boolean',
            'ordre'            => 'integer',
            'actif'            => 'boolean',
        ];
    }

    /** @param Builder<Ville> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('actif', true);
    }

    public function etablissements(): HasMany
    {
        return $this->hasMany(StructureSanitaire::class, 'ville_id');
    }

    /**
     * Les communes de cette ville, DÉRIVÉES des structures qui s'y trouvent (décision V7).
     *
     * Dérivée et non stockée : la liste ne peut pas diverger de la base puisqu'elle en sort. C'est
     * précisément ce qui manquait au mobile, où sept communes vivaient en dur dans un fichier
     * TypeScript — le défaut que P7-D2 avait déjà rencontré sur les libellés des voies d'accès.
     *
     * Contrepartie assumée (limite N3) : `commune` restant un texte libre, une faute de frappe
     * ferait apparaître un filtre fantôme. Promouvoir `commune` en table de référence changerait
     * le contrat `?commune=` de P3, validé G5.
     *
     * @return array<int, string>
     */
    public function communes(): array
    {
        if (! $this->affiche_communes) {
            return [];
        }

        return $this->etablissements()
            ->whereNotNull('commune')
            ->where('commune', '<>', '')
            ->distinct()
            ->orderBy('commune')
            ->pluck('commune')
            ->all();
    }
}
