<?php

namespace App\Http\Requests;

use App\Rules\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Changement volontaire de mot de passe par l'utilisateur connecté (modification.txt, dernier §).
 * La connaissance de l'ancien mot de passe fait office de preuve : pas d'OTP ici.
 */
class ChangePasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string'],
            // `confirmed` + politique unique ; `different` du courant pour éviter un no-op.
            'password'         => ['required', 'confirmed', 'different:current_password', ...PasswordPolicy::regles()],
        ];
    }
}
