<?php

namespace App\Services\Etablissement;

use App\Models\CategorieImageEtablissement;
use App\Models\EtablissementImage;
use App\Models\StructureSanitaire;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Images des établissements (P6.4c) — dépôt, suppression, diffusion.
 *
 * ═══ TOUTES LES GARDES SONT ICI ═══
 *
 * Le contrôleur ne fait que passer le fichier. Cinq contrôles, dont aucun ne rattrape les autres :
 *
 *  1. HABILITATION — permission nationale `etablissement.manage`, OU gestionnaire **de cet
 *     établissement-là** (`users.structure_id`). Vérifiée par `can()` DANS LE SERVICE et non par
 *     le middleware `permission:` de spatie : les routes sont Sanctum et les permissions sont
 *     posées sur le guard `web` — le piège de P4 (`rdv.validate`), déjà retombé en P6.3.
 *  2. CATÉGORIE — doit exister et être active. La liste vit en base (décision I4), le code ne
 *     l'énumère nulle part.
 *  3. QUOTA — `max_par_etablissement`, lu en donnée, vérifié **sous verrou pessimiste** : deux
 *     dépôts simultanés du logo doivent aboutir à un refus, pas à deux logos.
 *  4. NATURE DU FICHIER — MIME déterminé par `finfo` sur les octets, jamais l'extension déclarée ;
 *     liste blanche ; et `getimagesize` pour exiger une VRAIE image (un fichier maquillé en JPEG
 *     n'a pas de dimensions lisibles).
 *  5. NOM DE STOCKAGE — UUID, extension déduite du MIME réel. Le nom du client n'atteint jamais le
 *     disque : c'est ce qui ferme le path-traversal.
 *
 * ═══ CE QUE CE SERVICE NE FAIT PAS ═══
 *
 * Il ne redimensionne pas, ne génère pas de vignette et ne scanne pas. L'absence d'antivirus est un
 * choix symétrique de `PhotoMembreService` : image publique, déposée par un gestionnaire identifié
 * et habilité, jamais exécutée, servie avec son type réel. La borne de taille est à l'entrée.
 */
class ImagesEtablissement
{
    public const DISK = 'etablissements';

    public const PERMISSION = 'etablissement.manage';

    /** Extension de stockage pour chaque MIME accepté. Le client n'a pas voix au chapitre. */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Dépose une image. Renvoie la ligne créée.
     *
     * @throws HttpException 403 (habilitation), 404 (catégorie), 409 (quota), 422 (fichier)
     */
    public function deposer(
        UploadedFile $fichier,
        StructureSanitaire $structure,
        string $codeCategorie,
        User $acteur,
    ): EtablissementImage {
        $this->exigerHabilitation($acteur, $structure);

        $categorie = CategorieImageEtablissement::query()->active()->where('code', $codeCategorie)->first();

        abort_if(
            $categorie === null,
            404,
            "La catégorie d'image « {$codeCategorie} » n'existe pas ou n'est plus active.",
        );

        [$contenu, $mime, $dimensions] = $this->lireEtControler($fichier);
        $empreinte = hash('sha256', $contenu);

        return DB::transaction(function () use (
            $structure, $categorie, $contenu, $mime, $dimensions, $empreinte, $acteur
        ): EtablissementImage {
            // Verrou pessimiste sur les images DE CETTE CATÉGORIE : sans lui, deux dépôts
            // simultanés liraient tous deux « 0 logo » et en écriraient deux.
            $existantes = EtablissementImage::query()
                ->where('structure_id', $structure->id)
                ->where('categorie_code', $categorie->code)
                ->lockForUpdate()
                ->get();

            abort_if(
                $existantes->count() >= $categorie->max_par_etablissement,
                409,
                "Cet établissement a déjà {$existantes->count()} image(s) « {$categorie->libelle} », "
                    ."et le maximum est de {$categorie->max_par_etablissement}. "
                    .'Supprimez-en une avant d\'en ajouter une autre.',
            );

            abort_if(
                $existantes->contains('empreinte', $empreinte),
                409,
                'Cette image exacte est déjà publiée dans cette catégorie.',
            );

            $chemin = $structure->id.'/'.Str::uuid()->toString().'.'.self::EXTENSIONS[$mime];
            Storage::disk(self::DISK)->put($chemin, $contenu);

            return EtablissementImage::create([
                'structure_id'   => $structure->id,
                'categorie_code' => $categorie->code,
                'chemin'         => $chemin,
                'mime'           => $mime,
                'taille_octets'  => strlen($contenu),
                'empreinte'      => $empreinte,
                'largeur'        => $dimensions[0],
                'hauteur'        => $dimensions[1],
                'ordre'          => $existantes->max('ordre') + 1,
                'depose_par'     => $acteur->id,
            ]);
        });
    }

    /** Supprime une image (blob + ligne). */
    public function supprimer(EtablissementImage $image, User $acteur): void
    {
        $this->exigerHabilitation($acteur, $image->structure);

        if (Storage::disk(self::DISK)->exists($image->chemin)) {
            Storage::disk(self::DISK)->delete($image->chemin);
        }

        $image->delete();
    }

    /** Octets bruts, pour la diffusion. */
    public function contenu(EtablissementImage $image): string
    {
        return Storage::disk(self::DISK)->get($image->chemin);
    }

    /**
     * Qui peut publier les images d'un établissement.
     *
     * Deux chemins, et le second est le plus important : c'est l'hôpital lui-même qui renseigne sa
     * vitrine (CDC_11 §3), pas une administration centrale. Un gestionnaire n'a de droits que sur
     * SON établissement — `structure_id` fait foi, jamais un identifiant reçu du client.
     */
    private function exigerHabilitation(User $acteur, StructureSanitaire $structure): void
    {
        if ($acteur->can(self::PERMISSION)) {
            return;
        }

        abort_unless(
            $acteur->structure_id === $structure->id
                && $acteur->hasRole('gestionnaire_etablissement'),
            403,
            'Vous n\'êtes pas habilité à publier les images de cet établissement.',
        );
    }

    /**
     * Lit le fichier, en établit la nature, et refuse tout ce qui n'est pas une vraie image.
     *
     * @return array{0:string, 1:string, 2:array{0:?int,1:?int}}
     */
    private function lireEtControler(UploadedFile $fichier): array
    {
        $maxOctets = (int) config('masante.etablissement_images.max_ko') * 1024;
        $autorises = (array) config('masante.etablissement_images.mimetypes');

        abort_if(
            $fichier->getSize() > $maxOctets,
            422,
            'Image trop lourde ('.round($fichier->getSize() / 1024).' Ko) : le maximum est de '
                .config('masante.etablissement_images.max_ko').' Ko.',
        );

        $contenu = (string) file_get_contents($fichier->getRealPath());

        // Le MIME est établi sur les OCTETS. Un fichier nommé « logo.png » contenant un script se
        // dénonce ici, et c'est le seul endroit où on peut le voir.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contenu) ?: 'application/octet-stream';

        abort_unless(
            in_array($mime, $autorises, true) && isset(self::EXTENSIONS[$mime]),
            422,
            "Format « {$mime} » refusé. Formats acceptés : ".implode(', ', $autorises).'.',
        );

        // Second crible : une vraie image a des dimensions POSITIVES.
        //
        // `false` ne suffit pas comme critère, et c'est un vecteur de test qui l'a montré : un PNG
        // dont l'en-tête IHDR porte une largeur et une hauteur nulles est annoncé « image/png » par
        // `finfo` — donc le premier crible le laisse passer — et `getimagesizefromstring` répond
        // `[0, 0]` plutôt que `false`. Une image de zéro pixel serait entrée dans le stockage
        // public et se serait affichée comme un cadre vide, sans qu'aucune garde ne bronche.
        $taille = @getimagesizefromstring($contenu);

        abort_if(
            $taille === false || $taille[0] <= 0 || $taille[1] <= 0,
            422,
            'Ce fichier porte bien un en-tête d\'image mais n\'en est pas une : dimensions illisibles ou nulles.',
        );

        return [$contenu, $mime, [$taille[0], $taille[1]]];
    }
}
