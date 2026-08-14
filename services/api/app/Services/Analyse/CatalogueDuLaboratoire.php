<?php

namespace App\Services\Analyse;

use App\Models\Analyse;
use App\Models\LaboratoireAnalyse;
use App\Models\StructureSanitaire;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * P6.7b — Les analyses qu'un laboratoire réalise (CDC_09 §7.2 « analyses disponibles »).
 *
 * ═══ CE QUI EST GOUVERNÉ, ET CE QUI NE L'EST PAS ═══
 *
 * Le critère de P6.4a est refait ici, pas recopié. Une **accréditation** est délivrée par une
 * autorité : elle est gouvernée, et vit déjà dans `certifications_json`. La **liste des analyses
 * qu'un laboratoire réalise** change avec ses automates et son personnel — la soumettre au
 * quatre-yeux national ferait de l'arrivée d'un appareil une décision ministérielle. C'est donc une
 * donnée d'exploitation, tenue par le laboratoire lui-même.
 *
 * ═══ L'HABILITATION A DEUX CHEMINS ═══
 *
 * Permission nationale `etablissement.manage`, **OU** gestionnaire de CE laboratoire. Motif exact
 * de `ImagesEtablissement` (P6.4c) : CDC_11 §3 fait remplir sa fiche par l'établissement lui-même.
 * Vérifiée **en service** et non par le middleware spatie — les routes du portail sont sur le guard
 * `web`, mais le même service sert l'API mobile, et le piège de P4 sur `rdv.validate` a montré ce
 * que coûte un contrôle placé au mauvais endroit.
 */
final class CatalogueDuLaboratoire
{
    public const PERMISSION = 'etablissement.manage';

    /**
     * Déclare qu'un laboratoire réalise une analyse.
     *
     * @throws ValidationException si l'établissement n'est pas un laboratoire, ou si l'analyse est
     *                             déjà déclarée
     */
    public function declarer(
        StructureSanitaire $laboratoire,
        Analyse $analyse,
        User $acteur,
        ?int $delaiHeures = null,
        ?string $methode = null,
    ): LaboratoireAnalyse {
        $this->exigerHabilitation($acteur, $laboratoire);

        // UN CHU N'EST PAS UN LABORATOIRE. Sans ce contrôle, « analyses disponibles » deviendrait
        // une propriété de n'importe quel établissement, et le référentiel des laboratoires ne
        // voudrait plus rien dire.
        abort_unless(
            $laboratoire->type === 'laboratoire',
            422,
            "« {$laboratoire->nom} » n'est pas un laboratoire : il ne déclare pas d'analyses.",
        );

        $ligne = new LaboratoireAnalyse([
            'delai_rendu_heures' => $delaiHeures,
            'methode'            => $methode,
            'disponible'         => true,
        ]);

        $ligne->structure_id = $laboratoire->getKey();
        $ligne->analyse_id = $analyse->getKey();

        try {
            $ligne->save();
        } catch (UniqueConstraintViolationException) {
            // C'est le MOTEUR qui refuse : `UNIQUE(structure_id, analyse_id)`. Un laboratoire ne
            // déclare pas deux fois la même analyse, et deux délais contradictoires pour le même
            // examen laisseraient le patient sans savoir lequel croire.
            throw ValidationException::withMessages([
                'analyse_id' => 'Ce laboratoire déclare déjà cette analyse.',
            ]);
        }

        return $ligne;
    }

    public function retirer(StructureSanitaire $laboratoire, LaboratoireAnalyse $ligne, User $acteur): void
    {
        $this->exigerHabilitation($acteur, $laboratoire);

        // La ligne doit appartenir à CE laboratoire : sans ce contrôle, l'identifiant de l'URL
        // permettrait de retirer l'analyse d'un concurrent.
        abort_unless((int) $ligne->structure_id === (int) $laboratoire->getKey(), 404);

        $ligne->delete();
    }

    /**
     * Les analyses réalisées par un laboratoire, avec le délai qui s'applique réellement.
     *
     * LE DÉLAI DU LABORATOIRE PRIME SUR CELUI DU CATALOGUE quand il est renseigné : le catalogue
     * national donne un ordre de grandeur, l'officine sait ce qu'elle tient. Mais on ne remplace
     * jamais silencieusement — la réponse porte les deux, et dit lequel s'applique.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function analysesDe(StructureSanitaire $laboratoire): Collection
    {
        return LaboratoireAnalyse::query()
            ->with('analyse:id,code,libelle,unite,milieu_preleve,delai_rendu_heures')
            ->where('structure_id', $laboratoire->getKey())
            ->get()
            ->map(fn (LaboratoireAnalyse $l): array => [
                'id'                  => $l->id,
                'analyse'             => $l->analyse?->designation,
                'code'                => $l->analyse?->code,
                'unite'               => $l->analyse?->unite,
                'disponible'          => (bool) $l->disponible,
                'methode'             => $l->methode,
                'delai_laboratoire'   => $l->delai_rendu_heures,
                'delai_catalogue'     => $l->analyse?->delai_rendu_heures,
                'delai_applique'      => $l->delai_rendu_heures ?? $l->analyse?->delai_rendu_heures,
                'delai_source'        => $l->delai_rendu_heures !== null ? 'laboratoire' : 'catalogue',
            ])
            ->sortBy('analyse')
            ->values();
    }

    private function exigerHabilitation(User $acteur, StructureSanitaire $laboratoire): void
    {
        if ($acteur->can(self::PERMISSION)) {
            return;
        }

        abort_unless(
            $acteur->structure_id === $laboratoire->id && $acteur->hasRole('gestionnaire_etablissement'),
            403,
            'Vous n\'êtes pas habilité à déclarer les analyses de ce laboratoire.',
        );
    }
}
