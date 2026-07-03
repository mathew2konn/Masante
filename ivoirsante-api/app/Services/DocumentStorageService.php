<?php

namespace App\Services;

use App\Models\DocumentMedical;
use App\Models\MembreFamille;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * F2.10 — Stockage sécurisé des documents médicaux.
 *
 * Chaîne de sécurité (RAPPORT.md §F2.10, doc Sécurité) :
 *  - le blob n'est JAMAIS écrit en clair : `Crypt::encryptString` (AES-256, APP_KEY) avant écriture ;
 *  - le nom de stockage est un UUID (anti path-traversal) ; le nom d'origine reste en base, jamais sur le disque ;
 *  - le disque `documents` est privé (hors `public/`), non servi directement par le serveur web ;
 *  - le MIME est déterminé côté serveur (finfo), jamais l'extension déclarée par le client ;
 *  - `hash_sha256` du contenu clair sert d'empreinte d'intégrité et de détection de doublon.
 */
class DocumentStorageService
{
    public const DISK = 'documents';

    /**
     * Chiffre le contenu du fichier et l'écrit sous un nom UUID dans le disque privé.
     *
     * @return array{chemin:string, mime:string, extension:string, taille:int, hash:string}
     */
    public function storeEncrypted(UploadedFile $fichier, MembreFamille $membre): array
    {
        $contenu = file_get_contents($fichier->getRealPath());

        $chemin = $membre->id.'/'.Str::uuid()->toString().'.enc';

        Storage::disk(self::DISK)->put($chemin, Crypt::encryptString($contenu));

        return [
            'chemin'    => $chemin,
            'mime'      => $fichier->getMimeType(),                             // finfo (réel)
            'extension' => $fichier->guessExtension() ?: 'bin',                 // dérivée du MIME, jamais du client
            'taille'    => $fichier->getSize(),
            'hash'      => hash('sha256', $contenu),
        ];
    }

    /** Lit et déchiffre le blob d'un document (contenu clair en mémoire, pour streaming). */
    public function retrieveDecrypted(DocumentMedical $document): string
    {
        return Crypt::decryptString(Storage::disk(self::DISK)->get($document->fichier_url));
    }

    /** Supprime physiquement un blob (utilisé pour nettoyer un orphelin, pas au soft-delete métier). */
    public function deleteBlob(string $chemin): void
    {
        Storage::disk(self::DISK)->delete($chemin);
    }
}
