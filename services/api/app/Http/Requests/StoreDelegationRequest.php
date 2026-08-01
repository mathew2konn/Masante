<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Invitation d'un délégué par numéro de téléphone (Note_Continuite chap. 4.2).
 * L'autorisation (le membre appartient au titulaire) est vérifiée dans le contrôleur via la Policy.
 */
class StoreDelegationRequest extends FormRequest
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
