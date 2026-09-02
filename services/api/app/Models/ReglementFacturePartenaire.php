<?php

namespace App\Models;

use App\Support\MoyenReglement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Un encaissement reçu d'un établissement (facturation partenaire).
 *
 * ═══ POURQUOI CETTE TABLE EXISTE PLUTÔT QU'UN COMPTEUR ═══
 * Noter un encaissement en incrémentant `factures_partenaire.montant_regle` ferait disparaître la
 * date, le moyen et la référence de transaction — c'est-à-dire tout ce qu'on produit le jour où un
 * partenaire conteste. Un règlement est un FAIT DATÉ.
 *
 * ═══ IMMUABILITÉ TOTALE, SANS EXCEPTION D'ÉTAT ═══
 * Contrairement aux factures, il n'y a ici AUCUNE fenêtre de modification : ni « tant que la
 * facture est en brouillon », ni « tant que rien n'est soldé ». La ligne s'écrit une fois et ne
 * bouge plus, quel que soit l'état de quoi que ce soit. Une erreur de saisie ne se rattrape pas en
 * corrigeant la ligne fautive — cela effacerait la trace de l'erreur en même temps que l'erreur —
 * mais par une écriture de sens contraire, mécanisme à spécifier avec le service d'imputation.
 *
 * La clé étrangère porte le RÉSULTAT de l'imputation, pas un choix du payeur : le partenaire ne
 * désigne jamais la facture qu'il règle, l'imputation se fait sur la plus ancienne impayée.
 */
class ReglementFacturePartenaire extends Model
{
    protected $table = 'reglements_facture_partenaire';

    protected $fillable = [
        'facture_partenaire_id',
        'montant',
        'moyen',
        'reference_externe',
        'date_reglement',
        'commentaire',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'integer',
            'moyen' => MoyenReglement::class,
            'date_reglement' => 'datetime',
        ];
    }

    /** @return BelongsTo<FacturePartenaire, $this> */
    public function facturePartenaire(): BelongsTo
    {
        return $this->belongsTo(FacturePartenaire::class);
    }

    protected static function booted(): void
    {
        // Une exception EXPLICITE, jamais un `return false` : un refus silencieux ferait croire à
        // l'appelant que sa correction a été enregistrée (précédent du projet sur les journaux
        // append-only).
        static::updating(function () {
            throw new RuntimeException(
                'Un règlement partenaire est immuable : il ne se modifie jamais, quel que soit '
                .'l\'état de la facture. Une erreur se corrige par une écriture de sens contraire.'
            );
        });

        static::deleting(function () {
            throw new RuntimeException(
                'Un règlement partenaire ne se supprime jamais. Supprimer un encaissement '
                .'effacerait la preuve qu\'il a eu lieu.'
            );
        });
    }
}
