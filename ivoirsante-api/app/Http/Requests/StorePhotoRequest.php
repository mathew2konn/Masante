<?php

namespace App\Http\Requests;

use App\Models\MembreFamille;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Upload de la photo de profil d'un membre (profil enrichi).
 *
 * Autorisation : propriétaire du membre (Policy `update`). Le MIME est validé RÉELLEMENT (finfo,
 * via `mimetypes:`), jamais l'extension déclarée ; taille bornée (l'image est déjà réduite côté client).
 */
class StorePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $membre = $this->route('membre');

        return $membre instanceof MembreFamille && $this->user()?->can('update', $membre);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photo' => [
                'required',
                'file',
                'mimetypes:'.implode(',', config('masante.photo.mimetypes')),
                'max:'.config('masante.photo.max_ko'),
            ],
        ];
    }
}
