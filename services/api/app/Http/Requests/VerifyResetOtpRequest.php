<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Étape 2 « Mot de passe oublié » : vérification de l'OTP + preuve durcie (note Securite_2, chap. 4).
 * `date_naissance` est la preuve du palier « base » ; elle reste `nullable` ici pour renvoyer une
 * erreur métier explicite (422) via le service plutôt qu'une 422 de validation générique.
 */
class VerifyResetOtpRequest extends FormRequest
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
            'telephone'     => ['required', 'string', 'regex:/^\+225[0-9]{10}$/'],
            'code'          => ['required', 'string', 'digits:6'],
            'date_naissance' => ['nullable', 'date'],
        ];
    }
}
