<?php

namespace Tests\Concerns;

use App\Models\ReferentielMesure;
use App\Services\Referentiel\SourceSeuilsMesure;

/**
 * Met en vigueur les seuils nationaux de mesure — le préalable de tout vecteur qui écrit une mesure
 * depuis la bascule L1 (ADR-025 §5).
 *
 * La mécanique générique (deux comptes habilités, quatre-yeux, frontière de requête) vit désormais
 * dans {@see GouverneUnReferentiel}, extraite quand P6.8b a eu besoin de la même chose pour le
 * calendrier vaccinal. Ce trait ne garde que ce qui est propre aux seuils.
 */
trait PublieLesSeuilsDeMesure
{
    use GouverneUnReferentiel;

    /**
     * Enregistre, propose et publie le contenu ACTUEL de `referentiels_mesure`.
     *
     * @return int le numéro de la version mise en vigueur
     */
    protected function publierLesSeuils(string $motif = 'Mise en vigueur nationale.'): int
    {
        return $this->publierReferentiel(SourceSeuilsMesure::CODE, $motif);
    }

    /**
     * Corrige un seuil EN TABLE puis publie la version qui l'entérine — le chemin légitime d'une
     * correction clinique depuis L1.
     */
    protected function corrigerUnSeuilEtPublier(string $type, array $valeurs, string $motif): int
    {
        ReferentielMesure::where('type_mesure', $type)->update($valeurs);

        return $this->republierReferentiel(SourceSeuilsMesure::CODE, $motif);
    }
}
