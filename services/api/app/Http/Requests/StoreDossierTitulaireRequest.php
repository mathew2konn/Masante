<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * P6.1 — Complétion du profil santé du titulaire du compte (ADR-021 §2.1, variante (c)).
 *
 * Ne demande QUE ce qui manque. `nom` et `prenom` ne sont volontairement PAS acceptés du client :
 * ils sont repris du compte (`users`). Laisser le client les fournir permettrait de créer un
 * dossier de santé sous une identité différente de celle du compte — exactement la fragmentation
 * que le MPI (CDC_09 §2) doit combattre.
 */
class StoreDossierTitulaireRequest extends FormRequest
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
            'date_naissance' => ['required', 'date', 'before_or_equal:today'],
            'sexe'           => ['required', 'in:M,F'],
            'groupe_sanguin' => ['nullable', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_naissance.required'        => 'La date de naissance est obligatoire.',
            'date_naissance.before_or_equal' => 'La date de naissance ne peut pas être dans le futur.',
            'sexe.required'                  => 'Le sexe est obligatoire.',
            'sexe.in'                        => 'Le sexe doit être « M » ou « F ».',
        ];
    }
}
