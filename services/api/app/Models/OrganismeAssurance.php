<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P6.8d — Un organisme d'assurance agréé du référentiel national (CDC_09 §8).
 *
 * `code` est VOLONTAIREMENT ABSENT de `$fillable` — précédent constant depuis P6.4a : un client ne
 * choisit pas un code national, il le reçoit. Seul
 * {@see App\Services\Assurance\AttributeurCodeOrganisme} l'écrit.
 *
 * `numero_agrement` en est absent pour une autre raison : il désigne un acte administratif délivré
 * par une autorité. Le laisser remplir au formulaire par n'importe quel agent habilité reviendrait à
 * laisser SAISIR un agrément plutôt qu'à l'enregistrer — et le contenu livré n'en porte aucun, ce
 * qui est dit plutôt que masqué.
 */
class OrganismeAssurance extends Model
{
    protected $table = 'organismes_assurance';

    protected $fillable = [
        'pays_code',
        'nom',
        'sigle',
        'type',
        'agrement_statut',
        'agrement_debut',
        'agrement_fin',
        'source',
        'source_detail',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'agrement_debut' => 'date',
            'agrement_fin'   => 'date',
            'actif'          => 'boolean',
        ];
    }

    /** Les couvertures citoyennes qui le désignent. Sert à refuser une suppression, pas à compter. */
    public function couvertures(): HasMany
    {
        return $this->hasMany(CouvertureMembre::class, 'organisme_assurance_id');
    }

    public function scopeActif(Builder $query): Builder
    {
        return $query->where('actif', true);
    }

    /**
     * L'organisme actif portant ce code national dans ce pays, ou `null`.
     *
     * SEUL point d'entrée par code du module — précédents `SpecialiteMedicale::parCode`,
     * `Vaccin::parCode` et `Maladie::parCode` : si chaque contrôleur composait sa requête, l'un
     * d'eux oublierait un jour le filtre `actif` et laisserait rattacher une couverture à un
     * organisme retiré du registre.
     */
    public static function parCode(string $code, ?string $pays = null): ?self
    {
        return self::query()
            ->actif()
            ->where('pays_code', strtoupper($pays ?? (string) config('referentiels.pays_defaut', 'CI')))
            ->where('code', $code)
            ->first();
    }

    /** Ce que l'écran affiche : « CNAM » plutôt que « Caisse Nationale d'Assurance Maladie ». */
    public function getNomCourtAttribute(): string
    {
        return $this->sigle ?: $this->nom;
    }

    /** Cette entrée vient-elle du jeu de démonstration ? Le témoin du remplacement (motif P6.7a). */
    public function estDeDemonstration(): bool
    {
        return $this->source === 'demonstration';
    }
}
