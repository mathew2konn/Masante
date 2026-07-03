<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contact d'urgence (personne à prévenir) rattaché à un membre (F2.11). Alimente la carte vitale
 * d'urgence (Module 5) et le mode « bris de glace » (note Continuité d'accès).
 *
 * Modèle « exactement 2 contacts » (modification.txt) : le 1er créé est le contact PRINCIPAL
 * (`est_principal = true`), le 2e le SECONDAIRE. Le rôle découle de l'ordre de création — il n'est
 * jamais choisi par le client — et le plafond de 2 est appliqué au contrôleur.
 */
class ContactUrgence extends Model
{
    protected $table = 'contacts_urgence';

    protected $fillable = [
        'nom',
        'lien_parente',
        'telephone',
        'telephone_secondaire',
        'est_principal',
    ];

    protected function casts(): array
    {
        return [
            'est_principal' => 'boolean',
        ];
    }

    /**
     * Invariants du couple principal/secondaire, garantis quel que soit le point d'entrée :
     *  - `saved`   : au plus UN contact principal par membre (les autres repassent à false) ;
     *  - `deleted` : si le principal est supprimé, le contact restant est PROMU principal
     *                (le membre n'a jamais un secondaire orphelin sans principal).
     * Les `update`/`create` de masse ne déclenchent pas d'événement enfant → aucune récursion.
     */
    protected static function booted(): void
    {
        static::saved(function (ContactUrgence $contact): void {
            if ($contact->est_principal) {
                static::query()
                    ->where('membre_id', $contact->membre_id)
                    ->whereKeyNot($contact->getKey())
                    ->update(['est_principal' => false]);
            }
        });

        static::deleted(function (ContactUrgence $contact): void {
            if ($contact->est_principal) {
                static::query()
                    ->where('membre_id', $contact->membre_id)
                    ->orderBy('id')
                    ->first()
                    ?->update(['est_principal' => true]);
            }
        });
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(MembreFamille::class, 'membre_id');
    }
}
