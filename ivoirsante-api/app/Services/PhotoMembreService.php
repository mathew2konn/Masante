<?php

namespace App\Services;

use App\Models\MembreFamille;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Profil — Stockage sécurisé de la photo de profil d'un membre.
 *
 * Même chaîne de sécurité que les documents (F2.10) : blob chiffré au repos (AES-256, APP_KEY),
 * nom de stockage UUID (anti path-traversal), disque privé `avatars` hors `public/`. La photo
 * n'est jamais servie directement : uniquement via le contrôleur, déchiffrée, après contrôle de Policy.
 * Pas d'antivirus (image prise/choisie par l'utilisateur, affichée à lui seul) — cf. RAPPORT.md.
 */
class PhotoMembreService
{
    public const DISK = 'avatars';

    /** Remplace la photo du membre : supprime l'ancien blob puis écrit le nouveau (chiffré). */
    public function store(UploadedFile $fichier, MembreFamille $membre): void
    {
        $this->supprimerBlob($membre->photo_url);

        $chemin = $membre->id.'/'.Str::uuid()->toString().'.enc';
        Storage::disk(self::DISK)->put($chemin, Crypt::encryptString(file_get_contents($fichier->getRealPath())));

        $membre->forceFill(['photo_url' => $chemin])->save();
    }

    /**
     * Contenu clair + MIME réel (finfo sur les octets déchiffrés), ou null si pas de photo.
     *
     * @return array{contenu:string, mime:string}|null
     */
    public function retrieve(MembreFamille $membre): ?array
    {
        if ($membre->photo_url === null) {
            return null;
        }

        $contenu = Crypt::decryptString(Storage::disk(self::DISK)->get($membre->photo_url));

        return [
            'contenu' => $contenu,
            'mime'    => (new \finfo(FILEINFO_MIME_TYPE))->buffer($contenu) ?: 'application/octet-stream',
        ];
    }

    /** Supprime la photo (blob + colonne). */
    public function delete(MembreFamille $membre): void
    {
        $this->supprimerBlob($membre->photo_url);
        $membre->forceFill(['photo_url' => null])->save();
    }

    private function supprimerBlob(?string $chemin): void
    {
        if ($chemin !== null && Storage::disk(self::DISK)->exists($chemin)) {
            Storage::disk(self::DISK)->delete($chemin);
        }
    }
}
