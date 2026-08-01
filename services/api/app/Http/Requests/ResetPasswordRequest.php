<?php

namespace App\Http\Requests;

use App\Rules\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Étape 3 « Mot de passe oublié » : définition du nouveau mot de passe via le jeton intermédiaire.
 * Applique la politique de mot de passe unique du projet.
 */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // endpoint public (protégé par le jeton à usage unique).
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reset_token' => ['required', 'string'],
            'password'    => ['required', 'confirmed', ...PasswordPolicy::regles()],
        ];
    }
}
