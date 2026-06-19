<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation de la connexion par téléphone + mot de passe (doc Identification §4.3).
 */
class LoginRequest extends FormRequest
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
            'telephone' => ['required', 'string', 'regex:/^\+225[0-9]{10}$/'],
            'password'  => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'telephone.regex' => 'Le numéro doit être au format +225 suivi de 10 chiffres.',
        ];
    }
}
