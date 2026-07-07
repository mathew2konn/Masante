<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Création d'un membre de la famille (F2.1). La règle métier « max 15 membres par compte »
 * (F2.2, révisée par modification.txt) est vérifiée ici, sur le compte authentifié, et renvoyée
 * en erreur 422 lisible.
 *
 * `matricule_ivs`, `medecin_referent_id` et `user_id` ne sont PAS acceptés du client :
 * ils sont attribués/contrôlés côté serveur.
 */
class StoreMembreRequest extends FormRequest
{
    /** Plafond de membres par compte (F2.2, révisé de 5 à 15 par modification.txt). */
    public const MAX_MEMBRES = 15;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nom'            => ['required', 'string', 'max:100'],
            'prenom'         => ['required', 'string', 'max:100'],
            'date_naissance' => ['required', 'date', 'before_or_equal:today'],
            'sexe'           => ['required', 'in:M,F'],
            'groupe_sanguin' => ['nullable', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],
            // `photo_url` n'est PAS accepté du client : la photo se gère via l'endpoint dédié (chemin interne serveur).
            'cmu_numero'     => ['nullable', 'string', 'max:50'],
            'cmu_statut'     => ['nullable', 'in:actif,expire,non_inscrit'],
            'cmu_validite'   => ['nullable', 'date'],
        ];
    }

    /** Vérifie le plafond de membres après la validation des champs. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->user()->membresFamille()->count() >= self::MAX_MEMBRES) {
                $validator->errors()->add(
                    'membre',
                    'Limite atteinte : un compte ne peut pas dépasser '.self::MAX_MEMBRES.' membres.',
                );
            }
        });
    }
}
