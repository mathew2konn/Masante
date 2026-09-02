<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Medecin;
use App\Services\Professionnel\PhotoMedecin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Diffusion PUBLIQUE de la photo d'un médecin (B1-b / D5) — même patron que
 * `ImageEtablissementController` (P6.4c) : l'`ETag` est l'empreinte SHA-256 déjà calculée au
 * dépôt, la revalidation ne coûte rien. Publique comme le reste de l'annuaire (nom, spécialité,
 * établissement) : une photo de profil professionnel n'est pas une donnée sensible.
 *
 * Le dépôt/retrait, eux, restent réservés au gestionnaire de l'établissement (voir
 * `Portail\MedecinController`, gardé par `permission:medecin.manage` + cloisonnement.
 */
class PhotoMedecinController extends Controller
{
    public function __construct(private readonly PhotoMedecin $photos) {}

    /** GET /v1/medecins/{medecin}/photo. */
    public function show(Request $request, Medecin $medecin): Response
    {
        abort_if($medecin->photo_uuid === null, 404, 'Ce praticien n\'a pas de photo.');

        $etag = '"'.$medecin->photo_empreinte_sha256.'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304);
        }

        return response($this->photos->contenu($medecin))
            ->header('Content-Type', $medecin->photo_mime)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
