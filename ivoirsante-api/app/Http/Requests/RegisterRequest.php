<?php

namespace App\Http\Requests;

use App\Rules\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation de l'inscription (doc Identification §4.1 + modification.txt §1).
 * Téléphone = identifiant principal (format CI +225 + 10 chiffres). Inscription MINIMALE :
 * nom, prénom, téléphone, mot de passe. L'e-mail et les autres données (date de naissance,
 * groupe sanguin, sexe, photo…) sont renseignés plus tard depuis le profil, pas ici.
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
            // Politique de mot de passe unique du projet (barre de force côté mobile alignée dessus).
            'password'  => ['required', 'confirmed', ...PasswordPolicy::regles()],
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
