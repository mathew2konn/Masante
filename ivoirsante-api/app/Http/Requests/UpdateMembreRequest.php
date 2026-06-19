<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mise à jour d'un membre (F2.1). Tous les champs sont optionnels (`sometimes`) pour
 * autoriser les modifications partielles. L'appartenance est contrôlée par la Policy
 * dans le contrôleur ; ici, seule la forme des données est validée.
 */
class UpdateMembreRequest extends FormRequest
{
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
            'nom'            => ['sometimes', 'required', 'string', 'max:100'],
            'prenom'         => ['sometimes', 'required', 'string', 'max:100'],
            'date_naissance' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'sexe'           => ['sometimes', 'required', 'in:M,F'],
            'groupe_sanguin' => ['nullable', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],
            'photo_url'      => ['nullable', 'url', 'max:500'],
            'cmu_numero'     => ['nullable', 'string', 'max:50'],
            'cmu_statut'     => ['nullable', 'in:actif,expire,non_inscrit'],
            'cmu_validite'   => ['nullable', 'date'],
        ];
    }
}
