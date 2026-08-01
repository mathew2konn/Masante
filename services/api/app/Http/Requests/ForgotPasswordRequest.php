<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Étape 1 « Mot de passe oublié » : demande de réinitialisation par numéro de téléphone.
 * La réponse de l'API est identique que le numéro existe ou non (anti-énumération).
 */
class ForgotPasswordRequest extends FormRequest
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
        ];
    }

    public function messages(): array
    {
        return [
            'telephone.regex' => 'Le numéro doit être au format +225 suivi de 10 chiffres.',
        ];
    }
}
