<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validation de l'inscription (doc Identification §4.1).
 * Téléphone = identifiant principal (format CI +225 + 10 chiffres) ; e-mail optionnel.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // endpoint public.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'telephone' => ['required', 'string', 'regex:/^\+225[0-9]{10}$/', 'unique:users,telephone'],
            'nom'       => ['required', 'string', 'max:100'],
            'prenom'    => ['required', 'string', 'max:100'],
            // Mot de passe robuste (§3.4 Sécurité) : ≥8, lettres + chiffres, non compromis (HIBP).
            'password'  => ['required', 'confirmed', Password::min(8)->letters()->numbers()->uncompromised()],
            'email'     => ['nullable', 'email', 'max:255', 'unique:users,email'],
        ];
    }

    public function messages(): array
    {
        return [
            'telephone.regex'  => 'Le numéro doit être au format +225 suivi de 10 chiffres.',
            'telephone.unique' => 'Ce numéro est déjà associé à un compte.',
        ];
    }
}
