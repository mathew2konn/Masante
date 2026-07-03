<?php

namespace App\Http\Requests;

use App\Models\DocumentMedical;
use App\Models\MembreFamille;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * F2.10 — Import d'un document médical (multipart).
 *
 * `authorize()` délègue au cloisonnement du dossier (MembreFamillePolicy `update`) : seul le
 * propriétaire du compte du membre peut importer (anti-IDOR, §4.3). `fichier` est validé sur le
 * MIME RÉEL (règle `mimetypes:`, finfo) + taille, jamais sur l'extension déclarée.
 *
 * `membre_id`, `uploaded_by_user_id`, `statut_antivirus`, `hash_sha256` ne sont PAS acceptés du
 * client : ils sont déterminés côté serveur.
 */
class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $membre = $this->route('membre');

        return $membre instanceof MembreFamille
            && $this->user()?->can('update', $membre);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fichier' => [
                'required',
                'file',
                'max:'.(int) config('masante.upload.max_ko'),
                'mimetypes:'.implode(',', config('masante.upload.mimetypes')),
            ],
            'categorie'     => ['required', Rule::in(DocumentMedical::CATEGORIES)],
            'titre'         => ['nullable', 'string', 'max:200'],
            'date_document' => ['nullable', 'date', 'before_or_equal:today'],
            'source'        => ['sometimes', Rule::in(DocumentMedical::SOURCES)],
            'triage_id'     => ['nullable', 'integer', 'exists:triages,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'fichier.mimetypes' => 'Type de fichier non autorisé.',
            'fichier.max'       => 'Fichier trop volumineux.',
        ];
    }
}
