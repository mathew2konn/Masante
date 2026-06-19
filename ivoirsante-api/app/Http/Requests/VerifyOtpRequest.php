<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation de la vérification OTP (doc Identification §4.1, étape 3-4).
 */
class VerifyOtpRequest extends FormRequest
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
            'code'      => ['required', 'string', 'digits:6'],
            'but'       => ['sometimes', 'in:inscription,connexion,recuperation'],
        ];
    }

    public function messages(): array
    {
        return [
            'telephone.regex' => 'Le numéro doit être au format +225 suivi de 10 chiffres.',
            'code.digits'     => 'Le code de vérification comporte 6 chiffres.',
        ];
    }
}
