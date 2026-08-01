<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePhotoRequest;
use App\Models\MembreFamille;
use App\Services\PhotoMembreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Profil — Photo de profil d'un membre (upload / lecture / suppression).
 *
 * Réservé au propriétaire (Policy, anti-IDOR §4.3). La photo est chiffrée au repos et n'est
 * servie que déchiffrée par `show`, jamais via une URL publique. Le chemin de stockage interne
 * (`photo_url`) reste caché ; le client s'appuie sur le booléen `a_photo`.
 */
class PhotoMembreController extends Controller
{
    public function __construct(private readonly PhotoMembreService $photos)
    {
    }

    /** Upload/remplacement de la photo (multipart `photo`). */
    public function store(StorePhotoRequest $request, MembreFamille $membre): JsonResponse
    {
        $this->photos->store($request->file('photo'), $membre);

        return response()->json(['membre' => $membre->fresh()]);
    }

    /** Sert la photo déchiffrée (content-type réel). 404 si le membre n'a pas de photo. */
    public function show(MembreFamille $membre): Response
    {
        $this->authorize('view', $membre);

        $photo = $this->photos->retrieve($membre);
        abort_if($photo === null, 404, 'Aucune photo.');

        return response($photo['contenu'])
            ->header('Content-Type', $photo['mime'])
            ->header('Cache-Control', 'private, max-age=0, no-store');
    }

    /** Supprime la photo (blob + colonne). */
    public function destroy(MembreFamille $membre): JsonResponse
    {
        $this->authorize('update', $membre);

        $this->photos->delete($membre);

        return response()->json(['membre' => $membre->fresh()]);
    }
}
