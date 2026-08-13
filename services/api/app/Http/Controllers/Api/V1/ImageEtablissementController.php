<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EtablissementImage;
use App\Models\StructureSanitaire;
use App\Services\Etablissement\ImagesEtablissement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Images des établissements (P6.4c).
 *
 * FRONTIÈRE : ce contrôleur ne décide de rien. Qui a le droit, quelles catégories existent, combien
 * d'images sont permises, ce qu'est une vraie image — tout est dans `ImagesEtablissement`. Ici on
 * valide la forme de la requête et on transmet.
 *
 * `show` est PUBLIC, comme le reste de l'annuaire : une vitrine d'hôpital est faite pour être vue,
 * et l'exiger authentifiée empêcherait un citoyen de reconnaître l'établissement avant sa première
 * connexion. `store` et `destroy` sont sous Sanctum, puis re-gardés dans le service.
 */
class ImageEtablissementController extends Controller
{
    public function __construct(private readonly ImagesEtablissement $images) {}

    /** POST /v1/structures/{structure}/images — dépôt (multipart `image` + `categorie`). */
    public function store(Request $request, StructureSanitaire $structure): JsonResponse
    {
        $valide = $request->validate([
            // `file` et non `image` : la règle `image` de Laravel se fie à l'extension déclarée.
            // La nature réelle du fichier est établie dans le service, sur les octets.
            'image'     => ['required', 'file'],
            'categorie' => ['required', 'string', 'max:40'],
        ]);

        $image = $this->images->deposer(
            $request->file('image'),
            $structure,
            $valide['categorie'],
            $request->user(),
        );

        return response()->json(['image' => $image], 201);
    }

    /**
     * GET /v1/structures/{structure}/images/{image} — diffusion publique.
     *
     * L'`ETag` est l'empreinte SHA-256 déjà calculée au dépôt : la revalidation ne coûte rien, et
     * une image remplacée change forcément d'identifiant, donc d'URL. Le cache est déclaré `public`
     * — à l'inverse du `no-store` des photos de profil, qui, elles, sont privées.
     */
    public function show(Request $request, StructureSanitaire $structure, EtablissementImage $image): Response
    {
        // Anti-IDOR de forme : l'image doit appartenir à l'établissement de l'URL, sans quoi deux
        // chemins désigneraient la même ressource et les caches divergeraient.
        abort_unless($image->structure_id === $structure->id, 404);

        $etag = '"'.$image->empreinte.'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304);
        }

        return response($this->images->contenu($image))
            ->header('Content-Type', $image->mime)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /** DELETE /v1/structures/{structure}/images/{image}. */
    public function destroy(Request $request, StructureSanitaire $structure, EtablissementImage $image): JsonResponse
    {
        abort_unless($image->structure_id === $structure->id, 404);

        $this->images->supprimer($image, $request->user());

        return response()->json(['supprimee' => true]);
    }
}
