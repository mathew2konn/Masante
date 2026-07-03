<?php

namespace App\Http\Controllers\Api\V1\Carnet;

use App\Models\MembreFamille;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Contacts d'urgence par membre (F2.11) — révision modification.txt (2026-07-03).
 *
 * Règles produit :
 *  - EXACTEMENT 2 contacts par membre (1 principal + 1 secondaire) — plafond appliqué au `store` ;
 *  - le rôle découle de l'ORDRE de création (1er = principal), jamais choisi par le client ;
 *  - les 2 contacts doivent être des personnes DISTINCTES (téléphone différent) ;
 *  - lien de parenté choisi dans une liste fermée (miroir du `select` frontend) ;
 *  - pas d'e-mail (retiré du schéma).
 * Téléphone au format CI (+225 + 10 chiffres), comme l'inscription.
 */
class ContactUrgenceController extends CarnetSectionController
{
    /** Plafond de contacts d'urgence par membre (principal + secondaire). */
    public const MAX_CONTACTS = 2;

    /** Liens de parenté autorisés (miroir exact du select frontend). */
    public const LIENS_PARENTE = [
        'papa', 'maman', 'epouse', 'epoux', 'frere', 'soeur', 'cousin', 'cousine',
        'tante', 'oncle', 'tuteur', 'grand_mere', 'grand_pere', 'ami', 'autre',
    ];

    protected function relation(): string
    {
        return 'contactsUrgence';
    }

    protected function regles(): array
    {
        return [
            'nom'                  => ['required', 'string', 'max:200'],
            'lien_parente'         => ['required', Rule::in(self::LIENS_PARENTE)],
            'telephone'            => ['required', 'string', 'regex:/^\+225[0-9]{10}$/'],
            'telephone_secondaire' => ['nullable', 'string', 'regex:/^\+225[0-9]{10}$/'],
            // `est_principal` n'est PAS accepté du client : il est déduit de l'ordre de création.
        ];
    }

    /**
     * Surcharge : applique le plafond de 2, l'unicité du téléphone entre les 2 contacts, et
     * attribue le rôle par ordre (1er = principal). Le client ne fixe jamais `est_principal`.
     */
    public function store(Request $request, MembreFamille $membre): JsonResponse
    {
        $this->authorize('update', $membre);

        $donnees = $request->validate($this->regles());
        $existants = $membre->contactsUrgence()->get();

        if ($existants->count() >= self::MAX_CONTACTS) {
            throw ValidationException::withMessages([
                'contact' => 'Un membre ne peut avoir que '.self::MAX_CONTACTS
                    .' contacts d\'urgence (un principal et un secondaire).',
            ]);
        }

        if ($existants->pluck('telephone')->contains($donnees['telephone'])) {
            throw ValidationException::withMessages([
                'telephone' => 'Ce numéro est déjà le contact d\'urgence de ce membre : les deux contacts doivent être distincts.',
            ]);
        }

        // Le premier contact créé est le principal, le second le secondaire.
        $donnees['est_principal'] = $existants->isEmpty();

        $contact = $membre->contactsUrgence()->create($donnees);

        return response()->json(['item' => $contact], 201);
    }

    /**
     * Surcharge : à la mise à jour partielle, on interdit qu'un contact reprenne le téléphone de
     * l'AUTRE contact du membre (les deux restent distincts). Le rôle n'est pas modifiable ici.
     */
    public function update(Request $request, MembreFamille $membre, int $id): JsonResponse
    {
        $this->authorize('update', $membre);

        $contact = $membre->contactsUrgence()->findOrFail($id);
        $donnees = $request->validate($this->reglesPour(false));

        if (array_key_exists('telephone', $donnees)
            && $membre->contactsUrgence()
                ->whereKeyNot($contact->getKey())
                ->where('telephone', $donnees['telephone'])
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'telephone' => 'Ce numéro est déjà le contact d\'urgence de ce membre : les deux contacts doivent être distincts.',
            ]);
        }

        $contact->update($donnees);

        return response()->json(['item' => $contact]);
    }
}
