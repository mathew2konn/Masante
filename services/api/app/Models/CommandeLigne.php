<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne de commande (B3-d). L'identité du produit est FIGÉE à la commande (`medicament_code`,
 * `nom`, `dci`, `dosage`, `ordonnance_requise`) — renommer le produit au référentiel après coup ne
 * change pas la ligne (patron B3-a/B3-c/P6.6b).
 *
 * Toutes ces valeurs sont posées par le SERVEUR depuis le référentiel réel au moment où la
 * commande est passée (`ServiceCommande::passer()`) — jamais déclarées par le client.
 *
 * `medicament_id` DOIT être `$fillable` malgré ce qui précède : le chemin d'écriture
 * (`$commande->lignes()->create([...])`) est une ASSIGNATION DE MASSE, donc une clé absente
 * d'ici serait écartée EN SILENCE — piège relevé par P6.7b/B2-b/B3-b, et TROUVÉ ICI EN DIRECT AU
 * G2 (pas par un test) : sans elle, chaque ligne naissait avec `medicament_id = NULL`, ce qui
 * rendait `UNIQUE(commande_id, medicament_id)` inopérant (MySQL autorise plusieurs NULL sous un
 * index unique) et faisait échouer en silence `sortirVenteLibre()` (`ServiceTraitementCommande`),
 * qui se tait justement quand `medicament_id` est nul. Invisible en test parce qu'aucun vecteur
 * n'asserte la colonne elle-même, et que SQLite tolère la même pluralité de NULL que MySQL.
 */
class CommandeLigne extends Model
{
    protected $table = 'commande_lignes';

    protected $fillable = [
        'medicament_id',
        'medicament_code',
        'nom',
        'dci',
        'dosage',
        'ordonnance_requise',
        'ordonnance_ligne_id',
        'quantite',
        'prix_unitaire_indicatif_cfa',
    ];

    protected function casts(): array
    {
        return [
            'ordonnance_requise' => 'boolean',
        ];
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class, 'commande_id');
    }

    public function medicament(): BelongsTo
    {
        return $this->belongsTo(Medicament::class, 'medicament_id');
    }

    public function ordonnanceLigne(): BelongsTo
    {
        return $this->belongsTo(OrdonnanceLigne::class, 'ordonnance_ligne_id');
    }
}
