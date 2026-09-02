<?php

namespace App\Services\Professionnel;

use App\Models\Medecin;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Photo de profil d'un médecin de l'annuaire (B1-b / D5).
 *
 * Patron ALLÉGÉ de `ImagesEtablissement` (P6.4c) : UNE seule photo par praticien, pas une
 * galerie — pas de table séparée, pas de quota, pas de catégorie. L'habilitation N'EST PAS
 * revérifiée ici : elle l'est déjà par le groupe de routes (`permission:medecin.manage`) et par
 * `Portail\MedecinController::fichePossedee()` (cloisonnement par établissement), exactement
 * comme pour le reste de la fiche du praticien — la dupliquer ici créerait deux endroits où elle
 * pourrait diverger.
 *
 * HORS PROJECTION GOUVERNÉE (P6.5a) : même famille que biographie/tarif/contacts — une photo
 * n'engage aucune autorité nationale, à la différence du numéro d'ordre ou de l'autorisation
 * d'exercer.
 */
class PhotoMedecin
{
    public const DISK = 'medecins';

    /** Extension de stockage pour chaque MIME accepté. Le client n'a pas voix au chapitre. */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** Dépose (ou remplace) la photo. L'ancienne, s'il y en avait une, est supprimée du disque. */
    public function deposer(UploadedFile $fichier, Medecin $medecin): Medecin
    {
        [$contenu, $mime] = $this->lireEtControler($fichier);

        if ($medecin->photo_uuid !== null) {
            $this->supprimerFichier($medecin);
        }

        $uuid = Str::uuid()->toString();
        $chemin = $uuid.'.'.self::EXTENSIONS[$mime];
        Storage::disk(self::DISK)->put($chemin, $contenu);

        $medecin->forceFill([
            'photo_uuid' => $uuid,
            'photo_mime' => $mime,
            'photo_empreinte_sha256' => hash('sha256', $contenu),
        ])->save();

        return $medecin;
    }

    /** Retire la photo (blob + colonnes). Silencieux si le praticien n'en a pas. */
    public function supprimer(Medecin $medecin): void
    {
        if ($medecin->photo_uuid === null) {
            return;
        }

        $this->supprimerFichier($medecin);
        $medecin->forceFill([
            'photo_uuid' => null,
            'photo_mime' => null,
            'photo_empreinte_sha256' => null,
        ])->save();
    }

    /** Octets bruts, pour la diffusion. */
    public function contenu(Medecin $medecin): string
    {
        return Storage::disk(self::DISK)->get($this->chemin($medecin));
    }

    private function chemin(Medecin $medecin): string
    {
        return $medecin->photo_uuid.'.'.self::EXTENSIONS[$medecin->photo_mime];
    }

    private function supprimerFichier(Medecin $medecin): void
    {
        $chemin = $this->chemin($medecin);
        if (Storage::disk(self::DISK)->exists($chemin)) {
            Storage::disk(self::DISK)->delete($chemin);
        }
    }

    /**
     * Lit le fichier, en établit la nature, et refuse tout ce qui n'est pas une vraie image.
     * Même double crible que `ImagesEtablissement` (P6.4c) : MIME établi sur les OCTETS (jamais
     * l'extension déclarée), puis dimensions réellement positives — un PNG à en-tête nul passe le
     * premier crible sans le second (défaut réel trouvé par un vecteur de test en P6.4c).
     *
     * @return array{0:string, 1:string}
     */
    private function lireEtControler(UploadedFile $fichier): array
    {
        $maxOctets = (int) config('masante.medecin_photo.max_ko') * 1024;
        $autorises = (array) config('masante.medecin_photo.mimetypes');

        abort_if(
            $fichier->getSize() > $maxOctets,
            422,
            'Photo trop lourde ('.round($fichier->getSize() / 1024).' Ko) : le maximum est de '
                .config('masante.medecin_photo.max_ko').' Ko.',
        );

        $contenu = (string) file_get_contents($fichier->getRealPath());

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contenu) ?: 'application/octet-stream';

        abort_unless(
            in_array($mime, $autorises, true) && isset(self::EXTENSIONS[$mime]),
            422,
            "Format « {$mime} » refusé. Formats acceptés : ".implode(', ', $autorises).'.',
        );

        $taille = @getimagesizefromstring($contenu);

        abort_if(
            $taille === false || $taille[0] <= 0 || $taille[1] <= 0,
            422,
            'Ce fichier porte bien un en-tête d\'image mais n\'en est pas une : dimensions illisibles ou nulles.',
        );

        return [$contenu, $mime];
    }
}
