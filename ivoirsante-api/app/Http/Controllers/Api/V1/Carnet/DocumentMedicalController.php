<?php

namespace App\Http\Controllers\Api\V1\Carnet;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentRequest;
use App\Jobs\ScanDocumentJob;
use App\Models\MembreFamille;
use App\Services\DocumentStorageService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * F2.10 — Documents médicaux importés d'un membre.
 *
 * Sécurité (§4.3, RAPPORT.md §F2.10) :
 *  - autorisation sur le MEMBRE parent (MembreFamillePolicy) — anti-IDOR ;
 *  - tout document est requêté À TRAVERS la relation du membre (un id d'un autre membre → 404) ;
 *  - blob chiffré au repos (service) ; `uploaded_by_user_id` fixé serveur (audit loi 2013-450) ;
 *  - téléchargement verrouillé tant que `statut_antivirus !== 'sain'` (423).
 *
 * Pas de route `update` : un document importé est immuable (intégrité) ; corriger = ré-importer.
 */
class DocumentMedicalController extends Controller
{
    public function __construct(private readonly DocumentStorageService $stockage) {}

    public function index(MembreFamille $membre): JsonResponse
    {
        $this->authorize('view', $membre);

        return response()->json([
            'items' => $membre->documentsMedicaux()->latest()->get(),
        ]);
    }

    public function store(StoreDocumentRequest $request, MembreFamille $membre): JsonResponse
    {
        // Autorisation déjà vérifiée dans StoreDocumentRequest::authorize().
        $fichier = $request->file('fichier');
        $meta = $this->stockage->storeEncrypted($fichier, $membre);

        try {
            $document = $membre->documentsMedicaux()->make([
                'categorie'            => $request->validated('categorie'),
                'titre'                => $request->validated('titre') ?: $fichier->getClientOriginalName(),
                'nom_fichier_original' => $fichier->getClientOriginalName(),
                'fichier_url'          => $meta['chemin'],
                'mime_type'            => $meta['mime'],
                'extension'            => $meta['extension'],
                'taille_octets'        => $meta['taille'],
                'hash_sha256'          => $meta['hash'],
                'source'               => $request->validated('source', 'patient'),
                'date_document'        => $request->validated('date_document'),
                'triage_id'            => $request->validated('triage_id'),
                // statut_antivirus : défaut 'en_attente' (verrou), levé par ScanDocumentJob.
            ]);
            $document->uploaded_by_user_id = $request->user()->id; // hors $fillable : audit serveur
            $document->save();
        } catch (\Throwable $e) {
            $this->stockage->deleteBlob($meta['chemin']); // pas d'orphelin chiffré sur le disque
            throw $e;
        }

        // En prod : scan asynchrone (worker + ClamAV). En dev (antivirus off) : stub instantané → 'sain'.
        if (config('masante.antivirus.enabled')) {
            ScanDocumentJob::dispatch($document->id);
        } else {
            ScanDocumentJob::dispatchSync($document->id);
        }

        return response()->json(['item' => $document->fresh()], 201);
    }

    /** Téléchargement du fichier déchiffré (uniquement si `sain`). */
    public function show(MembreFamille $membre, int $id): StreamedResponse
    {
        $this->authorize('view', $membre);

        $document = $membre->documentsMedicaux()->findOrFail($id); // scopé → anti-IDOR

        abort_unless(
            $document->estTelechargeable(),
            423, // Locked : document non encore analysé ou infecté
            'Document indisponible : analyse antivirus en cours ou fichier rejeté.',
        );

        $contenu = $this->stockage->retrieveDecrypted($document);

        return response()->streamDownload(
            fn () => print($contenu),
            $document->nom_fichier_original,
            ['Content-Type' => $document->mime_type],
        );
    }

    /** Soft-delete : rétention médicale (le blob chiffré est conservé, pas de suppression dure). */
    public function destroy(MembreFamille $membre, int $id): JsonResponse
    {
        $this->authorize('update', $membre);

        $membre->documentsMedicaux()->findOrFail($id)->delete();

        return response()->json(['message' => 'Document supprimé.']);
    }
}
