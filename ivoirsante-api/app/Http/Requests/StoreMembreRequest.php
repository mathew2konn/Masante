<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Création d'un membre de la famille (F2.1). La règle métier « max 5 membres par compte »
 * (F2.2) est vérifiée ici, sur le compte authentifié, et renvoyée en erreur 422 lisible.
 *
 * `matricule_ivs`, `medecin_referent_id` et `user_id` ne sont PAS acceptés du client :
 * ils sont attribués/contrôlés côté serveur.
 */
class StoreMembreRequest extends FormRequest
{
    /** Plafond de membres par compte (CdC F2.2). */
    public const MAX_MEMBRES = 5;

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
            'photo_url'      => ['nullable', 'url', 'max:500'],
            'cmu_numero'     => ['nullable', 'string', 'max:50'],
            'cmu_statut'     => ['nullable', 'in:actif,expire,non_inscrit'],
            'cmu_validite'   => ['nullable', 'date'],
        ];
    }

    /** Vérifie le plafond de 5 membres après la validation des champs. */
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
